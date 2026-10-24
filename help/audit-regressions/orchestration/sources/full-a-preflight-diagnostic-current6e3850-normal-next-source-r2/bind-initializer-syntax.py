"""Bind already-completed real initializer syntax receipts; no Docker/PHP/job execution."""
import json,re
from pathlib import Path
import source_reader as r
OUT,B=r.OUT,r.B
ORG=B.parent/'project-organization-20261023'
def main():
 folder=ORG/'current6e3850-normal-initializer-syntax-actual-r1';rp=folder/'RESULT.json';value=r.obj(rp)
 assert r.pin(r.read(rp))=={'bytes':16230,'sha256':'2dcd8bec92569df2e64d9fc7697a4350d0f96dd7391d68497427f386466bec8d'}
 root=ORG/'current6e3850-normal-initializer-syntax-root-r2/SYNTAX-ACTUAL-REPORT.json';rootv=r.obj(root)
 assert r.pin(r.read(root))=={'bytes':1186,'sha256':'3d93e0b24448fb5acdfc301e22571e59ce40b1aebed40701b990dee716596a22'}
 result_keys={'status','started_ns','ended_ns','source_sha','candidate','copy','leaf_PINS','leaf_REPORT','candidate_source','job_code_PINS','pre_execution_authority','php_l_count_required','container_id','container_native_exit','operations','own_label_before_after_empty','ordinary_own_container_removed','all_input_windows_ended','initializer_or_M_S_App_business_execution','W_Val_Run_mutations'}
 assert set(value)==result_keys and value['status']=='PASS_CURRENT6E3850_COMPLETE_NORMAL_INITIALIZER_PHP82_SYNTAX_ONLY' and value['source_sha']==r.HEAD=='6e3850fe210b2b1e99b2a33baa68123e34aefab6'
 profile=ORG/'current6e3850-normal-profile-source-preparation-r1';profile_pins=profile/'PINS.json';profile_pointer=r.pointer(profile_pins)
 assert r.pin(r.read(profile_pins))=={'bytes':7614,'sha256':'acb041715667c30572a19642e4e35758da27fb706c7e72588200edfa83b32054'}
 profile_packet=r.obj(profile_pins);assert len(profile_packet['members'])==30 and len(profile_packet['candidate'])==9
 for name,pin in profile_packet['members'].items():r.fixed(profile/name,pin)
 profile_review=ORG/'current6e3850-normal-profile-root-source-r1/REPORT.json';qualified=r.obj(profile_review)
 assert r.pin(r.read(profile_review))=={'bytes':1194,'sha256':'36bc907557f1f85240cf07df4d550934b413c5f485b14a929e3cc6ae7bbd5a55'}
 assert qualified['status']=='PASS_ROOT_COMPLETE_NINE_PROFILE_SOURCE_ONLY_NEW_INITIALIZER_SYNTAX_PENDING' and qualified['finding_count']==0 and qualified['findings']==[] and qualified['Source_PINS']==profile_pointer
 assert qualified['total_original_functions']==336 and qualified['complete_nine_file_hash_DAG'] is qualified['initializer_three_live_seams_and_independent_leaf_whole_join'] is qualified['all_first_final_closed'] is True
 initializer=profile/'candidate/ms4-initialize.php'
 assert r.pin(r.read(initializer))=={'bytes':78955,'sha256':'136e5ddf48989c6bbf2746579fabed1b61b32f371026005a48ef98c585e56373'}
 assert r.fixed(value['candidate']['path'],value['candidate'])==r.fixed(value['copy']['path'],value['copy'])==r.read(initializer)
 leaf=Path(value['leaf_PINS']['path']);leaf_packet=r.obj(leaf);r.fixed(leaf,value['leaf_PINS'])
 assert r.pin(r.read(leaf))=={'bytes':1271,'sha256':'ced2e6e383298a0a9c44509acee7cd5b0e5c844e1393f36a053fefe32595b88c'} and len(leaf_packet['members'])==8
 for name,pin in leaf_packet['members'].items():r.fixed(leaf.parent/name,pin)
 leaf_report=r.obj(value['leaf_REPORT']['path']);r.fixed(value['leaf_REPORT']['path'],value['leaf_REPORT'])
 assert leaf_report['candidate']==value['candidate'] and leaf_report['actual_literal_seams']==3 and leaf_report['whole_normalized_body_identical'] is leaf_report['ordinary_forward_reverse_whole_bytes'] is True
 assert value['php_l_count_required']==1 and value['container_native_exit']==0 and value['initializer_or_M_S_App_business_execution']=='NOT RUN' and value['W_Val_Run_mutations']==0
 assert value['ordinary_own_container_removed'] is value['own_label_before_after_empty'] is value['all_input_windows_ended'] is True
 assert set(rootv)=={'schema','status','actual_RESULT','outer_terminal','native_exit','wait_returncode','namespace','native_operations','whole_php_l_count','all_complete_streams_PID_argv_time_hashes_verified','one_ordinary_own_CID_removed','before_after_selector_empty','fixed_image_none_network_readonly_nonroot_one_RO_candidate_bind','all_first_final_closed','read_windows','new_target_or_Docker_or_PHP_executed_by_reader','business_or_new_diagnostic_branch_pass','upload503_root_cause','W_Val_Run_mutations'}
 assert rootv['status']=='PASS_ROOT_COMPLETE_ACTUAL_NORMAL_INITIALIZER_PHP82_SYNTAX_ONLY' and rootv['actual_RESULT']==r.pointer(rp) and rootv['outer_terminal']==r.pointer(folder/'outer.terminal.json')
 assert rootv['native_exit']==rootv['wait_returncode']==0 and rootv['namespace']==38 and rootv['native_operations']==8 and rootv['whole_php_l_count']==1 and rootv['read_windows']==39
 assert rootv['all_complete_streams_PID_argv_time_hashes_verified'] is rootv['one_ordinary_own_CID_removed'] is rootv['before_after_selector_empty'] is rootv['fixed_image_none_network_readonly_nonroot_one_RO_candidate_bind'] is rootv['all_first_final_closed'] is True
 assert rootv['new_target_or_Docker_or_PHP_executed_by_reader'] is rootv['business_or_new_diagnostic_branch_pass'] is False and rootv['upload503_root_cause']=='UNPROVEN' and rootv['W_Val_Run_mutations']==0
 job=Path(value['job_code_PINS']['path']).parent;source_final=job/'FINAL-PINS.json';source_packet=r.obj(source_final)
 assert r.pin(r.read(source_final))=={'bytes':8375,'sha256':'ac1e239591ae98946ceec86fbc17a3201a1619bebc0dc06301a5b94e3103a82a'} and len(source_packet['files'])==55
 source_members=[]
 for name,pin in source_packet['files'].items():p=job/name;r.fixed(p,pin);source_members.append(r.pointer(p))
 source_members.append(r.pointer(source_final))
 source_root=ORG/'current6e3850-normal-initializer-syntax-root-r2/SOURCE-REPORT.json';source_root_value=r.obj(source_root)
 assert r.pin(r.read(source_root))=={'bytes':1212,'sha256':'040ec8155825d3d12d45ff5bf74efaf0fa324b96574787334d8bde9b90a2bb3d'}
 assert source_root_value['status']=='PASS_ROOT_COMPLETE_NORMAL_INITIALIZER_SYNTAX_SOURCE_R2' and source_root_value['finding_count']==0 and source_root_value['findings']==[] and source_root_value['Source_FINAL_PINS']==r.pointer(source_final)
 source_peer=source_root_value['peer_Source_report'];peer=r.obj(source_peer['path']);r.fixed(source_peer['path'],source_peer)
 assert r.pin(r.read(source_peer['path']))=={'bytes':6046,'sha256':'dce9fb90c56aa3f4d1d19623a7ca14e9f8841a7d0c4f09346a9e676937da9a5e'} and peer['findings']==[] and peer['Source_FINAL_PINS']==r.pointer(source_final)
 code=r.obj(value['job_code_PINS']['path']);r.fixed(value['job_code_PINS']['path'],value['job_code_PINS']);r.fixed(value['candidate_source']['path'],value['candidate_source'])
 assert set(code['files'])=={'run.py','launch.py','DESIGN.md','CANDIDATE-SOURCE.json'}
 for name,pin in code['files'].items():r.fixed(job/name,pin)
 declared=r.obj(value['candidate_source']['path'])
 assert declared['candidate']==value['candidate'] and declared['copy']==value['copy'] and declared['leaf_PINS']==value['leaf_PINS'] and declared['leaf_REPORT']==value['leaf_REPORT']
 leaf_root=declared['Root_Source_review'];leaf_qualification=r.obj(leaf_root['path']);r.fixed(leaf_root['path'],leaf_root)
 assert leaf_qualification['finding_count']==0 and leaf_qualification['candidate']==value['candidate'] and leaf_qualification['Source_PINS']==value['leaf_PINS']
 for p in [source_root,Path(source_peer['path']),leaf,Path(value['leaf_REPORT']['path']),Path(leaf_root['path']),profile_pins,profile_review]:source_members.append(r.pointer(p))
 for name in leaf_packet['members']:source_members.append(r.pointer(leaf.parent/name))
 source_members=list({p['path']:p for p in source_members}.values())
 authority=value['pre_execution_authority']
 assert set(authority)=={'authorization','required_flag','original_tool_session','original_S_pid','original_S_created','original_S_terminal','context','reader_Source_PINS','reader_FINAL_PINS','reader_RESULT_INTERFACE','retirement','reader_native_wrappers','Root_full_report','peer_full_report','source_only_is_not_actual_admission','raw_child_streams_read'}
 assert authority['authorization']=='EXPLICIT_ROOT_AFTER_REAL_S1_RETIREMENT_AND_FULL_ROOT_PEER' and authority['original_tool_session']==17155 and authority['original_S_pid']==35944 and authority['source_only_is_not_actual_admission'] is True and authority['raw_child_streams_read'] is False
 assert {k:authority['context'][k] for k in ['bytes','sha256']}=={'bytes':12301,'sha256':'c56e609828ba3e51e63f69b83e4af49279bdaebee487116728c383e02dae7959'}
 for mode in ['close','zero']:
  row=authority['retirement'][mode];end=r.obj(row['terminal']['path']);r.fixed(row['terminal']['path'],row['terminal']);assert end['pid']==row['pid'] and end['native_exit']==end['wait_returncode']==0
 for mode in ['root','peer']:
  row=authority['reader_native_wrappers'][mode];end=r.obj(row['terminal']['path']);r.fixed(row['terminal']['path'],row['terminal']);assert end['pid']==row['pid'] and end['native_exit']==end['wait_returncode']==0
 receipts=[];streams=[]
 expected_names=['own-label-before','existing-fixed-image','create-one-owned-lint-container','attach-original-php-l','same-cid-native-wait','same-cid-native-terminal','ordinary-exact-owned-cid-rm','own-label-after']
 assert len(value['operations'])==8
 for i,(op,name) in enumerate(zip(value['operations'],expected_names),1):
  assert op['stem']==str(i).zfill(2)+'-'+name
  row={key:r.pointer(folder/(op['stem']+'.'+suffix)) for key,suffix in [('created','created.json'),('terminal','terminal.json'),('stdout','stdout'),('stderr','stderr')]}
  made=r.obj(row['created']['path']);ended=r.obj(row['terminal']['path']);out=r.read(row['stdout']['path']);err=r.read(row['stderr']['path'])
  assert set(made)=={'pid','argv','started_ns'} and set(ended)=={'pid','argv','started_ns','native_exit','wait_returncode','finished_ns','stdout','stderr','stem'}
  assert ended==op and made=={'pid':op['pid'],'argv':op['argv'],'started_ns':op['started_ns']}
  assert type(op['pid']) is int and op['pid']>0 and op['finished_ns']>=op['started_ns'] and op['native_exit']==op['wait_returncode']==0 and err==b''
  assert r.pin(out)==op['stdout'] and r.pin(err)==op['stderr'];receipts.append(row);streams.append(out)
 image='sha256:1099f749e2a38ec9453c6b820db8a78174514bc72d14edf5c203e13b82816e0a';cid=value['container_id'];assert re.fullmatch('[a-f0-9]{64}',cid)
 assert streams[0]==streams[7]==b'' and streams[1]==(image+'\n').encode() and streams[2]==streams[6]==(cid+'\n').encode()
 assert streams[3]==b'No syntax errors detected in /candidate/ms4-initialize.php\n' and streams[4]==b'0\n'
 terminal=json.loads(streams[5]);assert terminal['id']==cid and terminal['image']==image and terminal['state']['Status']=='exited' and terminal['state']['Pid']==0 and terminal['state']['ExitCode']==0 and terminal['restart']==0
 assert terminal['network']=='none' and terminal['readonly'] is True and terminal['user']=='65534:65534' and terminal['labels']['codex.ms4.syntax']=='current6e3850-normal-initializer-r1'
 assert len(terminal['mounts'])==1 and terminal['mounts'][0]['Type']=='bind' and terminal['mounts'][0]['Destination']=='/candidate' and terminal['mounts'][0]['RW'] is False and terminal['mounts'][0]['Source'].replace('\\','/')==(job/'candidates').as_posix()
 names=sorted(p.name for p in folder.iterdir());expected=['RESULT.json','INPUTS.json','outer.created.json','outer.terminal.json','outer.stdout','outer.stderr']+[str(i).zfill(2)+'-'+name+'.'+suffix for i,name in enumerate(expected_names,1) for suffix in ['created.json','terminal.json','stdout','stderr']]
 assert names==sorted(expected) and len(names)==38 and all((folder/name).is_file() for name in names)
 public={name:r.pointer(folder/name) for name in names}
 outer_made=r.obj(folder/'outer.created.json');outer_end=r.obj(folder/'outer.terminal.json')
 assert set(outer_made)=={'pid','argv','started_ns'} and set(outer_end)=={'pid','argv','started_ns','native_exit','wait_returncode','finished_ns','stdout_bytes','stdout_sha256','stderr_bytes','stderr_sha256'}
 assert all(outer_end[k]==v for k,v in outer_made.items()) and outer_end['native_exit']==outer_end['wait_returncode']==0 and outer_made['started_ns']<outer_end['finished_ns']
 assert outer_made['argv']==['C:/Users/Vwang/AppData/Local/Programs/Python/Python314/python.exe','-B','-X','utf8',str(job/'run.py'),authority['required_flag']]
 for stream in ['stdout','stderr']:assert r.pin(r.read(folder/('outer.'+stream)))=={'bytes':outer_end[stream+'_bytes'],'sha256':outer_end[stream+'_sha256']}
 windows=r.obj(folder/'INPUTS.json');assert set(windows)=={'files','all_windows_ended','ended_ns'} and windows['all_windows_ended'] is True and len(windows['files'])==84
 for row in windows['files']:
  assert row['named_before']==row['named_after'] and row['fd_open']==row['fd_after'] and [row['named_before'][i] for i in [0,1,6,7]]==[row['fd_open'][i] for i in [0,1,6,7]] and row['named_birthtime_ns']==row['fd_birthtime_ns']
 r.put('INITIALIZER-PREFLIGHT-DIAGNOSTIC-SYNTAX.json',{'schema':1,'status':'PASS_COMPLETE_CURRENT6E3850_INITIALIZER_PHP82_SYNTAX_ONLY','source_profile_PINS':profile_pointer,
  'initializer':r.pointer(initializer),'actual_RESULT_pointer':r.pointer(rp),'actual_result':value,'Root_readback':r.pointer(root),
  'Root_profile_source_review':r.pointer(profile_review),'job_source_members':source_members,'native_receipts':receipts,'actual_public_job_files':public,'App_business':'NOT RUN'})
 print(json.dumps({'syntax_authority':r.pointer(OUT/'INITIALIZER-PREFLIGHT-DIAGNOSTIC-SYNTAX.json'),'complete_actual_files':38,'native_receipts':len(receipts),'runtime_invoked':'NOT RUN'}))
if __name__=='__main__':main()
