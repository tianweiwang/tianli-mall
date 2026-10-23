from pathlib import Path
import hashlib,json,subprocess,sys,time
BASE=Path(__file__).resolve().parent
B=BASE.parent
ROOT=Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-raw-env-normal-next-source-r1/validation')
PY='C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe'
mode=sys.argv[1];assert mode in ['materialize','normal-start']
def read(p):return json.loads(p.read_bytes())
def descriptor(p):
 raw=p.read_bytes();return {'path':p.as_posix(),'bytes':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}
def put(p,value):
 with p.open('xb') as f:f.write((json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode())
publication=read(ROOT/'PROFILE-PUBLICATION.json')
retirement=publication['finite_contexts']['prior_retirement']
assert retirement.get('status')=='ACTUAL_CURRENT_FAILED_FULL_A_CLOSED_ZERO_ROOT_READBACK','CURRENT_FAILED_RUN_RETIREMENT_PENDING'
expected_readback={'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a093-ar-failed-close-root-readback-r1/RESULT.json', 'bytes': 192490, 'sha256': '0df7f175baef8e6654b89a01ac9544b8c7a5a9f766cb2da9029c7b682f57df15', 'native9': [1618373208947831848, 281474986864037, 33206, 1, 0, 0, 192490, 1792686511557845100, 1792686511554882200]}
assert retirement['root_readback']==expected_readback and descriptor(Path(expected_readback['path']))=={k:expected_readback[k] for k in ['path','bytes','sha256']}
checked=read(Path(expected_readback['path']))
assert checked['status']=='ROOT_READBACK_ACTUAL_CLOSE0_AND_INDEPENDENT_ZERO0_COMPLETE' and checked['run_id']=='20261022134605842b51'
assert checked['all_finite_input_windows_ended'] is True and checked['native_receipts_full_created_terminal_and_streams']==96 and len(checked['inputs'])==397
assert checked['old_AR_failure_preserved'] is True and checked['Task6_success_preserved'] is True and checked['full_acceptance']=='INCOMPLETE'
for label,status,count in [('close','VERIFIED_FULL_A093_AR_FAILURE_NORMAL_CLOSE_ZERO_RESIDUAL',84),('zero','VERIFIED_FULL_A093_AR_FAILURE_INDEPENDENT_SIX_QUERY_ZERO',12)]:
 row=retirement[label];path=Path(row['path']);assert descriptor(path)=={k:row[k] for k in ['path','bytes','sha256']};value=read(path)
 assert value['status']==status and value['run_id']=='20261022134605842b51' and value['source_sha']=='093ed63d1807ab187b35c390c77aceddc380bcdf'
 assert value['freeze_sha256']=='51ab4521754a4233f914a9766b5f5968be6e8e2f44f990ae68b82211c06fc1ff' and len(value['operations'])==count
 assert checked['results'][label]['descriptor']=={k:row[k] for k in ['bytes','sha256','native9']}
 assert value['Task6']=='NORMAL_SUCCESS_ALREADY_RETIRED' and value['AR']=='FAILED_CUSTOMER_REVIEW_IMAGE_POST175_HTTP503_SITE_DETAIL_MISSING' and value['full_acceptance']=='INCOMPLETE'
latest_pointer = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-customer-form-next-source-r1/CURRENT-EADF-RETIREMENT-AUTHORITY.json', 'bytes': 227096, 'sha256': '3e072297b1089d9beddc175147549c41ef93d8536fa83d6da5325f41fac0357e'}
assert publication['finite_contexts']['current_retirement'] == latest_pointer
assert descriptor(Path(latest_pointer['path'])) == latest_pointer
latest = read(Path(latest_pointer['path']))
assert latest['status'] == 'ACTUAL_CURRENT_FAILED_FULL_A_CLOSED_ZERO_ROOT_READBACK' and latest['run_id'] == '20261022170115298d9f'
for label,count in [('close',84),('zero',12)]:
 row = latest[label]; assert descriptor(Path(row['path'])) == {k:row[k] for k in ['path','bytes','sha256']}
 value = read(Path(row['path'])); assert value['source_sha'] == 'eadf74f8924a5ed5b801043030b579dffe5d1702' and value['run_id'] == latest['run_id'] and len(value['operations']) == count
 assert value['Task6'] == 'NORMAL_SUCCESS_ALREADY_RETIRED' and value['AR'] == 'FAILED_CUSTOMER_REVIEW_IMAGE_POST175_HTTP503_PREFLIGHT_INCOMPLETE' and value['full_acceptance'] == 'INCOMPLETE'
 assert all(op['native_exit'] == op['wait_returncode'] == 0 for op in value['operations'])
peer_pointer=latest['independent_readback'];assert descriptor(Path(peer_pointer['path']))=={k:peer_pointer[k] for k in ['path','bytes','sha256']}
peer=read(Path(peer_pointer['path']));assert peer['findings']==[] and peer['all_input_windows_ended'] is True and peer['input_count']==423
assert not (BASE/(mode+'.created.json')).exists() and not (BASE/(mode+'.terminal.json')).exists()
current_driver_pointer = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-eight-volume-current-retirement-authority-r1/AUTHORITY.json', 'bytes': 272334, 'sha256': 'c4918dde1c04741d95e046d28bc90790dd46d9f07c3ab182c7e3921b3f443ba0'}
assert publication['finite_contexts']['current_driver_failure_retirement'] == current_driver_pointer
current_driver = read(Path(current_driver_pointer['path']))
assert descriptor(Path(current_driver_pointer['path'])) == current_driver_pointer
assert current_driver['status'] == 'ACTUAL_FAILED_EIGHT_VOLUME_DRIVER_A_NORMAL_CLOSE_ZERO_AUTHORITY'
assert current_driver['run_id'] == '20261022200902eba4fa' and current_driver['source_sha'] == '4e2723c2b96f4e0265b9fc75483e307820f3fe79'
assert current_driver['original_failure_truth']['failed_S_native_exit'] == 1 and current_driver['original_failure_truth']['initializer_native_exit'] == 0
assert current_driver['full_acceptance'] == 'INCOMPLETE' and current_driver['all_input_windows_ended'] is True

current_early_pointer = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-eight-volume-early-failed-retirement-authority-r1/AUTHORITY.json', 'bytes': 277206, 'sha256': '7e3a3d9b1c4cd750a01e93bd9a13cb875182cba48a035235564c4b8c71c15c68'}
assert publication['finite_contexts']['current_early_failure_retirement'] == current_early_pointer
assert descriptor(Path(current_early_pointer['path'])) == current_early_pointer
current_early = read(Path(current_early_pointer['path']))
assert current_early['status'] == 'ACTUAL_EARLY_SOURCE_FAILED_FULL_A_NORMAL_CLOSE_AND_INDEPENDENT_ZERO'
assert current_early['run_id'] == '202610222149258b3850' and current_early['source_sha'] == '1b819d0f7b9e7c6e58c9847a2868b696d9070151'
assert current_early['original_failure_truth']['outer_native_exit'] == current_early['original_failure_truth']['early_container_native_exit'] == 1
assert current_early['original_failure_truth']['initializer_native_exit'] == current_early['original_failure_truth']['MS5_driver_native_exit'] == 0
assert current_early['full_acceptance'] == 'INCOMPLETE' and current_early['all_input_windows_ended'] is True

current_customer_pointer = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-customer-prepare-failed-retirement-authority-r1/AUTHORITY.json', 'bytes': 438001, 'sha256': 'b545f4deb09b36190934a2b184b3705511b3cf67f512411e76c9d6c005ae02ea'}
assert publication['finite_contexts']['current_customer_prepare_failure_retirement'] == current_customer_pointer
assert descriptor(Path(current_customer_pointer['path'])) == current_customer_pointer
current_customer = read(Path(current_customer_pointer['path']))
assert current_customer['full_acceptance'] == 'INCOMPLETE' and current_customer['original_failure_truth']['failed_outer_native_exit'] == 'UNKNOWN'

if mode=='materialize':
 assert not (ROOT/'materialize-freeze-assets-r1').exists()
 args=[PY,'-B','-X','utf8',str(Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-raw-env-normal-next-source-r1/materializer/prepare.py'))]
else:
 setup=ROOT/'materialize-freeze-assets-r1/RESULT.json';value=read(setup)
 assert value['status']=='ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY' and value['source_sha']=='53e6d521b387b19a4cf370e0e381670b762d325e'
 assert value['current_early_failure_retirement']==current_early
 assert value['current_customer_prepare_failure_retirement']==current_customer
 assert read(BASE/'materialize.terminal.json')['native_exit']==0
 assert not (ROOT/'native-normal-start-assets-r1').exists()
 args=[PY,'-B','-X','utf8',str(ROOT/'run.py'),'--root-source-pins-sha256','d919d2f144c0a06358301ccad9ccaaf4cfb2fba85fdfc547328788f184673f60','--setup-sha256',descriptor(setup)['sha256']]
with (BASE/(mode+'.stdout')).open('xb') as out,(BASE/(mode+'.stderr')).open('xb') as err:
 p=subprocess.Popen(args,stdin=subprocess.DEVNULL,stdout=out,stderr=err)
 put(BASE/(mode+'.created.json'),{'pid':p.pid,'argv':args,'started_ns':time.time_ns()});rc=p.wait()
terminal={'pid':p.pid,'native_exit':rc,'wait_returncode':p.returncode,'finished_ns':time.time_ns()}
for ext in ['stdout','stderr']:terminal[ext]=descriptor(BASE/(mode+'.'+ext))
put(BASE/(mode+'.terminal.json'),terminal)
print(json.dumps({'mode':mode,'terminal':terminal},ensure_ascii=False))
sys.exit(rc)
