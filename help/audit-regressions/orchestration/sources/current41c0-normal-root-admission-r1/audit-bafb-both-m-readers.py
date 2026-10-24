"""Read-only join of two completed M readers; never executes a target reader."""
import hashlib
import json
import os
import stat
import time
from pathlib import Path

ORG = Path('H:/天俪商城/artifacts/project-organization-20261023')
BASE = Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1')
ADM = ORG / 'current41c0-normal-root-admission-r1'
ROOT = BASE / 'currentbafb-complete-materialization-root-readback-r1'
PEER = ORG / 'currentbafb-complete-materialization-independent-readback-r1'
NATIVE = ORG / 'currentbafb-m-readers-actual-r1'
HEAD = 'bafb6305062aeb6b6e945c21da572863062ca480'
START = time.time_ns()
WINDOWS = []


def need(ok, label):
    if not ok:
        raise AssertionError(label)


def object_pairs(pairs):
    out = {}
    for key, value in pairs:
        need(key not in out, 'duplicate JSON key')
        out[key] = value
    return out


def parse(raw):
    return json.loads(raw.decode('utf-8-sig'), object_pairs_hook=object_pairs)


def native9(s):
    return [s.st_dev, s.st_ino, s.st_mode, s.st_nlink, s.st_uid,
            s.st_gid, s.st_size, s.st_mtime_ns, s.st_ctime_ns]


def read(path, expected=None):
    need(path.is_absolute(), 'absolute path')
    for parent in [path, *path.parents]:
        info = parent.lstat()
        need(not stat.S_ISLNK(info.st_mode) and not getattr(info, 'st_file_attributes', 0) & 0x400,
             'no link/reparse path')
    before = path.stat()
    with path.open('rb') as stream:
        fd_before = os.fstat(stream.fileno())
        raw = stream.read()
        need(stream.read(1) == b'', 'EOF')
        fd_after = os.fstat(stream.fileno())
    after = path.stat()
    need(stat.S_ISREG(before.st_mode), 'regular file')
    need(native9(before) == native9(after), 'named window stable')
    need(native9(fd_before) == native9(fd_after), 'fd window stable')
    need(all(native9(before)[i] == native9(fd_before)[i] for i in (0, 1, 6, 7)), 'named/fd identity')
    need(before.st_birthtime_ns == fd_before.st_birthtime_ns, 'named/fd birthtime')
    desc = {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}
    need(desc['bytes'] == before.st_size, 'full bytes')
    if expected is not None:
        need(desc == expected, 'exact fixed output pin: ' + path.name)
    WINDOWS.append({'path': path.as_posix(), **desc, 'named_before': native9(before),
                    'named_after': native9(after), 'fd_before': native9(fd_before),
                    'fd_after': native9(fd_after), 'named_birthtime_ns': before.st_birthtime_ns,
                    'fd_birthtime_ns': fd_before.st_birthtime_ns, 'EOF': True, 'closed': True})
    return raw, desc


def saved_windows(rows, count, peer=False):
    need(len(rows) == count, 'complete reader finite input count')
    paths = set()
    for row in rows:
        need(row['path'] not in paths, 'distinct finite inputs')
        paths.add(row['path'])
        names = ['named_before', 'named_after', 'fd_before' if peer else 'fd_open', 'fd_after']
        vectors = [row[k] for k in names]
        need(all(type(v) is list and len(v) == 9 and all(type(x) is int for x in v) for v in vectors),
             'saved four native9 shapes')
        need(vectors[0] == vectors[1] and vectors[2] == vectors[3], 'saved own API stable')
        need(all(vectors[0][i] == vectors[2][i] for i in (0, 1, 6, 7)), 'saved cross API identity')
        need(type(row['named_birthtime_ns']) is int and row['named_birthtime_ns'] == row['fd_birthtime_ns'],
             'saved birthtime identity')
        pin = row if peer else row['pin']
        need(type(pin['bytes']) is int and pin['bytes'] == vectors[0][6], 'saved full-byte size')
        need(len(pin['sha256']) == 64 and all(c in '0123456789abcdef' for c in pin['sha256']), 'saved SHA shape')
        if peer:
            need(row['EOF'] is True and row['closed'] is True, 'peer input closed EOF')
            need(pi['started_ns'] <= row['started_ns'] <= row['ended_ns'] <= pi['ended_ns'], 'peer finite window times')


expected = {
    ('root', 'REPORT.json'): (4618, 'c885b90870a49500cce43527e2f690c4b3e418b9ced7d5e7c97ff7cf3936ee93'),
    ('root', 'INPUTS.json'): (7482392, '726f7e4b6a64ee71e25bbd581b6bf183212f734148cd256029cd8ef55d04e3db'),
    ('peer', 'REPORT.json'): (3537273, 'fda54130456e944f32b21a8d874a0fe806c8dcc9dc93b80b87bf35c0b0e944a5'),
    ('peer', 'INPUTS.json'): (9245145, '691aa9ad766992ea7533a44ec68cb3ec809d02e72010ffe49cf3f1c177bad5b3'),
    ('peer', 'CHECKS.json'): (10535, '692be983b0f43629a2477580a50837af60e939f7b990ab2325c028a8dcdcdf6e'),
    ('peer', 'PINS.json'): (508, '5cdfe6bada88124644a8b8b5724f7fffefd45996d1592088e40eba7505373db0'),
}
namespaces = {p: sorted(x.name for x in p.iterdir()) for p in (ROOT, PEER, NATIVE)}
need(namespaces[ROOT] == ['INPUTS.json', 'REPORT.json'], 'root exact output namespace')
need(namespaces[PEER] == ['CHECKS.json', 'INPUTS.json', 'PINS.json', 'REPORT.json'], 'peer exact output namespace')
need(namespaces[NATIVE] == sorted(f'{mode}-{name}' for mode in ('root', 'peer')
                                 for name in ('admission.json', 'created.json', 'terminal.json', 'stdout', 'stderr')),
     'native exact ten output namespace')
objects, pins = {}, {}
for (mode, name), (size, digest) in expected.items():
    directory = ROOT if mode == 'root' else PEER
    raw, pin = read(directory / name, {'bytes': size, 'sha256': digest})
    objects[(mode, name)], pins[(mode, name)] = parse(raw), pin
rr, ri = objects[('root', 'REPORT.json')], objects[('root', 'INPUTS.json')]
pr, pi = objects[('peer', 'REPORT.json')], objects[('peer', 'INPUTS.json')]
for name, pin in objects[('peer', 'PINS.json')]['files'].items():
    need(pin == pins[('peer', name)], 'peer seals three original bodies')
checks = objects[('peer', 'CHECKS.json')]
need(checks['total_checks'] == sum(checks['counts'].values()) > 0, 'peer complete check count')
need(ri['all_windows_ended'] is True and rr['all_input_windows_ended'] is True and rr['finite_reads'] == 5991,
     'root finite closure')
need(pi['all_finite_windows_closed'] is True and pr['all_finite_windows_closed'] is True
     and pi['finite_input_count'] == pr['finite_input_count'] == 6037, 'peer finite closure')
saved_windows(ri['files'], 5991)
saved_windows(list(pi['windows'].values()), 6037, peer=True)
need(all(k == v['path'] for k, v in pi['windows'].items()), 'peer windows exact path keys')

receipts = {}
for mode, pid in [('root', 1660), ('peer', 34708)]:
    row = {}
    for name in ('admission.json', 'created.json', 'terminal.json', 'stdout', 'stderr'):
        raw, pin = read(NATIVE / f'{mode}-{name}')
        row[name] = raw if name in ('stdout', 'stderr') else parse(raw)
        row[name + '_pin'] = pin
    created, terminal, admission = row['created.json'], row['terminal.json'], row['admission.json']
    need(created['pid'] == terminal['pid'] == pid, 'original reader PID identity')
    need(terminal['native_exit'] == terminal['wait_returncode'] == 0, 'original native and wait zero')
    need(created['started_ns'] <= terminal['finished_ns'], 'original process timing')
    need(admission['actual_M_PID'] == 22536 and admission['S_or_business_admitted'] is False, 'admission only M')
    argv = created['argv']
    need(argv[argv.index('--outer-pid') + 1] == '22536', 'reader consumes original M PID')
    for stream in ('stdout', 'stderr'):
        need(terminal[stream] == {'path': (NATIVE / f'{mode}-{stream}').as_posix(), **row[stream + '_pin']},
             'original raw stream pin joins terminal')
    need(row['stderr'] == b'', 'original empty stderr')
    row['stdout_value'] = parse(row['stdout'])
    receipts[mode] = row
need(receipts['root']['created.json']['started_ns'] <= ri['ended_ns'] <= receipts['root']['terminal.json']['finished_ns'],
     'root input closing time inside original process')
need(receipts['peer']['created.json']['started_ns'] <= pi['started_ns'] <= pi['ended_ns']
     <= receipts['peer']['terminal.json']['finished_ns'], 'peer complete finite interval inside original process')
rs, ps = receipts['root']['stdout_value'], receipts['peer']['stdout_value']
need(set(rs) == {'REPORT', 'finite_reads', 'M', 'freeze'}, 'exact original four-key Root stdout')
need(rs['REPORT'] == pins[('root', 'REPORT.json')] and rs['finite_reads'] == 5991, 'Root stdout report and count')
need(rs['M'] == {k: rr['actual_RESULT'][k] for k in ('bytes', 'sha256')}
     and rs['freeze'] == {k: rr['actual_freeze'][k] for k in ('bytes', 'sha256')}, 'Root stdout M and freeze')
need(set(ps) == {'REPORT', 'PINS', 'finite_inputs', 'native', 'Source_only_M'}, 'exact original peer stdout')
need(ps == {'REPORT': pins[('peer', 'REPORT.json')], 'PINS': pins[('peer', 'PINS.json')],
            'finite_inputs': 6037, 'native': 1150, 'Source_only_M': True}, 'peer stdout exact joins')
need(rr['status'] == 'PASS_ACTUAL_FULL_CURRENTBAFB_MATERIALIZATION_ONLY'
     and pr['status'] == 'PASS_COMPLETE_ACTUAL_CURRENTBAFB_M37_SOURCE_MATERIALIZATION_READBACK_ONLY', 'actual report scopes')
for key in ('source_sha', 'actual_RESULT', 'actual_freeze', 'separate_author_input_rows', 'separate_author_intersections'):
    need(rr[key] == pr[key], 'both reports same ' + key)
need(rr['source_sha'] == HEAD and pr['parent'] == 'd0a5d5398546e223122b5c2a2f115e1908f78ced', 'exact baseline lineage')
for rootkey, peerkey, value in [
    ('native_operations', 'actual_M_native_operations', 1150),
    ('outer_pid', 'actual_outer_pid', 22536), ('outer_native_exit_wait', 'actual_outer_native_exit_wait', 0),
    ('M_RESULT_fields', 'M_RESULT_current_fields', 37),
    ('historical_M_RESULT_fields', 'M_RESULT_historical_fields_preserved', 36),
    ('complete_source_snapshot_rows', 'complete_dual_source_metadata_rows', 31500),
    ('tracked_per_checkout', 'complete_tracked_rows_per_tree', 15750),
    ('source_group_union', 'complete_normal_source_union', 10886),
    ('M_complete_authority_inputs', 'authority_metadata_rows', 32491),
    ('callback_complete_inputs', 'complete_callback_graph_rows', 32497),
]:
    need(rr[rootkey] == pr[peerkey] == value, 'both original counters: ' + rootkey)
need(rr['all_original_native_created_terminal_and_streams_join'] is True
     and pr['all1150_created_terminal_and_double_raw_streams_complete'] is True, 'all original M receipts')
need(rr['steps'] == 24 and rr['cumulative'] == 311 and rr['historical_before_ninth_fields'] == 34, 'original stage and history counters')
need(pr['all24_actual_commit_native_before_after_blobs_and_paths_verified'] is True
     and pr['eleven_retirement_facts_whole_joined_without_overwriting_old_ten'] is True, 'actual lineage and eleven old whole objects')
need(pr['Source_plus_actual_output_namespace_files'] == 4640 and pr['saved_output_namespace_files'] == 4606,
     'complete original saved namespace counts')
tree = pr['source_tree_complete_freeze_plan']
need(len(tree) == 11 and tree['source_sha'] == HEAD and tree['file_count'] == len(tree['files']) == 15750
     and tree['directory_count'] == len(tree['directories']) == 3880, 'complete SourceTree shape')
manifest = hashlib.sha256(json.dumps({'files': tree['files'], 'directories': tree['directories']},
                                   ensure_ascii=False, separators=(',', ':')).encode('utf-8')).hexdigest()
need(manifest == tree['manifest_sha256'] == rr['complete_tracked_source_tree_verified']['manifest_sha256']
     == '5f148c27ab346bf3ebc0337481b95e6de4f8f256022153787325adc226e285ed', 'complete SourceTree manifest')
need(rr['old_source_bodies'] == 'NOT REREAD' and pr['old_or_current_worktree_bodies_reread'] is False,
     'readers did not reopen original source bodies')
need(pr['target_module_or_source_authority_import_or_execution'] is False
     and pr['new_Git_Docker_resource_or_process_calls'] == 0, 'readers are read-only')
need(rr['Run_App_DB_HTTP_business'] == pr['M_App_DB_HTTP_business'] == 'NOT RUN'
     and rr['A0'] == 'NOT CREATED' and pr['Run_created_by_M'] is False
     and pr['source_tree_copy_or_volume_created_by_M'] is False, 'no business acceptance inferred')
need(all(sorted(x.name for x in p.iterdir()) == names for p, names in namespaces.items()), 'all output namespaces stable')

inputs_path = ADM / 'R100-BOTH-M-READERS-ACTUAL-ROOT-INPUTS.json'
report_path = ADM / 'R100-BOTH-M-READERS-ACTUAL-ROOT-READBACK.json'
need(not inputs_path.exists() and not report_path.exists(), 'never replace previous qualification')
inputs = {'schema': 1, 'scope': 'ONLY_TWO_COMPLETED_M_READER_OUTPUTS_AND_ORIGINAL_NATIVE_RECEIPTS',
          'files': WINDOWS, 'finite_input_count': len(WINDOWS), 'all_finite_windows_closed': True,
          'started_ns': START, 'ended_ns': time.time_ns()}
raw = (json.dumps(inputs, ensure_ascii=False, indent=2) + '\n').encode('utf-8')
inputs_pin = {'path': inputs_path.as_posix(), 'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}
report = {'schema': 1, 'status': 'PASS_BOTH_COMPLETED_CURRENTBAFB_M_READERS_ACTUAL_ROOT_JOIN_ONLY',
          'source_sha': HEAD, 'actual_M_PID': 22536, 'actual_M': rr['actual_RESULT'], 'actual_freeze': rr['actual_freeze'],
          'Root_REPORT': {'path': (ROOT / 'REPORT.json').as_posix(), **pins[('root', 'REPORT.json')]},
          'peer_REPORT': {'path': (PEER / 'REPORT.json').as_posix(), **pins[('peer', 'REPORT.json')]},
          'peer_PINS': {'path': (PEER / 'PINS.json').as_posix(), **pins[('peer', 'PINS.json')]},
          'original_reader_PIDs': {'root': 1660, 'peer': 34708}, 'both_native_and_wait_exit': 0,
          'complete_saved_input_windows_validated': {'root': 5991, 'peer': 6037},
          'complete_original_M_native_operations': 1150, 'complete_source_metadata_rows': 31500,
          'source_tree_manifest_sha256': manifest, 'complete_authority_inputs': 32491,
          'readonly_failed_attempt_preserved': 'R100-BOTH-M-READERS-ROOT-AUDIT-ATTEMPT1.json',
          'stdout_join_correction': 'Original Root stdout exact REPORT/finite_reads/M/freeze; INPUTS independently sealed and fully read.',
          'actual_readers_rerun': False, 'original_M_or_source_corpus_reread_by_this_audit': False,
          'target_import_or_execution': False, 'Git_Docker_App_DB_HTTP_calls': 0,
          'S_A0_full_business_UI_or_new83daa_qualification': 'NOT ASSERTED', 'INPUTS': inputs_pin,
          'completed_ns': time.time_ns()}
with inputs_path.open('xb') as out:
    out.write(raw)
report_raw = (json.dumps(report, ensure_ascii=False, indent=2) + '\n').encode('utf-8')
with report_path.open('xb') as out:
    out.write(report_raw)
print(json.dumps({'status': report['status'], 'REPORT': {'path': report_path.as_posix(), 'bytes': len(report_raw),
      'sha256': hashlib.sha256(report_raw).hexdigest()}, 'INPUTS': inputs_pin, 'finite_output_reads': len(WINDOWS)}, ensure_ascii=False))
