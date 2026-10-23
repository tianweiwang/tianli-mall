def validate_initializer_syntax_admission(value):
    """Read the genuine full118698-byte syntax job; never invoke PHP or Docker."""
    interface = INITIALIZER_SYNTAX_INTERFACE
    need(type(interface) is dict, 'FIXED_REAL_WHOLE_UI_SYNTAX_SOURCE_INTERFACE_REQUIRED')
    source_root = Path(interface['source_root'])
    final = json.loads(exact_pointer(interface['final_PINS']))
    need(type(final['files']) is dict and len(final['files']) == interface['source_member_count'], 'WHOLE_FIXED_SYNTAX_SOURCE_NAMESPACE')
    for name, row in final['files'].items():
        WINDOWS.file(source_root / name, row)
    need(set(tree(source_root)['files']) == set(final['files']) | {'FINAL-PINS.json'}, 'EXACT_WHOLE_SYNTAX_SOURCE_NAMES')
    declared = json.loads(exact_pointer(interface['RESULT_INTERFACE']))
    need(declared == interface['declared'], 'REAL_FIXED_SOURCE_DEFINED_RESULT_INTERFACE')
    manifest = json.loads(exact_pointer(interface['manifest']))
    need(manifest == interface['manifest_expected'], 'REAL_FIXED_SOURCE_DEFINED_WHOLE_LEAF_MANIFEST')
    exact_pointer(interface['run'])
    exact_pointer(interface['launch'])
    exact_pointer(interface['CODE_PINS'])
    output = Path(declared['actual_directory'])
    need(type(value) is dict and set(value) == {'path', 'bytes', 'sha256'}
        and value['path'] == (output / 'RESULT.json').as_posix(), 'REAL_FIXED_SYNTAX_RESULT_POINTER_ONLY')
    result = json.loads(exact_pointer(value))
    need(set(result) == set(declared['result_exact_keys']) and len(result) == 20
        and result['status'] == declared['pass_status'] and result['source_sha'] == HEAD, 'REAL_FULL_UI_SYNTAX_PASS_TWENTY_KEYS')
    need(result['candidate'] == INITIALIZER_LEAF == manifest['candidate'] == declared['candidate']
        and result['copy'] == manifest['copy'] == declared['copy'], 'REAL_ORIGINAL_R1_POINTER_FULL118698_SOURCE_COPY')
    need(result['leaf_preparation_PINS'] == manifest['leaf_preparation_PINS']
        and result['author_preparation_PINS'] == manifest['author_preparation_PINS']
        and result['candidate_source'] == interface['manifest']
        and result['job_code_PINS'] == interface['CODE_PINS'], 'REAL_SYNTAX_LEAF_AUTHOR_JOB_SOURCE_PINS')
    for row in [result['candidate'], result['copy'], result['leaf_preparation_PINS'], result['author_preparation_PINS']]:
        exact_pointer(row)
    need(type(result['php_l_count_required']) is int and result['php_l_count_required'] == 1
        and type(result['container_native_exit']) is int and result['container_native_exit'] == 0,
        'ONE_WHOLE_UI_LEAF_PHP_L_REAL_CONTAINER_ZERO')
    need(result['own_label_before_after_empty'] is True and result['ordinary_own_container_removed'] is True
        and result['all_input_windows_ended'] is True and result['initializer_or_M_S_App_business_execution'] == 'NOT RUN'
        and type(result['W_Val_Run_mutations']) is int and result['W_Val_Run_mutations'] == 0, 'SYNTAX_ONLY_ORIGINAL_CLOSE_WINDOW_BOUNDARIES')
    need(type(result['started_ns']) is int and type(result['ended_ns']) is int
        and 0 < result['started_ns'] < result['ended_ns'], 'REAL_SYNTAX_NATIVE_WINDOW')
    made = json.loads(WINDOWS.file(output / 'outer.created.json'))
    ended = json.loads(WINDOWS.file(output / 'outer.terminal.json'))
    need(set(made) == set(declared['outer_created_exact_keys'])
        and set(ended) == set(declared['outer_terminal_exact_keys']), 'ORIGINAL_SYNTAX_OUTER_CREATED3_TERMINAL10')
    need(all(made[key] == ended[key] for key in ['pid', 'argv', 'started_ns'])
        and type(made['pid']) is int and made['pid'] > 0 and made['argv'] == interface['true_run_argv'], 'ORIGINAL_REAL_SYNTAX_SAME_PID_ARGV')
    need(type(ended['native_exit']) is int and type(ended['wait_returncode']) is int
        and ended['native_exit'] == ended['wait_returncode'] == 0
        and type(ended['finished_ns']) is int and 0 < made['started_ns'] < ended['finished_ns'], 'ORIGINAL_SYNTAX_OUTER_NATIVE_WAIT_ZERO')
    for key in ['stdout', 'stderr']:
        raw = WINDOWS.file(output / ('outer.' + key), {'bytes': ended[key + '_bytes'], 'sha256': ended[key + '_sha256']})
        if key == 'stderr':
            need(raw == b'', 'ORIGINAL_SYNTAX_OUTER_COMPLETE_EMPTY_STDERR')
    authority = result['pre_execution_authority']
    need(type(authority) is dict and set(authority) == set(declared['pre_execution_authority_exact_keys']), 'REAL_SYNTAX_PRE_EXECUTION_AUTHORITY_TEN')
    s = original_outcome(NORMAL_SOURCE.parent / 'launcher/normal-start')
    outcome(s, expected_pid=OUTER_PID)
    launcher = NORMAL_SOURCE.parent / 'launcher/launch.py'
    expected_authority = {'authorization': 'EXPLICIT_ROOT_OPERATOR_CLI_AFTER_ENDED_S',
        'required_flag': '--authorize-ended-S17155-root-admitted', 'original_tool_session': OUTER_SESSION,
        'original_S_pid': OUTER_PID, 'source_launcher': pointer(launcher, WINDOWS.file(launcher, ORIGINAL_LAUNCHER)),
        'created': s['created'], 'terminal': s['terminal'], 'original_S_native_exit': 0,
        'original_S_wait_returncode': 0, 'raw_child_streams_read': False}
    need(authority == expected_authority, 'GENUINE_ALREADY_ENDED_ORIGINAL_S0_NOT_SOURCE_CLAIM_OR_OLD_A_SYNTAX')
    cid = result['container_id']
    need(type(cid) is str and re.fullmatch('[a-f0-9]{64}', cid) is not None, 'REAL_SYNTAX_OWN_CONTAINER_ID')
    docker, image, label = interface['DOCKER'], interface['IMAGE'], interface['LABEL']
    candidate_parent = source_root / 'candidates'
    expected_args = [
        ['ps', '-a', '-q', '--no-trunc', '--filter', 'label=' + label],
        ['image', 'inspect', '--format', '{{.Id}}', image],
        ['create', '--pull', 'never', '--name', interface['CONTAINER_NAME'], '--label', label,
            '--network', 'none', '--read-only', '--user', '65534:65534', '--cap-drop', 'ALL', '--security-opt',
            'no-new-privileges:true', '--memory', '128m', '--pids-limit', '32', '--tmpfs',
            '/tmp:rw,nosuid,nodev,size=16m,mode=1777', '--mount', 'type=bind,source=' + candidate_parent.as_posix() + ',target=/candidate,readonly',
            '--entrypoint', '/bin/sh', image, '-c', 'php -l /candidate/ms4-initialize.php'],
        ['start', '-a', cid], ['wait', cid],
        ['inspect', '--format', '{"id":{{json .Id}},"image":{{json .Image}},"state":{{json .State}},"restart":{{json .RestartCount}},"labels":{{json .Config.Labels}},"network":{{json .HostConfig.NetworkMode}},"readonly":{{json .HostConfig.ReadonlyRootfs}},"user":{{json .Config.User}},"mounts":{{json .Mounts}}}', cid],
        ['rm', cid], ['ps', '-a', '-q', '--no-trunc', '--filter', 'label=' + label]]
    stems = ['01-own-label-before', '02-existing-fixed-image', '03-create-one-owned-lint-container',
        '04-attach-original-php-l', '05-same-cid-native-wait', '06-same-cid-native-terminal',
        '07-ordinary-exact-owned-cid-rm', '08-own-label-after']
    operations = result['operations']
    need(type(operations) is list and len(operations) == declared['native_operations_required'] == 8, 'ALL_ORIGINAL_EIGHT_NATIVE_SYNTAX_COMMANDS')
    stdout = []
    names = {'outer.created.json', 'outer.terminal.json', 'outer.stdout', 'outer.stderr', 'RESULT.json', 'INPUTS.json'}
    for operation, args, stem in zip(operations, expected_args, stems):
        need(type(operation) is dict and set(operation) == set(declared['native_terminal_exact_keys'])
            and operation['stem'] == stem and operation['argv'] == [docker, *args], 'FULL_ORIGINAL_SYNTAX_OPERATION_SCHEMA_ARGV_ORDER')
        created = json.loads(WINDOWS.file(output / (stem + '.created.json')))
        terminal = json.loads(WINDOWS.file(output / (stem + '.terminal.json')))
        need(set(created) == set(declared['native_created_exact_keys']) and terminal == operation
            and created == {key: operation[key] for key in ['pid', 'argv', 'started_ns']}, 'ORIGINAL_SYNTAX_NATIVE_CREATED_TERMINAL_FULL_JOIN')
        need(type(operation['pid']) is int and operation['pid'] > 0 and type(operation['native_exit']) is int
            and type(operation['wait_returncode']) is int and operation['native_exit'] == operation['wait_returncode'] == 0
            and type(operation['started_ns']) is int and type(operation['finished_ns']) is int
            and 0 < operation['started_ns'] < operation['finished_ns'], 'ALL_EIGHT_REAL_NATIVE_PIDS_WAIT_EXIT_ZERO')
        stdout.append(WINDOWS.file(output / (stem + '.stdout'), operation['stdout']))
        need(WINDOWS.file(output / (stem + '.stderr'), operation['stderr']) == b'', 'ALL_EIGHT_COMPLETE_EMPTY_STDERR')
        names |= {stem + suffix for suffix in ['.created.json', '.terminal.json', '.stdout', '.stderr']}
    need(stdout[0] == stdout[7] == b'' and stdout[1] == (image + '\n').encode()
        and stdout[2] == stdout[6] == (cid + '\n').encode()
        and stdout[3] == b'No syntax errors detected in /candidate/ms4-initialize.php\n'
        and stdout[4] == b'0\n', 'GENUINE_EIGHT_NATIVE_SYNTAX_FULL_STREAM_CONTENT')
    inspected = json.loads(stdout[5])
    need(inspected['id'] == cid and inspected['image'] == image and inspected['state']['Status'] == 'exited'
        and inspected['state']['ExitCode'] == 0 and inspected['state']['Pid'] == 0 and inspected['restart'] == 0
        and inspected['network'] == 'none' and inspected['readonly'] is True and inspected['user'] == '65534:65534'
        and inspected['labels']['codex.ms4.syntax'] == label.split('=', 1)[1], 'REAL_FIXED_PHP82_OWN_CONTAINER_READONLY_NONROOT_NETWORK_NONE')
    mounts = inspected['mounts']
    need(type(mounts) is list and len(mounts) == 1 and mounts[0]['Destination'] == '/candidate'
        and mounts[0]['Type'] == 'bind' and mounts[0]['RW'] is False
        and mounts[0]['Source'].replace('\\', '/') == candidate_parent.as_posix(), 'REAL_ONE_WHOLE_UI_LEAF_READONLY_BIND')
    inputs = json.loads(WINDOWS.file(output / 'INPUTS.json'))
    need(set(inputs) == set(declared['INPUTS_exact_keys']) and inputs['all_windows_ended'] is True
        and inputs['ended_ns'] == result['ended_ns'] and type(inputs['files']) is list, 'REAL_SYNTAX_ALL_SOURCE_INPUT_WINDOWS_ENDED')
    seen = set()
    for observed in inputs['files']:
        need(set(observed) == {'path', 'pin', 'named_before', 'named_after', 'fd_open', 'fd_after', 'named_birthtime_ns', 'fd_birthtime_ns'}, 'EXACT_ORIGINAL_SYNTAX_FINITE_ROW')
        need(all(type(observed[key]) is list and len(observed[key]) == 9 and all(type(v) is int for v in observed[key])
            for key in ['named_before', 'named_after', 'fd_open', 'fd_after']), 'ALL_SYNTAX_SOURCE_TYPED_NINE_FIELDS')
        need(observed['path'] not in seen and observed['named_before'] == observed['named_after']
            and observed['fd_open'] == observed['fd_after'] and all(observed['named_before'][i] == observed['fd_open'][i] for i in [0, 1, 6, 7])
            and observed['named_birthtime_ns'] == observed['fd_birthtime_ns'], 'ALL_SYNTAX_SOURCE_OWN_NINE_CROSS_FOUR_BIRTHTIME_WINDOWS')
        WINDOWS.file(Path(observed['path']), observed['pin'])
        need(native9(Path(observed['path'])) == observed['named_before']
            and Path(observed['path']).stat(follow_symlinks=False).st_birthtime_ns == observed['named_birthtime_ns'], 'ORIGINAL_SYNTAX_FULL_SOURCE_INPUT_STILL_IDENTICAL')
        seen.add(observed['path'])
    state = tree(output)
    need(set(state['files']) == names and state['directories'] == [] and len(names) == 38, 'EXACT_ORIGINAL_ALL38_SYNTAX_PUBLIC_OUTPUT_FILES')
    return value
