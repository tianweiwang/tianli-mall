<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
try {
    if (!is_file('/run/preview-app/initialized.json')) throw new RuntimeException('Preview not initialized');
    $app = preview_app();
    $app->console->run();
} catch (Throwable $error) { preview_error($error); exit(1); }
