"""Root ordinary single-frontend build. Draft qualification blocks before writes."""
from pathlib import Path
import argparse
import json
import re
import sys

sys.dont_write_bytecode = True
sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_common import (RUN_PARENT, qualification, require, regular, put,
                          repo_snapshot, preserved, identities, native9, dist_tree)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--frontend', choices=['admin', 'merchant', 'h5'], required=True)
    parser.add_argument('--pins-sha256', required=True)
    parser.add_argument('--run-id', required=True)
    args = parser.parse_args()
    q = qualification(args.pins_sha256)
    require(re.fullmatch('[0-9]{14}[a-f0-9]{8}', args.run_id) is not None, 'ROOT_FRESH_RUN_ID')
    RUN_PARENT.mkdir(parents=True, exist_ok=True)
    regular(RUN_PARENT, True)
    out = RUN_PARENT / (args.frontend + '-' + args.run_id)
    require(not out.exists() and not out.is_symlink(), 'NEVER_RESUME_OR_OVERWRITE_BUILD_RUN')
    out.mkdir()
    evidence = out / 'evidence'
    evidence.mkdir()
    context = {'qualification': q, 'out': out, 'evidence': evidence, 'run_id': args.run_id, 'native': []}
    put(out / 'ADMISSION.json', {'schema': 1, 'scope': 'ROOT_ORDINARY_BUILD_ADMITTED_NOT_COMPLETE',
                               'frontend': args.frontend, 'run_id': args.run_id, 'head': q['head'],
                               'source_repo': q['source_repo'], 'validation_repo': q['validation_repo'],
                               'source_authority': q['source_authority'], 'full_tree': q['full_tree']})
    try:
        initial = repo_snapshot(context, 'before')
        if args.frontend == 'h5':
            import h5_build
            result = h5_build.run(context)
        else:
            import windows_build
            result = windows_build.run(context, args.frontend)
        manifest = result.pop('dist')
        dist_root = Path(result['dist_root'])
        dist_names = [row['path'] for row in manifest['files']]
        dist_identities = {'root': native9(dist_root), 'files': identities(dist_root, dist_names),
                           'directories': identities(dist_root, manifest['directories'])}
        preserved(context, initial)
        require(dist_tree(dist_root) == manifest
                and dist_identities == {'root': native9(dist_root), 'files': identities(dist_root, dist_names),
                                        'directories': identities(dist_root, manifest['directories'])},
                'COMPLETE_DIST_BYTES_NAMESPACE_MODES_AND_NATIVE9_STILL_EXACT')
        put(out / 'DIST-TREE.json', manifest)
        put(out / 'DIST-NATIVE-IDENTITIES.json', dist_identities)
        result.update(schema=1, status='VERIFIED_ORDINARY_OFFLINE_BUILD_NOT_UI_OR_DEPLOYMENT',
                      run_id=args.run_id, full_source_and_val_preserved=True,
                      dist_manifest='DIST-TREE.json', native_operations=context['native'],
                      final_public_copy_four_manifests='NOT_CREATED_BY_THIS_DRIVER',
                      business_UI_devices='NOT_RUN', commit_push_deploy=False)
        put(out / 'BUILD-REPORT.json', result)
        print(json.dumps({'status': result['status'], 'frontend': args.frontend,
                          'run_id': args.run_id, 'source_head': q['head']}, ensure_ascii=False), flush=True)
        return 0
    except BaseException as error:
        put(out / 'FAILURE.json', {'scope': 'BUILD_FAILED_OR_UNKNOWN_STATE_PRESERVED',
                                  'frontend': args.frontend, 'run_id': args.run_id,
                                  'source_head': q['head'], 'error_class': type(error).__name__,
                                  'native_completed': len(context['native']), 'retry_force_or_cleanup': False,
                                  'build_UI_public_copy': 'INCOMPLETE'})
        raise


if __name__ == '__main__':
    raise SystemExit(main())
