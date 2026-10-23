<?php
declare(strict_types=1);

/** Qualified old-source HTTP entry only; parent registration/writer remains pending. */
function nhe_history_router(): bool
{
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    ini_set('zend.exception_ignore_args', '1'); umask(0077);
    $unavailable = static function (): void {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo "{\"status\":\"FAIL\",\"code\":\"NHE_HTTP_UNAVAILABLE\"}\n";
        } else {
            file_put_contents('php://stderr', "NHE_HTTP_UNAVAILABLE\n");
        }
    };
    $handled = false;
    register_shutdown_function(static function () use (&$handled, $unavailable): void {
        if (!$handled) $unavailable();
    });
    try {
        if (PHP_SAPI !== 'cli-server' || PHP_INT_SIZE < 8 || PHP_VERSION_ID < 80200 || PHP_VERSION_ID >= 80300
            || __FILE__ !== '/run/nonempty/legacy-history-router.php' || realpath(__FILE__) !== __FILE__
            || getcwd() !== '/legacy-workspace/crmeb'
            || realpath($_SERVER['DOCUMENT_ROOT'] ?? '') !== '/legacy-workspace/crmeb/public') {
            throw new RuntimeException('NHE_ROUTER_REAL_OLD_HTTP_ENTRY');
        }
        $bytes = getenv('NHE_CHILD_FREEZE_BYTES'); $sha = getenv('NHE_CHILD_FREEZE_SHA256');
        if (!is_string($bytes) || preg_match('/\A[1-9][0-9]{0,9}\z/D', $bytes) !== 1 || (string)(int)$bytes !== $bytes
            || !is_string($sha) || preg_match('/\A[a-f0-9]{64}\z/D', $sha) !== 1) {
            throw new RuntimeException('NHE_ROUTER_PARENT_QUALIFIED_FREEZE_ENV_REQUIRED');
        }
        if (spl_autoload_functions() !== [] || function_exists('app')) {
            throw new RuntimeException('NHE_ROUTER_NO_PRELOADED_APPLICATION');
        }
        foreach (get_declared_classes() as $class) {
            if (preg_match('/\A(?:app|think|crmeb|Composer)\\\\/i', $class)
                || str_starts_with($class, 'ComposerAutoloaderInit')) {
                throw new RuntimeException('NHE_ROUTER_NO_PRELOADED_APPLICATION_CLASS');
            }
        }
        require_once __DIR__ . '/legacy-app.php';
        $state = nhe_child_qualify(['path' => '/run/audit/preparation-source-binding.json',
            'bytes' => (int)$bytes, 'sha256' => $sha], 'http');
        unset($bytes, $sha);

        $uri = $_SERVER['REQUEST_URI'] ?? null;
        nhe_expect(is_string($uri) && $uri !== '' && strlen($uri) <= 8192 && $uri[0] === '/'
            && preg_match('/[\x00-\x20\x7f]/', $uri) !== 1, 'NHE_ROUTER_REAL_REQUEST_URI');
        $encodedPath = parse_url($uri, PHP_URL_PATH);
        nhe_expect(is_string($encodedPath) && $encodedPath !== '' && $encodedPath[0] === '/', 'NHE_ROUTER_REQUEST_PATH');
        $path = rawurldecode($encodedPath);
        nhe_expect($path !== '' && $path[0] === '/' && !str_contains($path, '\\') && !str_contains($path, '//')
            && preg_match('/[\x00-\x20\x7f]/', $path) !== 1
            && preg_match('~(?:^|/)\.{1,2}(?:/|$)~D', $path) !== 1, 'NHE_ROUTER_NO_PATH_TRAVERSAL');
        foreach (explode('/', $path) as $part) nhe_expect(!in_array($part,
            ['.env', 'private.env', 'legacy.env', 'install.lock'], true), 'NHE_ROUTER_NO_PRIVATE_OR_INSTALL_FILE');
        $phpPath = preg_match('~\.(?:php[0-9]*|phtml|phar|inc)(?:/|$)~i', $path) === 1;
        nhe_expect(!$phpPath || $path === '/index.php', 'NHE_ROUTER_NO_OTHER_EXECUTABLE_PUBLIC_FILE');
        $root = '/legacy-workspace/crmeb/public'; $candidate = $root . $path;
        if (!$phpPath && is_file($candidate)) {
            nhe_expect(is_string($_SERVER['SCRIPT_NAME'] ?? null)
                && $_SERVER['DOCUMENT_ROOT'] . $_SERVER['SCRIPT_NAME'] === $candidate,
                'NHE_ROUTER_NATIVE_STATIC_TARGET_SAME_AS_OLD_ROUTER');
            nhe_expect(!is_link($candidate) && realpath($candidate) === $candidate
                && str_starts_with($candidate, $root . '/'), 'NHE_ROUTER_CANONICAL_PUBLIC_SOURCE_FILE');
            $relative = substr($candidate, strlen('/legacy-workspace/'));
            $files = array_column($state['authority']['legacy_source_files'], null, 'path');
            nhe_expect(isset($files[$relative]), 'NHE_ROUTER_STATIC_FILE_IN_FULL_FROZEN_OLD_SOURCE');
            nhe_file($candidate, array_replace($files[$relative], ['path' => $candidate]));
            nhe_includes($state);
            $handled = true;
            return false;
        }

        require_once __DIR__ . '/legacy-history-producer.php';
        $httpTimeReference = nhep_http_time_reference();
        nhe_includes($state);
        defined('DS') || define('DS', DIRECTORY_SEPARATOR);
        nhe_expect(DS === DIRECTORY_SEPARATOR, 'NHE_ROUTER_ORIGINAL_DIRECTORY_SEPARATOR');
        $app = nhe_business_app($state);
        $http = $app->http;
        $response = $http->run();
        nhe_includes($state);
        $response->send();
        $http->end($response);
        nhep_http_time_reference($httpTimeReference);
        unset($httpTimeReference);
        nhe_includes($state);
        $handled = true;
        return true;
    } catch (Throwable $error) {
        // Never expose exception arguments, credentials, source paths or private reports.
        $handled = true;
        $unavailable();
        return true;
    }
}

if (PHP_SAPI !== 'cli-server') {
    fwrite(STDERR, "{\"status\":\"FAIL\",\"code\":\"NHE_HTTP_UNAVAILABLE\"}\n");
    exit(1);
}
return nhe_history_router();
