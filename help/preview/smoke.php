<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

function preview_http(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    if (!str_starts_with($path, '/') || str_contains($path, '://')) throw new RuntimeException('Invalid preview path');
    $headers = ['Accept: application/json'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    $config = preview_config();
    $origin = ($config['release_runtime'] ?? false) ? 'http://nginx:80'
        : (($config['image_runtime'] ?? false) ? 'http://preview-web:80'
            : (($config['full_runtime'] ?? false) ? 'http://preview-web:8080' : 'http://preview-app:8000'));
    $curl = curl_init($origin . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '']);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    $raw = curl_exec($curl); $http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    $business = is_array($json) && is_int($json['status'] ?? null) ? $json['status'] : null;
    echo json_encode(['method' => $method, 'path' => $path, 'http' => $http,
        'business_status' => $business, 'json' => is_array($json)], JSON_THROW_ON_ERROR) . "\n";
    if ($http !== 200 || $business !== 200) throw new RuntimeException('Preview positive HTTP check failed');
    return $json;
}

try {
    preview_assert_isolated();
    $config = preview_config();
    // Full runtime readiness is the original container health, checked by its lifecycle command.
    if (($config['full_runtime'] ?? false) !== true) preview_http('GET', '/preview-health');
    preview_http('GET', '/adminapi/login/info');
    foreach (['admin' => '/adminapi/login', 'merchant' => '/merchantapi/login', 'customer' => '/api/login'] as $role => $path) {
        $credentials = $config['credentials'][$role];
        $payload = ['account' => $credentials['account'], $role === 'admin' ? 'pwd' : 'password' => $credentials['password']];
        if ($role === 'customer') $payload += ['spread' => 0, 'agent_id' => 0];
        $result = preview_http('POST', $path, $payload);
        $token = $result['data']['token'] ?? null;
        if (!is_string($token) || $token === '') throw new RuntimeException('Actual login token missing');
        if ($role === 'merchant') preview_http('GET', '/merchantapi/me', null, $token);
    }
    echo "PASS preview initialization and three actual logins; trade and browser checks remain pending\n";
} catch (Throwable $error) { preview_error($error); exit(1); }
