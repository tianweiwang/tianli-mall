def verify_order_menu_bootstrap(context, result):
    """Read only the exact pre-early four-anchor/A-two-leaf native receipt."""
    def check(value, code):
        expect(value, 'ORDER_MENU_' + code)

    def keys(value, wanted, code):
        check(type(value) is dict and set(value) == set(wanted), code)

    def uint(value, positive=False):
        return type(value) is int and value >= (1 if positive else 0)

    def native_id(value):
        return uint(value, True) and value <= 65535

    def digest(value):
        return type(value) is str and re.fullmatch('[a-f0-9]{64}', value) is not None

    def encode(value, newline=False):
        return (json.dumps(value, ensure_ascii=False, separators=(',', ':'))
                + ('\n' if newline else '')).encode('utf-8')

    def exact(left, right, code):
        # JSON preserves bool/int and native scalar types that Python equality merges.
        check(encode(left) == encode(right), code)

    def source_cell(actual, wanted):
        if type(wanted) is int:
            return ((type(actual) is int and actual == wanted)
                    or (type(actual) is str and actual == str(wanted)))
        return type(actual) is type(wanted) and actual == wanted

    receipt = result['order_menu_fixture']
    keys(receipt, ('schema', 'scope', 'status', 'profile_mode', 'binding', 'source_files',
        'source_sql_sha256', 'source_meta_sha256', 'source_anchor_ids', 'anchor_ids',
        'anchors', 'leaf_receipts', 'preservation_before', 'preservation_after',
        'menus_before', 'retained_menus_after', 'menus_after', 'private_inputs_before',
        'private_inputs_after', 'accounts', 'App_business'), 'EXACT_RECEIPT_KEYS')
    modes = {'NORMAL_A_WITH_READ_LEAVES': 'A', 'UI_B_ANCHORS_ONLY': 'B'}
    check(ORDER_MENU_PROFILE_MODE in modes and type(receipt['schema']) is int
        and receipt['schema'] == 1
        and receipt['scope'] == 'MS4_NATIVE_ORDER_MENU_FIXTURE_BEFORE_EARLY_ONLY'
        and receipt['status'] == 'VERIFIED_MS4_NATIVE_ORDER_MENU_FIXTURE_BEFORE_EARLY_ONLY'
        and receipt['profile_mode'] == modes[ORDER_MENU_PROFILE_MODE]
        and receipt['App_business'] == 'NOT RUN', 'EXACT_SCOPE_AND_FIXED_PROFILE')

    binding = result['binding']
    keys(binding, ('source_sha', 'container_id', 'entry_namespace_pid',
        'created_sha256', 'freeze_sha256'), 'ORIGINAL_INITIALIZER_BINDING')
    check(type(context['run_id']) is str
        and re.fullmatch('[0-9]{14}[a-f0-9]{6}', context['run_id']) is not None
        and type(binding['source_sha']) is str
        and re.fullmatch('[a-f0-9]{40}', binding['source_sha']) is not None
        and binding['source_sha'] == context['source_sha']
        and type(binding['container_id']) is str
        and re.fullmatch('[a-f0-9]{64}', binding['container_id']) is not None
        and type(binding['entry_namespace_pid']) is int and binding['entry_namespace_pid'] == 1
        and digest(binding['created_sha256']) and digest(binding['freeze_sha256'])
        and binding['freeze_sha256'] == context['freeze_sha256'], 'SAME_INITIALIZER_SOURCE_CID_FREEZE')
    exact(receipt['binding'], {'run_id': context['run_id'], **binding},
          'SAME_RUN_AND_ORIGINAL_INITIALIZER_BINDING')

    paths = {
        'help/audit-regressions/isolated/lib/ms4-order-menu-fixture.php',
        'help/audit-regressions/orchestration/order_menu_receipt.py',
        'help/preview/storefront.php',
        'crmeb/public/install/crmeb.sql',
        'help/migrations/marketplace-ms4-order-read-menus.php',
        'help/migrations/marketplace-ms1.php',
    }
    check(type(ORDER_MENU_SOURCE_FILES) is tuple and len(ORDER_MENU_SOURCE_FILES) == 6,
          'FIXED_SIX_SOURCE_ROWS')
    for row in ORDER_MENU_SOURCE_FILES:
        keys(row, ('path', 'bytes', 'sha256'), 'EXACT_SOURCE_PIN')
        check(type(row['path']) is str and uint(row['bytes']) and digest(row['sha256']),
              'TYPED_SOURCE_PIN')
    sources = {row['path']: row for row in ORDER_MENU_SOURCE_FILES}
    check(set(sources) == paths, 'SIX_UNIQUE_EXPECTED_SOURCE_PATHS')
    exact(receipt['source_files'], list(ORDER_MENU_SOURCE_FILES), 'FIXED_ORDERED_SOURCE_PINS')
    freeze_path = Path(context['run_dir']) / 'preparation-source-binding.json'
    frozen = load(freeze_path)
    check(hashlib.sha256(freeze_path.read_bytes()).hexdigest() == context['freeze_sha256']
        and frozen['source_sha'] == context['source_sha'], 'ACTUAL_COMPLETE_FREEZE_BYTES')
    complete = {row['path']: row for row in frozen['source_tree']['files']}
    exact(receipt['source_files'], [complete[row['path']] for row in ORDER_MENU_SOURCE_FILES],
          'SIX_CURRENT_FROZEN_SOURCE_ROWS')
    check(receipt['source_sql_sha256'] == sources['crmeb/public/install/crmeb.sql']['sha256'],
          'EXACT_CAPTURED_INSTALL_SQL')

    names = ('order_root', 'order_page', 'order_info_group', 'order_info')
    meta = ORDER_MENU_SOURCE_META
    keys(meta, ('columns', 'rows'), 'FIXED_SOURCE_META')
    columns = meta['columns']
    check(type(columns) is list and len(columns) == len(set(columns)) == 22
        and all(type(name) is str and re.fullmatch('[a-z_]+', name) is not None for name in columns)
        and columns[0] == 'id', 'TWENTY_TWO_SOURCE_COLUMNS')
    keys(meta['rows'], names, 'FOUR_ORIGINAL_META_ROWS')
    check(list(meta['rows']) == list(names), 'ORIGINAL_META_INSERT_ORDER')
    check(receipt['source_meta_sha256'] == hashlib.sha256(encode(meta, True)).hexdigest(),
          'ACTUAL_SQL_PARSED_META_EQUALS_FIXED_LITERAL')
    for key in ('source_anchor_ids', 'anchor_ids', 'anchors'):
        keys(receipt[key], names, 'EXACT_FOUR_' + key.upper())
        check(list(receipt[key]) == list(names), 'EXACT_ANCHOR_ORDER')
    ids = receipt['anchor_ids']
    check(all(native_id(value) for value in ids.values()) and len(set(ids.values())) == 4,
          'FOUR_DISTINCT_DATABASE_GENERATED_IDS')
    source_ids = {}
    for name in names:
        cells = meta['rows'][name]
        check(type(cells) is list and len(cells) == 22
            and all(value is None or type(value) in (str, int) for value in cells),
            'COMPLETE_TYPED_SOURCE_CELLS')
        expected = dict(zip(columns, cells))
        check(native_id(expected['id']), 'ORIGINAL_SQL_SOURCE_ID')
        source_ids[name] = expected['id']
        expected['id'] = ids[name]
        if name == 'order_page':
            expected['pid'] = ids['order_root']
            expected['path'] = str(ids['order_root'])
        elif name == 'order_info_group':
            expected['pid'] = ids['order_page']
            expected['path'] = str(ids['order_root']) + '/' + str(ids['order_page'])
        elif name == 'order_info':
            expected['pid'] = ids['order_info_group']
        actual = receipt['anchors'][name]
        keys(actual, columns, 'COMPLETE_NATIVE_ANCHOR_COLUMNS')
        check(list(actual) == columns and all(source_cell(actual[key], expected[key])
            for key in columns), 'ONLY_NATIVE_ID_PARENT_AND_PATH_MAPPING')
    check(list(source_ids.values()) == [4, 5, 2562, 2585], 'FOUR_ORIGINAL_SQL_IDENTITIES')
    exact(receipt['source_anchor_ids'], source_ids, 'SOURCE_IDS_NOT_INSERTED_AS_NATIVE_IDS')

    proof_order = ('order_root', 'order_page', 'order_info', 'order_info_group')
    proof = {name: {'id': ids[name], 'sha256': hashlib.sha256(
        encode(receipt['anchors'][name])).hexdigest()} for name in proof_order}
    fresh = {'mode': 'check', 'state': 'applicable', 'changed': False, 'tables': [],
        'menu_ids': [], 'issues': [], 'anchors': proof, 'source_binding': {
            'entry_sha256': sources['help/migrations/marketplace-ms4-order-read-menus.php']['sha256'],
            'ms1_sha256': sources['help/migrations/marketplace-ms1.php']['sha256']},
        'authorization_cache_producer': 'fresh_scope_for_request_exact_all_auth_and_current_roles',
        'roles_modified': False}
    leaves = receipt['leaf_receipts']
    leaf_ids = []
    if receipt['profile_mode'] == 'A':
        keys(leaves, ('precheck', 'apply', 'postcheck', 'reapply'), 'A_ORIGINAL_FOUR_MIGRATION_RECEIPTS')
        keys(leaves['apply'], (*fresh, 'created_menu_ids'), 'A_EXACT_APPLY_KEYS')
        native_leaves = leaves['apply']['menu_ids']
        keys(native_leaves, ('order-list-read', 'order-chart-read'), 'TWO_ORIGINAL_LEAF_IDENTITIES')
        check(list(native_leaves) == ['order-list-read', 'order-chart-read']
            and all(native_id(value) for value in native_leaves.values())
            and len(set(native_leaves.values())) == 2
            and not set(native_leaves.values()).intersection(ids.values()), 'TWO_DISTINCT_NEW_NATIVE_LEAVES')
        complete = {**fresh, 'state': 'completed', 'menu_ids': native_leaves}
        applied = {**complete, 'mode': 'apply', 'changed': True, 'created_menu_ids': native_leaves}
        reapplied = {**complete, 'mode': 'apply', 'created_menu_ids': []}
        exact(leaves, {'precheck': fresh, 'apply': applied, 'postcheck': complete,
            'reapply': reapplied}, 'A_REAL_FRESH_APPLY_CHECK_REAPPLY_EXACT')
        leaf_ids = list(native_leaves.values())
    else:
        keys(leaves, ('precheck',), 'B_CHECK_ONLY_NO_LEAF_MIGRATION')
        exact(leaves, {'precheck': fresh}, 'B_TWO_READ_LEAVES_REMAIN_ABSENT')

    def preservation(value):
        keys(value, ('names', 'tables', 'ddl', 'redis', 'application_modes', 'maintenance_modes',
            'application_grants_sha256', 'maintenance_grants_sha256'), 'COMPLETE_PRESERVATION_KEYS')
        table_names = value['names']
        check(type(table_names) is list and len(table_names) == 166
            and all(type(name) is str and re.fullmatch('(eb_[a-z0-9_]+|audit_sentinel)', name)
                is not None for name in table_names)
            and table_names == sorted(set(table_names)), 'ORIGINAL_166_TABLE_NAMES')
        check(type(value['tables']) is dict and set(value['tables']) == set(table_names) - {'eb_system_menus'}
            and len(value['tables']) == 165
            and {'eb_system_role', 'eb_marketplace_role'} <= set(value['tables']),
            'ALL_165_NONMENU_TABLES_AND_ROLES')
        check(type(value['ddl']) is dict and set(value['ddl']) == set(table_names)
            and all(digest(item) for item in value['ddl'].values()), 'ALL_166_ORIGINAL_DDL')
        for item in value['tables'].values():
            keys(item, ('count', 'sha256'), 'EXACT_PROTECTED_TABLE_DIGEST')
            check(uint(item['count']) and digest(item['sha256']), 'TYPED_PROTECTED_TABLE_DIGEST')
        check(type(value['redis']) is dict and bool(value['redis'])
            and all(digest(key) and digest(item) for key, item in value['redis'].items()),
            'COMPLETE_ORIGINAL_CACHE_DIGESTS')
        for key in ('application_modes', 'maintenance_modes'):
            keys(value[key], ('session_mode', 'global_mode'), 'BOTH_NATIVE_SQL_MODE_KEYS')
            check(all(type(item) is str for item in value[key].values()), 'BOTH_NATIVE_SQL_MODE_STRINGS')
        check(digest(value['application_grants_sha256'])
            and digest(value['maintenance_grants_sha256']), 'BOTH_ORIGINAL_GRANT_DIGESTS')

    preservation(receipt['preservation_before'])
    preservation(receipt['preservation_after'])
    exact(receipt['preservation_before'], receipt['preservation_after'],
          'OLD_TABLES_ROLES_DDL_CACHE_MODES_GRANTS_UNCHANGED')
    for key in ('menus_before', 'retained_menus_after', 'menus_after'):
        keys(receipt[key], ('count', 'sha256'), 'EXACT_MENU_ROW_DIGEST')
        check(uint(receipt[key]['count']) and digest(receipt[key]['sha256']), 'TYPED_MENU_ROW_DIGEST')
    exact(receipt['menus_before'], receipt['retained_menus_after'], 'EVERY_OLD_MENU_ROW_RETAINED')
    check(receipt['menus_after']['count'] == receipt['menus_before']['count'] + 4 + len(leaf_ids),
          'ONLY_FOUR_OR_SIX_NEW_MENU_ROWS')
    for key in ('private_inputs_before', 'private_inputs_after'):
        keys(receipt[key], ('migrator', 'app'), 'EXACT_TWO_ORIGINAL_PRIVATE_INPUTS')
        for item in receipt[key].values():
            keys(item, ('dev', 'ino', 'bytes', 'mode', 'sha256'), 'EXACT_PRIVATE_NATIVE_PIN')
            check(uint(item['dev']) and uint(item['ino'], True) and uint(item['bytes'], True)
                and item['mode'] == '0600' and digest(item['sha256']), 'TYPED_PRIVATE_NATIVE_PIN')
        check((receipt[key]['migrator']['dev'], receipt[key]['migrator']['ino'])
            != (receipt[key]['app']['dev'], receipt[key]['app']['ino']), 'DISTINCT_ORIGINAL_PRIVATE_INPUTS')
    exact(receipt['private_inputs_before'], receipt['private_inputs_after'],
          'ORIGINAL_PRIVATE_CONFIGURATIONS_UNCHANGED')
    exact(receipt['accounts'], {'application': 'audit_app', 'maintenance': 'audit_migrator'},
          'ORIGINAL_SEPARATE_ACCOUNTS')
