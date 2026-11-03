<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/WebContext.php';
$count = 0;
function expect(bool $value): void { global $count; $count++; if (!$value) throw new RuntimeException('Échec du contrôle '.$count); }
function refused(callable $call): void { try { $call(); } catch (Throwable $e) { expect(true); return; } expect(false); }

putenv('DASHBOARD_PUBLIC_URL');
expect(WebContext::origin(['HTTP_HOST'=>'localhost:8090']) === 'http://localhost:8090');
refused(fn()=>WebContext::origin(['HTTP_HOST'=>'outside.example']));
// Forwarded headers are never trusted to determine the public address.
expect(WebContext::origin(['HTTP_HOST'=>'localhost:8090','HTTP_X_FORWARDED_PROTO'=>'https']) === 'http://localhost:8090');
putenv('DASHBOARD_PUBLIC_URL=https://screens.example.test/');
expect(WebContext::origin(['HTTP_HOST'=>'screens.example.test']) === 'https://screens.example.test');
expect(WebContext::origin(['HTTP_HOST'=>'SCREENS.EXAMPLE.TEST:443']) === 'https://screens.example.test');
refused(fn()=>WebContext::origin(['HTTP_HOST'=>'screens.example.test:8080']));
refused(fn()=>WebContext::origin(['HTTP_HOST'=>'evil.test','HTTP_X_FORWARDED_HOST'=>'screens.example.test']));
putenv('DASHBOARD_PUBLIC_URL=http://192.0.2.10:8090');
expect(WebContext::origin(['HTTP_HOST'=>'192.0.2.10:8090']) === 'http://192.0.2.10:8090');
refused(fn()=>WebContext::origin(['HTTP_HOST'=>'192.0.2.10:8090:80']));
putenv('DASHBOARD_PUBLIC_URL=https://[::1]:8443');
expect(WebContext::origin(['HTTP_HOST'=>'[::1]:8443']) === 'https://[::1]:8443');
foreach (['https://example.test/path','ftp://example.test','https://u:p@example.test','https://example.test?x=1','https://example.test/#x','https://example.test:0','https://exam ple.test'] as $url) {
    putenv('DASHBOARD_PUBLIC_URL='.$url); refused(fn()=>WebContext::publicOrigin());
}
putenv('DASHBOARD_PUBLIC_URL');
putenv('DASHBOARD_LDAP_URI=ldap://127.0.0.1:389');
putenv('DASHBOARD_LDAP_BASE_DN=dc=test');
putenv('DASHBOARD_LDAP_GROUP_DN=cn=screen,dc=test');
putenv('DASHBOARD_LDAP_BIND_DN');
putenv('DASHBOARD_LDAP_BIND_PASSWORD_FILE');
putenv('DASHBOARD_LDAP_TLS_VERIFY');
expect(Auth::config()['tls_verify'] === true);
putenv('DASHBOARD_LDAP_TLS_VERIFY=false');
expect(Auth::config()['tls_verify'] === false);
putenv('DASHBOARD_LDAP_TLS_VERIFY=garbage');
refused(fn()=>Auth::config());
putenv('DASHBOARD_LDAP_TLS_VERIFY=true');
putenv('DASHBOARD_LDAP_GROUP_DN=');
refused(fn()=>Auth::config());
putenv('DASHBOARD_LDAP_GROUP_DN=cn=screen,dc=test');
putenv('DASHBOARD_LDAP_BIND_DN=cn=reader,dc=test');
refused(fn()=>Auth::config());
$secret = tempnam(sys_get_temp_dir(),'ldap-test-secret-');
try {
    file_put_contents($secret, "test-only-password\n"); chmod($secret,0600);
    putenv('DASHBOARD_LDAP_BIND_PASSWORD_FILE='.$secret);
    expect(Auth::config()['bind_password'] === 'test-only-password');
} finally { unlink($secret); }
refused(fn()=>Auth::config());
echo "$count contrôles de configuration réussis.\n";
