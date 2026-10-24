"""Root-reviewable entry for the two original complete Source callbacks only."""
import hashlib
import json
import sys
import time
import types
from pathlib import Path

SOURCE = Path('H:/天俪商城/artifacts/ms4-worker-normal-source-preparation-r1/full-a-preflight-diagnostic-current6e3850-normal-next-source-r2')
OUTPUT = Path('H:/天俪商城/artifacts/project-organization-20261023/current6e3850-original-source-callback-actual-r2')
FLAG = '--authorize-root-reviewed-original-full-source-callbacks-only'
SOURCE_PINS = {'bytes': 68413, 'sha256': 'd3e61099b79c564658d3c2a2d56407501b47583790c44c34bbdd3617da1b4f54'}
ENTRY_PINS = {
    'source_reader.py': {'bytes': 4096, 'sha256': '9f1fcaca4973319c543675e90a277bc817cc111569c7a130052ed227b65711bc'},
    'author_support.py': {'bytes': 4968, 'sha256': '8a8d574ce925b0f5bf7d98489602fa589cf007a166974eecf2dcb085b5735351'},
    'materializer/prepare.py': {'bytes': 9047962, 'sha256': '99d37776a46262608cec9277d68d29d672a14b7addeef721bf3868f3ee8812d3'},
    'validation/run.py': {'bytes': 9063290, 'sha256': '00464dd6a91b90f83210a96686321d7fac28cdc934656b78618c4fc29b53d90f'},
    'validation/PROFILE-PUBLICATION.json': {'bytes': 1426325, 'sha256': 'dd3aa7af66f93cdefa7ff9db24e9c0f19c12bc5a6994c0e8be736ab2cd7bd803'},
}


def pin(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def unique_pairs(items):
    result = {}
    for key, value in items:
        if key in result:
            raise ValueError('DUPLICATE_JSON_KEY')
        result[key] = value
    return result


def exact_module(name, raw):
    if name in sys.modules:
        raise RuntimeError('FRESH_PROCESS_ORIGINAL_MODULE_NAME_REQUIRED')
    module = types.ModuleType(name)
    module.__file__ = str(SOURCE / (name + '.py'))
    sys.modules[name] = module
    exec(compile(raw, module.__file__, 'exec'), module.__dict__)
    return module


def main():
    if sys.flags.optimize or sys.argv[1:] != [FLAG]:
        raise RuntimeError('ROOT_ADMISSION_AND_NONOPTIMIZED_PYTHON_REQUIRED')
    if OUTPUT.exists():
        raise RuntimeError('FRESH_OWN_OUTPUT_REQUIRED')
    started = time.time_ns()
    source_raw = (SOURCE / 'PINS.json').read_bytes()
    if pin(source_raw) != SOURCE_PINS:
        raise RuntimeError('EXACT_FROZEN_SOURCE_PINS_REQUIRED')
    source_pins = json.loads(source_raw, object_pairs_hook=unique_pairs)
    raw = {}
    for name, expected in ENTRY_PINS.items():
        raw[name] = (SOURCE / name).read_bytes()
        row = source_pins['files'][name]
        if set(row) != {'path', 'bytes', 'sha256', 'native9'} or row['path'] != (SOURCE / name).as_posix() or pin(raw[name]) != expected or {key: row[key] for key in ['bytes', 'sha256']} != expected:
            raise RuntimeError('EXACT_ORIGINAL_SOURCE_ENTRY_REQUIRED')
    reader = exact_module('source_reader', raw['source_reader.py'])
    support = exact_module('author_support', raw['author_support.py'])
    assert support.r is reader and reader.CACHE == {} and reader.READS == {}
    assert reader.fixed(SOURCE / 'PINS.json', SOURCE_PINS) == source_raw
    for name, expected in ENTRY_PINS.items():
        assert reader.fixed(SOURCE / name, expected) == raw[name]
        assert reader.READS[(SOURCE / name).as_posix()]['native9'] == source_pins['files'][name]['native9']
    results = {}
    qualification = {}
    for label, name in [('M', 'materializer/prepare.py'), ('S', 'validation/run.py')]:
        publication = json.loads(reader.fixed(SOURCE / 'validation/PROFILE-PUBLICATION.json', ENTRY_PINS['validation/PROFILE-PUBLICATION.json']), object_pairs_hook=unique_pairs)
        results[label], qualification[label] = support.actual_namespace_qualification(raw[name], label, publication)
    assert results['M'] == results['S']
    result = results['M']
    assert len(result['all']) == 15748 and len(result['committed']) == 307 and len(result['commit_chain']) == 20
    assert len([key for key in result if 'retirement' in key]) == 10
    assert {key: len(rows) for key, rows in result['groups'].items()} == publication['finite_contexts']['source_counts']
    assert all(row['manual_dependency_injection'] is False and row['unbound_globals'] == [] and row['pure_source_checks'] > 0 for row in qualification.values())
    encoded = json.dumps(result, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode('utf8')
    inputs = {'files': reader.READS, 'cache_semantics': 'ORIGINAL_FIRST_FULL_EOF_READ_ONCE_PER_PATH_THEN_CACHE', 'callback_invocations': 2}
    report = {'schema': 1, 'status': 'PASS_ORIGINAL_COMPLETE_SOURCE_AUTHORITY_CALLBACKS_ONLY', 'source_PINS': {'path': (SOURCE / 'PINS.json').as_posix(), **SOURCE_PINS}, 'entries': ENTRY_PINS, 'qualification': qualification, 'complete_return_objects_equal': True, 'complete_canonical_return_pin': pin(encoded), 'return_keys': list(result), 'tracked': len(result['all']), 'committed': len(result['committed']), 'stages': len(result['commit_chain']), 'retirements': 10, 'group_counts': {key: len(rows) for key, rows in result['groups'].items()}, 'actual_input_paths': len(reader.READS), 'callback_native_calls': 0, 'M_body_or_S_main_or_App_business': 'NOT RUN', 'A0': False, 'started_ns': started, 'ended_ns': time.time_ns()}
    OUTPUT.mkdir()
    for name, value in [('INPUTS.json', inputs), ('REPORT.json', report)]:
        with (OUTPUT / name).open('x', encoding='utf8', newline='\n') as handle:
            handle.write(json.dumps(value, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps(report, ensure_ascii=False))


if __name__ == '__main__':
    main()
