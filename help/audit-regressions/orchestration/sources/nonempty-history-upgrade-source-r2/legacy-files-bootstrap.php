<?php
declare(strict_types=1);
require_once __DIR__ . '/legacy-app.php';

function nhe_files_plan(array $authority): array
{
    $children = []; $files = [];
    foreach ($authority['legacy_source_directories'] as $path)
        $children[dirname($path)][basename($path)] = 'directory';
    foreach ($authority['legacy_source_files'] as $row) {
        $path = $row['path']; $files[$path] = $row;
        nhe_expect(!isset($children[dirname($path)][basename($path)]), 'NHE_PLAN_FILE_DIRECTORY_DISTINCT');
        $children[dirname($path)][basename($path)] = 'file';
    }
    $expected = [];
    $visit = function (string $directory) use (&$visit, &$expected, $children, $files): void {
        $entries = $children[$directory] ?? []; ksort($entries, SORT_STRING);
        foreach ($entries as $name => $kind) {
            $path = $directory . '/' . $name;
            if ($kind === 'directory') {
                if (!str_contains($path, 'uploads')) $visit($path);
                continue;
            }
            if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') continue;
            nhe_expect(isset($files[$path]), 'NHE_PLANNED_FROZEN_FILE');
            $md5 = md5_file('/legacy-source/' . $path);
            nhe_expect(is_string($md5) && preg_match('/\A[a-f0-9]{32}\z/D', $md5) === 1, 'NHE_PLANNED_SOURCE_MD5');
            $expected[] = ['id' => count($expected) + 1, 'filename' => substr($path, strlen('crmeb/')), 'md5' => $md5];
        }
    };
    foreach (['crmeb/app', 'crmeb/crmeb'] as $root) {
        nhe_expect(in_array($root, $authority['legacy_source_directories'], true), 'NHE_BOTH_FROZEN_SCAN_ROOTS');
        $visit($root);
    }
    nhe_expect($expected !== [], 'NHE_NONEMPTY_PLANNED_CHECKSUMS');
    return $expected;
}
function nhe_files_projection(array $rows): array
{
    $hashes = array_map(static fn(array $row): string => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)), $rows);
    sort($hashes, SORT_STRING);
    return ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $hashes))];
}

function nhe_files_main(): int
{
    $phase = 'source'; $code = null;
    try {
        ini_set('display_errors', '0'); ini_set('log_errors', '0'); ini_set('zend.exception_ignore_args', '1'); umask(0077);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new RuntimeException('NHE_PHP_RUNTIME_WARNING');
        });
        nhe_expect(PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__
            && __FILE__ === '/run/nonempty/legacy-files-bootstrap.php' && count($_SERVER['argv']) === 3,
            'NHE_REAL_BOOTSTRAP_ENTRY');
        nhe_expect(preg_match('/\A--freeze-bytes=([1-9][0-9]*)\z/D', $_SERVER['argv'][1], $bytes) === 1
            && preg_match('/\A--freeze-sha256=([a-f0-9]{64})\z/D', $_SERVER['argv'][2], $sha) === 1,
            'NHE_EXTERNAL_FREEZE_PIN_REQUIRED');
        $freezePin = ['path' => '/run/audit/preparation-source-binding.json', 'bytes' => (int) $bytes[1], 'sha256' => $sha[1]];
        $state = nhe_qualify($freezePin, 'audit-legacy-files-bootstrap');
        $phase = 'migrator'; $migrator = m4m_read('/run/audit-bootstrap/migrator.json');
        nhe_expect(($migrator['username'] ?? '') === 'audit_migrator' && is_string($migrator['password'] ?? null), 'NHE_DEDICATED_MIGRATOR');
        $db = audit_pdo($migrator); $grants = $db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        nhe_expect(count($grants) === 2
            && count(array_filter($grants, static fn(string $g): bool => str_starts_with($g, 'GRANT USAGE ON *.* TO '))) === 1
            && count(array_filter($grants, static fn(string $g): bool => str_starts_with($g, 'GRANT ALL PRIVILEGES ON `audit_auth`.* TO ')
                && !str_contains($g, 'GRANT OPTION'))) === 1
            && $db->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth'
            && $db->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN) === [getenv('AUDIT_RUN_ID')],
            'NHE_MIGRATOR_OWN_SCHEMA_SENTINEL');
        $statistics = $db->query('SELECT @@SESSION.information_schema_stats_expiry AS session_expiry,@@GLOBAL.information_schema_stats_expiry AS global_expiry')->fetch(PDO::FETCH_ASSOC);
        nhe_expect($statistics === ['session_expiry' => 0, 'global_expiry' => 0], 'NHE_ACTUAL_FRESH_STATISTICS');
        $before = m4m_snapshot();
        foreach (['eb_marketplace_order_idempotency', 'eb_marketplace_stock_movement'] as $table)
            nhe_expect(!in_array($table, $before['names'], true), 'NHE_PRE_MS4_TABLES');
        foreach (M4M_ADDITIONS as $table => $columns) foreach ($columns as $column)
            nhe_expect(isset($before['tables'][$table]) && !isset($before['tables'][$table]['metadata']['columns'][$column]), 'NHE_PRE_MS4_COLUMNS');
        nhe_expect((int) $db->query('SELECT COUNT(*) FROM eb_system_file_md5')->fetchColumn() === 0
            && (int) $db->query('SELECT COUNT(*) FROM eb_cache')->fetchColumn() === 0, 'NHE_FRESH_FILE_MARKER_TABLES');
        $increments = $db->query('SELECT @@SESSION.auto_increment_increment AS increment_step,@@SESSION.auto_increment_offset AS increment_offset')->fetch(PDO::FETCH_ASSOC);
        nhe_expect($increments === ['increment_step' => 1, 'increment_offset' => 1], 'NHE_NATIVE_UNIT_INCREMENTS');
        $beforeDdl = [];
        foreach (['eb_system_file_md5', 'eb_cache'] as $table) {
            $ddl = $db->query('SHOW CREATE TABLE ' . m4m_quote($table))->fetch(PDO::FETCH_NUM);
            nhe_expect(is_array($ddl) && is_string($ddl[1] ?? null)
                && hash('sha256', $ddl[1]) === $before['tables'][$table]['ddl_sha256']
                && ($table === 'eb_system_file_md5'
                    ? in_array($before['tables'][$table]['metadata']['table']['AUTO_INCREMENT'], [1, '1'], true)
                    : $before['tables'][$table]['metadata']['table']['AUTO_INCREMENT'] === null),
                'NHE_FRESH_TARGET_DDL_COUNTER');
            $beforeDdl[$table] = $ddl[1];
        }
        nhe_expect($before['tables']['eb_system_file_md5']['projected_columns'] === ['id', 'filename', 'md5']
            && $before['tables']['eb_cache']['projected_columns'] === ['key', 'result', 'expire_time', 'add_time'],
            'NHE_COMPLETE_TARGET_COLUMN_CONTRACT');
        $expected = nhe_files_plan($state['authority']);
        $expectedProjection = nhe_files_projection($expected);
        $phase = 'old_app'; $app = nhe_app($state, $migrator); unset($migrator);
        $writerDb = $app->db->connect()->getPdo();
        nhe_expect($writerDb instanceof PDO
            && $writerDb->query('SELECT DATABASE()')->fetchColumn() === 'audit_auth'
            && $writerDb->query('SELECT CURRENT_USER()')->fetchColumn() === 'audit_migrator@%'
            && $writerDb->query('SELECT run_id FROM audit_sentinel')->fetchAll(PDO::FETCH_COLUMN) === [getenv('AUDIT_RUN_ID')]
            && $writerDb->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) === $grants
            && $writerDb->query('SELECT @@SESSION.auto_increment_increment AS increment_step,@@SESSION.auto_increment_offset AS increment_offset')->fetch(PDO::FETCH_ASSOC) === $increments,
            'NHE_ACTUAL_OLD_WRITER_OWN_SCHEMA_NATIVE_UNIT_INCREMENTS');
        $phase = 'file_initialization';
        nhe_expect($app->make(\app\services\system\log\SystemFileServices::class)->writeMd5() === true, 'NHE_REAL_OLD_WRITE_MD5');
        $actual = $db->query('SELECT id,filename,md5 FROM eb_system_file_md5 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        nhe_expect($actual === $expected
            && $app->make(\app\services\system\log\SystemFileMd5Services::class)->checkFile() === [], 'NHE_COMPLETE_OLD_FILE_MD5_ROWS');
        $phase = 'version_marker';
        $version = parse_ini_file('/legacy-source/crmeb/.version', false, INI_SCANNER_RAW);
        nhe_expect(is_array($version) && is_string($version['version_code'] ?? null) && $version['version_code'] !== ''
            && $version['version_code'] !== '0' && get_crmeb_version_vode() === $version['version_code'], 'NHE_REAL_OLD_VERSION');
        $cache = $app->make(\app\services\other\CacheServices::class);
        $markerStart = time();
        $cache->setDbCache('write_md5', $version['version_code']);
        $markerEnd = time();
        nhe_expect($markerEnd >= $markerStart, 'NHE_REAL_MARKER_TIME_WINDOW');
        $actualMarker = $db->query('SELECT `key`,result,expire_time,add_time FROM eb_cache ORDER BY `key`')->fetchAll(PDO::FETCH_ASSOC);
        nhe_expect($cache->checkDbCache('write_md5', $version['version_code'])
            && count($actualMarker) === 1,
            'NHE_EXACT_OLD_VERSION_MARKER');
        $phase = 'database_preservation'; $afterDatabase = m4m_snapshot();
        nhe_expect($afterDatabase['names'] === $before['names'] && $afterDatabase['modes'] === $before['modes']
            && $afterDatabase['redis'] === $before['redis']
            && $db->query('SELECT @@SESSION.auto_increment_increment AS increment_step,@@SESSION.auto_increment_offset AS increment_offset')->fetch(PDO::FETCH_ASSOC) === $increments,
            'NHE_NAMES_MODES_REDIS_INCREMENTS_UNCHANGED');
        nhe_expect($afterDatabase['tables']['eb_system_file_md5']['projection'] === $expectedProjection,
            'NHE_FULL_NATIVE_ID_CHECKSUM_PROJECTION');
        $markerMatches = false;
        for ($second = $markerStart; $second <= $markerEnd; ++$second) {
            $candidate = [['key' => 'write_md5', 'result' => json_encode($version['version_code'], JSON_THROW_ON_ERROR),
                'expire_time' => 0, 'add_time' => $second]];
            if ($actualMarker === $candidate
                && $afterDatabase['tables']['eb_cache']['projection'] === nhe_files_projection($candidate)) {
                $markerMatches = true; break;
            }
        }
        nhe_expect($markerMatches, 'NHE_FULL_MARKER_ROW_IN_REAL_NATIVE_TIME_WINDOW');
        foreach ($before['tables'] as $table => $old) {
            $now = $afterDatabase['tables'][$table];
            if (!isset($beforeDdl[$table])) {
                nhe_expect($now === $old, 'NHE_ALL_OTHER_TABLE_DESCRIPTORS_UNCHANGED'); continue;
            }
            $count = $table === 'eb_system_file_md5' ? count($expected) : 1;
            $metadata = $old['metadata']; $oldCounter = $metadata['table']['AUTO_INCREMENT'];
            if ($table === 'eb_system_file_md5')
                $metadata['table']['AUTO_INCREMENT'] = is_int($oldCounter) ? $count + 1 : (string) ($count + 1);
            nhe_expect($now['metadata'] === $metadata && $now['projected_columns'] === $old['projected_columns']
                && $now['projection']['rows'] === $count, 'NHE_ONLY_APPROVED_TARGET_ROWS_COUNTER');
            $ddl = $db->query('SHOW CREATE TABLE ' . m4m_quote($table))->fetch(PDO::FETCH_NUM);
            nhe_expect(is_array($ddl) && is_string($ddl[1] ?? null)
                && hash('sha256', $ddl[1]) === $now['ddl_sha256'], 'NHE_ACTUAL_TARGET_DDL_READBACK');
            if ($table === 'eb_cache') {
                nhe_expect($ddl[1] === $beforeDdl[$table], 'NHE_CACHE_FULL_DDL_UNCHANGED'); continue;
            }
            $normalized = [];
            foreach ([[$beforeDdl[$table], 1], [$ddl[1], $count + 1]] as [$text, $counter]) {
                $changes = 0;
                $normalized[] = preg_replace_callback('/(\) ENGINE=InnoDB)(?: AUTO_INCREMENT=([1-9][0-9]*))?(?= )/',
                    static function (array $match) use ($counter): string {
                        nhe_expect(isset($match[2]) ? $match[2] === (string) $counter : $counter === 1,
                            'NHE_DDL_EXACT_NATIVE_COUNTER');
                        return $match[1];
                    }, $text, -1, $changes);
                nhe_expect($changes === 1 && is_string(end($normalized)), 'NHE_ONLY_TABLE_OPTION_COUNTER');
            }
            nhe_expect($normalized[0] === $normalized[1], 'NHE_FULL_TARGET_DDL_OTHERWISE_UNCHANGED');
        }
        nhe_expect($db->query('SELECT @@SESSION.information_schema_stats_expiry AS session_expiry,@@GLOBAL.information_schema_stats_expiry AS global_expiry')->fetch(PDO::FETCH_ASSOC) === $statistics,
            'NHE_FRESH_STATISTICS_UNCHANGED');
        nhe_expect($app->db->connect()->getPdo() === $writerDb
            && $writerDb->query('SELECT @@SESSION.auto_increment_increment AS increment_step,@@SESSION.auto_increment_offset AS increment_offset')->fetch(PDO::FETCH_ASSOC) === $increments,
            'NHE_SAME_OLD_WRITER_UNIT_INCREMENTS_UNCHANGED');
        $phase = 'final_source'; $after = nhe_qualify($freezePin, 'audit-legacy-files-bootstrap');
        nhe_expect($after === $state, 'NHE_FINAL_FULL_SOURCE_AND_STAGE_UNCHANGED');
        $includes = nhe_includes($state);
        $result = ['schema' => 1, 'status' => 'LEGACY_FILE_INITIALIZATION_ONLY', 'run_id' => getenv('AUDIT_RUN_ID'),
            'source_sha' => getenv('AUDIT_SOURCE_SHA'), 'legacy_source_sha' => $state['authority']['legacy_source_sha'],
            'container_id' => $state['container_id'], 'entry_namespace_pid' => getmypid(),
            'freeze_sha256' => $freezePin['sha256'], 'native_exit' => 0, 'checksum_rows' => count($actual),
            'old_version_marker_matches' => true, 'included_source_files' => $includes,
            'non_target_database_and_redis_unchanged' => true, 'target_native_id_full_validation' => true,
            'marker_add_time_native_window' => ['before' => $markerStart, 'after' => $markerEnd],
            'complete_target_rows_and_projections_verified' => true,
            'business_acceptance' => 'NOT RUN', 'migration' => 'NOT RUN', 'producer' => 'NOT IMPLEMENTED'];
        m4m_new('/run/audit/evidence/audit-legacy-files-bootstrap-result.json', nhe_json($result));
        echo nhe_json(['status' => $result['status'], 'native_exit' => 0, 'checksum_rows' => $result['checksum_rows'],
            'business_acceptance' => 'NOT RUN']); return 0;
    } catch (Throwable $error) {
        if (preg_match('/\A[A-Z0-9_]+\z/D', $error->getMessage()) === 1) $code = $error->getMessage();
        echo nhe_json(['status' => 'FAIL', 'native_exit' => 1, 'phase' => $phase, 'code' => $code,
            'class' => get_class($error), 'business_acceptance' => 'NOT RUN']); return 1;
    }
}
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(nhe_files_main());
