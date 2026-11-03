"""Real, isolated LDAP server with synthetic users; never touches the esede directory."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import time

class LdapFixture:
    def __init__(self, root, data):
        self.root = Path(root)
        self.data = Path(data)
        self.data.mkdir(mode=0o700, exist_ok=True)
        self.proc = None
        self.log = None
        self.slapd = shutil.which('slapd') or '/opt/homebrew/opt/openldap/libexec/slapd'
        schema = Path(os.environ.get('LDAP_TEST_SCHEMA', '/opt/homebrew/etc/openldap/schema'))
        if not schema.exists(): schema = Path('/etc/ldap/schema')
        if not Path(self.slapd).exists() or not schema.exists():
            raise RuntimeError('Tests LDAP : installer OpenLDAP (slapd et ses schémas).')
        self.ldapadd = shutil.which('ldapadd')
        self.ldapmodify = shutil.which('ldapmodify')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0)); self.port = sock.getsockname()[1]
        self.uri = f'ldap://127.0.0.1:{self.port}'
        self.admin = 'cn=admin,dc=test,dc=local'
        self.admin_password = 'fixture-admin-only'
        self.password = 'fixture-user-only'
        (self.root/'ldap-db').mkdir()
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1',
                        '-subj','/CN=localhost','-keyout',str(self.root/'tls.key'),'-out',str(self.root/'tls.crt')],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        includes = '\n'.join(f'include {schema}/{name}.schema' for name in ('core','cosine','inetorgperson','nis'))
        # Debian ships MDB as a module; Homebrew builds it into slapd.
        modules = ''
        for module_dir in (Path('/usr/lib/ldap'), Path('/usr/lib64/openldap')):
            if list(module_dir.glob('back_mdb*')):
                modules = f'modulepath {module_dir}\nmoduleload back_mdb\n'
                break
        config = f'''{includes}
{modules}
pidfile {self.root}/slapd.pid
argsfile {self.root}/slapd.args
TLSCertificateFile {self.root}/tls.crt
TLSCertificateKeyFile {self.root}/tls.key
database mdb
maxsize 10485760
suffix "dc=test,dc=local"
rootdn "{self.admin}"
rootpw {self.admin_password}
directory {self.root}/ldap-db
access to attrs=userPassword by anonymous auth by self auth by * none
access to * by * read
'''
        (self.root/'slapd.conf').write_text(config)
        self.log = open(self.root/'slapd.log','w+')
        self.proc = subprocess.Popen([self.slapd,'-f',str(self.root/'slapd.conf'),'-h',self.uri,'-d','0'],stdout=self.log,stderr=self.log)
        for _ in range(60):
            if self.proc.poll() is not None: raise RuntimeError('Le serveur LDAP de test ne démarre pas.')
            try:
                with socket.create_connection(('127.0.0.1',self.port),timeout=.2): break
            except OSError: time.sleep(.05)
        ldif = '''dn: dc=test,dc=local
objectClass: domain
dc: test

dn: ou=users,dc=test,dc=local
objectClass: organizationalUnit
ou: users

'''
        for name in ('member','outsider'):
            ldif += f'''dn: uid={name},ou=users,dc=test,dc=local
objectClass: inetOrgPerson
cn: {name}
sn: Test
uid: {name}
userPassword: {self.password}

'''
        ldif += '''dn: cn=screen,dc=test,dc=local
objectClass: posixGroup
cn: screen
gidNumber: 1000
memberUid: member

'''
        self.write_ldif(ldif)
        c = dict(uri=self.uri,base_dn='dc=test,dc=local',group_dn='cn=screen,dc=test,dc=local',tls_verify=False)
        (self.data/'ldap.json').write_text(json.dumps(c))
        os.chmod(self.data/'ldap.json',0o600)

    def write_ldif(self, ldif, modify=False):
        # Synthetic credentials only, fixture-local listener.
        subprocess.run([self.ldapmodify if modify else self.ldapadd,'-x','-H',self.uri,'-D',self.admin,'-w',self.admin_password],
                       input=ldif.encode(), stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)

    def membership(self, allowed):
        value = 'member' if allowed else 'outsider'
        self.write_ldif(f'dn: cn=screen,dc=test,dc=local\nchangetype: modify\nreplace: memberUid\nmemberUid: {value}\n',True)

    def close(self):
        if self.proc and self.proc.poll() is None: self.proc.terminate(); self.proc.wait(timeout=5)
        if self.log: self.log.close()
