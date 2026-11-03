"""Finite Root outer lifetime recorder; the unbound draft cannot create a Run."""
from pathlib import Path
import argparse
import datetime
import json
import sys
import uuid

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_common import ROOT, RUN_PARENT, PYTHON, qualification, regular, require, native, read, put


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--frontend', choices=['admin', 'merchant', 'h5'], required=True)
    parser.add_argument('--pins-sha256', required=True)
    args = parser.parse_args()
    q = qualification(args.pins_sha256)
    run_id = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S') + uuid.uuid4().hex[:8]
    RUN_PARENT.mkdir(parents=True, exist_ok=True)
    regular(RUN_PARENT, True)
    outer = RUN_PARENT / (args.frontend + '-' + run_id + '-launch')
    require(not outer.exists() and not outer.is_symlink(), 'NEW_OUTER_LIFETIME_ONLY')
    outer.mkdir()
    context = {'evidence': outer, 'native': []}
    argv = [PYTHON, '-I', '-B', '-X', 'utf8', str(ROOT / 'root-build.py'), '--frontend', args.frontend,
            '--pins-sha256', args.pins_sha256, '--run-id', run_id]
    _, terminal = native(context, 'wrapper', argv, ROOT, allow_failure=True, allow_stderr=True)
    report_path = RUN_PARENT / (args.frontend + '-' + run_id) / 'BUILD-REPORT.json'
    report = json.loads(read(report_path)) if report_path.is_file() else None
    verified = (terminal['native_exit'] == terminal['wait_returncode'] == 0 and type(report) is dict
                and report.get('status') == 'VERIFIED_ORDINARY_OFFLINE_BUILD_NOT_UI_OR_DEPLOYMENT'
                and report.get('source_head') == q['head'] and report.get('run_id') == run_id
                and report.get('frontend') == args.frontend)
    result = {'scope': 'ROOT_REAL_BUILD_OUTER_LIFETIME_NOT_BROWSER',
              'status': 'ACTUAL_ORDINARY_BUILD_VERIFIED' if verified else 'BUILD_FAILED_OR_UNKNOWN_PRESERVED',
              'frontend': args.frontend, 'run_id': run_id, 'source_head': q['head'],
              'wrapper': terminal, 'build_report': report_path.as_posix(),
              'public_copy_four_manifests': 'NOT_CREATED', 'HTTP_DOM_devices': 'NOT_RUN', 'push_deploy': False}
    put(outer / 'RESULT.json', result)
    print(json.dumps({'status': result['status'], 'frontend': args.frontend, 'run_id': run_id}, ensure_ascii=False), flush=True)
    return 0 if verified else 1


if __name__ == '__main__':
    raise SystemExit(main())
