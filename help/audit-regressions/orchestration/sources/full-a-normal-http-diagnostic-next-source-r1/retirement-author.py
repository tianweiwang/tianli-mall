"""Bind seventh actual current881 public retirement only; no target or resource operations."""
import json
from pathlib import Path
import source_reader as r

def four(stem):
    return {key:r.pointer(Path(str(stem)+'.'+suffix),True) for key,suffix in
        [('created','created.json'),('terminal','terminal.json'),('stdout','stdout'),('stderr','stderr')]}

def main():
    index_path=r.B/'current881-paid-failure-retirement-authority-r1/INDEX.json'
    assert r.pin(r.read(index_path))=={'bytes':2825,'sha256':'caff9bd9b3e58ee15d92251a8febbfee781a4d0dff6325722ac60b144e9812f7'}
    index=r.obj(index_path)
    inputs={'index':r.pointer(index_path)}|index['authority']
    for pointer in inputs.values():r.fixed(pointer['path'],pointer)
    inputs['M_result']=r.pointer(r.OLD/'validation/materialize-freeze-assets-r1/RESULT.json',True)
    inputs['M_root_readback']=r.pointer(r.B/'current881-complete-materialization-root-readback-r1/REPORT.json',True)
    close=r.obj(inputs['close']['path']);zero=r.obj(inputs['zero']['path']);failure=r.obj(inputs['actual_failure']['path'])
    native={}
    for label,value in [('close',close),('zero',zero)]:
        folder=Path(inputs[label]['path']).parent
        native[label]=[{'index':op['index'],'stem':op['stem'],**four(folder/op['stem'])} for op in value['operations']]
    root=[]
    for op in failure['root_native_commands']:
        path=Path(op['created']['path']);root.append(four(path.with_name(path.name.removesuffix('.created.json'))))
    outer={'M':four(r.OLD/'launcher/materialize'),'S':four(r.OLD/'launcher/normal-start'),
        'close':four(r.B/'current881-paid-failure-close-root-launch-r1/outer'),
        'zero':four(r.B/'current881-paid-failure-zero-root-launch-r1/outer')}
    actual_inputs=r.obj(inputs['Root_actual_inputs']['path'])
    assert actual_inputs['all_windows_ended'] is True and len(actual_inputs['files'])==479
    full=[]
    for row in actual_inputs['files']:
        assert row['named_before']==row['named_after'] and row['fd_open']==row['fd_after']
        r.fixed(row['path'],row['pin']);full.append(r.pointer(Path(row['path']),True))
    value={'schema':1,'scope':'SEVENTH_CURRENT881_ACTUAL_FAILED_FULL_A_COMPLETE_PUBLIC_RETIREMENT_AUTHORITY',
        'inputs':inputs,'native_receipts':native,'original_root_native_receipts':root,'outer_native_receipts':outer,
        'Root_actual_complete_public_inputs':full,'historical_six_retirements':'UNCHANGED_FULL_HISTORICAL_SIXTEEN_AUTHORITY',
        'M32_preserved':True,'M_native_count':1046,'M_complete_source_metadata_rows':31492,
        'original_S_native_exit':1,'paid_producer_native_exit':1,'business_acceptance':'INCOMPLETE',
        'HTTP_root_cause':'UNKNOWN','private_or_old_source_bodies_read':False}
    assert len(native['close'])==93 and len(native['zero'])==20 and len(root)==108 and len(full)==479
    r.put('CURRENT-881-RETIREMENT-COMPLETE.json',value)
    print(json.dumps({'seventh_authority':r.pointer(r.OUT/'CURRENT-881-RETIREMENT-COMPLETE.json'),
        'native_receipts':113,'original_root_native_receipts':108,'Root_public_inputs':479},ensure_ascii=False))

if __name__=='__main__':main()
