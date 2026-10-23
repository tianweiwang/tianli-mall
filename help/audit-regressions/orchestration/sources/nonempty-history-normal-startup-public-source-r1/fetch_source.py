"""Fetch only public PHP Source/license/commit metadata; never imports/runs target code."""
from pathlib import Path
import hashlib, json, time, urllib.request

OUT=Path(__file__).resolve().parent
rows=[]
def get(url):
    request=urllib.request.Request(url,headers={'User-Agent':'source-only-normal-startup-review','Accept':'application/vnd.github+json'})
    with urllib.request.urlopen(request,timeout=30) as response:
        assert response.status==200
        raw=response.read()
    rows.append({'url':url,'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest(),'complete_response_read':True})
    return raw
def put(name,raw):
    p=OUT/name;p.parent.mkdir(parents=True,exist_ok=True)
    with p.open('xb') as stream:assert stream.write(raw)==len(raw)
    assert p.read_bytes()==raw
tagraw=get('https://api.github.com/repos/php/php-src/git/ref/tags/php-8.2.33')
put('upstream/tag-ref.json',tagraw)
obj=json.loads(tagraw)['object']
assert obj['type'] in ['tag','commit']
if obj['type']=='tag':
    raw=get(obj['url']);put('upstream/tag-object.json',raw);obj=json.loads(raw)['object']
assert obj['type']=='commit' and len(obj['sha'])==40
commit=obj['sha']
for name in ['sapi/cli/php_cli_server.c','LICENSE']:
    raw=get('https://raw.githubusercontent.com/php/php-src/'+commit+'/'+name)
    put('upstream/'+name,raw)
source=(OUT/'upstream/sapi/cli/php_cli_server.c').read_bytes()
required=[b'"PHP %s Development Server (http://%s%s%s:%d) started"',
 b'fprintf(stderr, "[%s] %s\\n", buf, msg);',b'php_localtime_r(&tv.tv_sec, &tm);',b'php_asctime_r(&tm, buf);',
 b'char *workers = getenv("PHP_CLI_SERVER_WORKERS");',b'php_cli_server_do_event_loop(&server);']
assert all(value in source for value in required)
evidence={'schema':1,'scope':'PUBLIC_PHP_8_2_33_SOURCE_ONLY','tag':'php-8.2.33','actual_commit':commit,
 'http_startup_message_and_UTC_native_log_Source':True,'single_process_when_original_workers_env_absent':True,
 'no_target_or_private_or_resources_executed_or_read':True,'responses':rows,'ended_ns':time.time_ns()}
put('SOURCE.json',(json.dumps(evidence,ensure_ascii=False,indent=2)+'\n').encode())
members={}
for p in sorted(OUT.rglob('*')):
 if p.is_file() and p.name!='PINS.json':
  raw=p.read_bytes();members[p.relative_to(OUT).as_posix()]={'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
put('PINS.json',(json.dumps({'schema':1,'scope':'PUBLIC_SOURCE_DOWNLOAD_ONLY','members':members},indent=2)+'\n').encode())
print(json.dumps({'status':'PUBLIC_PHP_STARTUP_SOURCE_PINNED','commit':commit,
 'source':{'bytes':len(source),'sha256':hashlib.sha256(source).hexdigest()},
 'PINS':{'bytes':(OUT/'PINS.json').stat().st_size,'sha256':hashlib.sha256((OUT/'PINS.json').read_bytes()).hexdigest()},
 'target_execution':'NOT RUN'}))
