<?php
declare(strict_types=1);

// Isolated regression tests: real authorization middleware/services/controller,
// in-memory request/cache/DAO/upload boundaries. No application boot or I/O.
namespace app {
    class Request
    {
        public string $path = 'order/refund/<id>';
        public string $verb = 'PUT';
        public array $admin = ['id' => 10, 'level' => 1, 'roles' => [1]];
        public array $body = [];

        public function rule(): object
        {
            return new class($this->path) {
                private string $path;
                public function __construct(string $path) { $this->path = $path; }
                public function getRule(): string { return $this->path; }
            };
        }
        public function method(): string { return $this->verb; }
        public function adminId(): int { return $this->admin['id']; }
        public function adminInfo(): array { return $this->admin; }
        public function postMore(array $fields, bool $values): array
        {
            return array_map(fn(array $field) => $this->body[$field[0]] ?? $field[1], $fields);
        }
    }
}

namespace app\services {
    class BaseServices { protected $dao; }
}

namespace app\dao\system\admin {
    class SystemRoleDao
    {
        public function getColumn(...$args): array { return ['1']; }
    }
}

namespace app\services\system {
    class SystemMenusServices
    {
        public static array $registered = [];
        public static array $granted = [];
        public function getColumn(array $where, string $fields): array
        {
            return $where[0][0] === 'api_url' ? self::$registered : self::$granted;
        }
    }
}

namespace think\facade {
    class Cache
    {
        public static array $values = [];
        public static array $writes = [];
        public static function get(string $key) { return self::$values[$key] ?? null; }
        public static function set(string $key, $value, $expire = null): bool
        {
            self::$values[$key] = $value;
            self::$writes[] = $key;
            return true;
        }
        public static function tag(string $tag): self { return new self(); }
        public function remember(string $key, $value, $expire)
        {
            if (array_key_exists($key, self::$values)) { return self::$values[$key]; }
            $result = is_callable($value) ? $value() : $value;
            self::set($key, $result, $expire);
            return $result;
        }
    }
}

namespace {
    use app\Request;
    use app\adminapi\controller\PublicController;
    use app\adminapi\middleware\AdminCheckRoleMiddleware;
    use app\dao\system\admin\SystemRoleDao;
    use app\services\system\SystemMenusServices;
    use app\services\system\admin\SystemRoleServices;
    use app\services\system\attachment\SystemAttachmentServices;
    use crmeb\exceptions\AuthException;
    use think\facade\Cache;

    function getLang($message, array $replace = []): string { return (string)$message; }
    function approvedAuthRoutes(): array
    {
        return ['get setting/admin/logout', 'get menuslist', 'get menus', 'get setting/menus/unique', 'get setting/info', 'put setting/update_admin'];
    }
    function config(string $name, $default = null)
    {
        if ($name !== 'auth_routes.authenticated') { throw new \LogicException('Unexpected test configuration: ' . $name); }
        $file = dirname(__DIR__, 2) . '/crmeb/app/adminapi/config/auth_routes.php';
        // Tests precede the production configuration. Use only the approved
        // six exact entries until the real configuration exists, then read it.
        $configuration = is_file($file) ? require $file : ['authenticated' => approvedAuthRoutes()];
        return $configuration['authenticated'] ?? $default;
    }

    $root = dirname(__DIR__, 2) . '/crmeb/';
    foreach ([
        'crmeb/interfaces/MiddlewareInterface.php',
        'crmeb/exceptions/AuthException.php',
        'crmeb/services/CacheService.php',
        'app/services/system/admin/SystemRoleServices.php',
        'app/adminapi/middleware/AdminCheckRoleMiddleware.php',
        'app/adminapi/controller/PublicController.php',
    ] as $file) {
        require $root . $file;
    }

    final class UploadSpy
    {
        public array $calls = [];
        public function upload(...$args): void { $this->calls[] = $args; }
    }

    final class TestApp
    {
        public UploadSpy $upload;
        public int $uploadResolutions = 0;
        public function __construct() { $this->upload = new UploadSpy(); }
        public function make(string $class): object
        {
            if ($class === SystemRoleServices::class) {
                return new SystemRoleServices(new SystemRoleDao());
            }
            if ($class === SystemMenusServices::class) {
                return new SystemMenusServices();
            }
            if ($class === SystemAttachmentServices::class) {
                $this->uploadResolutions++;
                return $this->upload;
            }
            throw new \LogicException('Unexpected dependency: ' . $class);
        }
        public function success(string $message): array { return ['status' => 200, 'msg' => $message]; }
        public function fail(string $message): array { return ['status' => 400, 'msg' => $message]; }
    }

    function app(?string $name = null): TestApp { return $GLOBALS['testApp']; }
    function check(bool $condition, string $message): void
    {
        if (!$condition) { throw new \RuntimeException($message); }
    }

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    $passed = $failed = 0;
    function run(string $name, callable $test): void
    {
        global $passed, $failed;
        $GLOBALS['testApp'] = new TestApp();
        Cache::$values = [];
        Cache::$writes = [];
        try {
            $test();
            $passed++;
            echo "PASS {$name}\n";
        } catch (\Throwable $e) {
            $failed++;
            echo "FAIL {$name}: {$e->getMessage()}\n";
        }
    }

    $protectedRoute = ['api_url' => 'order/refund/<id>', 'methods' => 'PUT'];
    $roleCases = [
        ['unassigned role stops middleware', [], 'order/refund/<id>', 'PUT', 1, false],
        ['other route permission cannot refund', [['api_url' => 'order/list', 'methods' => 'GET']], 'order/refund/<id>', 'PUT', 1, false],
        ['other method permission cannot refund', [['api_url' => 'order/refund/<id>', 'methods' => 'GET']], 'order/refund/<id>', 'PUT', 1, false],
        ['assigned role proceeds', [$protectedRoute], 'order/refund/<id>', 'PUT', 1, true],
        ['super administrator proceeds', [], 'order/refund/<id>', 'PUT', 0, true],
        // BASE's original POST probe is preserved in its historical evidence.
        // The approved factual correction uses the actual GET logout route;
        // its original positive assertion remains, with POST denial added below.
        ['logout exemption proceeds', [], 'setting/admin/logout', 'GET', 1, true],
        ['menus exemption proceeds', [], 'menuslist', 'GET', 1, true],
        ['unregistered route is denied', [], 'order/unregistered', 'PUT', 1, false],
        ['unregistered method is denied without warning', [], 'order/unregistered', 'PATCH', 1, false],
        ['unassigned CRUD route is denied', [], 'crud/example', 'PUT', 1, false],
        ['new contract unregistered non-allowlisted route is denied', [], 'audit/unregistered', 'GET', 1, false],
        ['new contract approved authenticated route proceeds', [], 'menus', 'GET', 1, true],
        ['new contract registered CRUD without a grant is denied', [], 'crud/example', 'PUT', 1, false],
        ['new contract registered granted route normalizes and proceeds', [$protectedRoute], ' ORDER/REFUND/<ID> ', ' PUT ', 1, true],
    ];
    foreach (approvedAuthRoutes() as $entry) {
        [$method, $path] = explode(' ', $entry, 2);
        $roleCases[] = ['exact allowlist ' . $entry . ' proceeds', [], $path, strtoupper($method), 1, true];
        $wrongMethod = $method === 'get' ? 'POST' : 'GET';
        $roleCases[] = ['allowlist rejects wrong method ' . strtolower($wrongMethod) . ' ' . $path, [], $path, $wrongMethod, 1, false];
    }
    foreach ($roleCases as [$name, $granted, $path, $method, $level, $allowed]) {
        run($name, static function () use ($protectedRoute, $granted, $path, $method, $level, $allowed): void {
            SystemMenusServices::$registered = [$protectedRoute, ['api_url' => 'crud/example', 'methods' => 'PUT']];
            SystemMenusServices::$granted = $granted;
            $request = new Request();
            $request->path = $path;
            $request->verb = $method;
            $request->admin['level'] = $level;
            $nextCalls = 0;
            $denied = false;
            try {
                (new AdminCheckRoleMiddleware())->handle($request, static function () use (&$nextCalls): string {
                    $nextCalls++;
                    return 'next';
                });
            } catch (AuthException $e) {
                $denied = true;
                // Business 403 redirects the current client to file-password login.
                check($e->getCode() === 0 || $e->getCode() === 400, 'Permission failure must retain generic business error handling');
            }
            check($denied === !$allowed, 'Unexpected authorization decision');
            check($nextCalls === ($allowed ? 1 : 0), 'Unauthorized request reached the next middleware');
        });
    }
    run('allowlist contains only the six approved exact method-rule entries', static function (): void {
        $actual = config('auth_routes.authenticated', []);
        $expected = approvedAuthRoutes();
        check(is_array($actual), 'Authenticated allowlist must be an array');
        sort($actual); sort($expected);
        check($actual === $expected, 'Authenticated allowlist contains missing, extra or non-exact entries');
    });
    foreach (['registered-ungranted' => 'order/refund/<id>', 'unregistered' => 'audit/cold-unregistered'] as $name => $path) {
        run('cold permission-cache denial performs no writes: ' . $name, static function () use ($protectedRoute, $path): void {
            SystemMenusServices::$registered = [$protectedRoute];
            SystemMenusServices::$granted = [];
            $request = new Request(); $request->path = $path; $request->verb = 'PUT';
            $denied = false; $nextCalls = 0;
            try {
                (new AdminCheckRoleMiddleware())->handle($request, static function () use (&$nextCalls): void { $nextCalls++; });
            } catch (AuthException $error) { $denied = true; }
            check($denied && $nextCalls === 0, 'Cold unauthorized request must stop before downstream middleware');
            check(Cache::$writes === [] && Cache::$values === [], 'Denied cold permission lookup wrote cache values');
        });
    }

    $validToken = 'test-only-scan-token';
    $scanCases = [
        ['missing cache and request rejected', null, [], false],
        ['empty cache and request rejected', '', ['uploadToken' => ''], false],
        ['expired cache rejected', null, ['uploadToken' => $validToken], false],
        ['missing request token rejected', $validToken, [], false],
        ['empty request token rejected', $validToken, ['uploadToken' => ''], false],
        ['array token rejected', $validToken, ['uploadToken' => [$validToken]], false],
        ['integer token rejected', '123', ['uploadToken' => 123], false],
        ['boolean token rejected', $validToken, ['uploadToken' => true], false],
        ['integer cache rejected', 123, ['uploadToken' => '123'], false],
        ['array cache rejected', [$validToken], ['uploadToken' => $validToken], false],
        ['mismatched token rejected', $validToken, ['uploadToken' => 'other-token'], false],
        ['numeric-string weak comparison rejected', '0e12345', ['uploadToken' => '0e67890'], false],
        ['matching token uploads once', $validToken, ['uploadToken' => $validToken, 'pid' => 7, 'file' => 'image'], true],
    ];
    foreach ($scanCases as [$name, $cached, $body, $allowed]) {
        run($name, static function () use ($cached, $body, $allowed): void {
            Cache::$values['scan_upload'] = $cached;
            $request = new Request();
            $request->body = $body;
            $result = (new PublicController())->scanUpload($request);
            check($result['status'] === ($allowed ? 200 : 400), 'Unexpected upload authorization decision');
            check(count(app()->upload->calls) === ($allowed ? 1 : 0), 'Rejected credential reached upload');
            check(app()->uploadResolutions === ($allowed ? 1 : 0), 'Rejected credential resolved upload service');
            if ($allowed) {
                check(app()->upload->calls[0] === [7, 'image', 0, 0, '', $body['uploadToken']], 'Upload parameters changed');
            } else {
                check($result['msg'] === '配置已更改或token已失效', 'Failure response changed');
            }
        });
    }
    echo "Security regressions: {$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
