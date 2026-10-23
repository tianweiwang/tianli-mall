"""Finalize only the current Source preparation packet, not B output/admission."""
from pathlib import Path
import ast, hashlib, json, time

ROOT = Path(__file__).resolve().parent


def pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def pointer(path):
    return {'path': path.as_posix(), **pin(path.read_bytes())}


def put(name, value):
    with (ROOT / name).open('xb') as stream:
        stream.write((json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode())


def main():
    assert not (ROOT / 'PINS.json').exists()
    static = json.loads((ROOT / 'STATIC-CHECKS.json').read_bytes())
    transform = json.loads((ROOT / 'TRANSFORM.json').read_bytes())
    namespace = json.loads((ROOT / 'DECLARED-NAMESPACE.json').read_bytes())
    assert namespace['undeclared'] == []
    assert static['AST_scope']['changed_methods'] == ['validate_success', 'new_adapter', 'build']
    assert static['AST_scope']['added_methods'] == []
    assert static['ordinary_full_diff_forward_reverse'] == {'author': True, 'initializer': True}
    assert (ROOT / 'construct-r1.stderr').read_bytes() == (ROOT / 'check-r1.stderr').read_bytes() == b''
    for name, wanted in static['complete_static_source_input_window'].items():
        assert pin(Path(name).read_bytes()) == wanted
    author = (ROOT / 'impl/author_draft.py').read_bytes()
    leaf = (ROOT / 'leaf/ms4-initialize.php').read_bytes()
    tree = ast.parse(author)
    assert not any(isinstance(node, ast.Call) and isinstance(node.func, ast.Name)
                   and node.func.id in ['exec', 'eval', '__import__'] for node in ast.walk(tree))
    assert b'subprocess' not in author
    for required in [b'len(COMMITTED_FILES)==4', b'len(committed) == len(COMMITTED_PATHS) == 4',
                     b"len(inputs['committed_files']) == 4", b'len(latest) == 4', b'CURRENT_M_RESULT_KEYS35',
                     b"len(m['commit_chain']) == 19", b'== 1088', b'paid_28dc_failure_retirement_expected',
                     b'CURRENT_QUOTA_WIRE_SOURCE_HISTORY', b'quota_wire_current_source_successor',
                     b'FULL_ORIGINAL26_PROOF_COVERAGE', b'KEEP_ALL31_ORIGINAL_TRANSFORM_MEMBERS']:
        assert required in author
    h5 = ROOT.parent / 'h5-current175f-descriptor-inventory-r1'
    h5pins = json.loads((h5 / 'PINS.json').read_bytes())
    assert pin((h5 / 'PINS.json').read_bytes()) == {'bytes': 865, 'sha256': '3b69b1014f2af2bffc5d16c830c1f532966beebda0b7c3bcaa579f2634a47545'}
    report = {
        'schema': 1, 'status': 'FIXED_CURRENT175F_FULL_FRESHB_AUTHOR_LEAF_SOURCE_ONLY_UNEXECUTED_UNSEALED',
        'basis': 'Root delegated exact175f rebind of complete approved current28dc preparation under existing sole checklist; no new execution checklist.',
        'predecessor': {'PINS': pointer(ROOT.parent / 'fresh-b-current28dc-source-preparation-r1/PINS.json'),
                        'FINAL': pointer(ROOT.parent / 'fresh-b-current28dc-source-preparation-r1/FINAL-PINS.json'),
                        'whole_packet_verified': True, 'preserved_immutable': True},
        'author': pointer(ROOT / 'impl/author_draft.py'), 'initializer_leaf': pointer(ROOT / 'leaf/ms4-initialize.php'),
        'verification': {'static_checks': pointer(ROOT / 'STATIC-CHECKS.json'),
                         'namespace': pointer(ROOT / 'DECLARED-NAMESPACE.json'),
                         'full_ordinary_author_and_leaf_forward_reverse': True,
                         'exact_author_seams': len(transform['ordered_exact_source_seams']),
                         'exact_leaf_tuple_changes_from_previous28dc': 1,
                         'all_other_leaf_bytes_and_paid_donor_membership_optimizations': 'PRESERVED',
                         'changed_methods': ['validate_success', 'new_adapter', 'build'],
                         'all_other_original_methods_whole': static['AST_scope']['unchanged_methods'],
                         'no_added_methods_or_imports': True, 'all_recursive_globals_declared': True,
                         'source_only_constructor_and_checker_exit': [0, 0]},
        'current_actual_M_source_only': static['current_actual_A_Source_only'],
        'source_contract_delta': {
            'source_sha': '175f3cd6183894185656a2974579e1be39eed7a7',
            'parent': '28dcab632da3e62f7c8b8d926b6ab9406534d0d3',
            'strict_M_fields': 35, 'history_steps': 19, 'real_A_M_operations': 1088,
            'latest_four_producer_and_all_three_consumers': True,
            'adoption': 'CURRENT_QUOTA_WIRE_SOURCE_HISTORY',
            'new_ninth_retirement': 'CURRENT_28DC_PAID_PRODUCER_FAILURE_RETIREMENT',
            'all_previous_eight_retirement_objects_sources_and_semantics': 'PRESERVED',
            'full_CU7_deltas_against_original53e': 4, 'CU7_deltas_against_previous28dc': 1,
            'current_helper': {'path': 'help/audit-regressions/isolated/lib/ms4-customer-form-normal.php', 'bytes': 68224,
                               'sha256': 'fb729bd1a554441186938c22a4323a78537b3614c9561f7e9acf4dd00de2406d'},
            'UI8_Core3_browser_original3changed5reused_vs_original53e': 'UNCHANGED_FROM_PREVIOUS28DC',
            'B_execution_budget': 'NOT DERIVED FROM A-M OR CLAIMED'},
        'preserved_scope': static['retained_original_scope'],
        'complete_original_closure': {'transforms': 31, 'whole_reversible_proofs': 26, 'profile_browser_root_DAG': [17, 8, 4],
                                      'r26_base_source_and_all59_history_tools3': 'PRESERVED',
                                      'postA_whole_consumer': static['postA']['original_complete_consumer']},
        'H5_same_task_inventory': {'PINS': pointer(h5 / 'PINS.json'), 'REPORT': pointer(h5 / 'REPORT.json'),
                                   'Source_evidence': pointer(h5 / 'SOURCE-EVIDENCE.json'), 'candidate_implemented': False,
                                   'full31_sources': True, 'necessary_changes': 14, 'whole_reuse': 17,
                                   'nine_scenarios_27width_JPEG_original_driver_close_zero': 'PRESERVED', 'substitutes_UI20': False},
        'pending': ['Real same175f A0 full Root result/context/outer0 and its original successful workerclose/independentzero/postA history.',
                    'Distinct complete B author/leaf independent Source admission; full118698-byte changed leaf actual PHP82 syntax after live S fully ends and Root admits it.',
                    'Actual12-key public input producer, complete B author invocation/generation/seal/materialization/start.',
                    'Complete UI20/20ACK/42frames/threewidth/N1N2 and Task2 all three9steps/20frames/18DOM actual evidence.',
                    'H5 exact31 descriptor/coordinator successor implementation and later independent nine-scenario actual evidence.',
                    'All original Kernel/multipart/multiple goods/readers/savedreads/device/finance/manual/release remaining requirements.'],
        'boundary': {'author_or_leaf_or_B_Host_Docker_Node_browser_invoked': False,
                     'A0_postA_success_close_zero': 'UNBOUND', 'runtime_ready': False,
                     'stopped_security_negative_fault_concurrency_disconnect_recovery_expiry_T4': 'NOTRUN',
                     'real_funds_or_deployment': 'NOT AUTHORIZED',
                     'old_Source_W_Val_Git_N175f_Run_canonical_CP_modified': False,
                     'future_success_hash_PID_receipt_created': False},
        'scope_of_evidence': 'Source-only preparation and static verification, not full B generation or runtime acceptance.',
    }
    put('REPORT.json', report)
    files = {path.relative_to(ROOT).as_posix(): pin(path.read_bytes()) for path in sorted(ROOT.rglob('*')) if path.is_file()}
    put('PINS.json', {'schema': 1, 'scope': 'COMPLETE_IMMUTABLE_SOURCE_PREPARATION_PACKET_NOT_B_OUTPUT_PINS',
                      'files': files, 'namespace_exact': sorted(files), 'author_invoked': False,
                      'initializer_PHP_executed': False, 'B_generated': False, 'A0': 'UNBOUND', 'runtime_ready': False})
    put('FINAL-PINS.json', {'schema': 1, 'status': report['status'], 'packet_PINS': pointer(ROOT / 'PINS.json'),
                            'report': pointer(ROOT / 'REPORT.json'), 'static_checks': pointer(ROOT / 'STATIC-CHECKS.json'),
                            'author': report['author'], 'initializer_leaf': report['initializer_leaf'],
                            'author_or_target_invoked': False})
    print(json.dumps({'PINS': pointer(ROOT / 'PINS.json'), 'FINAL': pointer(ROOT / 'FINAL-PINS.json'),
                      'report': pointer(ROOT / 'REPORT.json'), 'author': report['author'], 'leaf': report['initializer_leaf']}))


if __name__ == '__main__':
    main()
