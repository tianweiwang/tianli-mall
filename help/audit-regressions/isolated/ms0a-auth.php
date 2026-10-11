<?php
declare(strict_types=1);

/**
 * MS0a real-HTTP contracts. Preparation uses only synthetic isolated fixtures.
 *
 * admin: POST /adminapi/login {account,pwd}, GET /adminapi/product/product/rule
 * out: POST /outapi/access_token {appid,appsecret}, GET /outapi/category/list
 *      POST /outapi/refresh_token {access_token}
 * kefu: POST /kefuapi/login {account,password}, GET /kefuapi/service/info
 * api: POST /api/login {account,password}, GET /api/userinfo
 * Tokens travel only in Authori-zation: Bearer headers (refresh uses its body).
 * MCP: POST /outapi/mcp, account/password headers, JSON-RPC initialize.
 * set_status: PUT /adminapi/setting/system_out_account/set_status/{id}/{status}.
 *
 * No token, password, key, account name, full response, or row contents are
 * written to evidence. Real login establishes the Redis serialization/key
 * namespace before any test mutates a whitelist record.
 */
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/token-forge.php';

const MS0A_BASE = '9685731dcd116784d38e6bbdb902cec3c0c84975';
const MS0A_CASES = ['A01', 'A09', 'A11', 'A12', 'A13', 'A14', 'A15', 'A16', 'A17', 'A18', 'A19', 'A20', 'A21', 'A23', 'A26', 'A27', 'A10', 'A28', 'A02', 'A03', 'A04', 'A05', 'A06', 'A08', 'A30'];
const MS0A_PROBES = ['admin' => '/adminapi/product/product/rule', 'out' => '/outapi/category/list', 'kefu' => '/kefuapi/service/info', 'api' => '/api/userinfo'];

$GLOBALS['ms0a_only'] = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--only=')) {
        fwrite(STDERR, "Usage: ms0a-auth.php [--only=A10,A20]\n"); exit(1);
    }
    $ids = explode(',', substr($argument, 7));
    if (!$ids || array_diff($ids, MS0A_CASES)) {
        fwrite(STDERR, "Unknown MS0a case selection\n"); exit(1);
    }
    $GLOBALS['ms0a_only'] = $ids;
}

function ms0a_selected(string $id): bool { return !$GLOBALS['ms0a_only'] || in_array($id, $GLOBALS['ms0a_only'], true); }
function ms0a_check(bool $condition, string $description): void
{
    $GLOBALS['ms0a_case']['checks'][] = ['passed' => $condition, 'description' => $description];
}
function ms0a_fingerprint(string $value): string { return substr(hash('sha256', $value), 0, 12); }
function ms0a_response_summary(array $response): array
{
    $json = $response['json'] ?? [];
    return [
        'http' => $response['http'] ?? 0,
        'business_status' => is_numeric($json['status'] ?? null) ? (int)$json['status'] : null,
        'rpc_error' => is_numeric($json['error']['code'] ?? null) ? (int)$json['error']['code'] : null,
        'issued_token' => !empty($json['data']['token']) || !empty($json['data']['access_token']),
        'has_rpc_result' => array_key_exists('result', $json),
        'protected_data_present' => !empty($json['data']),
    ];
}
function ms0a_http(string $method, string $path, ?string $token = null, mixed $body = null, array $headers = []): array
{
    if ($token !== null) { $headers['Authori-zation'] = 'Bearer ' . $token; }
    $response = audit_http($method, 'http://audit-app:8000' . $path, $headers, $body);
    if (isset($GLOBALS['ms0a_case'])) {
        $GLOBALS['ms0a_case']['requests'][] = ['method' => $method, 'path' => $path] + ms0a_response_summary($response);
    }
    return $response;
}
function ms0a_status(array $response): ?int
{
    return is_numeric($response['json']['status'] ?? null) ? (int)$response['json']['status'] : null;
}
function ms0a_assert_response(array $response, int $status): void
{
    ms0a_check(ms0a_status($response) === $status, 'business status must equal ' . $status);
    ms0a_check(($response['http'] ?? 0) > 0 && ($response['http'] ?? 0) < 500, 'HTTP response must not be an uncaught server error');
}
function ms0a_case(string $id, string $subcase, string $expected, string $kind, callable $fn): void
{
    if (!ms0a_selected($id)) { return; }
    audit_case($id, $subcase, 'INTEGRATION', static function () use ($id, $subcase, $expected, $kind, $fn): array {
        $GLOBALS['ms0a_case'] = ['checks' => [], 'requests' => [], 'snapshots' => [], 'db_diff' => [], 'cache_diff' => []];
        try { $fn(); } catch (Throwable $error) {
            ms0a_check(false, 'case preparation or recovery failed: ' . get_class($error));
        }
        $capture = $GLOBALS['ms0a_case'];
        $passed = !in_array(false, array_column($capture['checks'], 'passed'), true) && $capture['checks'] !== [];
        $phase = getenv('AUDIT_PHASE') ?: 'after';
        $classification = $kind;
        if ($phase === 'baseline' && $kind === '待复现风险' && $passed) { $classification = '既有拒绝回归'; }
        $negativeRequests = array_values(array_filter($capture['requests'], static fn(array $request): bool => $request['negative'] ?? false));
        $alreadyDenied = $negativeRequests !== [] && !in_array(false, array_map(static fn(array $request): bool => $request['http'] > 0 && $request['http'] < 500 && ($request['business_status'] !== null && $request['business_status'] !== 200 || $request['rpc_error'] === -32600), $negativeRequests), true);
        $unexpectedCache = array_filter($capture['cache_diff'], static fn(array $diff): bool => !$diff['expected_own_revocation'] && !$diff['documented_limiter']);
        if ($phase === 'baseline' && !$passed && $alreadyDenied && $capture['db_diff'] === [] && $unexpectedCache === []) {
            $classification = '新增契约';
        }
        $failedChecks = array_values(array_filter($capture['checks'], static fn(array $check): bool => !$check['passed']));
        $onlyUniformCodeFailure = $failedChecks !== [] && array_values(array_unique(array_column($failedChecks, 'description'))) === ['business status must equal 401'];
        $existingRefusalCodes = $negativeRequests !== [] && !in_array(false, array_map(static fn(array $request): bool =>
            $request['http'] > 0 && $request['http'] < 500
            && in_array($request['business_status'], [400, 402], true)
            && ($request['protected_data_present'] ?? true) === false
            && ($request['issued_token'] ?? true) === false,
            $negativeRequests), true);
        $statusCodeOnlyRegression = $phase === 'baseline' && $kind === '待复现风险'
            && $onlyUniformCodeFailure && $existingRefusalCodes
            && $capture['db_diff'] === [] && $unexpectedCache === [];
        if ($statusCodeOnlyRegression) { $classification = '既有拒绝回归'; }
        if ($phase !== 'baseline') {
            foreach ($GLOBALS['ms0a_baseline'] ?? [] as $key => $baseline) {
                if ($key === $id . '/' . $subcase) { $classification = $baseline['finding_kind']; break; }
            }
        }
        $counter = ($GLOBALS['ms0a_evidence_counter'] ?? 0) + 1;
        $GLOBALS['ms0a_evidence_counter'] = $counter;
        $reference = 'evidence/' . $phase . '-' . $GLOBALS['ms0a_execution'] . '-' . $id . '-' . $subcase . '-' . $counter . '.json';
        // The evidence is written before the final pass/fail assertion is returned.
        audit_write_json(audit_run_dir() . '/' . $reference, ['case_id' => $id, 'subcase' => $subcase, 'phase' => $phase, 'commit_sha' => getenv('AUDIT_SOURCE_SHA'), 'run_id' => getenv('AUDIT_RUN_ID'), 'expected' => $expected] + $capture);
        $summaries = array_map(static fn(array $request): string => 'HTTP ' . $request['http'] . '/business ' . ($request['business_status'] ?? 'none') . ($request['rpc_error'] !== null ? '/RPC ' . $request['rpc_error'] : ''), $capture['requests']);
        $notes = $phase === 'baseline' ? 'Business source is identical to historical AUDIT_BASE_SHA ' . MS0A_BASE . '; commit_sha records actual tested HEAD.' : '';
        if (!$passed && $phase === 'baseline') { $notes .= ' Baseline actual behavior retained; final contract expectations were not changed.'; }
        if ($statusCodeOnlyRegression) {
            $codes = array_values(array_unique(array_column($negativeRequests, 'business_status')));
            $notes .= ' Existing authentication refusal returns business ' . implode('/', $codes) . ' with no protected data or unapproved side effects. Only the uniform business 401 contract is unmet; this is not an identity bypass.';
        } elseif ($phase === 'baseline' && !$passed && $alreadyDenied && $capture['db_diff'] === [] && $unexpectedCache === []) {
            $notes .= ' Baseline already refuses the negative request; no identity-bypass risk was reproduced. The final status/revocation contract is still unmet.';
        }
        if ($id === 'A28' && $subcase === 'normal-out-login-probe-refresh' && in_array(400, array_column($negativeRequests, 'business_status'), true)) {
            $notes .= ' Old out token is already rejected with business 400; uniform business 401 is unmet. This is not an identity bypass.';
        }
        unset($GLOBALS['ms0a_case']);
        return [
            'status' => $passed ? 'PASS' : 'FAIL', 'expected' => $expected,
            'actual' => implode('; ', $summaries) . '; checks ' . count(array_filter(array_column($capture['checks'], 'passed'))) . '/' . count($capture['checks']) . '; db changes ' . count($capture['db_diff']) . '; cache changes ' . count($capture['cache_diff']),
            'finding_kind' => $classification, 'db_diff' => $capture['db_diff'], 'cache_diff' => $capture['cache_diff'], 'evidence_ref' => $reference, 'notes' => trim($notes),
        ];
    });
}
function ms0a_scan(Redis $redis, string $pattern): array
{
    $cursor = null; $all = [];
    do { foreach ($redis->scan($cursor, $pattern, 500) ?: [] as $key) { $all[$key] = true; } } while ($cursor !== 0);
    return array_keys($all);
}
function ms0a_confirm_session(string $token, string $type, int $id): array
{
    $redis = audit_redis();
    $matches = [];
    foreach (ms0a_scan($redis, '*' . md5($token)) as $key) {
        if ($redis->type($key) !== Redis::REDIS_STRING) { continue; }
        $record = @unserialize((string)$redis->get($key), ['allowed_classes' => false]);
        if (is_array($record) && ($record['type'] ?? null) === $type && (string)($record['uid'] ?? '') === (string)$id && is_string($record['token'] ?? null) && hash_equals($record['token'], $token) && is_numeric($record['exp'] ?? null) && (int)$record['exp'] > 0) {
            $matches[$key] = $record;
        }
    }
    audit_expect(count($matches) === 1, 'real login session is not a unique serialized matching record');
    $key = array_key_first($matches);
    $prefix = substr($key, 0, -32);
    $tagKeys = [];
    foreach (ms0a_scan($redis, $prefix . '*' . md5($type)) as $candidate) {
        if ($redis->type($candidate) === Redis::REDIS_SET && in_array($key, $redis->sMembers($candidate), true)) { $tagKeys[] = $candidate; }
    }
    audit_expect(count($tagKeys) === 1, 'real login session tag set was not confirmed');
    $tagPrefix = substr($tagKeys[0], strlen($prefix), -32);
    if (isset($GLOBALS['ms0a_namespace'])) {
        audit_expect($GLOBALS['ms0a_namespace'] === ['prefix' => $prefix, 'tag_prefix' => $tagPrefix], 'real login cache namespaces disagree');
    } else {
        $GLOBALS['ms0a_namespace'] = ['prefix' => $prefix, 'tag_prefix' => $tagPrefix];
        audit_write_json(audit_run_dir() . '/evidence/session-layout-' . $GLOBALS['ms0a_execution'] . '.json', ['run_id' => getenv('AUDIT_RUN_ID'), 'confirmed_from_real_login' => true, 'record_fields' => array_keys($matches[$key]), 'value_encoding' => 'PHP serialize array', 'record_key_shape' => 'confirmed cache prefix + md5(token)', 'tag_key_shape' => 'confirmed cache prefix + tag prefix + md5(type)', 'tag_storage' => 'Redis SET', 'key_fingerprint' => ms0a_fingerprint($key)]);
        echo "Confirmed real Redis whitelist: serialized array; record prefix+digest; tag SET; key SHA256/12=" . ms0a_fingerprint($key) . "\n";
    }
    return $matches[$key];
}
function ms0a_session_key(string $token): string { return $GLOBALS['ms0a_namespace']['prefix'] . md5($token); }
function ms0a_fixture_type(string $fixture): string
{
    if (str_starts_with($fixture, 'admin_')) { return 'admin'; }
    if (str_starts_with($fixture, 'out_')) { return 'out'; }
    if (str_starts_with($fixture, 'kefu_')) { return 'kefu'; }
    return 'api';
}
function ms0a_wait_signing_second(string $identity): void
{
    $last = $GLOBALS['ms0a_last_issue'][$identity] ?? 0;
    while (time() <= $last) { usleep(50000); }
}
function ms0a_login(string $fixture, ?string $password = null): string
{
    audit_guard_assert();
    $account = $GLOBALS['ms0a_accounts'][$fixture];
    $type = ms0a_fixture_type($fixture);
    $identity = $type . ':' . $account['id'];
    ms0a_wait_signing_second($identity);
    $secret = $password ?? $account['password'];
    [$path, $body] = match ($type) {
        'admin' => ['/adminapi/login', ['account' => $account['account'], 'pwd' => $secret]],
        'out' => ['/outapi/access_token', ['appid' => $account['account'], 'appsecret' => $secret]],
        'kefu' => ['/kefuapi/login', ['account' => $account['account'], 'password' => $secret]],
        'api' => ['/api/login', ['account' => $account['account'], 'password' => $secret]],
    };
    $response = ms0a_http('POST', $path, null, $body);
    $token = $response['json']['data'][$type === 'out' ? 'access_token' : 'token'] ?? null;
    audit_expect(ms0a_status($response) === 200 && is_string($token) && $token !== '', 'normal synthetic login did not issue a token; HTTP=' . $response['http'] . '/business=' . (ms0a_status($response) ?? 'none'));
    $GLOBALS['ms0a_last_issue'][$identity] = time();
    ms0a_confirm_session($token, $type, (int)$account['id']);
    return $token;
}
function ms0a_probe(string $type, string $token): array { return ms0a_http('GET', MS0A_PROBES[$type], $token); }
function ms0a_prepare(string $fixture): string
{
    $token = ms0a_login($fixture);
    audit_expect(ms0a_status(ms0a_probe(ms0a_fixture_type($fixture), $token)) === 200, 'normal synthetic protected probe failed');
    return $token;
}
function ms0a_refresh(string $token, int $id): string
{
    audit_guard_assert();
    ms0a_wait_signing_second('out:' . $id);
    $response = ms0a_http('POST', '/outapi/refresh_token', null, ['access_token' => $token]);
    $new = $response['json']['data']['access_token'] ?? null;
    audit_expect(ms0a_status($response) === 200 && is_string($new) && $new !== '', 'normal synthetic refresh did not issue a token');
    audit_expect(!hash_equals($new, $token), 'refresh must produce a distinct JWT; signing second was advanced');
    $GLOBALS['ms0a_last_issue']['out:' . $id] = time();
    ms0a_confirm_session($new, 'out', $id);
    return $new;
}
function ms0a_snapshot(): array
{
    return audit_snapshot(['tables' => ['eb_cache' => null, 'eb_store_product' => null, 'eb_system_admin' => null, 'eb_out_account' => null, 'eb_user' => null], 'redis' => ['*']]);
}
function ms0a_redact_snapshot(array $snapshot): array
{
    $safe = ['tables' => $snapshot['tables'], 'redis' => []];
    foreach ($snapshot['redis']['*'] ?? [] as $key => $value) { $safe['redis'][ms0a_fingerprint($key)] = $value; }
    ksort($safe['redis']);
    return $safe;
}
function ms0a_capture_negative(callable $request, int|string $expected = 401, array $ownDeletions = [], ?callable $beforeRequest = null, ?callable $afterRequest = null): array
{
    audit_guard_assert();
    $before = ms0a_snapshot();
    $validated = [];
    foreach ($ownDeletions as $item) {
        [$token, $type, $id] = $item;
        $record = ms0a_confirm_session($token, $type, $id);
        ms0a_check($record['type'] === $type && (string)$record['uid'] === (string)$id && hash_equals($record['token'], $token), 'expected revocation belongs to this application and identity');
        $validated[ms0a_session_key($token)] = ms0a_fingerprint($token);
    }
    $response = ['http' => 0, 'json' => null, 'raw' => ''];
    $requestOffset = count($GLOBALS['ms0a_case']['requests']);
    try {
        if ($beforeRequest !== null) { $beforeRequest(); }
        $response = $request();
    } catch (Throwable $error) {
        ms0a_check(false, 'negative HTTP or service-control failure: ' . get_class($error));
    } finally {
        if ($afterRequest !== null) { $afterRequest(); }
    }
    for ($index = $requestOffset; $index < count($GLOBALS['ms0a_case']['requests']); $index++) {
        $GLOBALS['ms0a_case']['requests'][$index]['negative'] = true;
        $GLOBALS['ms0a_case']['requests'][$index]['expected_contract'] = $expected;
    }
    audit_guard_assert();
    $after = ms0a_snapshot();
    $dbDiff = [];
    foreach ($before['tables'] as $table => $value) {
        if ($value !== $after['tables'][$table]) { $dbDiff[] = ['table' => $table, 'before' => $value, 'after' => $after['tables'][$table]]; }
    }
    $cacheDiff = []; $unexpected = [];
    $old = $before['redis']['*']; $new = $after['redis']['*'];
    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
        if (($old[$key] ?? null) === ($new[$key] ?? null)) { continue; }
        $change = !array_key_exists($key, $old) ? 'added' : (!array_key_exists($key, $new) ? 'deleted' : 'changed');
        $allowed = isset($validated[$key]) && $change === 'deleted';
        // This precise documented limiter is separate from all session keys.
        $limiter = $key === $GLOBALS['ms0a_namespace']['prefix'] . 'login_captcha';
        $diff = ['key_sha256_12' => ms0a_fingerprint($key), 'change' => $change, 'before_sha256' => $old[$key] ?? null, 'after_sha256' => $new[$key] ?? null, 'expected_own_revocation' => $allowed, 'documented_limiter' => $limiter];
        $cacheDiff[] = $diff;
        if (!$allowed && !$limiter) { $unexpected[] = $diff; }
    }
    foreach ($validated as $key => $tokenFingerprint) {
        ms0a_check(isset($old[$key]) && !array_key_exists($key, $new), 'exact current-application token must be revoked; token SHA256/12=' . $tokenFingerprint);
    }
    ms0a_check($dbDiff === [], 'five business tables must be unchanged by the negative request');
    ms0a_check($unexpected === [], 'all other Redis keys and values, including session tags, must be unchanged');
    $GLOBALS['ms0a_case']['db_diff'] = array_merge($GLOBALS['ms0a_case']['db_diff'], $dbDiff);
    $GLOBALS['ms0a_case']['cache_diff'] = array_merge($GLOBALS['ms0a_case']['cache_diff'], $cacheDiff);
    $GLOBALS['ms0a_case']['snapshots'][] = ['before' => ms0a_redact_snapshot($before), 'after' => ms0a_redact_snapshot($after), 'expected_deleted_token_fingerprints' => array_values($validated)];
    if ($expected === 'mcp-denied') {
        ms0a_check(($response['json']['error']['code'] ?? null) === -32600 && !array_key_exists('result', $response['json'] ?? []), 'MCP initialize must return authentication error -32600 and no result');
        ms0a_check($response['http'] > 0 && $response['http'] < 500, 'MCP must not return an uncaught server error');
    } elseif ($expected === 'not-success') {
        ms0a_check(ms0a_status($response) !== 200 && $response['http'] > 0, 'request must receive a non-success response');
    } else {
        ms0a_assert_response($response, $expected);
        if ($expected === 401) { ms0a_check(empty($response['json']['data']), 'authentication failure must not return protected data'); }
    }
    return $response;
}
function ms0a_update(string $table, int $id, array $values): void
{
    audit_guard_assert();
    audit_expect(in_array($table, ['eb_system_admin', 'eb_out_account'], true), 'synthetic mutation table rejected');
    audit_expect(in_array($id, [101, 102, 103, 104, 105], true), 'synthetic mutation identity rejected');
    audit_expect(array_diff(array_keys($values), ['status', 'is_del', 'pwd']) === [], 'synthetic mutation field rejected');
    $assignments = implode(', ', array_map(static fn(string $column): string => $column . '=?', array_keys($values)));
    audit_db()->prepare('UPDATE ' . $table . ' SET ' . $assignments . ' WHERE id=?')->execute([...array_values($values), $id]);
}
function ms0a_admin_payload(int $id = 101): array
{
    $statement = audit_db()->prepare('SELECT pwd FROM eb_system_admin WHERE id=?'); $statement->execute([$id]);
    $passwordHash = $statement->fetchColumn();
    audit_expect(is_string($passwordHash), 'synthetic admin credential hash missing');
    $now = time();
    return ['iss' => 'audit-app', 'aud' => 'audit-app', 'iat' => $now, 'nbf' => $now, 'exp' => $now + 3600, 'jti' => ['id' => $id, 'type' => 'admin'], 'pwd' => md5($passwordHash)];
}
function ms0a_put_forged_session(string $token, int $id = 101, string $type = 'admin'): void
{
    audit_guard_assert();
    $redis = audit_redis(); $key = ms0a_session_key($token);
    $record = ['uid' => $id, 'type' => $type, 'token' => $token, 'exp' => 3660];
    $redis->setex($key, 3660, serialize($record));
    $redis->sAdd($GLOBALS['ms0a_namespace']['prefix'] . $GLOBALS['ms0a_namespace']['tag_prefix'] . md5($type), $key);
}
function ms0a_drop_forged_session(string $token): void
{
    audit_guard_assert();
    $redis = audit_redis(); $key = ms0a_session_key($token);
    $redis->del($key);
    // Remove only this test-created membership, never a tag or another token.
    $redis->sRem($GLOBALS['ms0a_namespace']['prefix'] . $GLOBALS['ms0a_namespace']['tag_prefix'] . md5('admin'), $key);
}
function ms0a_tamper_session(string $fixture, string $type, string $mode): void
{
    $token = ms0a_prepare($fixture); $redis = audit_redis(); $key = ms0a_session_key($token);
    $raw = $redis->get($key); $ttl = $redis->pttl($key);
    audit_expect(is_string($raw) && $ttl !== -2, 'real session must exist before exact-key tamper');
    $deadline = $ttl >= 0 ? (int)floor(microtime(true) * 1000) + $ttl : null;
    $record = unserialize($raw, ['allowed_classes' => false]);
    audit_guard_assert();
    if ($mode === 'wrong-type') { $record['type'] = $type === 'out' ? 'admin' : 'out'; }
    if ($mode === 'wrong-uid') { $record['uid'] = 102; }
    if ($mode === 'wrong-token') { $record['token'] = 'AUDIT-mismatched-session-value'; }
    if ($mode === 'record-deleted') { $redis->del($key); }
    elseif ($deadline === null) { $redis->set($key, serialize($record)); }
    else { $redis->pSetEx($key, max(1, $deadline - (int)floor(microtime(true) * 1000)), serialize($record)); }
    try { ms0a_capture_negative(static fn(): array => ms0a_probe($type, $token)); }
    finally {
        audit_guard_assert(); $restore = audit_redis();
        if ($deadline === null) { $restore->set($key, $raw); }
        else {
            $remaining = $deadline - (int)floor(microtime(true) * 1000);
            if ($remaining > 0) { $restore->pSetEx($key, $remaining, $raw); } else { $restore->del($key); }
        }
    }
}
function ms0a_prepare_draft_key(): array|false
{
    audit_guard_assert();
    $quotedKey = chr(96) . 'key' . chr(96);
    $statement = audit_db()->prepare('SELECT * FROM eb_cache WHERE ' . $quotedKey . '=?');
    $statement->execute(['101_product_data']); $row = $statement->fetch();
    // Both A27 requests begin from an absent precise synthetic draft key.
    // The original row stays only in memory and is restored in finally.
    audit_db()->prepare('DELETE FROM eb_cache WHERE ' . $quotedKey . '=?')->execute(['101_product_data']);
    return $row;
}
function ms0a_restore_draft_key(array|false $row): void
{
    audit_guard_assert();
    $quotedKey = chr(96) . 'key' . chr(96);
    audit_db()->prepare('DELETE FROM eb_cache WHERE ' . $quotedKey . '=?')->execute(['101_product_data']);
    if ($row !== false) {
        audit_expect(($row['key'] ?? null) === '101_product_data', 'draft recovery key rejected');
        audit_db()->prepare('INSERT INTO eb_cache (' . $quotedKey . ',result,expire_time,add_time) VALUES (?,?,?,?)')->execute([$row['key'], $row['result'], $row['expire_time'], $row['add_time']]);
    }
}
function ms0a_permission_negative(callable $request): array
{
    audit_guard_assert();
    $scope = ['tables' => ['eb_store_product_rule' => null, 'eb_system_role' => 'id=101', 'eb_system_menus' => null, 'eb_system_route' => null], 'redis' => []];
    $before = audit_snapshot($scope);
    // Reuse the unchanged strict five-table / ALL-Redis negative contract.
    // Cold authorization-cache rebuilds are failures, not permitted writes.
    $response = ms0a_capture_negative($request, 400);
    $after = audit_snapshot($scope);
    $differences = [];
    foreach ($before['tables'] as $table => $value) {
        if ($value !== $after['tables'][$table]) { $differences[] = ['table' => $table, 'before' => $value, 'after' => $after['tables'][$table]]; }
    }
    $GLOBALS['ms0a_case']['db_diff'] = array_merge($GLOBALS['ms0a_case']['db_diff'], $differences);
    $GLOBALS['ms0a_case']['snapshots'][] = ['permission_and_rule_tables_before' => $before['tables'], 'permission_and_rule_tables_after' => $after['tables']];
    ms0a_check($differences === [], 'permission metadata and product-rule table must remain unchanged by denied request');
    ms0a_check(($response['json']['msg'] ?? null) === '您暂时没有访问权限', 'permission middleware must deny before the business controller');
    ms0a_check(empty($response['json']['data']), 'permission failure must not return protected data');
    return $response;
}
function ms0a_fixture_role_status(): int
{
    $status = audit_db()->query('SELECT status FROM eb_system_role WHERE id=101')->fetchColumn();
    audit_expect($status !== false && (int)$GLOBALS['ms0a_accounts']['_permissions']['role'] === 101, 'synthetic role identity must exist');
    return (int)$status;
}
function ms0a_set_fixture_role_status(string $superToken, int $status): void
{
    audit_guard_assert();
    audit_expect(in_array($status, [0, 1], true), 'synthetic role status rejected');
    ms0a_assert_response(ms0a_http('PUT', '/adminapi/setting/role/set_status/101/' . $status, $superToken), 200);
    ms0a_check((int)audit_db()->query('SELECT status FROM eb_system_role WHERE id=101')->fetchColumn() === $status, 'real role setter must persist the exact synthetic role state');
}
function ms0a_restore_menu_row(array $row): void
{
    audit_guard_assert();
    $menuId = (int)$GLOBALS['ms0a_accounts']['_permissions']['admin_menu'];
    audit_expect((int)($row['id'] ?? 0) === $menuId && (int)$row['auth_type'] === 2 && strtolower(trim($row['api_url'])) === 'product/product/rule' && strtolower(trim($row['methods'])) === 'get', 'only the original granted installation permission may be restored');
    $quote = static fn(string $column): string => chr(96) . $column . chr(96);
    $columns = array_keys($row);
    $updates = implode(',', array_map(static fn(string $column): string => $quote($column) . '=VALUES(' . $quote($column) . ')', $columns));
    audit_db()->prepare('INSERT INTO eb_system_menus (' . implode(',', array_map($quote, $columns)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ') ON DUPLICATE KEY UPDATE ' . $updates)->execute(array_values($row));
    $statement = audit_db()->prepare('SELECT * FROM eb_system_menus WHERE id=?'); $statement->execute([$menuId]);
    ms0a_check($statement->fetch() === $row, 'the exact complete original permission row must be restored');
}
function ms0a_redis_control(string $action): void
{
    audit_expect(in_array($action, ['stop', 'start'], true), 'service-control action rejected');
    $request = audit_run_dir() . '/' . $action . '-redis.request';
    $done = audit_run_dir() . '/' . $action . '-redis.done';
    foreach ([$request, $done] as $file) { if (is_file($file)) { unlink($file); } }
    file_put_contents($request, getenv('AUDIT_RUN_ID') . "\n"); chmod($request, 0600);
    for ($attempt = 0; $attempt < 250; $attempt++) {
        if (is_file($done)) {
            audit_expect(trim(file_get_contents($done)) === getenv('AUDIT_RUN_ID'), 'service-control acknowledgement identity rejected');
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('isolated Redis service-control acknowledgement timed out');
}

audit_guard_assert();
$GLOBALS['ms0a_execution'] = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$GLOBALS['ms0a_baseline'] = [];
foreach (glob(audit_run_dir() . '/evidence/ms0a-auth-baseline-*.jsonl') ?: [] as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $record = json_decode($line, true);
        if (is_array($record)) { $GLOBALS['ms0a_baseline'][$record['case_id'] . '/' . $record['subcase']] = $record; }
    }
}
$GLOBALS['ms0a_accounts'] = json_decode(file_get_contents('/run/audit-fixtures/accounts.json'), true, 512, JSON_THROW_ON_ERROR);
try {
    audit_app_start();
    // Warm each identity's real authorized path outside every negative snapshot.
    $prepared = [];
    foreach (['admin_super', 'admin_limited', 'out_101', 'out_102', 'kefu_101', 'user_101', 'user_102'] as $fixture) { $prepared[$fixture] = ms0a_prepare($fixture); }
} catch (Throwable $error) {
    audit_write_json(audit_run_dir() . '/evidence/auth-preflight-' . $GLOBALS['ms0a_execution'] . '.json', ['status' => 'BLOCKED', 'error_class' => get_class($error), 'notes' => $error instanceof RuntimeException ? $error->getMessage() : 'normal synthetic HTTP preparation failed']);
    fwrite(STDERR, "BLOCKED normal synthetic login/probe preflight failed; inspect redacted preflight evidence\n"); exit(1);
}

ms0a_case('A01', 'missing-admin-token', 'business 401; no business or session changes', '既有拒绝回归', static function (): void {
    ms0a_capture_negative(static fn(): array => ms0a_http('GET', MS0A_PROBES['admin']));
});

$matrix = ['admin' => $prepared['admin_super'], 'out' => $prepared['out_101'], 'kefu' => $prepared['kefu_101'], 'api' => $prepared['user_101']];
foreach ($matrix as $source => $token) {
    foreach (array_keys($matrix) as $target) {
        ms0a_case('A09', $source . '-to-' . $target, $source === $target ? 'own protected probe succeeds with business 200' : 'wrong application business 401; no business or session changes', $source === $target ? '新增契约' : '待复现风险', static function () use ($source, $target, $token): void {
            if ($source === $target) {
                $response = ms0a_probe($target, $token); ms0a_assert_response($response, 200);
                if ($target === 'kefu') { ms0a_check((int)($response['json']['data']['id'] ?? 0) === 101, 'own synthetic customer-service identity returned'); }
                if ($target === 'api') { ms0a_check((int)($response['json']['data']['uid'] ?? 0) === 101, 'own synthetic customer identity returned'); }
            } else { ms0a_capture_negative(static fn(): array => ms0a_probe($target, $token)); }
        });
    }
}

foreach (['out_101' => 'super', 'out_102' => 'limited'] as $fixture => $label) {
    ms0a_case('A11', 'out-login-to-admin-' . $label, 'business 401; original out session preserved', '既有拒绝回归', static function () use ($fixture): void {
        $token = ms0a_prepare($fixture);
        ms0a_capture_negative(static fn(): array => ms0a_probe('admin', $token));
        ms0a_assert_response(ms0a_probe('out', $token), 200);
    });
    ms0a_case('A12', 'out-refresh-to-admin-' . $label, 'business 401; no protected read or write; refreshed out session preserved', '待复现风险', static function () use ($fixture): void {
        $old = ms0a_prepare($fixture); $token = ms0a_refresh($old, (int)$GLOBALS['ms0a_accounts'][$fixture]['id']);
        audit_expect(ms0a_status(ms0a_probe('out', $token)) === 200, 'normal refreshed out probe failed');
        ms0a_capture_negative(static fn(): array => ms0a_probe('admin', $token));
        ms0a_assert_response(ms0a_probe('out', $token), 200);
    });
}
foreach (['A13' => ['kefu_101', 'kefu'], 'A14' => ['user_101', 'api']] as $id => [$fixture, $type]) {
    ms0a_case($id, $type . '-to-admin-collision', 'business 401; original application session still succeeds', '既有拒绝回归', static function () use ($fixture, $type): void {
        $token = ms0a_prepare($fixture);
        ms0a_capture_negative(static fn(): array => ms0a_probe('admin', $token));
        ms0a_assert_response(ms0a_probe($type, $token), 200);
    });
}

foreach (['admin_super', 'admin_limited'] as $fixture) {
    ms0a_case('A15', $fixture . '-disabled-after-login', 'next request 401 and exact own session revoked; restoring status requires fresh login', '待复现风险', static function () use ($fixture): void {
        $token = ms0a_prepare($fixture); $id = (int)$GLOBALS['ms0a_accounts'][$fixture]['id'];
        try {
            ms0a_update('eb_system_admin', $id, ['status' => 0]);
            ms0a_capture_negative(static fn(): array => ms0a_probe('admin', $token), 401, [[$token, 'admin', $id]]);
            ms0a_update('eb_system_admin', $id, ['status' => 1]);
            ms0a_capture_negative(static fn(): array => ms0a_probe('admin', $token));
            ms0a_assert_response(ms0a_probe('admin', ms0a_login($fixture)), 200);
        } finally { ms0a_update('eb_system_admin', $id, ['status' => 1]); }
    });
}
ms0a_case('A15', 'kefu-disabled-after-login', 'disabled customer-service old JWT returns 401 and exact own session is revoked; restoring status requires a fresh login', '待复现风险', static function (): void {
    $token = ms0a_prepare('kefu_101');
    $originalStatus = audit_db()->query('SELECT status FROM eb_store_service WHERE id=101')->fetchColumn();
    audit_expect($originalStatus !== false && (int)$GLOBALS['ms0a_accounts']['kefu_101']['id'] === 101, 'synthetic customer-service fixture must exist');
    $setStatus = static function (int $status): void {
        audit_guard_assert();
        audit_db()->prepare('UPDATE eb_store_service SET status=? WHERE id=101')->execute([$status]);
    };
    $negative = static function (array $ownDeletions = []) use ($token): void {
        audit_guard_assert();
        $scope = ['tables' => ['eb_store_service' => null], 'redis' => []];
        $before = audit_snapshot($scope);
        ms0a_capture_negative(static fn(): array => ms0a_probe('kefu', $token), 401, $ownDeletions);
        $after = audit_snapshot($scope);
        $unchanged = $before['tables']['eb_store_service'] === $after['tables']['eb_store_service'];
        ms0a_check($unchanged, 'customer-service table must remain unchanged by the negative request');
        if (!$unchanged) {
            $GLOBALS['ms0a_case']['db_diff'][] = ['table' => 'eb_store_service', 'before' => $before['tables']['eb_store_service'], 'after' => $after['tables']['eb_store_service']];
        }
        $GLOBALS['ms0a_case']['snapshots'][] = ['customer_service_table_before' => $before['tables']['eb_store_service'], 'customer_service_table_after' => $after['tables']['eb_store_service']];
    };
    try {
        $setStatus(0);
        $negative([[$token, 'kefu', 101]]);
        $setStatus(1);
        $negative();
        $newToken = ms0a_login('kefu_101');
        ms0a_check(!hash_equals($token, $newToken), 're-enabled customer-service account must obtain a distinct new session');
        ms0a_assert_response(ms0a_probe('kefu', $newToken), 200);
    } finally { $setStatus((int)$originalStatus); }
});
foreach (['soft-delete', 'physical-delete', 'record-absent'] as $mode) {
    ms0a_case('A16', $mode, 'business 401 and exact own session revoked; no business writes', $mode === 'soft-delete' ? '待复现风险' : '既有拒绝回归', static function () use ($mode): void {
        $id = $mode === 'record-absent' ? 909101 : 102;
        $row = null;
        if ($mode === 'record-absent') {
            audit_expect((int)audit_db()->query('SELECT COUNT(*) FROM eb_system_admin WHERE id=909101')->fetchColumn() === 0, 'absent synthetic identity must not exist');
            $payload = ms0a_admin_payload(); $payload['jti']['id'] = $id;
            $token = audit_forge_jwt($payload, audit_config()['signing_key']); ms0a_put_forged_session($token, $id);
        } else {
            $token = ms0a_prepare('admin_limited');
            $statement = audit_db()->prepare('SELECT * FROM eb_system_admin WHERE id=?'); $statement->execute([$id]); $row = $statement->fetch();
        }
        try {
            if ($mode === 'soft-delete') { ms0a_update('eb_system_admin', $id, ['is_del' => 1]); }
            if ($mode === 'physical-delete') { audit_guard_assert(); audit_db()->prepare('DELETE FROM eb_system_admin WHERE id=?')->execute([$id]); }
            ms0a_capture_negative(static fn(): array => ms0a_probe('admin', $token), 401, [[$token, 'admin', $id]]);
        } finally {
            if ($mode === 'soft-delete') { ms0a_update('eb_system_admin', $id, ['is_del' => 0]); }
            if ($mode === 'physical-delete' && $row !== null) {
                audit_guard_assert();
                $quote = static fn(string $column): string => chr(96) . $column . chr(96);
                audit_db()->prepare('INSERT INTO eb_system_admin (' . implode(',', array_map($quote, array_keys($row))) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            }
            if ($mode === 'record-absent') { ms0a_drop_forged_session($token); }
        }
    });
}
foreach (['missing-pwd', 'empty-pwd', 'wrong-pwd'] as $mode) {
    ms0a_case('A17', $mode, 'business 401; empty/missing claims preserve whitelist; wrong credential revokes only exact own session', $mode === 'wrong-pwd' ? '新增契约' : '待复现风险', static function () use ($mode): void {
        $payload = ms0a_admin_payload();
        if ($mode === 'missing-pwd') { unset($payload['pwd']); } elseif ($mode === 'empty-pwd') { $payload['pwd'] = ''; } else { $payload['pwd'] = str_repeat('0', 32); }
        $token = audit_forge_jwt($payload, audit_config()['signing_key']); ms0a_put_forged_session($token);
        try { ms0a_capture_negative(static fn(): array => ms0a_probe('admin', $token), 401, $mode === 'wrong-pwd' ? [[$token, 'admin', 101]] : []); }
        finally { ms0a_drop_forged_session($token); }
    });
}
foreach (['wrong-type', 'wrong-uid', 'wrong-token', 'record-deleted'] as $mode) {
    ms0a_case('A18', $mode, 'business 401; mismatched whitelist record causes no other session changes', $mode === 'record-deleted' ? '既有拒绝回归' : '待复现风险', static function () use ($mode): void {
        ms0a_tamper_session('admin_super', 'admin', $mode);
    });
}
foreach (['out' => 'out_101', 'kefu' => 'kefu_101', 'api' => 'user_101'] as $type => $fixture) {
    foreach (['wrong-type', 'wrong-uid', 'wrong-token', 'record-deleted'] as $mode) {
        ms0a_case('A18', $type . '-' . $mode, 'own application business 401; exact tampered record and all other sessions unchanged; no protected data or business write', $mode === 'record-deleted' ? '既有拒绝回归' : '待复现风险', static function () use ($fixture, $type, $mode): void {
            ms0a_tamper_session($fixture, $type, $mode);
        });
    }
}

ms0a_case('A19', 'disabled-login', 'out disabled login business 401; no token or business changes', '既有拒绝回归', static function (): void {
    $account = $GLOBALS['ms0a_accounts']['out_disabled'];
    $response = ms0a_capture_negative(static fn(): array => ms0a_http('POST', '/outapi/access_token', null, ['appid' => $account['account'], 'appsecret' => $account['password']]));
    ms0a_check(!ms0a_response_summary($response)['issued_token'], 'disabled login must not issue a token');
});
foreach (['closed' => ['status' => 0], 'soft-deleted' => ['is_del' => 1]] as $state => $changes) {
    foreach (['access', 'refresh'] as $operation) {
        ms0a_case('A19', $state . '-' . $operation, 'out business 401; exact own session revoked; no business mutation', $state === 'closed' ? '待复现风险' : '既有拒绝回归', static function () use ($changes, $operation): void {
            $token = ms0a_prepare('out_101');
            try {
                ms0a_update('eb_out_account', 101, $changes);
                $response = ms0a_capture_negative($operation === 'access' ? static fn(): array => ms0a_probe('out', $token) : static fn(): array => ms0a_http('POST', '/outapi/refresh_token', null, ['access_token' => $token]), 401, [[$token, 'out', 101]]);
                ms0a_check(!ms0a_response_summary($response)['issued_token'], 'disabled/deleted request must not issue a token');
            } finally { ms0a_update('eb_out_account', 101, ['status' => 1, 'is_del' => 0]); }
        });
    }
}
ms0a_case('A19', 'soft-deleted-login', 'soft-deleted out login business 401; no token or business changes', '既有拒绝回归', static function (): void {
    $account = $GLOBALS['ms0a_accounts']['out_101'];
    try {
        ms0a_update('eb_out_account', 101, ['is_del' => 1]);
        $response = ms0a_capture_negative(static fn(): array => ms0a_http('POST', '/outapi/access_token', null, ['appid' => $account['account'], 'appsecret' => $account['password']]));
        ms0a_check(!ms0a_response_summary($response)['issued_token'], 'soft-deleted login must not issue a token');
    } finally { ms0a_update('eb_out_account', 101, ['is_del' => 0]); }
});
foreach (['disabled' => 'out_disabled', 'soft-deleted' => 'out_101'] as $state => $fixture) {
    ms0a_case('A19', $state . '-mcp', 'MCP authentication error -32600; no protected result or side effects', '既有拒绝回归', static function () use ($state, $fixture): void {
        $account = $GLOBALS['ms0a_accounts'][$fixture];
        try {
            if ($state === 'soft-deleted') { ms0a_update('eb_out_account', 101, ['is_del' => 1]); }
            ms0a_capture_negative(static fn(): array => ms0a_http('POST', '/outapi/mcp', null, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], ['account' => $account['account'], 'password' => $account['password']]), 'mcp-denied');
        } finally { if ($state === 'soft-deleted') { ms0a_update('eb_out_account', 101, ['is_del' => 0]); } }
    });
}
ms0a_case('A19', 'illegal-set-status-two', 'parameter error business 400; database status remains unchanged', '待复现风险', static function (): void {
    $token = ms0a_prepare('admin_super');
    try {
        $response = ms0a_capture_negative(static fn(): array => ms0a_http('PUT', '/adminapi/setting/system_out_account/set_status/105/2', $token), 400);
        ms0a_check(($response['json']['msg'] ?? null) === '参数错误', 'illegal status must return the explicit parameter error');
        ms0a_check((int)audit_db()->query('SELECT status FROM eb_out_account WHERE id=105')->fetchColumn() === 0, 'illegal state must not change the synthetic account');
    } finally { ms0a_update('eb_out_account', 105, ['status' => 0]); }
});
ms0a_case('A19', 'valid-set-status-round-trip', 'real status setter accepts 1 then 0 with business 200 and persists each state', '新增契约', static function (): void {
    $token = ms0a_prepare('admin_super');
    $originalStatus = audit_db()->query('SELECT status FROM eb_out_account WHERE id=105')->fetchColumn();
    audit_expect($originalStatus !== false, 'synthetic round-trip account must exist');
    try {
        foreach ([1, 0] as $status) {
            audit_guard_assert();
            ms0a_assert_response(ms0a_http('PUT', '/adminapi/setting/system_out_account/set_status/105/' . $status, $token), 200);
            ms0a_check((int)audit_db()->query('SELECT status FROM eb_out_account WHERE id=105')->fetchColumn() === $status, 'real setter must persist approved state ' . $status);
        }
    } finally { ms0a_update('eb_out_account', 105, ['status' => (int)$originalStatus]); }
});

foreach (['expired', 'future-nbf', 'wrong-signature', 'none', 'HS512', 'malformed'] as $mode) {
    ms0a_case('A20', $mode, 'business 401; no HTTP 500, business writes, or whitelist changes', $mode === 'malformed' ? '待复现风险' : '新增契约', static function () use ($mode): void {
        $payload = ms0a_admin_payload(); $key = audit_config()['signing_key']; $algorithm = 'HS256';
        if ($mode === 'expired') { $payload['iat'] = time() - 3600; $payload['nbf'] = time() - 3600; $payload['exp'] = time() - 120; }
        if ($mode === 'future-nbf') { $payload['nbf'] = time() + 120; }
        if ($mode === 'wrong-signature') { $key = bin2hex(random_bytes(32)); }
        if ($mode === 'none' || $mode === 'HS512') { $algorithm = $mode; }
        $token = $mode === 'malformed' ? 'AUDIT-malformed-jwt' : audit_forge_jwt($payload, $key, $algorithm);
        ms0a_put_forged_session($token);
        try { ms0a_capture_negative(static fn(): array => ms0a_probe('admin', $token)); }
        finally { ms0a_drop_forged_session($token); }
    });
}
ms0a_case('A21', 'admin-password-change', 'old token business 401 and exact revocation; new password login succeeds', '新增契约', static function (): void {
    $token = ms0a_prepare('admin_super'); $oldHash = audit_db()->query('SELECT pwd FROM eb_system_admin WHERE id=101')->fetchColumn();
    $newPassword = bin2hex(random_bytes(12));
    try {
        ms0a_update('eb_system_admin', 101, ['pwd' => password_hash($newPassword, PASSWORD_BCRYPT)]);
        ms0a_capture_negative(static fn(): array => ms0a_probe('admin', $token), 401, [[$token, 'admin', 101]]);
        ms0a_assert_response(ms0a_probe('admin', ms0a_login('admin_super', $newPassword)), 200);
    } finally { ms0a_update('eb_system_admin', 101, ['pwd' => $oldHash]); }
});
ms0a_case('A23', 'missing-whitelist', 'missing whitelist request is not successful; no business or other Redis changes', '既有拒绝回归', static function (): void {
    $token = ms0a_prepare('admin_super'); audit_guard_assert(); audit_redis()->del(ms0a_session_key($token));
    ms0a_capture_negative(static fn(): array => ms0a_probe('admin', $token), 'not-success');
});
ms0a_case('A23', 'redis-unavailable', 'actual isolated Redis outage rejects request; after restart sentinels and all business/session values persist', '既有拒绝回归', static function (): void {
    $token = ms0a_prepare('admin_super');
    ms0a_capture_negative(static fn(): array => ms0a_probe('admin', $token), 'not-success', [],
        static fn() => ms0a_redis_control('stop'),
        static function (): void { ms0a_redis_control('start'); audit_guard_assert(); ms0a_check(audit_redis()->get('audit:sentinel') === getenv('AUDIT_RUN_ID'), 'restarted Redis retains the exact run sentinel'); });
});

foreach ([['admin_super', 'admin', 'out'], ['admin_super', 'admin', 'kefu'], ['admin_super', 'admin', 'api'], ['out_101', 'out', 'admin'], ['admin_limited', 'admin', 'kefu']] as [$fixture, $source, $target]) {
    ms0a_case('A26', $fixture . '-wrong-' . $target . '-then-own', 'wrong application business 401 without session changes; original protected probe remains business 200', '待复现风险', static function () use ($fixture, $source, $target): void {
        $token = ms0a_prepare($fixture);
        ms0a_capture_negative(static fn(): array => ms0a_probe($target, $token));
        ms0a_assert_response(ms0a_probe($source, $token), 200);
    });
}
foreach (['wrong-signature', 'expired'] as $mode) {
    ms0a_case('A27', $mode . '-product-save', 'business 401; entire eb_cache and eb_store_product unchanged, including draft key 101_product_data; whitelist unchanged', '待复现风险', static function () use ($mode): void {
        $payload = ms0a_admin_payload(); $key = audit_config()['signing_key'];
        if ($mode === 'wrong-signature') { $key = bin2hex(random_bytes(32)); } else { $payload['iat'] = time() - 3600; $payload['nbf'] = time() - 3600; $payload['exp'] = time() - 120; }
        $token = audit_forge_jwt($payload, $key); ms0a_put_forged_session($token);
        $originalDraft = ms0a_prepare_draft_key();
        try { ms0a_capture_negative(static fn(): array => ms0a_http('POST', '/adminapi/product/product/0', $token, ['store_name' => 'AUDIT-A27-' . getenv('AUDIT_RUN_ID')])); }
        finally {
            try { ms0a_restore_draft_key($originalDraft); } finally { ms0a_drop_forged_session($token); }
        }
    });
}

foreach (['empty-key' => ['APP_KEY' => ''], 'default-key' => ['APP_KEY' => 'default'], 'file-cache' => ['CACHE_DRIVER' => 'file']] as $configName => $override) {
    foreach (['admin-login', 'admin-probe', 'out-login'] as $operation) {
        ms0a_case('A10', $configName . '-' . $operation, 'configuration error business 503; no issued token, business write, or session change', '新增契约', static function () use ($override, $operation): void {
            audit_app_start(); $token = ms0a_prepare('admin_super');
            try {
                audit_app_start($override);
                $response = ms0a_capture_negative(static function () use ($operation, $token): array {
                    if ($operation === 'admin-probe') { return ms0a_probe('admin', $token); }
                    $account = $GLOBALS['ms0a_accounts'][$operation === 'admin-login' ? 'admin_super' : 'out_101'];
                    return $operation === 'admin-login' ? ms0a_http('POST', '/adminapi/login', null, ['account' => $account['account'], 'pwd' => $account['password']]) : ms0a_http('POST', '/outapi/access_token', null, ['appid' => $account['account'], 'appsecret' => $account['password']]);
                }, 503);
                ms0a_check(!ms0a_response_summary($response)['issued_token'], 'configuration failure must not issue a token');
            } finally { audit_app_start(); }
        });
    }
}

ms0a_case('A28', 'normal-admin-login-probe-logout', 'admin login and own probe succeed; logout succeeds; old token business 401', '新增契约', static function (): void {
    $token = ms0a_login('admin_limited');
    ms0a_assert_response(ms0a_probe('admin', $token), 200);
    ms0a_assert_response(ms0a_http('GET', '/adminapi/setting/admin/logout', $token), 200);
    ms0a_capture_negative(static fn(): array => ms0a_probe('admin', $token));
});
ms0a_case('A28', 'normal-out-login-probe-refresh', 'out login and own probe succeed; real refresh issues distinct usable token and invalidates old token', '新增契约', static function (): void {
    $old = ms0a_login('out_101'); ms0a_assert_response(ms0a_probe('out', $old), 200);
    $new = ms0a_refresh($old, 101); ms0a_assert_response(ms0a_probe('out', $new), 200);
    ms0a_capture_negative(static fn(): array => ms0a_probe('out', $old));
});
foreach (['kefu_101' => 'kefu', 'user_101' => 'api'] as $fixture => $type) {
    ms0a_case('A28', 'normal-' . $type . '-login-probe', 'real own login issues a matching session; protected probe succeeds', '新增契约', static function () use ($fixture, $type): void {
        ms0a_assert_response(ms0a_probe($type, ms0a_login($fixture)), 200);
    });
}

// Task 7 additions: the original 86 authentication scenarios above retain
// their exact assertions. These authorization failures also keep ALL Redis
// keys unchanged, even when the permission cache is cold after a real edit.
ms0a_case('A02', 'registered-ungranted-product-rule-write', 'registered but ungranted write is denied before controller; all business, permission and session values unchanged', '既有拒绝回归', static function (): void {
    $token = ms0a_prepare('admin_limited');
    $registered = audit_db()->query("SELECT COUNT(*) FROM eb_system_menus WHERE auth_type=2 AND LOWER(api_url)='product/product/rule/<id>' AND LOWER(methods)='post'")->fetchColumn();
    audit_expect((int)$registered > 0, 'write probe must be an existing registered permission');
    $marker = 'AUDIT-A02-' . getenv('AUDIT_RUN_ID');
    $statement = audit_db()->prepare('SELECT COUNT(*) FROM eb_store_product_rule WHERE rule_name=?'); $statement->execute([$marker]);
    audit_expect((int)$statement->fetchColumn() === 0, 'synthetic negative-write marker must not preexist');
    try {
        ms0a_permission_negative(static fn(): array => ms0a_http('POST', '/adminapi/product/product/rule/0', $token, ['rule_name' => $marker, 'spec' => []]));
    } finally {
        // If a regression unexpectedly reached save(), preserve its failure
        // evidence and remove only the otherwise-absent synthetic marker.
        audit_guard_assert();
        audit_db()->prepare('DELETE FROM eb_store_product_rule WHERE rule_name=?')->execute([$marker]);
    }
});
ms0a_case('A03', 'unregistered-product-list', 'unregistered product list is denied before protected read; zero business and session changes', '待复现风险', static function (): void {
    $token = ms0a_prepare('admin_limited');
    audit_expect((int)audit_db()->query("SELECT COUNT(*) FROM eb_system_menus WHERE auth_type=2 AND LOWER(api_url)='product/product' AND LOWER(methods)='get'")->fetchColumn() === 0, 'product-list probe must remain unregistered');
    ms0a_permission_negative(static fn(): array => ms0a_http('GET', '/adminapi/product/product', $token));
});
foreach (['system-info' => '/adminapi/system/info', 'route-import' => '/adminapi/route/import_api', 'download' => '/adminapi/download/AUDITmissing' . getenv('AUDIT_RUN_ID')] as $name => $path) {
    ms0a_case('A03', 'former-auth-only-' . $name, 'new role middleware denies before server-info/import/download controller; no protected data or mutations', '新增契约', static function () use ($path): void {
        $token = ms0a_prepare('admin_limited');
        // Import has no file_path and download uses an absent synthetic key.
        // The exact permission message distinguishes denial from controller
        // validation; no real import file or downloadable file is supplied.
        ms0a_permission_negative(static fn(): array => ms0a_http('GET', $path, $token));
    });
}
ms0a_case('A04', 'temporary-unregistered-crud-probe', 'unregistered CRUD read is denied; its existing readonly controller is never entered', '待复现风险', static function (): void {
    $file = audit_repo_dir() . '/crmeb/app/adminapi/route/crud/audit-ms0a.php';
    audit_expect(is_file($file), 'host harness must install the scoped temporary CRUD probe');
    $handle = fopen($file, 'r'); $firstLine = rtrim((string)fgets($handle), "\r\n"); fclose($handle);
    audit_expect($firstLine === '<?php // AUDIT-RUN ' . getenv('AUDIT_RUN_ID'), 'CRUD probe must carry the exact current-run first-line sentinel');
    ms0a_check(true, 'temporary source probe identity verified read-only');
    $token = ms0a_prepare('admin_limited');
    ms0a_permission_negative(static fn(): array => ms0a_http('GET', '/adminapi/crud/audit-ms0a', $token));
    // Source is read-only in the runner. run.sh owns installation and its EXIT
    // trap removes only this exact matching sentinel, including on test failure.
});
ms0a_case('A05', 'limited-registered-granted-read', 'limited administrator retains the existing granted product-rule read', '既有修复回归', static function (): void {
    ms0a_assert_response(ms0a_probe('admin', ms0a_login('admin_limited')), 200);
});
ms0a_case('A05', 'super-protected-unregistered-read', 'level-zero administrator retains the existing protected product-list access', '既有修复回归', static function (): void {
    ms0a_assert_response(ms0a_http('GET', '/adminapi/product/product', ms0a_prepare('admin_super')), 200);
});
foreach (['logout' => '/adminapi/setting/admin/logout', 'search-menus' => '/adminapi/menusList', 'own-menus' => '/adminapi/menus', 'own-menu-unique' => '/adminapi/setting/menus/unique', 'own-info' => '/adminapi/setting/info'] as $name => $path) {
    ms0a_case('A06', 'allowlist-' . $name, 'approved exact GET route remains available to an authenticated limited administrator', '新增契约', static function () use ($name, $path): void {
        $token = ms0a_prepare('admin_limited'); $response = ms0a_http('GET', $path, $token);
        ms0a_assert_response($response, 200);
        if ($name === 'logout') { ms0a_check(audit_redis()->exists(ms0a_session_key($token)) === 0, 'logout must remove only the current session record'); }
        if ($name === 'own-info') {
            ms0a_check((int)($response['json']['data']['id'] ?? 0) === 102, 'self-information route must return the authenticated synthetic administrator');
            ms0a_check(!array_key_exists('pwd', $response['json']['data'] ?? []), 'self-information response must omit credential hash');
        }
        if ($name === 'own-menus' || $name === 'own-menu-unique') {
            ms0a_check(($response['json']['data']['menus'] ?? null) === [], 'limited API-only fixture must not gain unassigned navigation menus');
        }
    });
}
ms0a_case('A06', 'allowlist-own-update', 'approved exact PUT self-update succeeds and changes only the current synthetic profile', '新增契约', static function (): void {
    $token = ms0a_prepare('admin_limited');
    $before = audit_db()->query('SELECT * FROM eb_system_admin WHERE id=102')->fetch();
    $other = audit_db()->query('SELECT * FROM eb_system_admin WHERE id=101')->fetch();
    audit_expect(is_array($before) && is_array($other), 'synthetic self-update identities must exist');
    try {
        audit_guard_assert();
        ms0a_assert_response(ms0a_http('PUT', '/adminapi/setting/update_admin', $token, ['real_name' => 'AUDIT SELF', 'head_pic' => $before['head_pic']]), 200);
        $after = audit_db()->query('SELECT * FROM eb_system_admin WHERE id=102')->fetch();
        ms0a_check(($after['real_name'] ?? null) === 'AUDIT SELF', 'self-update must persist the current synthetic administrator name');
        foreach (['account', 'pwd', 'roles', 'level', 'status', 'is_del', 'division_id'] as $column) {
            ms0a_check(($after[$column] ?? null) === $before[$column], 'self-update must not change protected account field ' . $column);
        }
        ms0a_check(audit_db()->query('SELECT * FROM eb_system_admin WHERE id=101')->fetch() === $other, 'self-update must not change the other synthetic administrator');
    } finally {
        audit_guard_assert();
        audit_db()->prepare('UPDATE eb_system_admin SET real_name=?,head_pic=? WHERE id=102')->execute([$before['real_name'], $before['head_pic']]);
    }
});
ms0a_case('A06', 'public-login', 'public credential login remains available without an existing token', '新增契约', static function (): void {
    $token = ms0a_login('admin_limited');
    ms0a_check($token !== '', 'public login must issue the normal matching synthetic session');
});
ms0a_case('A06', 'public-login-info', 'public login-info remains available without an existing token', '新增契约', static function (): void {
    audit_guard_assert();
    ms0a_assert_response(ms0a_http('GET', '/adminapi/login/info'), 200);
});
ms0a_case('A08', 'real-role-status-revocation', 'real role revocation makes the very next limited request fail without business or session changes', '既有修复回归', static function (): void {
    $GLOBALS['ms0a_case']['coverage_refs'] = ['A28/normal-admin-login-probe-logout', 'A20/expired'];
    $limited = ms0a_prepare('admin_limited'); $super = ms0a_prepare('admin_super');
    $originalStatus = ms0a_fixture_role_status();
    try {
        ms0a_set_fixture_role_status($super, 0);
        ms0a_permission_negative(static fn(): array => ms0a_probe('admin', $limited));
    } finally { ms0a_set_fixture_role_status($super, $originalStatus); }
});
foreach (['delete', 'update-interface-type'] as $operation) {
    ms0a_case('A30', 'real-menu-' . $operation . '-revocation', 'real existing permission deletion/edit invalidates authorization before the next read; rejection writes no business or cache data', '待复现风险', static function () use ($operation): void {
        $limited = ms0a_prepare('admin_limited'); $super = ms0a_prepare('admin_super');
        $menuId = (int)$GLOBALS['ms0a_accounts']['_permissions']['admin_menu'];
        $statement = audit_db()->prepare('SELECT * FROM eb_system_menus WHERE id=?'); $statement->execute([$menuId]); $original = $statement->fetch();
        audit_expect(is_array($original) && (int)$original['auth_type'] === 2, 'original granted installation interface must exist');
        $roleStatus = ms0a_fixture_role_status();
        try {
            audit_guard_assert();
            if ($operation === 'delete') {
                ms0a_assert_response(ms0a_http('DELETE', '/adminapi/setting/menus/' . $menuId, $super), 200);
                $statement->execute([$menuId]);
                ms0a_check($statement->fetch() === false, 'real menu DELETE must remove the exact original permission');
            } else {
                // Edit the original row out of auth_type=2; no new permission
                // row, URL or method is invented to manufacture the result.
                $body = $original; $body['auth_type'] = 3;
                $body['path'] = $original['path'] === '' ? [] : explode('/', $original['path']);
                ms0a_assert_response(ms0a_http('PUT', '/adminapi/setting/menus/' . $menuId, $super, $body), 200);
                $statement->execute([$menuId]); $edited = $statement->fetch();
                ms0a_check((int)($edited['auth_type'] ?? 0) === 3, 'real menu PUT must remove the original row from the interface permission set');
            }
            ms0a_permission_negative(static fn(): array => ms0a_probe('admin', $limited));
        } finally {
            try { ms0a_restore_menu_row($original); } finally {
                // Restoration uses the existing real role setter to clear its
                // permission caches before the next test, never direct Redis
                // wildcard deletion and never a new permission registration.
                ms0a_set_fixture_role_status($super, $roleStatus);
            }
        }
    });
}
audit_finish();
