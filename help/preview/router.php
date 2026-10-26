<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
try {
    if (!is_file('/run/preview-app/initialized.json')) throw new RuntimeException('Preview not initialized');
    if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/preview-health') {
        preview_assert_isolated();
        header('Content-Type: application/json');
        echo json_encode(['status' => 200, 'mode' => 'isolated-preview']);
        return;
    }
    $app = preview_app();
    $http = $app->http;
    $response = $http->run();
    $response->send();
    $http->end($response);
} catch (Throwable $error) {
    preview_error($error);
    http_response_code(503); header('Content-Type: application/json');
    echo '{"status":503,"msg":"Preview unavailable; check local service diagnostics"}';
}
