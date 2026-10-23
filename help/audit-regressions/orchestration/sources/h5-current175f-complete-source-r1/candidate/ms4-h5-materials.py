"""Create finite H5 input manifests from actual local sources; no Run or service starts."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import runpy
import subprocess

PREP = Path(__file__).resolve().parent
BUILD = Path('H:/天俪商城/artifacts/u-status-h5-r2')
BUILD_REPORT_SHA = 'ddf15c750505567095467cd55b4d16b0084236b21a56783d5fe93ecc717d9344'
OLD_HEAD = '2830b3ce273f5cbc0a951e9bf29377d55864cf93'
BUILD_REFERENCE_PINS = {'BUILD-REPORT.json': {'bytes': 3815, 'sha256': 'ddf15c750505567095467cd55b4d16b0084236b21a56783d5fe93ecc717d9344'}, 'MAIN-H5-INPUTS.json': {'bytes': 137701, 'sha256': '034349ae19bca8675a4a002290c86937de70710a428231fb8f451d1d15aca445'}, 'TOOL-INPUTS.json': {'bytes': 6119646, 'sha256': 'e89b24e803cec932b34115e1c10881865fed53d7fd33eca5dc0dae4795b36dfd'}, 'SOURCE-CANDIDATE.json': {'bytes': 295211, 'sha256': '1a884d8baf5deeb26cb4b007a4aacc25929c0ff5245d16f1d2ccdafd70edc9fe'}, 'COMPILER-NATIVE.json': {'bytes': 1806, 'sha256': '8e60ba3dae6bdc8b3b61c15692d867cb15cf0aa453fbdbabe0ad02ac2eaec166'}, 'NATIVE-TOOL-CONTRACT.json': {'bytes': 5074, 'sha256': 'de6ad546063c7299f83ebc953e6c34a1de44a64d37743d312d27f8f35bec0bbc'}, 'DIST-MANIFEST.json': {'bytes': 62116, 'sha256': '922e3315fd5f0982743d1dbb060c34cec6af1044511518ae338553339db118ab'}, 'input-checks.py': {'bytes': 6650, 'sha256': 'a39404aac605a96c510b1b5d8e64945fed29c4438e75a20940cf4d9d7d141063'}, 'verify-build.py': {'bytes': 7507, 'sha256': 'ae8eceb9b5dbf296118dd5db4daaaa9cea2e6b78b168a29ff9729605ad77064d'}, 'build-native.py': {'bytes': 3462, 'sha256': '28b90235bc6c5883d8532f308472d7142ec2fc4ce0b6535b7dd6ccef1e1530f6'}, 'native-explicit-pass.cjs': {'bytes': 12286, 'sha256': '045996c2a2e623455456335d0eddbd77c6b5ca241e99b1964364d0fcfef53bb3'}, 'logs/adapter-receipt.json': {'bytes': 3206, 'sha256': 'd5680d4ea2285d83e90b0ed753b5d5706b92371491464450df9ab5881feef5a1'}, 'logs/compiler.log': {'bytes': 1383, 'sha256': 'f9109dc78ff3d1dfcc733061f45bcfdda129173b33627b197e8fa27f974b3bd6'}, 'bootstrap-head.native.json': {'bytes': 424, 'sha256': '2d2bfe57bd6e8eb4a05995ecc6eb23075ea7c280bc9596ac9717a6cda54c3933'}, 'bootstrap-head.stdout': {'bytes': 41, 'sha256': 'c1a853d43d99a6285295d28573c0b504e3307cfb05c2e3b4abbae7ced165cc35'}, 'bootstrap-head.stderr': {'bytes': 0, 'sha256': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}, 'bootstrap-status.native.json': {'bytes': 455, 'sha256': '630ff33517a117213411a7c22b530d34785401543b1b913a34e537a556af8c52'}, 'bootstrap-status.stdout': {'bytes': 0, 'sha256': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}, 'bootstrap-status.stderr': {'bytes': 0, 'sha256': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}, 'bootstrap-source-files.native.json': {'bytes': 480, 'sha256': '29f66e5fc678a12da74fa842ec3f269f3530b4beceff03e81b2fa06effe6ef25'}, 'bootstrap-source-files.stdout': {'bytes': 28172, 'sha256': '6cefe036a59de80cd26782a63f0c904d25bc501fed129579d6e00a5d63ea339a'}, 'bootstrap-source-files.stderr': {'bytes': 0, 'sha256': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}, 'bootstrap-source-stages.native.json': {'bytes': 491, 'sha256': 'ffcec415592c0d8a86665ac127c0d05a363743567ba2f222333a0bbb8402d5f8'}, 'bootstrap-source-stages.stdout': {'bytes': 55972, 'sha256': 'bb6e705ddfb6060bf9c9d1abefa92321de778e674005a87f93ac640b8f74dd02'}, 'bootstrap-source-stages.stderr': {'bytes': 0, 'sha256': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}, 'clone-tools.native.json': {'bytes': 72, 'sha256': '1e75fb53fc4ed573d8e4e02ac2f871bd184e83841f9dc602010e18ede31fd4c5'}, 'clone-tools.stdout': {'bytes': 90, 'sha256': 'd97e404219ada84ff22d6791c9dce2d10d1c4ff722607f58574299e31875ccc9'}, 'clone-tools.stderr': {'bytes': 0, 'sha256': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}, 'prepare-source.native.json': {'bytes': 73, 'sha256': 'a6bf3a4b33bab9344c4344c7617904a84a927d7c7d938423131678f40113438a'}, 'prepare-source.stdout': {'bytes': 251, 'sha256': '01a91a5daf9a94c4ce1c6ca787b4ac9d3afd3a8ce20263b7a82ab7b67b3ee697'}, 'prepare-source.stderr': {'bytes': 0, 'sha256': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}, 'prepare-adapter.native.json': {'bytes': 73, 'sha256': '9a7edddcad6678e30477e7fc9ac489dca7aceba755302f43b7fb1b55205036b3'}, 'prepare-adapter.stdout': {'bytes': 256, 'sha256': '563bbfcc5d7074fb6fcca0807d73f05cfe76b0eb7cce5679541df29ff1e5f36d'}, 'prepare-adapter.stderr': {'bytes': 0, 'sha256': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}, 'build-native.native.json': {'bytes': 71, 'sha256': 'a6e1d91996dfadbf03c6203d4d96cdaa627ddd79cd5e59fae45c0779105b7c1b'}, 'build-native.stdout': {'bytes': 241, 'sha256': 'd9e4f82c7a7d9504d364d0b7d2baa70d4a07ca8e4c89403752447611fd743dba'}, 'build-native.stderr': {'bytes': 0, 'sha256': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}, 'verify-build.native.json': {'bytes': 73, 'sha256': 'd404f0ece959936cf2464336084b3daf78b438304d42979b8ba0a5d0a96ddfae'}, 'verify-build.stdout': {'bytes': 620, 'sha256': '4fa42763987d989841b86aaf2d10f7bed40476884f6e5d97473eba0647f8b734'}, 'verify-build.stderr': {'bytes': 0, 'sha256': 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'}, 'root-run-build.py': {'bytes': 8099, 'sha256': '4856bc3a135cb3ff05abbd63cceca0554746c2659451d89a72c4f8e373288e00'}, 'prepare-source.py': {'bytes': 1522, 'sha256': '47f8626200bab7ff1c9ac6d9f660dabea24a8bc9d1d6e43fae6e7763c7fb85f0'}, 'clone-tools.ps1': {'bytes': 1907, 'sha256': 'ff0c3d06ccfa5e64c38d89337f2e48e6311bdc6e3938d2d2510e5336d2a14e40'}, 'prepare-native-adapter.py': {'bytes': 2386, 'sha256': '78821656e45c5e345e62538576fa116ac13484d2b15683986b06cf6010f0be78'}}
INPUTS = {
    'language': {'path': '/run/ms4-prep/inputs/language.json', 'bytes': 318204,
                 'sha256': '9e6aab1cf67ea492d137b12052220b0e710fb7fe16f3b5f93135a6693945c716'},
    'theme': {'path': '/run/ms4-prep/inputs/theme.json', 'bytes': 102429,
              'sha256': '8f879a5f7775d39cf190023aca1ea49e7cacf7a680277e10a0712d0796ba1bc1'},
}


def require(ok, code):
    if not ok:
        raise RuntimeError(code)


def fp(raw):
    return {'bytes': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def read(path):
    require(path.is_file() and not path.is_symlink(), 'REGULAR_SOURCE')
    return path.read_bytes()


def load(path):
    return json.loads(read(path))


def git(repo, *args):
    env = dict(os.environ, GIT_OPTIONAL_LOCKS='0')
    process = subprocess.run(['git', '-c', 'safe.directory=' + repo.as_posix(), '-C', str(repo),
                              *args], capture_output=True, env=env)
    require(process.returncode == 0 and process.stderr == b'', 'NATIVE_GIT_ZERO')
    return process.stdout


def clean(repo, head):
    require(git(repo, 'rev-parse', 'HEAD').decode().strip() == head
            and git(repo, 'status', '--porcelain=v1') == b'', 'SAME_CLEAN_SOURCE')


def tree(root):
    require(root.is_dir() and not root.is_symlink(), 'REGULAR_TREE_ROOT')
    files, directories = [], []
    for folder, names, leaves in os.walk(root, followlinks=False):
        for name in sorted(names):
            path = Path(folder) / name
            require(path.is_dir() and not path.is_symlink(), 'REGULAR_DIRECTORY')
            directories.append(path.relative_to(root).as_posix())
        for name in sorted(leaves):
            path = Path(folder) / name
            files.append({'path': path.relative_to(root).as_posix(), **fp(read(path))})
    return {'schema': 1, 'files': sorted(files, key=lambda row: row['path']),
            'directories': sorted(directories)}


def build_reuse(repo, head):
    """Recheck bytes against the actual prior build, retaining that build's original SHA."""
    clean(repo, head)
    report_raw = read(BUILD / 'BUILD-REPORT.json')
    require(fp(report_raw)['sha256'] == BUILD_REPORT_SHA, 'ORIGINAL_BUILD_REPORT_BYTES')
    report = json.loads(report_raw)
    require(report['status'] == 'H5_REAL_BUILD_AND_STATIC_RESOURCE_GATE_PASS'
            and report['main_head'] == OLD_HEAD and report['compiler_native_exit'] == 0
            and report['final_gate_native_exit'] == 0 and report['source_input_count'] == 556
            and report['H5_input_count'] == 556 and report['backend_source_only_count'] == 0,
            'ACTUAL_ORIGINAL_BUILD_RESULT')
    # Pin the original reader before definitions-only loading; its three parameters describe this completed build,
    # never the current checkout. Do not run verify_frozen_inputs (which requires the historical checkout HEAD).
    for name, wanted in BUILD_REFERENCE_PINS.items():
        require(fp(read(BUILD / name)) == wanted, 'ACTUAL_ORIGINAL_BUILD_REFERENCE_BYTES')
    parameters = {'MS4_H5_EXPECTED_ROOT': BUILD.as_posix(), 'MS4_H5_EXPECTED_HEAD': OLD_HEAD,
                  'MS4_H5_SOURCE_FREEZE_SHA': report['source_freeze']['sha256']}
    saved = {key: os.environ.get(key) for key in parameters}
    try:
        os.environ.update(parameters)
        checks = runpy.run_path(str(BUILD / 'input-checks.py'))
    finally:
        for key, value in saved.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value
    frozen, tools = checks['manifests']()
    require(checks['HEAD'] == OLD_HEAD and len(frozen['files']) == 556, 'HISTORICAL_BUILD_IDENTITY_RETAINED')
    bootstrap = ['bootstrap-head', 'bootstrap-status', 'bootstrap-source-files', 'bootstrap-source-stages']
    children = ['clone-tools', 'prepare-source', 'prepare-adapter', 'build-native', 'verify-build']
    pids = set()
    for label in bootstrap + children:
        receipt = load(BUILD / (label + '.native.json'))
        stdout, stderr = read(BUILD / (label + '.stdout')), read(BUILD / (label + '.stderr'))
        wanted = {'pid', 'argv', 'native_exit', 'stdout', 'stderr'} if label in bootstrap else {'pid', 'native_exit', 'stdout_bytes', 'stderr_bytes'}
        require(type(receipt) is dict and set(receipt) == wanted and type(receipt['pid']) is int
                and receipt['pid'] > 0 and receipt['pid'] not in pids and type(receipt['native_exit']) is int
                and receipt['native_exit'] == 0 and stderr == b'', 'REAL_NINE_ORIGINAL_NATIVE_TERMINALS')
        pids.add(receipt['pid'])
        if label in bootstrap:
            require(receipt['stdout'] == fp(stdout) and receipt['stderr'] == fp(stderr)
                    and receipt['argv'][:2] == ['git', '-c'], 'REAL_BOOTSTRAP_ARGV_AND_FULL_STREAMS')
        else:
            require(type(receipt['stdout_bytes']) is int and type(receipt['stderr_bytes']) is int
                    and receipt['stdout_bytes'] == len(stdout) and receipt['stderr_bytes'] == 0,
                    'REAL_FIVE_BUILD_STAGE_FULL_STREAMS')
        if label == 'bootstrap-head':
            require(stdout == (OLD_HEAD + '\n').encode('ascii'), 'REAL_BOOTSTRAP_ORIGINAL_HEAD')
        elif label == 'bootstrap-status':
            require(stdout == b'', 'REAL_BOOTSTRAP_ORIGINAL_CLEAN')
    adapter = load(BUILD / 'logs/adapter-receipt.json')
    require(adapter['status'] == 'SECOND_PASS_OBSERVED' and adapter['factory_count'] == 1
            and [row['pass'] for row in adapter['passes']] == [1, 2]
            and all(row['has_errors'] is False for row in adapter['passes'])
            and adapter['after_emit_passes'] == [2] and len(adapter['after_emit_inventories']) == 1
            and adapter['callback_count'] == 1 and adapter['first_callback_success'] is True
            and adapter['purged_module_count'] == 8 and adapter['explicit_invalidations'] == 1
            and adapter['phase_counts']['failed'] == 0 and adapter['native_failure_exit_requested'] is False
            and report['real_two_passes'] == adapter['passes'] and report['warnings']['suppressed'] is False,
            'ORIGINAL_TWO_REAL_PASSES_AND_UNSUPPRESSED_WARNINGS')
    paths = sorted(filter(None, git(repo, '-c', 'core.quotepath=false', 'ls-files', '-z', '--',
                                    'template/uni-app').decode('utf-8').split('\0')))
    require(paths == sorted(row['path'] for row in frozen['files']), 'ALL_CURRENT_FRONTEND_PATHS')
    stages = filter(None, git(repo, '-c', 'core.quotepath=false', 'ls-files', '--stage', '-z', '--',
                             'template/uni-app').decode('utf-8').split('\0'))
    blobs = {}
    for item in stages:
        meta, path = item.split('\t', 1)
        mode, blob, stage = meta.split()
        require(mode in ['100644', '100755'] and stage == '0' and path not in blobs,
                'ONE_TRACKED_FRONTEND_BLOB')
        blobs[path] = blob
    require(blobs == {row['path']: row['git_blob'] for row in frozen['files']},
            'ALL_CURRENT_FRONTEND_GIT_BLOBS')
    checks['row_check'](repo, frozen['files'])
    checks['row_check'](BUILD / 'source', frozen['files'])
    copied_source = tree(BUILD / 'source')['files']
    require(copied_source == [{key: row[key] for key in ['path', 'bytes', 'sha256']}
                             for row in frozen['files']], 'COMPLETE_PRIOR_BUILD_SOURCE_COPY')
    expected_tools = sorted(tools['files'], key=lambda row: row['path'])
    for root in [Path(tools['source_root']), BUILD / 'toolchain/plugins']:
        require(checks['tree'](root, tools['parts'], tools['excluded_directory_names'],
                               tools['forbidden_private_names']) == expected_tools,
                'FULL_ORIGINAL_AND_CLONED_TOOL_BYTES')
    require(fp(read(Path(tools['node']['path']))) == {key: tools['node'][key]
                                                    for key in ['bytes', 'sha256']},
            'ACTUAL_PINNED_NODE_BYTES')
    old = checks['OLD']
    for row in tools['original_files']:
        raw = read(old / row['path'])
        require(fp(raw) == {key: row[key] for key in ['bytes', 'sha256']}
                and read(BUILD / 'original' / row['path']) == raw, 'ORIGINAL_BUILD_HELPER_BYTES')
    checks['verify_adapter']()
    native = load(BUILD / 'COMPILER-NATIVE.json')
    require(native['compiler_exit'] == 0 and native['main_head'] == OLD_HEAD
            and native['source_manifest'] == fp(read(BUILD / 'SOURCE-CANDIDATE.json'))
            and native['full_source_freeze'] == fp(read(BUILD / 'MAIN-H5-INPUTS.json'))
            and native['full_tool_freeze'] == fp(read(BUILD / 'TOOL-INPUTS.json'))
            and native['adapter_manifest'] == fp(read(BUILD / 'NATIVE-TOOL-CONTRACT.json'))
            and native['compiler_log'] == fp(read(BUILD / 'logs/compiler.log')),
            'ORIGINAL_NATIVE_COMPILER_BINDINGS')
    actual = tree(BUILD / 'dist')
    dist = load(BUILD / 'DIST-MANIFEST.json')
    signature = ''.join(row['path'] + '\0' + str(row['bytes']) + '\0' + row['sha256'] + '\n'
                        for row in actual['files']).encode('utf-8')
    inventory = {'file_count': len(actual['files']),
                 'bytes': sum(row['bytes'] for row in actual['files']),
                 'manifest_sha256': hashlib.sha256(signature).hexdigest()}
    require(actual['files'] == dist['files'] and inventory == report['dist']
            and inventory == {key: dist[key] for key in inventory}, 'ACTUAL_ALL_DIST_BYTES')
    require(read(BUILD / 'BUILD-REPORT.json') == report_raw, 'ORIGINAL_REPORT_NOT_RELABELLED')
    for name, wanted in BUILD_REFERENCE_PINS.items():
        require(fp(read(BUILD / name)) == wanted, 'ALL_ORIGINAL_BUILD_REFERENCES_UNCHANGED_AFTER_REUSE')
    clean(repo, head)
    return actual, {'original_build_head': OLD_HEAD, 'current_head': head,
                    'frontend_files': len(paths), 'immutable_tool_files': len(expected_tools),
                    'all_frontend_paths_blobs_and_bytes_equal': True,
                    'both_full_tool_trees_equal': True, 'original_report': fp(report_raw),
                    'compiler_rerun': False, 'dist': inventory}


def write_new(path, value):
    require(not path.exists() and not path.is_symlink() and path.parent.is_dir(),
            'CREATE_ONLY_OUTPUT')
    raw = (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode('utf-8')
    with path.open('xb') as stream:
        require(stream.write(raw) == len(raw), 'COMPLETE_OUTPUT_WRITE')
        stream.flush()
    require(read(path) == raw, 'EXACT_OUTPUT_READBACK')
    return {'path': '/run/ms4-prep/' + path.relative_to(PREP).as_posix(), **fp(raw)}


def materialize(repo, head):
    clean(repo, head)
    targets = [PREP / 'manifests' / name for name in ['public-source.json', 'h5-dist.json', 'inputs.json']]
    require(all(not path.exists() and not path.is_symlink() for path in targets)
            and not (PREP / 'h5-materials-result.json').exists(), 'NEW_MATERIAL_SET_ONLY')
    original_public = tree(repo / 'crmeb/public')
    require('install.lock' not in {row['path'] for row in original_public['files']}
            and not (repo / 'crmeb/.env').exists(), 'NO_HOST_INSTALL_OR_ENV')
    dist, reuse = build_reuse(repo, head)
    for name, row in INPUTS.items():
        require(fp(read(PREP / 'inputs' / (name + '.json'))) == {key: row[key]
                                                                for key in ['bytes', 'sha256']},
                'ACTUAL_FIXED_INPUT_BYTES')
    require(tree(repo / 'crmeb/public') == original_public, 'FULL_PUBLIC_SOURCE_UNCHANGED')
    clean(repo, head)
    descriptors = {}
    for name, path, value in zip(['public_source', 'h5_dist', 'inputs'], targets,
                                [original_public, dist, {'schema': 1, **INPUTS}]):
        descriptors[name] = write_new(path, value)
    result = {'schema': 1, 'scope': 'CUSTOMER_H5_LOCAL_MATERIAL_BYTES_ONLY',
              'status': 'MATERIALS_CREATED_NOT_RUNTIME', 'source_sha': head,
              'requested_repo_source': repo.as_posix(), 'build_reuse': reuse,
              'manifests': descriptors, 'synthetic_png':
              {'path': '/run/ms4-prep/ms4-order.png', **fp(read(PREP / 'ms4-order.png'))},
              'public_files': len(original_public['files']), 'dist_files': len(dist['files']),
              'Run_App_SQL_HTTP_DOM': 'NOT RUN', 'full_acceptance': False}
    receipt = write_new(PREP / 'h5-materials-result.json', result)
    clean(repo, head)
    return {'status': result['status'], 'source_sha': head, 'public_files': result['public_files'],
            'dist_files': result['dist_files'], 'frontend_files': reuse['frontend_files'],
            'receipt': receipt, 'runtime': 'NOT RUN'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--repo', required=True)
    parser.add_argument('--source', required=True)
    args = parser.parse_args()
    print(json.dumps(materialize(Path(args.repo).resolve(strict=True), args.source), ensure_ascii=False))
