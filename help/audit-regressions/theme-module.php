<?php
declare(strict_types=1);

// Real MySQL + ThemeModule model/DAO/service; creates and drops only a random
// audit_schema_<hex> database. Never copies or changes business rows/tables.
use Audit\Migration\ThemeModuleMigration;
use function Audit\Migration\databaseConfig;
use function Audit\Migration\connectDatabase;
use function Audit\Migration\identifier;

require dirname(__DIR__) . '/migrations/theme-module.php';
require dirname(__DIR__, 2) . '/crmeb/vendor/autoload.php';
require dirname(__DIR__, 2) . '/crmeb/vendor/topthink/framework/src/helper.php';

function getLang($message, array $replace = []): string { return (string)$message; }
function guardDatabase(string $name): void
{
    if (!preg_match('/^audit_schema_[a-f0-9]{16}$/D', $name)) {
        throw new RuntimeException('Refusing operations outside the isolated schema test database');
    }
}
function check(bool $condition, string $name): void
{
    if (!$condition) { throw new RuntimeException('FAIL ' . $name); }
    echo 'PASS ' . $name . "\n";
}
function expectRefusal(callable $call, string $name): void
{
    try { $call(); } catch (RuntimeException $e) {
        if ($e instanceof PDOException) { throw $e; }
        echo 'PASS ' . $name . "\n";
        return;
    }
    throw new RuntimeException('FAIL ' . $name);
}
function tableDefinition(PDO $db, string $table): string
{
    return (string)$db->query('SHOW CREATE TABLE ' . identifier($table))->fetch(PDO::FETCH_NUM)[1];
}
function bootModels(array $config, string $database): app\services\diy\ThemeModuleServices
{
    guardDatabase($database);
    $app = new think\App(dirname(__DIR__, 2) . '/crmeb/');
    $db = new think\DbManager();
    $db->setConfig([
        'default' => 'mysql', 'auto_timestamp' => false,
        'connections' => ['mysql' => [
            'type' => crmeb\compat\think\orm\db\connector\Mysql::class,
            'hostname' => $config['hostname'], 'hostport' => $config['hostport'],
            'username' => $config['username'], 'password' => $config['password'],
            'database' => $database, 'prefix' => $config['prefix'], 'charset' => 'utf8mb4',
            'debug' => false, 'fields_strict' => true,
        ]],
    ]);
    $app->instance('think\DbManager', $db);
    $app->instance('think\Db', $db);
    think\Model::setDb($db);
    think\Model::setInvoker([$app, 'invoke']);
    return new app\services\diy\ThemeModuleServices(new app\dao\diy\ThemeModuleDao());
}
function runDefaultCheck(string $database, ?string $passwordFile): array
{
    guardDatabase($database);
    $args = [PHP_BINARY, dirname(__DIR__) . '/migrations/theme-module.php', '--database=' . $database];
    if ($passwordFile !== null) { $args[] = '--root-password-file=' . $passwordFile; }
    $process = proc_open($args, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not start migration CLI'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($error !== '') { throw new RuntimeException('Migration CLI failed; credentials omitted'); }
    return [$code, json_decode($output, true, 512, JSON_THROW_ON_ERROR)];
}

$database = 'audit_schema_' . bin2hex(random_bytes(8));
guardDatabase($database);
$created = false;
$admin = null;
$exitCode = 0;
try {
    $passwordFile = getenv('AUDIT_DB_PASSWORD_FILE') ?: null;
    $config = databaseConfig($passwordFile);
    $admin = connectDatabase($config);
    $admin->exec('CREATE DATABASE ' . identifier($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $db = connectDatabase($config, $database);
    $migration = new ThemeModuleMigration($db, $config['prefix']);
    $service = bootModels($config, $database);
    if (($argv[1] ?? '') === '--before-migration') {
        // Red-capable baseline: the real list call must succeed to pass.
        $service->getModuleList([]);
        check(true, 'theme list works before migration');
    } else {
        check($migration->check()['state'] === 'missing', 'missing table reported');
        [$code, $result] = runDefaultCheck($database, $passwordFile);
        check($code === 2 && $result['mode'] === 'check' && $result['state'] === 'missing', 'CLI defaults to read-only check');
        check($db->query('SHOW TABLES')->fetchAll() === [], 'default check creates no tables');
        $result = $migration->apply();
        check($result['state'] === 'created' && $result['changed'], 'apply creates verified schema');
        $table = $config['prefix'] . 'theme_module';
        check(count($db->query('SHOW TABLES')->fetchAll()) === 1, 'only theme_module was created');
        $list = $service->getModuleList([]);
        check($list['count'] === 0 && $list['list'] === [], 'real service lists empty table');
        $payload = ['title' => '主题组件🧩', 'children' => [['text' => '测试内容']]];
        $id = (int)$service->saveModule(0, ['type' => 'banner', 'data' => $payload]);
        check($id > 0 && $service->getModuleInfo($id)['data'] === $payload, 'real service creates and reads Unicode JSON');
        $updated = ['title' => '更新组件✅'];
        check($service->saveModule($id, ['data' => $updated]) === $id && $service->getModuleInfo($id)['data'] === $updated, 'real service updates existing component');
        $emptyId = (int)$service->saveModule(0, ['type' => 'footer']);
        check($service->getModuleInfo($emptyId)['data'] === [], 'type-only component uses nullable data');
        $list = $service->getModuleList(['type' => 'banner']);
        check($list['count'] === 1 && (int)$list['list'][0]['id'] === $id, 'real DAO filters components by type');
        $beforeRows = $db->query('SELECT * FROM ' . identifier($table) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $result = $migration->apply();
        check($result['state'] === 'compatible' && !$result['changed'], 'apply is idempotent with existing data');
        check($beforeRows === $db->query('SELECT * FROM ' . identifier($table) . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'idempotent apply preserves every row');
        // AUTO_INCREMENT may change after CRUD, so compare schema immediately around apply.
        $beforeDefinition = tableDefinition($db, $table);
        $migration->apply();
        check($beforeDefinition === tableDefinition($db, $table), 'idempotent apply leaves table definition unchanged');
        expectRefusal(fn() => $migration->rollbackEmpty(), 'nonempty rollback refused');
        check(count($db->query('SELECT * FROM ' . identifier($table))->fetchAll()) === 2, 'refused rollback preserves data');

        foreach ([
            'short_type' => "MODIFY `type` VARCHAR(20) NOT NULL DEFAULT ''",
            'signed_id' => 'MODIFY `id` INT NOT NULL AUTO_INCREMENT',
            'wrong_charset' => 'DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci',
            'extra_column' => 'ADD `unexpected` INT NOT NULL',
            'wrong_engine' => 'ENGINE=MyISAM',
        ] as $name => $alter) {
            $prefix = 'bad_' . $name . '_';
            $badTable = $prefix . 'theme_module';
            $bad = new ThemeModuleMigration($db, $prefix);
            $bad->apply();
            $db->exec('ALTER TABLE ' . identifier($badTable) . ' ' . $alter);
            $before = tableDefinition($db, $badTable);
            check($bad->check()['state'] === 'incompatible', $name . ' mismatch detected');
            expectRefusal(fn() => $bad->apply(), $name . ' apply refused');
            expectRefusal(fn() => $bad->rollbackEmpty(), $name . ' rollback refused');
            check($before === tableDefinition($db, $badTable), $name . ' existing schema unchanged');
        }
        $unowned = new ThemeModuleMigration($db, 'unowned_');
        $unowned->apply();
        $db->exec("ALTER TABLE `unowned_theme_module` COMMENT=''");
        check(!$unowned->apply()['changed'], 'compatible preexisting schema accepted without mutation');
        expectRefusal(fn() => $unowned->rollbackEmpty(), 'unowned table rollback refused');
        check($service->deleteModule($id) && $service->deleteModule($emptyId), 'real service deletes isolated fixtures');
        check($service->getModuleList([])['count'] === 0, 'real service confirms empty table');
        check($migration->rollbackEmpty()['state'] === 'removed-empty', 'explicit rollback removes only owned empty table');
        check($migration->check()['state'] === 'missing', 'rollback verified missing');
        echo "Theme module migration and real CRUD regressions passed\n";
    }
} catch (Throwable $e) {
    $exitCode = 1;
    // Framework database exceptions may include connection details; print only type/code.
    preg_match('/SQLSTATE\[[A-Z0-9]+\]/', $e->getMessage(), $sqlState);
    fwrite(STDERR, 'FAIL ' . get_class($e) . ' (code ' . $e->getCode() . ')' . ($sqlState ? ' ' . $sqlState[0] : '') .
        (($e instanceof RuntimeException && !($e instanceof PDOException)) || $e instanceof Error ? ': ' . $e->getMessage() : '; details omitted') . "\n");
} finally {
    if ($created && $admin instanceof PDO) {
        guardDatabase($database);
        $admin->exec('DROP DATABASE ' . identifier($database));
        echo "Isolated audit_schema database removed\n";
    }
}
exit($exitCode);
