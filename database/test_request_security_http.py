"""Local HTTP throttling test; synthetic random buckets only. Requires PHP on PATH."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import uuid

ROOT=Path(__file__).resolve().parent.parent
PHP=shutil.which('php') or r'C:\xampp\php\php.exe'
subject='security-http-'+uuid.uuid4().hex
directory=ROOT/'.runtime'/'security-audit'
directory.mkdir(parents=True,exist_ok=True)
router=directory/(subject+'.php')
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
router.write_text("<?php\nrequire "+json.dumps(str(ROOT/'config/database.php').replace('\\','/'))+";\nrequire "+json.dumps(str(ROOT/'includes/request_security.php').replace('\\','/'))+";\n"+f"""
if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) {{ http_response_code(500); exit; }}
if ($_SERVER['REQUEST_URI']==='/health') exit('ready');
if ($_SERVER['REQUEST_URI']==='/offline') get_db()->exec('CREATE TEMPORARY TABLE security_rate_limits (invalid_column INT)');
security_throttle('http-test','{subject}',3,900);
echo 'accepted';
""",encoding='utf-8')
log=open(directory/'http-test.log','wb')
process=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}',str(router)],cwd=ROOT,stdout=log,stderr=log)
def request(route,forwarded='198.51.100.10'):
    req=urllib.request.Request(f'http://127.0.0.1:{port}'+route,headers={'X-Forwarded-For':forwarded})
    try:
        with urllib.request.urlopen(req,timeout=5) as result: return result.status,result.headers,result.read().decode()
    except urllib.error.HTTPError as error: return error.code,error.headers,error.read().decode()
try:
    for _ in range(50):
        try:
            if request('/health')[0]==200: break
        except OSError: pass
        time.sleep(.1)
    else: raise RuntimeError('Local PHP test server unavailable')
    for _ in range(3): assert request('/limit')[0]==200
    status,headers,body=request('/limit','203.0.113.200')
    assert status==429 and int(headers['Retry-After'])>0 and 'Too many requests' in body
    print('PASS: real HTTP 429, Retry-After, and limit survives new requests/forwarded IP changes')
    status,headers,body=request('/offline')
    assert status==503 and headers['Retry-After']=='30' and 'SQL' not in body
    print('PASS: rate-store failure returns generic HTTP 503, not an unprotected request')
    timestamp=int(time.time())
    code="require 'config/database.php'; require 'includes/request_security.php'; echo security_rate_take(get_db(),'concurrency-test',"+json.dumps(subject)+",3,900,"+str(timestamp)+");"
    workers=[subprocess.Popen([PHP,'-r',code],cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE) for _ in range(12)]
    accepted=0
    for worker in workers:
        out,err=worker.communicate(timeout=30)
        assert worker.returncode==0, 'Concurrent limiter test worker failed'
        accepted+=int(out.strip()==b'0')
    assert accepted<=3
    print('PASS: twelve concurrent database requests never exceed the three-request limit')
finally:
    process.terminate(); process.wait(timeout=10); log.close(); router.unlink(missing_ok=True)
    # Remove only this run's random test buckets, including a possible window boundary.
    code="require 'config/database.php'; require 'includes/request_security.php'; $s="+json.dumps(subject)+"; $p=get_db()->prepare('DELETE FROM security_rate_limits WHERE bucket=?'); foreach ([time()-900,time(),time()+900] as $n) {$end=(intdiv($n,900)+1)*900; foreach (['http-test','concurrency-test'] as $scope) $p->execute([hash('sha256',$scope.chr(0).$s.chr(0).$end)]);}"
    subprocess.run([PHP,'-r',code],cwd=ROOT,check=True)
