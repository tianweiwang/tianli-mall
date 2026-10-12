<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

try {
    $task8 = ($argv[1] ?? '') === '--context-task8';
    if ($task8) array_splice($argv, 1, 1);
    $command = $task8
        ? ['queue:work', 'marketplace', '--queue=marketplace', '--sleep=1', '--tries=1', '--quiet']
        : ['queue:work', '--queue=marketplace', '--sleep=1', '--tries=1', '--quiet'];
    audit_expect(array_slice($argv, 1) === $command, 'ordinary worker command differs');
    $run = (string)getenv('AUDIT_RUN_ID');
    audit_expect(getenv('AUDIT_ISOLATED') === '1' && preg_match('/^[0-9]{14}[a-f0-9]{6}$/D', $run) === 1, 'isolated worker RUN differs');
    $stem = $task8 ? 'ms1-context-worker' : 'ms1-worker';
    $permission = audit_run_dir() . '/' . $stem . '-start.json'; $until = microtime(true) + 60;
    while (!is_file($permission) && microtime(true) < $until) usleep(100000);
    audit_expect(is_file($permission) && !is_link($permission), 'worker start permission unavailable');
    $start = audit_read_control_json($permission); $manifest = audit_manifest(); $worker = $manifest['services']['audit-worker'] ?? [];
    if ($task8) audit_expect(($manifest['guard_mode'] ?? null) === 'read-only', 'ordinary Task8 requires read-only guards before any guard invocation');
    $id = $start['container_id'] ?? ''; $image = $start['image_id'] ?? ''; $volume = $manifest['project'] . '_worker-runtime';
    audit_expect(($start['run_id'] ?? '') === $run && is_string($id) && preg_match('/^[a-f0-9]{64}$/D', $id) === 1
        && is_string($image) && preg_match('/^sha256:[a-f0-9]{64}$/D', $image) === 1
        && ($worker['id'] ?? '') === $id && ($worker['image_id'] ?? '') === $image
        && filter_var($worker['address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && gethostname() === substr($id, 0, 12) && gethostbyname(gethostname()) === ($worker['address'] ?? null)
        && gethostbyname('audit-worker') === ($worker['address'] ?? null)
        && ($worker['runtime_volume'] ?? '') === $volume && in_array($volume, $manifest['volumes'] ?? [], true)
        && isset($manifest['volume_identities'][$volume]) && is_dir(audit_repo_dir() . '/crmeb/runtime'), 'registered worker identity differs');
    audit_guard_assert();
    require_once audit_repo_dir() . '/crmeb/vendor/autoload.php';
    require_once __DIR__ . '/Ms1EmptyWorkerJob.php';
    \Audit\ms1_worker_environment();
    $argv[0] = audit_repo_dir() . '/crmeb/think'; $_SERVER['argv'] = $argv;
    if ($task8) {
        require_once __DIR__ . '/Ms1ContextWorkerObserver.php';
        // think itself constructs an App. Keep the observer and the real console
        // on this same App instead of registering on an App that think replaces.
        $app = new \think\App(audit_repo_dir() . '/crmeb/');
        $app->initialize();
        \Audit\Ms1ContextWorkerObserver::register($app);
        $app->console->run();
    } else {
        require audit_repo_dir() . '/crmeb/think';
    }
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR) . "\n");
    exit(2);
}
