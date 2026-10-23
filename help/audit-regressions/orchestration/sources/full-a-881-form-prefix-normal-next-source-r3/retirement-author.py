"""Name the finite saved public evidence; no target/resource query or body snapshot traversal."""
import json
from pathlib import Path
import source_reader as r

def four(stem):
    return {k:r.pointer(Path(str(stem)+'.'+ext),True) for k,ext in [('created','created.json'),('terminal','terminal.json'),('stdout','stdout'),('stderr','stderr')]}
def main():
    first=r.obj(r.OUT/'CURRENT-2E4-RETIREMENT.json');inputs=first['inputs'];close=r.obj(inputs['close']['path']);zero=r.obj(inputs['zero']['path']);failure=r.obj(inputs['failure_authority']['path'])
    native={}
    for label,value in [('close',close),('zero',zero)]:
        folder=Path(inputs[label]['path']).parent
        native[label]=[{'index':op['index'],'stem':op['stem'],**four(folder/op['stem'])} for op in value['operations']]
    root=[]
    for op in failure['root_native_commands']:
        p=Path(op['created']['path']);assert p.name.endswith('.created.json');root.append(four(p.with_name(p.name[:-len('.created.json')])))
    old=r.OLD/'launcher'
    outer={'M':four(old/'materialize'),'S':four(old/'normal-start'),'close':four(r.B/'current2e4-paid-failure-close-root-launch-r1/outer'),'zero':four(r.B/'current2e4-paid-failure-zero-root-launch-r1/outer')}
    value={'schema':1,'scope':'SIXTH_CURRENT2E4_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY','inputs':inputs,'native_receipts':native,'original_root_native_receipts':root,'outer_native_receipts':outer,
      'M31_preserved':True,'M_native_count':1037,'M_complete_source_metadata_rows':31490,'original_five_retirements':'UNCHANGED_HISTORICAL_FACTS','original_S_native_exit':1,'paid_producer_native_exit':1,'business_acceptance':'INCOMPLETE','private_or_old_source_bodies_read':False}
    assert len(native['close'])==93 and len(native['zero'])==20 and len(root)==81
    r.put('CURRENT-2E4-RETIREMENT-COMPLETE.json',value)
    print(json.dumps({'complete_sixth_authority':r.pointer(r.OUT/'CURRENT-2E4-RETIREMENT-COMPLETE.json'),'native_receipts':113,'old_root_native_receipts':81},ensure_ascii=False))
if __name__=='__main__':main()
