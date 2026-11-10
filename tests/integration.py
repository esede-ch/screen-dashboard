#!/usr/bin/env python3
"""Tests isolés : aucune connexion à une vraie box, aucune commande de TV."""
import contextlib
import concurrent.futures
import copy
import fcntl
import hashlib
import http.cookiejar
import http.cookies
import http.server
import json
import os
from pathlib import Path
import re
import signal
import socket
import subprocess
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
from ldap_fixture import LdapFixture

ROOT = Path(__file__).resolve().parents[1]

def timetable_jpeg(bar_width):
    script = """
        $im = imagecreatetruecolor(800, 450);
        imagefill($im, 0, 0, 0xffffff);
        imagefilledrectangle($im, 24, 45, 24 + (int)$argv[1], 67, 0xe34724);
        imagejpeg($im);
    """
    return subprocess.check_output(['php', '-r', script, str(bar_width)])

KEY = 'T' * 64
CONFIG = dict(MODE='auto', ON_TIME='0600', OFF_TIME='1700', WEEKEND_ENABLED='no', HDMI_LOCK_ENABLED='yes')

class MockBox(http.server.BaseHTTPRequestHandler):
    mode = 'normal'
    received = []
    config = CONFIG.copy()
    revision = 'r1'
    def log_message(self, *args): pass
    def do_GET(self): self.handle_api()
    def do_POST(self): self.handle_api()
    def handle_api(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
        action = urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query).get('action', [''])[0]
        type(self).received.append((action, self.command, self.headers.get('Authorization'), body))
        if self.headers.get('Authorization') != 'Bearer ' + KEY:
            return self.reply(401, {'ok': False})
        if self.mode.startswith('legacy'):
            path=urllib.parse.urlsplit(self.path).path
            if path.startswith('/admin/'):
                return self.reply(404, {'ok':False,'error':'not_found'})
            if path=='/' and action=='status':
                data = {'ok':True,'hostname':'bad/name' if self.mode=='legacy-bad-device' else 'box-test',
                    'time':'2026-11-02 12:00:10','config':type(self).config,'tv_status':'on','schedule_status':'on'}
                if self.mode == 'legacy-identity':
                    data['television'] = {'status':'detected','brand':'LG','name':'LG SIGNAGE','source':'edid'}
                elif self.mode == 'legacy-invalid-identity':
                    data['television'] = 'invalid'
                return self.reply(200, data)
            if path=='/' and action=='config' and self.command=='POST':
                type(self).config.update(json.loads(body))
                return self.reply(200, {'ok':True,'config':type(self).config if self.mode!='legacy-missing-ack' else {}})
            if path=='/' and action in ('tv_auto','reboot','cec_refresh'):
                if self.command != ('GET' if action=='cec_refresh' else 'POST'):
                    return self.reply(405, {'ok':False})
                if self.mode=='legacy-failed-apply' and action=='tv_auto': return self.reply(500, {'ok':False})
                return self.reply(200, {'ok':True,'tv_status':'off','result':{'return_code':1 if self.mode=='legacy-failed-command' else 0, 'output':[KEY]}})
            query=urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query)
            if query.get('token')!=[KEY] or query.get('device_id')!=['box-test']:
                return self.reply(403,{'error':'unauthorized'})
            if path=='/screenshot-info.php':
                if self.mode=='legacy-missing-image': return self.reply(404,{'error':'Image introuvable'})
                return self.reply(200, {'device_id':'box-test','timestamp':'2026-11-02 12:00:00',
                    'image_url':f'http://127.0.0.1:{self.server.server_port}/unexpected?token='+KEY})
            if path=='/screenshot.php': return self.reply(200,MockBox.legacy_jpeg,'image/jpeg')
            return self.reply(404,{'ok':False})
        if self.mode == 'slow': time.sleep(5)
        if self.mode == 'cache-delay': time.sleep(.2)
        if self.mode == 'redirect':
            self.send_response(302); self.send_header('Location', '/unexpected'); self.end_headers(); return
        if self.mode == 'invalid': return self.reply(200, b'<html>Erreur</html>')
        if self.mode == 'large': return self.reply(200, b'x' * 300000)
        if self.mode == 'missing-ack': return self.reply(200, {'ok': True})
        if action == 'state':
            television = {
                'tv-philips': {'brand':None, 'manufacturer_code':'PHP', 'name':'PHP FTV'},
                'tv-samsung': {'brand':None, 'manufacturer_code':'SAM', 'name':'SM320MX3'},
                'tv-unknown': {'brand':None, 'manufacturer_code':'ZZZ', 'name':'Unknown TV'},
                'tv-explicit-brand': {'brand':'Existing brand', 'manufacturer_code':'SAM', 'name':'Display'},
                'tv-invalid-code': {'brand':None, 'manufacturer_code':['SAM'], 'name':'Display'},
            }.get(self.mode, {'brand':'Philips'})
            return self.reply(200, {'ok': True, 'hostname': 'box-test', 'boot_id': 'boot-test',
                'private_key': KEY, 'runtime': {'php_version': '8.5.5'},
                'cec': {'config': type(self).config, 'revision': type(self).revision, 'timezone': 'Europe/Zurich',
                    'television': television, 'tv_status': 'off', 'age_seconds': 2},
                'capture': MockBox.capture})
        if action == 'screenshot': return self.reply(200, MockBox.jpeg, 'image/jpeg')
        if action == 'cec-config':
            data = json.loads(body)
            if data.get('revision') != type(self).revision: return self.reply(409, {'ok': False})
            type(self).config = {k: data[k] for k in CONFIG}
            type(self).revision += 'x'
            return self.reply(200, {'ok': True, 'saved': True, 'applied': self.mode != 'not-applied'})
        if action in ('restart-box', 'restart-display'): return self.reply(202, {'ok': True, 'accepted': True, 'boot_id': 'boot-test'})
        if action == 'cec-refresh': return self.reply(200, {'ok': True, 'refresh_busy': False})
        return self.reply(404, {'ok': False})
    def reply(self, code, data, content_type='application/json'):
        if not isinstance(data, bytes): data = json.dumps(data).encode()
        self.send_response(code); self.send_header('Content-Type', content_type); self.send_header('Content-Length', str(len(data))); self.end_headers()
        with contextlib.suppress(BrokenPipeError, ConnectionResetError): self.wfile.write(data)

class Integration(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.orange_image = timetable_jpeg(751)
        cls.short_image = timetable_jpeg(250)
        # Keep the large legacy response test, using valid JPEG comment segments.
        comment = b'\xff\xfe\xea\x62' + b'x' * 60000
        MockBox.legacy_jpeg = cls.orange_image[:2] + comment * 5 + cls.orange_image[2:]
        cls.temp = tempfile.TemporaryDirectory(prefix='dashboard-tests-')
        cls.data = Path(cls.temp.name) / 'data'
        cls.ldap = LdapFixture(cls.temp.name, cls.data)
        cls.addClassCleanup(cls.ldap.close)
        cls.sessions = Path(cls.temp.name) / 'sessions'
        cls.sessions.mkdir(mode=0o700)
        cls.mock = http.server.ThreadingHTTPServer(('127.0.0.1', 0), MockBox)
        cls.mock.daemon_threads = True
        threading.Thread(target=cls.mock.serve_forever, daemon=True).start()
        cls.address = f'http://127.0.0.1:{cls.mock.server_port}'
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); port = sock.getsockname()[1]
        cls.url = f'http://127.0.0.1:{port}'
        cls.log = open(Path(cls.temp.name) / 'php.log', 'w+')
        cls.proc = subprocess.Popen(['php', '-d', 'display_errors=0', '-d', 'error_reporting=E_ALL', '-d', 'session.save_path='+str(cls.sessions), '-S', f'127.0.0.1:{port}', '-t', str(ROOT/'public')],
            stdout=cls.log, stderr=cls.log, start_new_session=True,
            env=dict(os.environ, DASHBOARD_DATA_DIR=str(cls.data), PHP_CLI_SERVER_WORKERS='4'))
        cls.client = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        for _ in range(50):
            try:
                with cls.client.open(cls.url, timeout=1) as response: cls.html = response.read().decode()
                break
            except OSError: time.sleep(.1)
        else: raise RuntimeError('Le serveur de test ne démarre pas')
        cls.client, cls.token = cls.login_client()
    @classmethod
    def login_client(cls):
        # Reset only the synthetic fixture limiter; authentication itself is real LDAP.
        (cls.data/'login-attempts.json').unlink(missing_ok=True)
        client = urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        with client.open(cls.url+'/login.php') as response: html=response.read().decode()
        csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
        body=urllib.parse.urlencode(dict(csrf=csrf,username='member',password=cls.ldap.password)).encode()
        with client.open(cls.url+'/login.php',body) as response: html=response.read().decode()
        return client,re.search(r'name="dashboard-token" content="([a-f0-9]+)"',html)[1]
    @classmethod
    def tearDownClass(cls):
        os.killpg(cls.proc.pid, signal.SIGTERM); cls.proc.wait(timeout=5)
        cls.mock.shutdown(); cls.mock.server_close()
        cls.ldap.close()
        cls.log.seek(0); logs = cls.log.read(); cls.log.close(); cls.temp.cleanup()
        if re.search(r'PHP (Warning|Fatal|Deprecated|Notice)', logs): raise AssertionError('Diagnostic PHP inattendu : ' + logs)
    def setUp(self):
        self.ldap.membership(True)
        (self.data/'login-attempts.json').unlink(missing_ok=True)
        MockBox.jpeg = self.orange_image
        MockBox.capture = dict(available=True, age_seconds=3, updated_at='2026-11-02T12:00:00+01:00')
        MockBox.mode = 'normal'; MockBox.received = []; MockBox.config = CONFIG.copy(); MockBox.revision = 'r1'
        if (self.data/'boxes.json').exists(): (self.data/'boxes.json').unlink()
        for path in self.data.glob('cec-*.json'): path.unlink()
        for path in self.data.glob('shared-*.json'): path.unlink()

    def anonymous(self):
        jar=http.cookiejar.CookieJar()
        client=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(jar))
        return client,jar

    def try_login(self, username, password, client=None, token=None):
        client=client or self.anonymous()[0]
        if token is None:
            with client.open(self.url+'/login.php') as r: html=r.read().decode()
            token=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
        req=urllib.request.Request(self.url+'/login.php',urllib.parse.urlencode(dict(username=username,password=password,csrf=token)).encode())
        try: r=client.open(req)
        except urllib.error.HTTPError as e: r=e
        with r: return r.status,r.read().decode(),client

    def age_session(self, client, field, seconds):
        jar=next(h.cookiejar for h in client.handlers if isinstance(h,urllib.request.HTTPCookieProcessor))
        sid=next(c.value for c in jar if c.name=='screens_dashboard')
        code='session_name("screens_dashboard");session_id($argv[1]);session_start();$_SESSION["auth"][$argv[2]]=time()-(int)$argv[3];session_write_close();'
        subprocess.run(['php','-d','session.save_path='+str(self.sessions),'-r',code,sid,field,str(seconds)],check=True)
        return self.sessions/('sess_'+sid)

    def assert_private_denied(self, client):
        for action in ['list','status','image&id=anything','cec-auto','command','save','remove','reorder']:
            data=b'{}' if action in ['cec-auto','command','save','remove','reorder'] else None
            req=urllib.request.Request(self.url+'/api.php?action='+action,data,headers={'X-Dashboard-Token':self.token,'Content-Type':'application/json'})
            with self.assertRaises(urllib.error.HTTPError) as caught: client.open(req)
            self.assertEqual(caught.exception.code,401)
            self.assertEqual(json.loads(caught.exception.read())['code'],'auth_required')

    def test_auth_anonymous_cannot_read_or_command_even_with_csrf(self):
        client,_=self.anonymous()
        with client.open(self.url) as r:
            self.assertTrue(r.url.endswith('/login.php'))
            self.assertNotIn('id="screens"',r.read().decode())
        self.assert_private_denied(client)
        self.assertEqual(MockBox.received,[])

    def test_auth_member_allowed_password_not_persisted_and_session_rotates(self):
        client,jar=self.anonymous()
        with client.open(self.url+'/login.php') as r: r.read()
        old=next(c.value for c in jar if c.name=='screens_dashboard')
        status,html,_=self.try_login('member',self.ldap.password,client)
        self.assertEqual(status,200);self.assertIn('Se déconnecter',html)
        new=next(c.value for c in jar if c.name=='screens_dashboard')
        self.assertNotEqual(old,new)
        self.assertNotIn(self.ldap.password,(self.sessions/('sess_'+new)).read_text())
        self.assertFalse((self.sessions/('sess_'+old)).exists())

    def test_auth_nonmember_and_bad_password_denied(self):
        for user,password in [('outsider',self.ldap.password),('member','wrong'),('absent',self.ldap.password),('*',self.ldap.password),('*)(uid=*)',self.ldap.password)]:
            status,html,client=self.try_login(user,password)
            self.assertEqual(status,401);self.assertIn('Identifiants incorrects ou accès non autorisé.',html)
            self.assertNotIn('id="screens"',html);self.assert_private_denied(client)

    def test_auth_empty_password_and_csrf_and_rate_limit(self):
        self.assertEqual(self.try_login('member','')[0],400)
        self.assertEqual(self.try_login('member',self.ldap.password,token='invalid')[0],403)
        for _ in range(5):self.assertEqual(self.try_login('member','bad')[0],401)
        self.assertEqual(self.try_login('member',self.ldap.password)[0],429)

    def test_auth_group_removal_revokes_existing_session_at_next_check(self):
        client,token=self.login_client()
        self.ldap.membership(False)
        self.age_session(client,'checked_at',61)
        self.assert_private_denied(client)
        with client.open(self.url) as r:self.assertTrue(r.url.endswith('/login.php'))

    def test_auth_expired_session_denied(self):
        client,_=self.login_client()
        self.age_session(client,'issued_at',28801)
        self.assert_private_denied(client)

    def test_auth_logout_requires_post_and_csrf_then_revokes_cookie(self):
        client,token=self.login_client()
        for data,code in [(None,405),(b'csrf=wrong',403)]:
            with self.assertRaises(urllib.error.HTTPError) as caught:client.open(self.url+'/logout.php',data)
            self.assertEqual(caught.exception.code,code)
        with client.open(self.url+'/logout.php',urllib.parse.urlencode(dict(csrf=token)).encode()) as r:self.assertTrue(r.url.endswith('/login.php'))
        self.assert_private_denied(client)

    def test_auth_config_change_and_missing_config_fail_closed(self):
        path=self.data/'ldap.json';raw=path.read_text()
        client,_=self.login_client()
        try:
            c=json.loads(raw);c['group_dn']='cn=other,dc=test,dc=local';path.write_text(json.dumps(c))
            self.assert_private_denied(client)
            path.unlink()
            self.assert_private_denied(self.anonymous()[0])
            with self.assertRaises(urllib.error.HTTPError) as caught:self.anonymous()[0].open(self.url+'/login.php')
            self.assertEqual(caught.exception.code,503)
        finally:path.write_text(raw)

    def test_auth_untrusted_certificate_refused_in_strict_mode(self):
        path=self.data/'ldap.json';raw=path.read_text()
        try:
            c=json.loads(raw);c['tls_verify']=True;path.write_text(json.dumps(c))
            self.assertEqual(self.try_login('member',self.ldap.password)[0],503)
        finally:path.write_text(raw)

    def test_auth_revalidation_failure_revokes_session(self):
        # A missing group yields a directory error, never an authorization fallback.
        client,_=self.login_client()
        self.age_session(client,'checked_at',61)
        self.ldap.write_ldif('dn: cn=screen,dc=test,dc=local\nchangetype: delete\n',True)
        try:self.assert_private_denied(client)
        finally:self.ldap.write_ldif('dn: cn=screen,dc=test,dc=local\nobjectClass: posixGroup\ncn: screen\ngidNumber: 1000\nmemberUid: member\n')

    def test_https_proxy_origin_secure_cookie_and_ldap_login(self):
        # Simulate a TLS proxy forwarding HTTP with the original public Host.
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        address=f'http://127.0.0.1:{port}'
        log=open(Path(self.temp.name)/'proxy-test.log','w+')
        proc=subprocess.Popen(['php','-d','display_errors=0','-d','session.save_path='+str(self.sessions),'-S',f'127.0.0.1:{port}','-t',str(ROOT/'public')],stdout=log,stderr=log,
            env=dict(os.environ,DASHBOARD_DATA_DIR=str(self.data),DASHBOARD_PUBLIC_URL='https://screens.example.test',PHP_CLI_SERVER_WORKERS='1'))
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self,*args):return None
        client=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
        def fetch(path,data=None,headers=None):
            req=urllib.request.Request(address+path,data,headers={'Host':'screens.example.test',**(headers or {})})
            try:r=client.open(req,timeout=5)
            except urllib.error.HTTPError as error:r=error
            with r:return r.status,r.headers,r.read().decode()
        def cookie(headers):
            c=http.cookies.SimpleCookie();c.load(headers['Set-Cookie'])
            self.assertTrue(c['screens_dashboard']['secure'])
            return 'screens_dashboard='+c['screens_dashboard'].value
        try:
            for _ in range(50):
                try:status,headers,html=fetch('/login.php');break
                except OSError:time.sleep(.05)
            else:self.fail('Serveur HTTP du test proxy indisponible')
            self.assertEqual(status,200)
            csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
            data=urllib.parse.urlencode(dict(username='member',password=self.ldap.password,csrf=csrf)).encode()
            status,headers,_=fetch('/login.php',data,{'Cookie':cookie(headers),'Content-Type':'application/x-www-form-urlencoded','Origin':'https://screens.example.test'})
            self.assertEqual(status,303)
            session_cookie=cookie(headers)
            status,_,html=fetch('/',headers={'Cookie':session_cookie})
            self.assertEqual(status,200)
            token=re.search(r'name="dashboard-token" content="([a-f0-9]+)"',html)[1]
            auth_headers={'Cookie':session_cookie,'X-Dashboard-Token':token,'Origin':'https://screens.example.test'}
            self.assertEqual(fetch('/api.php?action=list',headers=auth_headers)[0],200)
            auth_headers['Origin']='http://screens.example.test'
            self.assertEqual(fetch('/api.php?action=list',headers=auth_headers)[0],403)
            self.assertEqual(fetch('/login.php',headers={'Host':'127.0.0.1','X-Forwarded-Host':'screens.example.test'})[0],403)
        finally:
            proc.terminate();proc.wait(timeout=5);log.close()
    def expire_cache(self):
        for path in self.data.glob('shared-*.json'):
            data=json.loads(path.read_text()); data['fetched_at']-=16; path.write_text(json.dumps(data))
    def image(self, box, expected=200):
        req=urllib.request.Request(self.url+'/api.php?action=image&id='+box['id'],headers={'X-Dashboard-Token':self.token})
        try: response=self.client.open(req)
        except urllib.error.HTTPError as error: response=error
        with response:
            raw=response.read(); self.assertEqual(response.status,expected); self.assertNotIn(KEY.encode(),raw)
            return raw
    def api(self, action, body=None, expected=200, headers=None, token=True):
        req = urllib.request.Request(self.url + '/api.php?action=' + action,
            data=None if body is None else json.dumps(body).encode(),
            headers={**({'X-Dashboard-Token': self.token} if token else {}), 'Content-Type': 'application/json', **(headers or {})})
        try: response = self.client.open(req, timeout=12)
        except urllib.error.HTTPError as error: response = error
        with response:
            raw = response.read(); self.assertEqual(response.status, expected, raw[:200])
        self.assertNotIn(KEY.encode(), raw)
        return json.loads(raw)
    def add(self, **overrides):
        return self.api('save', {'name':'Box de test', 'address':self.address, 'key':KEY, **overrides})['box']
    def command(self, box, command, expected=200, **extra):
        return self.api('command', dict(id=box['id'], box_revision=box['revision'], command=command, **extra), expected)
    def monitor(self, now):
        script = "require $argv[1].'/src/bootstrap.php'; require $argv[1].'/src/CecMonitor.php'; echo json_encode(CecMonitor::tick((new Store(dashboard_dir()))->all(),(int)$argv[2]));"
        run=subprocess.run(['php','-r',script,str(ROOT),str(now)],capture_output=True,text=True,
            env=dict(os.environ,DASHBOARD_DATA_DIR=str(self.data)),timeout=12,check=True)
        self.assertEqual(run.stderr,''); self.assertNotIn(KEY,run.stdout)
        return json.loads(run.stdout)
    def test_monitor_refreshes_each_minute_and_uses_current_registry(self):
        box=self.add(); self.assertEqual(self.monitor(1000),{box['id']:'ok'})
        self.assertEqual([r[0] for r in MockBox.received],['state','cec-refresh'])
        self.monitor(1059); self.assertEqual(len(MockBox.received),2)
        self.monitor(1060); self.assertEqual(len(MockBox.received),4)
        self.api('remove',dict(id=box['id'],revision=box['revision'],confirm=True))
        self.monitor(1120); self.assertEqual(len(MockBox.received),4)
    def test_page_triggered_cec_requires_post_and_token_and_deduplicates(self):
        box=self.add()
        self.api('cec-auto',expected=404)
        self.api('cec-auto',{},expected=403,token=False)
        self.assertEqual(MockBox.received,[])
        self.assertEqual(self.api('cec-auto',{})['results'],{box['id']:'ok'})
        count=len(MockBox.received)
        self.api('cec-auto',{})
        self.assertEqual(len(MockBox.received),count)
        self.api('list');self.api('status')
        self.assertEqual(len([r for r in MockBox.received if r[0]=='cec-refresh']),1)
    def test_monitor_legacy_refresh_updates_shared_observation(self):
        box=self.add(); MockBox.mode='legacy'
        self.assertEqual(self.monitor(1000),{box['id']:'ok'})
        self.assertEqual([r[0] for r in MockBox.received],['state','status','cec_refresh'])
        self.assertEqual(self.api('status')['boxes'][0]['state']['tv_status'],'off')
        MockBox.received=[]; self.monitor(1059); self.assertEqual(MockBox.received,[])
    def test_monitor_defers_busy_box_and_retries_failure_next_minute(self):
        box=self.add()
        with open(self.data/f"command-{box['id']}.lock",'w') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            self.monitor(1000); self.assertEqual(MockBox.received,[])
        MockBox.mode='invalid'; self.assertEqual(self.monitor(1001),{box['id']:'error'})
        self.monitor(1060); self.assertEqual(len(MockBox.received),1)
        MockBox.mode='normal'; self.assertEqual(self.monitor(1061),{box['id']:'ok'})
    def test_monitor_bad_key_does_not_block_other_box(self):
        bad=self.add(key='X'*64)
        good=self.add(address=self.address.replace('127.0.0.1','localhost'),name='Good')
        self.assertEqual(self.monitor(1000),{bad['id']:'error',good['id']:'ok'})
        self.assertEqual(len([r for r in MockBox.received if r[0]=='cec-refresh']),1)
    def test_crud_persistence_and_key_preserved(self):
        box = self.add(); self.assertNotIn('key', box)
        stored = json.loads((self.data/'boxes.json').read_text())[0]; self.assertEqual(stored['key'], KEY)
        self.assertEqual((self.data/'boxes.json').stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.data.stat().st_mode & 0o777, 0o700)
        changed = self.api('save', dict(box, name='Accueil modifié', key=''))['box']
        self.assertEqual(changed['name'], 'Accueil modifié')
        self.assertEqual(json.loads((self.data/'boxes.json').read_text())[0]['key'], KEY)
        self.api('save', dict(box, key=''), 409)
        self.api('remove', dict(id=box['id'], revision=box['revision'], confirm=True), 409)
        self.api('remove', dict(id=changed['id'], revision=changed['revision']), 400)
        self.api('remove', dict(id=changed['id'], revision=changed['revision'], confirm=True))
        self.assertEqual(self.api('list')['boxes'], [])
        self.assertEqual(MockBox.received, [])
    def test_address_key_name_validation(self):
        for address in ['file:///etc/passwd', 'ftp://box', 'http://user:pass@box', 'box/path', 'box?token=x', 'box#x', 'box:70000', 'box name', 'box\\evil']:
            self.api('save', dict(name='Test', address=address, key=KEY), 400)
        self.api('save', dict(name='Test', address='box', key='short'), 400)
        self.api('save', dict(name='é'*121, address='box', key=KEY), 400)
        row = self.add(name='é'*120); self.assertEqual(len(row['name']), 120)
    def test_reorder_persists_without_changing_box_details(self):
        a=self.add(); b=self.add(name='B',address='127.0.0.1:61002'); c=self.add(name='C',address='127.0.0.1:61003')
        original=[a['id'],b['id'],c['id']]; wanted=[c['id'],a['id'],b['id']]
        before={r['id']:r for r in json.loads((self.data/'boxes.json').read_text())}
        rows=self.api('reorder',dict(order=wanted,expected_order=original))['boxes']
        self.assertEqual([r['id'] for r in rows],wanted)
        self.assertEqual([r['id'] for r in self.api('list')['boxes']],wanted)
        stored=json.loads((self.data/'boxes.json').read_text())
        self.assertEqual([r['id'] for r in stored],wanted)
        self.assertEqual({r['id']:r for r in stored},before)
        self.api('save',dict(a,name='Renamed',key=''))
        self.assertEqual([r['id'] for r in self.api('list')['boxes']],wanted)
        self.assertEqual(MockBox.received,[])
    def test_reorder_rejects_invalid_or_outdated_list(self):
        a=self.add();b=self.add(address='127.0.0.1:61002');ids=[a['id'],b['id']]
        for order in [[a['id']], [a['id'],a['id']], ['unknown',b['id']], [None,b['id']]]:
            self.api('reorder',dict(order=order,expected_order=ids),400)
        self.api('reorder',dict(order=list(reversed(ids)),expected_order=ids))
        self.api('reorder',dict(order=ids,expected_order=ids),409)
        self.add(address='127.0.0.1:61003')
        self.api('reorder',dict(order=ids,expected_order=list(reversed(ids))),409)
        self.api('reorder',dict(order=ids,expected_order=ids),403,token=False)
    def test_ip_dns_and_duplicate(self):
        for address, normalized in [('192.0.2.10','http://192.0.2.10:8088'), ('TV.LOCAL','http://tv.local:8088'), ('https://tv.local:8443/','https://tv.local:8443'), ('[::1]:9876','http://[::1]:9876')]:
            self.assertEqual(self.api('save', dict(name='Test', address=address, key=KEY))['box']['address'], normalized)
        self.api('save', dict(name='Doublon', address='http://TV.LOCAL:8088/', key=KEY), 400)
    def test_csrf_and_origin(self):
        self.api('list', token=False, expected=403)
        self.api('list', headers={'X-Dashboard-Token': 'wrong'}, expected=403)
        self.api('save', {}, headers={'Origin': 'https://outside.example'}, expected=403)
        req = urllib.request.Request(self.url, headers={'Host':'outside.example'})
        with self.assertRaises(urllib.error.HTTPError) as caught: self.client.open(req)
        self.assertEqual(caught.exception.code, 403)
    def test_secret_not_web_accessible(self):
        self.add()
        for route in ['/', '/data/boxes.json', '/src/Store.php', '/app.js', '/app.css']:
            try:
                with self.client.open(self.url+route) as response: raw=response.read()
            except urllib.error.HTTPError as response: raw=response.read()
            self.assertNotIn(KEY.encode(), raw)
    def test_pages_reference_content_versioned_assets(self):
        for route, names in [('/', ['app.js', 'app.css', 'auth.css']), ('/login.php', ['login.css'])]:
            with self.client.open(self.url + route) as response:
                html = response.read().decode()
                self.assertIn('no-store', response.headers.get('Cache-Control', ''))
            for name in names:
                match = re.search(r'(?:src|href)="(/' + re.escape(name) + r'\?v=([a-f0-9]{16}))"', html)
                self.assertIsNotNone(match, name)
                with self.client.open(self.url + match[1]) as response:
                    contents = response.read()
                    self.assertEqual(response.status, 200)
                self.assertEqual(hashlib.sha256(contents).hexdigest()[:16], match[2])

    def test_tv_brand_fallback_uses_manufacturer_without_overwriting_brand(self):
        self.add()
        cases = [('tv-philips','Philips','PHP FTV'), ('tv-samsung','Samsung','SM320MX3'),
                 ('tv-unknown',None,'Unknown TV'), ('tv-explicit-brand','Existing brand','Display'),
                 ('tv-invalid-code',None,'Display')]
        for mode, brand, name in cases:
            with self.subTest(mode=mode):
                MockBox.mode=mode; self.expire_cache()
                row=self.api('status')['boxes'][0]
                self.assertTrue(row['online'])
                self.assertEqual(row['state']['brand'], brand)
                self.assertEqual(row['state']['tv_name'], name)

    def test_state_projection_and_image(self):
        box = self.add(); row = self.api('status')['boxes'][0]
        self.assertTrue(row['online']); self.assertEqual(row['state']['brand'], 'Philips')
        self.assertEqual(row['state']['config'], CONFIG)
        req = urllib.request.Request(self.url+'/api.php?action=image&id='+box['id'], headers={'X-Dashboard-Token':self.token})
        with self.client.open(req) as response:
            self.assertEqual(response.headers['Content-Type'],'image/jpeg'); self.assertEqual(response.read(),MockBox.jpeg)
        self.assertTrue(all(r[2]=='Bearer '+KEY for r in MockBox.received))
    def test_bad_key_and_offline(self):
        self.add(key='X'*64)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        self.api('save', dict(name='Offline', address=f'127.0.0.1:{port}', key=KEY))
        rows = self.api('status')['boxes']; self.assertEqual([r['error_kind'] for r in rows], ['key','offline'])
    def test_legacy_capture_without_token_leak_or_false_tv_state(self):
        box=self.add(); MockBox.mode='legacy'
        row=self.api('status')['boxes'][0]
        self.assertTrue(row['online']); self.assertEqual(row['state']['api_generation'],'legacy')
        self.assertEqual(row['state']['tv_status'],'unknown')
        self.assertEqual(row['state']['capture'],dict(available=True,updated_at='2026-11-02T12:00:00',age_seconds=10))
        self.assertNotIn('image_url',json.dumps(row))
        req=urllib.request.Request(self.url+'/api.php?action=image&id='+box['id'],headers={'X-Dashboard-Token':self.token})
        with self.client.open(req) as response:
            image=response.read(); self.assertEqual(response.status,200); self.assertGreater(len(image),300000)
            self.assertTrue(image.startswith(b'\xff\xd8') and image.endswith(b'\xff\xd9'))
            self.assertNotIn(KEY.encode(),image)
    def test_legacy_tv_identity_is_exposed_and_shared(self):
        self.add(); MockBox.mode='legacy-identity'
        row=self.api('status')['boxes'][0]
        self.assertTrue(row['online'])
        self.assertEqual(row['state']['brand'], 'LG')
        self.assertEqual(row['state']['tv_name'], 'LG SIGNAGE')
        self.assertEqual(row['state']['tv_status'], 'unknown')
        calls=len(MockBox.received)
        again=self.api('status')['boxes'][0]
        self.assertEqual(again['state']['tv_name'], 'LG SIGNAGE')
        self.assertEqual(len(MockBox.received), calls)

    def test_legacy_missing_or_invalid_identity_remains_compatible(self):
        self.add()
        for mode in ('legacy', 'legacy-invalid-identity'):
            with self.subTest(mode=mode):
                MockBox.mode=mode; self.expire_cache()
                row=self.api('status')['boxes'][0]
                self.assertTrue(row['online'])
                self.assertIsNone(row['state']['brand'])
                self.assertIsNone(row['state']['tv_name'])

    def test_legacy_missing_capture_keeps_box_online(self):
        self.add(); MockBox.mode='legacy-missing-image'
        row=self.api('status')['boxes'][0]
        self.assertTrue(row['online']); self.assertFalse(row['state']['capture']['available'])
    def test_legacy_modes_saved_before_immediate_apply(self):
        box=self.add(); MockBox.mode='legacy'
        MockBox.config.update(CEC_DEVICE='/dev/cec1',CEC_PHYS_ADDR='2.0.0.0')
        for mode in ['force_on','force_off','auto']:
            MockBox.received=[]
            self.assertTrue(self.command(box,'mode',mode=mode)['applied'])
            writes=[r for r in MockBox.received if r[1]=='POST']
            self.assertEqual([r[0] for r in writes],['config','tv_auto'])
            self.assertEqual(json.loads(writes[0][3]),dict(MODE=mode,ON_TIME='0600',OFF_TIME='1700',WEEKEND_ENABLED='no'))
            self.assertEqual(MockBox.config['MODE'],mode)
            self.assertEqual(MockBox.config['CEC_DEVICE'],'/dev/cec1')
            self.assertEqual(MockBox.config['CEC_PHYS_ADDR'],'2.0.0.0')
    def test_legacy_settings_revision_and_apply_failure(self):
        box=self.add(); MockBox.mode='legacy'
        revision=self.api('status')['boxes'][0]['state']['revision']
        settings=dict(CONFIG,ON_TIME='0800',revision=revision)
        result=self.command(box,'settings',settings=settings)
        self.assertTrue(result['saved']); self.assertTrue(result['applied'])
        self.command(box,'settings',settings=settings,expected=409)
        settings['revision']=self.api('status')['boxes'][0]['state']['revision']
        MockBox.mode='legacy-failed-apply'
        result=self.command(box,'settings',settings=settings)
        self.assertTrue(result['saved']); self.assertFalse(result['applied'])
        self.command(box,'settings',settings=dict(settings,ON_TIME='1900'),expected=400)
    def test_legacy_save_requires_ack_before_applying(self):
        box=self.add(); MockBox.mode='legacy-missing-ack'
        self.command(box,'mode',mode='force_off',expected=502)
        self.assertNotIn('tv_auto',[r[0] for r in MockBox.received])
    def test_legacy_cec_observation_survives_polling_and_new_session(self):
        box=self.add(); MockBox.mode='legacy'
        self.assertEqual(self.api('status')['boxes'][0]['state']['tv_status'],'unknown')
        self.command(box,'cec-refresh')
        row=self.api('status')['boxes'][0]['state']
        self.assertEqual(row['tv_status'],'off'); self.assertLess(row['tv_age_seconds'],3)
        self.assertIsNotNone(row['tv_checked_at'])
        cache=list(self.data.glob('cec-*.json')); self.assertEqual(len(cache),1)
        self.assertEqual(cache[0].stat().st_mode & 0o777,0o600)
        other, token=self.login_client()
        with other.open(self.url) as response:
            html=response.read().decode(); self.assertNotIn(KEY,html)
        token=re.search(r'name="dashboard-token" content="([a-f0-9]+)"',html)[1]
        with other.open(urllib.request.Request(self.url+'/api.php?action=status',headers={'X-Dashboard-Token':token})) as response:
            shared=json.loads(response.read())['boxes'][0]['state']
            self.assertEqual(shared['tv_status'],'off'); self.assertEqual(shared['tv_checked_at'],row['tv_checked_at'])
        observation=json.loads(cache[0].read_text()); observation['checked']-=180; cache[0].write_text(json.dumps(observation))
        self.expire_cache()
        self.assertGreaterEqual(self.api('status')['boxes'][0]['state']['tv_age_seconds'],180)
        MockBox.mode='legacy-failed-command'
        self.command(box,'cec-refresh',expected=502)
        self.assertEqual(json.loads(cache[0].read_text()),observation)
    def test_legacy_restart_confirmation_and_unsupported_display(self):
        box=self.add(); MockBox.mode='legacy'
        self.command(box,'restart-box',expected=400)
        self.assertEqual(MockBox.received,[])
        self.assertTrue(self.command(box,'restart-box',confirm=True)['accepted'])
        self.command(box,'restart-display',confirm=True,expected=400)
        self.assertEqual(len([r for r in MockBox.received if r[0]=='reboot']),1)
        MockBox.mode='legacy-failed-command'
        self.command(box,'restart-box',confirm=True,expected=502)
    def test_legacy_rejects_invalid_capture_device(self):
        self.add();MockBox.mode='legacy-bad-device'
        self.assertEqual(self.api('status')['boxes'][0]['error_kind'],'api')
        self.assertEqual(len(MockBox.received),2)
    def test_redirect_is_not_followed(self):
        self.add(); MockBox.mode='redirect'
        self.assertEqual(self.api('status')['boxes'][0]['error_kind'],'api')
        self.assertEqual(len(MockBox.received),1)
    def test_invalid_and_oversize_responses(self):
        self.add()
        for mode in ['invalid','large']:
            self.expire_cache()
            MockBox.mode=mode; self.assertEqual(self.api('status')['boxes'][0]['error_kind'],'api')
    def test_timeout_bounded(self):
        self.add(); MockBox.mode='slow'; begin=time.monotonic()
        self.assertEqual(self.api('status')['boxes'][0]['error_kind'],'offline')
        self.assertLess(time.monotonic()-begin,6)
    def test_modes_preserve_schedule(self):
        box=self.add()
        for mode in ['force_on','force_off','auto']:
            result=self.command(box,'mode',mode=mode); self.assertTrue(result['applied'])
            self.assertEqual(MockBox.config,dict(CONFIG,MODE=mode))
        self.command(box,'mode',mode='invalid',expected=400)
    def test_settings_immediate_revision_and_warning(self):
        box=self.add(); settings=dict(CONFIG,ON_TIME='0800',OFF_TIME='1900',WEEKEND_ENABLED='yes',HDMI_LOCK_ENABLED='no',revision='r1')
        self.command(box,'settings',settings=settings); self.assertEqual(MockBox.config['ON_TIME'],'0800')
        self.command(box,'settings',settings=settings,expected=409)
        settings['revision']=MockBox.revision; MockBox.mode='not-applied'
        result=self.command(box,'settings',settings=settings); self.assertTrue(result['saved']); self.assertFalse(result['applied'])
        self.command(box,'settings',settings=dict(settings,ON_TIME='2500'),expected=400)
        self.command(box,'settings',settings=dict(settings,ON_TIME=settings['OFF_TIME']),expected=400)
    def test_restart_confirmation_and_routes(self):
        box=self.add()
        for cmd in ['restart-box','restart-display']:
            self.command(box,cmd,expected=400)
            self.assertTrue(self.command(box,cmd,confirm=True)['accepted'])
        payload=json.loads([r for r in MockBox.received if r[0]=='restart-box'][0][3]); self.assertTrue(payload['confirm'])
        self.command(box,'cec-refresh'); self.command(box,'cec-input',expected=400)
        self.command(box,'../../etc/passwd',expected=400)
    def test_unconfirmed_command_not_reported_as_success(self):
        box=self.add(); MockBox.mode='missing-ack'
        self.command(box,'restart-box',confirm=True,expected=502)
        self.command(box,'settings',settings=dict(CONFIG,revision='r1'),expected=502)
    def test_busy_command_and_stale_box(self):
        box=self.add()
        with open(self.data/f"command-{box['id']}.lock",'w') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            self.command(box,'cec-refresh',expected=423)
        self.command(dict(box,revision='old'),'cec-refresh',expected=409)
        self.assertEqual(MockBox.received,[])
    def test_corrupt_store_preserved(self):
        self.add(); path=self.data/'boxes.json'; path.write_text('{broken')
        self.api('list',expected=500); self.api('save',dict(name='Test',address=self.address,key=KEY),expected=500)
        self.assertEqual(path.read_text(),'{broken')

    def test_orange_bar_requires_two_distinct_fresh_captures(self):
        box = self.add()
        def observe(second, age=3):
            self.expire_cache()
            MockBox.capture.update(updated_at=f'2026-11-02T12:00:{second:02d}+01:00', age_seconds=age)
            self.api('status')
            req = urllib.request.Request(self.url + '/api.php?action=image&id=' + box['id'],
                                         headers={'X-Dashboard-Token': self.token})
            with self.client.open(req) as response:
                return response.headers['X-Orange-Bar']
        MockBox.jpeg = self.short_image
        self.assertEqual(observe(0), 'ok')
        self.assertEqual(observe(0), 'ok')  # same file, even after cache expiry
        self.assertEqual(observe(2), 'ok')  # metadata overlaps the first JPEG download
        self.assertEqual(observe(10), 'alarm')
        MockBox.jpeg = self.orange_image
        self.assertEqual(observe(20), 'ok')
        MockBox.jpeg = self.short_image
        self.assertEqual(observe(25), 'ok')
        self.assertEqual(observe(30, 91), 'unverified')
        self.assertEqual(observe(35), 'ok')
        MockBox.capture['available'] = False
        self.expire_cache()
        self.assertFalse(self.api('status')['boxes'][0]['state']['capture']['available'])
        MockBox.capture['available'] = True
        self.assertEqual(observe(40), 'ok')
        MockBox.jpeg = b'\xff\xd8broken\xff\xd9'
        self.expire_cache()
        self.api('status')
        self.image(box, expected=502)
        MockBox.jpeg = self.short_image
        self.assertEqual(observe(50), 'ok')
        self.assertEqual(observe(55), 'alarm')

    def test_ten_clients_share_state_and_image_even_on_simultaneous_cache_miss(self):
        box=self.add(); MockBox.mode='cache-delay'
        clients=[]
        for _ in range(10):
            client, token=self.login_client()
            with client.open(self.url) as response: html=response.read().decode()
            clients.append((client,re.search(r'name="dashboard-token" content="([a-f0-9]+)"',html)[1]))
        def get(client_token, route):
            client,token=client_token
            with client.open(urllib.request.Request(self.url+'/api.php?action='+route,headers={'X-Dashboard-Token':token}),timeout=12) as response:
                raw=response.read(); self.assertNotIn(KEY.encode(),raw); return raw
        with concurrent.futures.ThreadPoolExecutor(max_workers=10) as pool:
            rows=list(pool.map(lambda c:get(c,'status'),clients))
            self.assertTrue(all(json.loads(row)['boxes'][0]['online'] for row in rows))
            images=list(pool.map(lambda c:get(c,'image&id='+box['id']),clients))
            self.assertTrue(all(image==MockBox.jpeg for image in images))
        self.assertEqual([r[0] for r in MockBox.received],['state','screenshot'])
        for path in self.data.glob('shared-*.json'):
            self.assertEqual(path.stat().st_mode & 0o777,0o600)
            self.assertNotIn(KEY,path.read_text())
        self.expire_cache()
        self.api('status');self.image(box)
        self.assertEqual([r[0] for r in MockBox.received],['state','screenshot','state','screenshot'])

    def test_cache_uses_current_names_order_and_credentials(self):
        box=self.add();self.api('status');self.image(box)
        renamed=self.api('save',dict(box,name='Renamed',key=''))['box']
        self.assertEqual(self.api('status')['boxes'][0]['name'],'Renamed')
        self.assertEqual(len(MockBox.received),2)
        bad=self.api('save',dict(renamed,key='X'*64))['box']
        self.assertFalse(self.api('status')['boxes'][0]['online']);self.image(bad,502)
        self.assertGreater(len(MockBox.received),2)

    def test_cache_failures_are_shared_without_serving_old_image_as_fresh(self):
        box=self.add();self.api('status');self.image(box)
        self.expire_cache();MockBox.mode='invalid'
        self.assertFalse(self.api('status')['boxes'][0]['online']);self.image(box,502)
        count=len(MockBox.received)
        self.api('status');self.image(box,502);self.assertEqual(len(MockBox.received),count)
        self.expire_cache();MockBox.mode='normal'
        self.assertTrue(self.api('status')['boxes'][0]['online']);self.image(box)

    def test_cache_age_increases_and_commands_invalidate_immediately(self):
        box=self.add();first=self.api('status')['boxes'][0]
        path=self.data/f"shared-state-{box['id']}.json"
        entry=json.loads(path.read_text());entry['fetched_at']-=5;path.write_text(json.dumps(entry))
        state=self.api('status')['boxes'][0]['state']
        self.assertGreaterEqual(state['tv_age_seconds'],first['state']['tv_age_seconds']+5)
        self.assertGreaterEqual(state['capture']['age_seconds'],first['state']['capture']['age_seconds']+5)
        self.command(box,'mode',mode='force_on')
        self.assertEqual(self.api('status')['boxes'][0]['state']['config']['MODE'],'force_on')
        self.api('cec-auto',{})
        self.assertFalse(path.exists())
        self.api('status');self.image(box)
        self.command(box,'restart-display',confirm=True)
        self.assertFalse(path.exists());self.assertFalse((self.data/f"shared-image-{box['id']}.json").exists())

    def test_expired_cache_does_not_start_work_without_requests(self):
        box=self.add();self.api('status');self.image(box)
        before=len(MockBox.received);self.expire_cache()
        self.api('list')
        self.assertEqual(len(MockBox.received),before)
        self.api('status');self.assertEqual(len(MockBox.received),before+1)

if __name__=='__main__': unittest.main(verbosity=2)
