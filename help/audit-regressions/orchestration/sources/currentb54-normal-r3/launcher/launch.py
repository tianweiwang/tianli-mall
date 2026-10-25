from pathlib import Path
import hashlib,json,subprocess,sys,time
BASE=Path(__file__).resolve().parent
B=BASE.parent
ROOT=Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/validation')
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

current_paid_pointer = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-paid-producer-failed-retirement-authority-r1/AUTHORITY.json', 'bytes': 483703, 'sha256': 'aa6e3d41eaad35640f9607c74556478e1766c57602f179f3912a40a04851a7fb'}
assert publication['finite_contexts']['current_paid_producer_failure_retirement'] == current_paid_pointer
assert descriptor(Path(current_paid_pointer['path'])) == current_paid_pointer
current_paid = read(Path(current_paid_pointer['path']))
assert current_paid['full_acceptance'] == 'INCOMPLETE' and current_paid['original_failure_truth']['failed_outer_native_exit'] == 1 and current_paid['original_failure_truth']['original_S_terminal'] == 'PRESENT'

current_2e4_pointer = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-881-form-prefix-normal-next-source-r2/CURRENT-2E4-RETIREMENT-COMPLETE.json', 'bytes': 443311, 'sha256': '5dd4d34586ea8a38243838539c7806acdf9fa32de23c8cba362908fabf28d993'}
assert publication['finite_contexts']['current_2e4_paid_producer_failure_retirement'] == current_2e4_pointer
assert descriptor(Path(current_2e4_pointer['path'])) == current_2e4_pointer
current_2e4 = read(Path(current_2e4_pointer['path']))
assert current_2e4['original_S_native_exit'] == current_2e4['paid_producer_native_exit'] == 1 and current_2e4['business_acceptance'] == 'INCOMPLETE'
CURRENT_M_RESULT_KEYS32 = ['schema', 'status', 'source_sha', 'parent', 'source_patch_base', 'commit_chain', 'repo', 'profile', 'freeze', 'freeze_bytes', 'freeze_sha256', 'root_source_pins', 'profile_publication', 'selected9', 'committed_files', 'source_counts', 'unique_source_files_actual_read', 'proof_resource_required', 'proof_php_ini', 'prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'native_operations', 'Run_created', 'App_DB_HTTP_business', 'root_source_snapshot', 'complete_source_snapshot', 'materialize_source', 'materialize_source_inputs']

current_881_pointer = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-normal-http-diagnostic-next-source-r1/CURRENT-881-RETIREMENT-COMPLETE.json', 'bytes': 728080, 'sha256': '3475f285f47c0b25bf61b9dec5c9a84d37f81d0beb20a9f111e53e856e41e9f3'}
assert publication['finite_contexts']['current_881_paid_producer_failure_retirement'] == current_881_pointer
assert descriptor(Path(current_881_pointer['path'])) == current_881_pointer
current_881 = read(Path(current_881_pointer['path']))
assert current_881['original_S_native_exit'] == current_881['paid_producer_native_exit'] == 1 and current_881['business_acceptance'] == 'INCOMPLETE'
CURRENT_M_RESULT_KEYS33 = ['schema', 'status', 'source_sha', 'parent', 'source_patch_base', 'commit_chain', 'repo', 'profile', 'freeze', 'freeze_bytes', 'freeze_sha256', 'root_source_pins', 'profile_publication', 'selected9', 'committed_files', 'source_counts', 'unique_source_files_actual_read', 'proof_resource_required', 'proof_php_ini', 'prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'native_operations', 'Run_created', 'App_DB_HTTP_business', 'root_source_snapshot', 'complete_source_snapshot', 'materialize_source', 'materialize_source_inputs']

current_0c13_pointer = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-upload-root-current28dc-normal-next-source-r1/CURRENT-0C13-RETIREMENT-COMPLETE.json', 'bytes': 7629389, 'sha256': '0c912bb891d4cf0c2f45817aa9241cf0dfc453fd36ed7258c80f051276dddf5b'}
assert publication['finite_contexts']['current_0c13_paid_producer_failure_retirement'] == current_0c13_pointer
assert descriptor(Path(current_0c13_pointer['path'])) == current_0c13_pointer
current_0c13 = read(Path(current_0c13_pointer['path']))
assert current_0c13['original_S_native_exit'] == current_0c13['paid_producer_native_exit'] == 1 and current_0c13['business_acceptance'] == 'INCOMPLETE'
CURRENT_M_RESULT_KEYS34 = ['schema', 'status', 'source_sha', 'parent', 'source_patch_base', 'commit_chain', 'repo', 'profile', 'freeze', 'freeze_bytes', 'freeze_sha256', 'root_source_pins', 'profile_publication', 'selected9', 'committed_files', 'source_counts', 'unique_source_files_actual_read', 'proof_resource_required', 'proof_php_ini', 'prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'native_operations', 'Run_created', 'App_DB_HTTP_business', 'root_source_snapshot', 'complete_source_snapshot', 'materialize_source', 'materialize_source_inputs']

current_28dc_pointer = {'path': 'H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-quota-wire-current175f-normal-next-source-r1/CURRENT-28DC-RETIREMENT-COMPLETE.json', 'bytes': 33024728, 'sha256': '65cdd89dcd9d02247b99705af76322ecffedb5cd18b47c85776ac4b7fb7c01c8'}
assert publication['finite_contexts']['current_28dc_paid_producer_failure_retirement'] == current_28dc_pointer
assert descriptor(Path(current_28dc_pointer['path'])) == current_28dc_pointer
current_28dc = read(Path(current_28dc_pointer['path']))
assert current_28dc['original_S_native_exit'] == current_28dc['paid_producer_native_exit'] == 1 and current_28dc['business_acceptance'] == 'INCOMPLETE'
CURRENT_M_RESULT_KEYS35 = ['schema', 'status', 'source_sha', 'parent', 'source_patch_base', 'commit_chain', 'repo', 'profile', 'freeze', 'freeze_bytes', 'freeze_sha256', 'root_source_pins', 'profile_publication', 'selected9', 'committed_files', 'source_counts', 'unique_source_files_actual_read', 'proof_resource_required', 'proof_php_ini', 'prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'current_28dc_paid_producer_failure_retirement', 'native_operations', 'Run_created', 'App_DB_HTTP_business', 'root_source_snapshot', 'complete_source_snapshot', 'materialize_source', 'materialize_source_inputs']

current_175f_pointer = {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current175f-tenth-retirement-authority-preparation-r2/CURRENT-175F-RETIREMENT-COMPLETE.json', 'bytes': 68600317, 'sha256': '032410e9de4a3ad9ddacb914ddc49b7624c0442d2df791a5ad919472cfdc2628'}
assert publication['finite_contexts']['current_175f_paid_producer_failure_retirement'] == current_175f_pointer
assert descriptor(Path(current_175f_pointer['path'])) == current_175f_pointer
current_175f = read(Path(current_175f_pointer['path']))
assert current_175f['original_S_native_exit'] == current_175f['paid_producer_native_exit'] == 1 and current_175f['business_acceptance'] == 'INCOMPLETE'
CURRENT_M_RESULT_KEYS36 = ['schema', 'status', 'source_sha', 'parent', 'source_patch_base', 'commit_chain', 'repo', 'profile', 'freeze', 'freeze_bytes', 'freeze_sha256', 'root_source_pins', 'profile_publication', 'selected9', 'committed_files', 'source_counts', 'unique_source_files_actual_read', 'proof_resource_required', 'proof_php_ini', 'prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'current_28dc_paid_producer_failure_retirement', 'current_175f_paid_producer_failure_retirement', 'native_operations', 'Run_created', 'App_DB_HTTP_business', 'root_source_snapshot', 'complete_source_snapshot', 'materialize_source', 'materialize_source_inputs']

current_6e3850_pointer = {'path': 'H:/天俪商城/artifacts/project-organization-20261023/current6e3850-eleventh-retirement-authority-preparation-r1/CURRENT-6E3850-RETIREMENT-COMPLETE.json', 'bytes': 142275335, 'sha256': '835c2cbff9ce7ccf5412c1dd3b91d75110d968cecdd08b0139ebcd98c2fd4dda'}
assert publication['finite_contexts']['current_6e3850_paid_producer_failure_retirement'] == current_6e3850_pointer
assert descriptor(Path(current_6e3850_pointer['path'])) == current_6e3850_pointer
current_6e3850 = read(Path(current_6e3850_pointer['path']))
assert current_6e3850['original_S_native_exit'] == current_6e3850['paid_producer_native_exit'] == 1 and current_6e3850['business_acceptance'] == 'INCOMPLETE'
CURRENT_M_RESULT_KEYS37 = ['schema', 'status', 'source_sha', 'parent', 'source_patch_base', 'commit_chain', 'repo', 'profile', 'freeze', 'freeze_bytes', 'freeze_sha256', 'root_source_pins', 'profile_publication', 'selected9', 'committed_files', 'source_counts', 'unique_source_files_actual_read', 'proof_resource_required', 'proof_php_ini', 'prior_retirement', 'current_driver_failure_retirement', 'current_early_failure_retirement', 'current_customer_prepare_failure_retirement', 'current_paid_producer_failure_retirement', 'current_2e4_paid_producer_failure_retirement', 'current_881_paid_producer_failure_retirement', 'current_0c13_paid_producer_failure_retirement', 'current_28dc_paid_producer_failure_retirement', 'current_175f_paid_producer_failure_retirement', 'current_6e3850_paid_producer_failure_retirement', 'native_operations', 'Run_created', 'App_DB_HTTP_business', 'root_source_snapshot', 'complete_source_snapshot', 'materialize_source', 'materialize_source_inputs']

if mode=='materialize':
 assert not (ROOT/'materialize-freeze-assets-r1').exists()
 args=[PY,'-B','-X','utf8',str(Path('H:/天俪商城/artifacts/project-organization-20261023/currentb54-next-normal-preparation-r3/normal-source/materializer/prepare.py'))]
else:
 setup=ROOT/'materialize-freeze-assets-r1/RESULT.json';value=read(setup)
 assert set(value)==set(CURRENT_M_RESULT_KEYS37) and len(value)==37
 assert value['current_2e4_paid_producer_failure_retirement']==current_2e4
 assert value['current_881_paid_producer_failure_retirement']==current_881
 assert value['current_0c13_paid_producer_failure_retirement']==current_0c13
 assert value['current_28dc_paid_producer_failure_retirement']==current_28dc
 assert value['current_175f_paid_producer_failure_retirement']==current_175f
 assert value['current_6e3850_paid_producer_failure_retirement']==current_6e3850
 assert value['status']=='ACTUAL_CLEAN_SOURCE_AND_NINE_FILE_PROFILE_FROZEN_ONLY' and value['source_sha']=='b54ad9c39ca95a1b8b15ed6724082b98b1370c53'
 assert value['current_early_failure_retirement']==current_early
 assert value['current_customer_prepare_failure_retirement']==current_customer
 assert value['current_paid_producer_failure_retirement']==current_paid
 assert read(BASE/'materialize.terminal.json')['native_exit']==0
 assert not (ROOT/'native-normal-start-assets-r1').exists()
 args=[PY,'-B','-X','utf8',str(ROOT/'run.py'),'--root-source-pins-sha256','a5aba6db21420553b0cb1d9247512c0be8f5383081f41b8bf0730c46ac4f9863','--setup-sha256',descriptor(setup)['sha256']]
with (BASE/(mode+'.stdout')).open('xb') as out,(BASE/(mode+'.stderr')).open('xb') as err:
 p=subprocess.Popen(args,stdin=subprocess.DEVNULL,stdout=out,stderr=err)
 put(BASE/(mode+'.created.json'),{'pid':p.pid,'argv':args,'started_ns':time.time_ns()});rc=p.wait()
terminal={'pid':p.pid,'native_exit':rc,'wait_returncode':p.returncode,'finished_ns':time.time_ns()}
for ext in ['stdout','stderr']:terminal[ext]=descriptor(BASE/(mode+'.'+ext))
put(BASE/(mode+'.terminal.json'),terminal)
print(json.dumps({'mode':mode,'terminal':terminal},ensure_ascii=False))
sys.exit(rc)
