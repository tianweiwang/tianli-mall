<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

use app\services\system\config\SystemConfigServices;
use app\services\system\crontab\SystemCrontabServices;
use crmeb\services\CacheService;
use crmeb\services\SystemConfigService;
use think\facade\Cache;

// Configure the original scheduler only; never execute a cancellation or rewrite an order deadline here.
try {
    if (!is_file('/run/preview-app/initialized.json')) throw new RuntimeException('Preview not initialized');
    $mode = $argv[1] ?? '';
    $hours = $argv[2] ?? '0.5';
    if (!in_array($mode, ['configure', 'restore'], true)
        || preg_match('/\A(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,6})?\z/D', $hours) !== 1) {
        throw new InvalidArgumentException('Invalid preview timer arguments');
    }
    $app = preview_app(); // Dedicated app DML role, database and Redis sentinels checked.
    $identity = preview_config();
    $db = preview_pdo();
    $rows = $db->query("SELECT id,menu_name,value FROM eb_system_config WHERE menu_name='order_cancel_time'")->fetchAll();
    if (count($rows) !== 1) throw new RuntimeException('Unique deadline configuration required');
    $row = $rows[0];
    $backupPath = '/workspace/crmeb/runtime/preview-timer-config.json';
    $backup = is_file($backupPath) ? json_decode(file_get_contents($backupPath), true, 512, JSON_THROW_ON_ERROR) : null;
    if ($backup === null) {
        if ($mode === 'restore') throw new RuntimeException('No original deadline configuration recorded');
        $backup = ['project'=>$identity['project'], 'run_id'=>$identity['run_id'], 'id'=>$row['id'],
            'original_value'=>$row['value'], 'last_value'=>$row['value']];
        $file = fopen($backupPath, 'x');
        if ($file === false) throw new RuntimeException('Cannot preserve original deadline');
        $json = json_encode($backup, JSON_THROW_ON_ERROR) . "\n";
        $written = fwrite($file, $json); fclose($file);
        if ($written !== strlen($json)) throw new RuntimeException('Incomplete deadline backup');
    }
    if ($backup['project'] !== $identity['project'] || $backup['run_id'] !== $identity['run_id']
        || $backup['id'] !== $row['id'] || $backup['last_value'] !== $row['value']) {
        throw new RuntimeException('Deadline configuration changed outside this helper');
    }
    $tasks = $db->query('SELECT * FROM eb_system_timer WHERE is_del=0 ORDER BY id')->fetchAll();
    $expected = ['mark'=>'orderCancel', 'type'=>1, 'is_open'=>1, 'month'=>0, 'week'=>0,
        'day'=>0, 'hour'=>0, 'minute'=>0, 'second'=>30, 'timeStr'=>'*/30 * * * * *'];
    if (!$tasks) {
        $data = $expected; unset($data['timeStr']);
        $app->make(SystemCrontabServices::class)->saveTimer($data + ['id'=>0, 'name'=>'',
            'content'=>'隔离预览：正常扫描到期未付款订单', 'customCode'=>'']);
        $tasks = $db->query('SELECT * FROM eb_system_timer WHERE is_del=0 ORDER BY id')->fetchAll();
    }
    if (count($tasks) !== 1) throw new RuntimeException('Unexpected preview timer tasks');
    foreach ($expected as $key=>$value) {
        if ((string)$tasks[0][$key] !== (string)$value) throw new RuntimeException('Preview timer task mismatch');
    }
    if (json_decode($tasks[0]['customCode'], true, 512, JSON_THROW_ON_ERROR) !== '') {
        throw new RuntimeException('Unexpected custom timer code');
    }
    $cached = Cache::get('crontabCache');
    if (!is_array($cached) || count($cached) !== 1 || (string)$cached[0]['id'] !== (string)$tasks[0]['id']) {
        throw new RuntimeException('Preview timer cache unavailable');
    }
    foreach (array_keys($expected + ['update_time'=>0]) as $key) {
        if ((string)$cached[0][$key] !== (string)$tasks[0][$key]) throw new RuntimeException('Preview timer cache mismatch');
    }
    $raw = $mode === 'restore' ? $backup['original_value'] : json_encode($hours, JSON_THROW_ON_ERROR);
    if ($raw !== $row['value']) {
        $app->make(SystemConfigServices::class)->update(
            ['id'=>$row['id'], 'menu_name'=>'order_cancel_time', 'value'=>$row['value']], ['value'=>$raw]);
    }
    $actual = $db->query("SELECT value FROM eb_system_config WHERE id=" . (int)$row['id'])->fetchColumn();
    if ($actual !== $raw) throw new RuntimeException('Deadline update readback mismatch');
    $backup['last_value'] = $raw;
    $json = json_encode($backup, JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($backupPath, $json, LOCK_EX) !== strlen($json)) throw new RuntimeException('Deadline backup update failed');
    $keys = ['order_cancel_time', 'order_activity_time', 'order_bargain_time', 'order_seckill_time', 'order_pink_time'];
    CacheService::delete('system_config_order_cancel_time');
    CacheService::delete('system_config_' . md5(implode(',', $keys)));
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (SystemConfigService::get('order_cancel_time', null) !== $decoded
        || (SystemConfigService::more($keys)['order_cancel_time'] ?? null) !== $decoded) {
        throw new RuntimeException('Deadline cache readback mismatch');
    }
    echo json_encode(['project'=>$identity['project'], 'mode'=>$mode, 'new_order_hours'=>$decoded,
        'original_hours'=>json_decode($backup['original_value'], true, 512, JSON_THROW_ON_ERROR),
        'task_id'=>$tasks[0]['id'], 'mark'=>'orderCancel', 'schedule'=>$tasks[0]['timeStr'],
        'existing_order_deadlines_unchanged'=>true], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) { preview_error($error); exit(1); }
