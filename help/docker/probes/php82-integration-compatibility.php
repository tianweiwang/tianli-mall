<?php
declare(strict_types=1);

const PHP82_INTEGRATION_SENTINEL = '__PHP82_INTEGRATION__|';
const PHP82_INTEGRATION_PROBE = 'php82-integration-compatibility';

$probeCase = $argv[1] ?? '';
$probeState = [
    'probe' => PHP82_INTEGRATION_PROBE,
    'case' => $probeCase,
    'status' => 'fail',
    'checks' => (object) [],
    'diagnostics' => [],
    'unexpectedOutput' => '',
    'throwable' => null,
    'emitted' => false,
];

function probeSeverityName(int $severity): string
{
    $names = [
        E_ERROR => 'E_ERROR',
        E_WARNING => 'E_WARNING',
        E_PARSE => 'E_PARSE',
        E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_STRICT => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    ];

    return $names[$severity] ?? 'E_' . $severity;
}

function probeThrowable(Throwable $throwable): array
{
    return [
        'class' => get_class($throwable),
        'message' => $throwable->getMessage(),
        'file' => $throwable->getFile(),
        'line' => $throwable->getLine(),
    ];
}

function probeEmit(): void
{
    global $probeState;

    if ($probeState['emitted']) {
        return;
    }
    $probeState['emitted'] = true;

    $captured = '';
    while (ob_get_level() > 0) {
        $chunk = ob_get_clean();
        if ($chunk !== false) {
            $captured = $chunk . $captured;
        }
    }
    $probeState['unexpectedOutput'] = $captured;

    $checksPassed = $probeState['checks'] !== [];
    foreach ($probeState['checks'] as $passed) {
        if ($passed !== true) {
            $checksPassed = false;
            break;
        }
    }
    $probeState['status'] = $checksPassed
        && $probeState['diagnostics'] === []
        && $probeState['unexpectedOutput'] === ''
        && $probeState['throwable'] === null
        ? 'pass'
        : 'fail';

    $result = $probeState;
    unset($result['emitted']);
    $json = json_encode(
        $result,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        $json = '{"probe":"' . PHP82_INTEGRATION_PROBE . '","case":"json-encoding","status":"fail",'
            . '"checks":{},"diagnostics":[],"unexpectedOutput":"",'
            . '"throwable":{"class":"JsonException","message":"Unable to encode probe result"}}';
    }

    fwrite(STDOUT, PHP82_INTEGRATION_SENTINEL . $json . PHP_EOL);
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    global $probeState;
    $probeState['diagnostics'][] = [
        'severity' => $severity,
        'severityName' => probeSeverityName($severity),
        'message' => $message,
        'file' => $file,
        'line' => $line,
    ];
    return true;
}, E_ALL);
set_exception_handler(static function (Throwable $throwable): void {
    global $probeState;
    $probeState['throwable'] = probeThrowable($throwable);
    probeEmit();
});
register_shutdown_function(static function (): void {
    global $probeState;

    if ($probeState['emitted']) {
        return;
    }
    $lastError = error_get_last();
    $fatalSeverities = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($lastError !== null && in_array($lastError['type'], $fatalSeverities, true)) {
        $probeState['diagnostics'][] = [
            'severity' => $lastError['type'],
            'severityName' => probeSeverityName($lastError['type']),
            'message' => $lastError['message'],
            'file' => $lastError['file'],
            'line' => $lastError['line'],
        ];
    }
    probeEmit();
});
ob_start();

function probeCheck(array &$checks, string $name, bool $passed): void
{
    $checks[$name] = $passed;
}

function probeNewApp(string $suffix): think\App
{
    $root = '/tmp/php82-' . $suffix . '-' . bin2hex(random_bytes(6));
    if (!mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Unable to create ThinkPHP probe root');
    }

    return new think\App($root);
}

function probeInvokeManagerDriver(object $manager, string $driver): object
{
    $method = new ReflectionMethod($manager, 'driver');
    $method->setAccessible(true);
    $provider = $method->invoke($manager, $driver);
    if (!is_object($provider)) {
        throw new RuntimeException('Manager did not resolve an object provider: ' . $driver);
    }

    return $provider;
}

function probeJwt(): array
{
    $checks = [];
    $now = time();
    $claims = [
        'sub' => 'account-1001',
        'role' => 'operator',
        'iat' => $now,
        'exp' => $now + 300,
    ];
    $secret = 'neutral-hs256-key-32-bytes-long';
    $encoded = Firebase\JWT\JWT::encode($claims, $secret, 'HS256');
    $decoded = (array) Firebase\JWT\JWT::decode($encoded, $secret, ['HS256']);
    probeCheck($checks, 'firebase_hs256_three_segments', count(explode('.', $encoded)) === 3);
    probeCheck($checks, 'firebase_hs256_claims_round_trip', $decoded === $claims);

    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($key === false) {
        throw new RuntimeException('Unable to generate RSA key');
    }
    $privateKey = '';
    if (!openssl_pkey_export($key, $privateKey)) {
        throw new RuntimeException('Unable to export RSA private key');
    }
    $keyDetails = openssl_pkey_get_details($key);
    if (!is_array($keyDetails) || empty($keyDetails['key'])) {
        throw new RuntimeException('Unable to export RSA public key');
    }

    $credentials = new Google\Auth\Credentials\ServiceAccountCredentials(
        ['https://www.googleapis.com/auth/cloud-platform.read-only'],
        [
            'client_email' => 'service-probe@example.invalid',
            'private_key' => $privateKey,
            'project_id' => 'neutral-project',
        ]
    );
    $credentials->useJwtAccessWithScope();
    $httpCalls = 0;
    $token = $credentials->fetchAuthToken(static function () use (&$httpCalls): void {
        $httpCalls++;
        throw new RuntimeException('HTTP handler must not be called');
    });
    $googleJwt = is_array($token) ? ($token['access_token'] ?? '') : '';
    $googleClaims = $googleJwt !== ''
        ? (array) Firebase\JWT\JWT::decode($googleJwt, $keyDetails['key'], ['RS256'])
        : [];
    probeCheck($checks, 'google_self_signed_token_present', $googleJwt !== '');
    probeCheck($checks, 'google_self_signed_three_segments', count(explode('.', $googleJwt)) === 3);
    probeCheck($checks, 'google_self_signed_rs256_verified', ($googleClaims['iss'] ?? '') === 'service-probe@example.invalid');
    probeCheck($checks, 'google_poison_http_handler_unused', $httpCalls === 0);

    return $checks;
}

function probeSpreadsheet(): array
{
    $checks = [];
    $workbookPath = '/tmp/php82-spreadsheet-' . bin2hex(random_bytes(6)) . '.xlsx';
    $imagePath = '/tmp/php82-spreadsheet-' . bin2hex(random_bytes(6)) . '.png';
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFElEQVR42mNkYPj/n4GBgYGJAQoAHgQCAfK7l5QAAAAASUVORK5CYII=',
        true
    );
    if ($png === false || file_put_contents($imagePath, $png) === false) {
        throw new RuntimeException('Unable to create PNG fixture');
    }

    $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'Inventory item');
    $sheet->setCellValue('B1', 19.5);
    $sheet->getStyle('A1')->getFont()->setBold(true);
    $sheet->getStyle('A1')->getFill()
        ->setFillType(PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setARGB('FF4F81BD');
    $sheet->getStyle('B1')->getNumberFormat()->setFormatCode('0.00');
    $drawing = new PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
    $drawing->setName('Product image');
    $drawing->setPath($imagePath);
    $drawing->setCoordinates('C3');
    $drawing->setWorksheet($sheet);
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($workbookPath);
    $spreadsheet->disconnectWorksheets();

    $loaded = PhpOffice\PhpSpreadsheet\IOFactory::load($workbookPath);
    $loadedSheet = $loaded->getActiveSheet();
    $loadedDrawings = $loadedSheet->getDrawingCollection();
    $imageInfo = getimagesize($imagePath);
    probeCheck($checks, 'xlsx_file_created', is_file($workbookPath) && filesize($workbookPath) > 0);
    probeCheck($checks, 'cell_value_round_trip', $loadedSheet->getCell('A1')->getValue() === 'Inventory item');
    probeCheck($checks, 'bold_style_round_trip', $loadedSheet->getStyle('A1')->getFont()->getBold() === true);
    probeCheck($checks, 'fill_style_round_trip', $loadedSheet->getStyle('A1')->getFill()->getStartColor()->getARGB() === 'FF4F81BD');
    probeCheck($checks, 'number_format_round_trip', $loadedSheet->getStyle('B1')->getNumberFormat()->getFormatCode() === '0.00');
    probeCheck($checks, 'png_fixture_format', is_array($imageInfo) && ($imageInfo[2] ?? null) === IMAGETYPE_PNG);
    probeCheck($checks, 'embedded_image_round_trip', count($loadedDrawings) === 1
        && $loadedDrawings[0]->getName() === 'Product image'
        && $loadedDrawings[0]->getCoordinates() === 'C3');
    $loaded->disconnectWorksheets();

    return $checks;
}

function probeOrderExport(): array
{
    $checks = [];
    $app = probeNewApp('order-export');
    $orderService = new class extends app\services\order\StoreOrderServices {
        public function __construct()
        {
        }

        public function getOrderList(array $where, array $field = ['*'], array $with = [])
        {
            return [
                'data' => [[
                    'order_id' => 'ORDER-1001',
                    'real_name' => 'Customer One',
                    'user_phone' => '13800000000',
                    'user_address' => 'Example Road 10',
                    'paid' => 1,
                    'pay_type' => 'weixin',
                    'status' => 0,
                    'shipping_type' => 1,
                    'refund_status' => 0,
                    'custom_form' => [['title' => 'Delivery window', 'value' => 'Morning']],
                    '_info' => [[
                        'cart_info' => [
                            'productInfo' => [
                                'store_name' => 'Support cushion',
                                'attrInfo' => ['suk' => 'Standard'],
                            ],
                            'cart_num' => 2,
                            'truePrice' => 19.5,
                        ],
                    ]],
                    'total_price' => 39.0,
                    'pay_price' => 39.0,
                    'pay_time' => 1700000000,
                    'add_time' => '2023-11-14 22:13:20',
                    'mark' => 'Leave at reception',
                    'remark' => 'Packed',
                ]],
                'count' => 1,
            ];
        }
    };
    $app->instance(app\services\order\StoreOrderServices::class, $orderService);

    $result = (new app\services\other\export\ExportServices())->exportOrderList([]);
    $row = $result['export'][0] ?? [];
    $goodsRows = $row[4] ?? [];
    probeCheck($checks, 'order_header_17_columns', count($result['header'] ?? []) === 17);
    probeCheck($checks, 'order_row_14_top_level_values', count($row) === 14);
    probeCheck($checks, 'order_goods_rows_present', count($goodsRows) === 1);
    probeCheck($checks, 'order_goods_row_four_fields', isset($goodsRows[0]) && count($goodsRows[0]) === 4);
    probeCheck($checks, 'order_goods_values_preserved', ($goodsRows[0] ?? null) === ['Support cushion', 'Standard', 2, 19.5]);

    return $checks;
}

function probeProductExport(): array
{
    $checks = [];
    $app = probeNewApp('product-export');
    $productDao = new class {
        public function getList(array $where, int $page, int $limit): array
        {
            return [[
                'id' => 101,
                'store_name' => 'Support cushion',
                'virtual_type' => 0,
                'cate_id' => '10',
                'unit_name' => 'piece',
                'ficti' => 5,
                'min_qty' => 1,
                'spec_type' => 1,
                'store_info' => 'Daily support product',
                'keyword' => 'support',
                'command_word' => 'ITEM101',
                'give_integral' => 2,
            ]];
        }
    };
    $productService = new class($productDao) extends app\services\product\product\StoreProductServices {
        public $dao;

        public function __construct(object $dao)
        {
            $this->dao = $dao;
        }
    };
    $categoryService = new class extends app\services\product\product\StoreCategoryServices {
        public function __construct()
        {
        }

        public function getColumn(array $where, string $field, string $key = ''): array
        {
            return [];
        }

        public function getCateParentAndChildName(string $cateIds): array
        {
            return [['id' => 10, 'one' => 'Daily goods', 'two' => 'Support items']];
        }
    };
    $descriptionService = new class extends app\services\product\product\StoreDescriptionServices {
        public function __construct()
        {
        }

        public function getColumn(array $where, string $field, string $key = ''): array
        {
            return [101 => 'Product detail'];
        }
    };
    $attributeService = new class extends app\services\product\sku\StoreProductAttrResultServices {
        public function __construct()
        {
        }

        public function getColumn(array $where, string $field, string $key = ''): array
        {
            return [101 => json_encode([
                'attr' => [[
                    'value' => 'Size',
                    'detail' => [['value' => 'Standard']],
                ]],
                'value' => [[
                    'detail' => ['Standard'],
                    'price' => 29.5,
                    'ot_price' => 39.5,
                    'cost' => 12.0,
                    'stock' => 20,
                    'weight' => 1,
                    'volume' => 2,
                    'bar_code' => 'ITEM-101',
                    'bar_code_number' => '6900000000101',
                ]],
            ], JSON_UNESCAPED_SLASHES)];
        }
    };
    $app->instance(app\services\product\product\StoreProductServices::class, $productService);
    $app->instance(app\services\product\product\StoreCategoryServices::class, $categoryService);
    $app->instance(app\services\product\product\StoreDescriptionServices::class, $descriptionService);
    $app->instance(app\services\product\sku\StoreProductAttrResultServices::class, $attributeService);

    $exportService = new class extends app\services\other\export\ExportServices {
        public function getPageValue(bool $isPage = true, bool $isRelieve = true)
        {
            return [0, 10, 10];
        }
    };
    $result = $exportService->exportProductList([]);
    $row = $result['export'][0] ?? [];
    probeCheck($checks, 'product_list_header_22_columns', count($result['header'] ?? []) === 22);
    probeCheck($checks, 'product_list_row_22_values', count($row) === 22);
    probeCheck($checks, 'product_list_neutral_values_preserved', ($row['store_name'] ?? '') === 'Support cushion'
        && ($row['sku_name'] ?? '') === 'Standard'
        && ($row['bar_code'] ?? '') === 'ITEM-101');

    $migrationHeaders = [
        '商品编号', '商品名称', '商品类型', '商品分类(一级)', '商品分类(二级)', '商品单位',
        '商品图片', '商品视频', '商品详情', '已售数量', '起购数量', '规格类型', '规格类型值', '规格名称',
        '规格值组合', '规格图片', '售价', '划线价', '成本价', '库存', '重量', '体积', '商品编码', '条形码', '商品简介',
        '商品关键字', '商品口令', '购买送积分',
    ];
    $migrationRow = [
        101, 'Support cushion', '普通商品', 'Daily goods', 'Support items', 'piece',
        'https://example.invalid/item.png', '', 'Product detail', 5, 1, '多规格', 'Size', 'Size',
        'Standard', '', 29.5, 39.5, 12, 20, 1, 2, 'ITEM-101', '6900000000101', 'Daily support product',
        'support', 'ITEM101', 2,
    ];
    $migrationPath = '/tmp/php82-product-migration-' . bin2hex(random_bytes(6)) . '.xlsx';
    $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray($migrationHeaders, null, 'A1');
    $sheet->fromArray($migrationRow, null, 'A2');
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($migrationPath);
    $spreadsheet->disconnectWorksheets();
    $readRows = (new crmeb\services\FileService())->readExcel($migrationPath, 'product', 1, 'Xlsx');
    $readRow = $readRows[0] ?? [];
    probeCheck($checks, 'product_migration_header_28_columns', count($migrationHeaders) === 28);
    probeCheck($checks, 'product_migration_row_28_values', count($migrationRow) === 28);
    probeCheck($checks, 'file_service_read_excel_28_fields', count($readRow) === 28);
    probeCheck($checks, 'file_service_product_values_preserved', ($readRow['store_name'] ?? '') === 'Support cushion'
        && ($readRow['sku_value'] ?? '') === 'Standard'
        && ($readRow['bar_code'] ?? '') === 'ITEM-101');

    return $checks;
}

function probeEasyWechat(): array
{
    $checks = [];
    $app = probeNewApp('easywechat');
    $request = new class extends app\Request {
        public function isApp()
        {
            return false;
        }
    };
    $systemConfig = new class extends app\services\system\config\SystemConfigServices {
        public function __construct()
        {
        }

        public function getConfigAll(array $configName = [])
        {
            return array_fill_keys($configName, 'neutral-value');
        }
    };
    $cache = new class {
        private $values = [];

        public function get(string $key)
        {
            return $this->values[$key] ?? null;
        }

        public function set(string $key, $value, $ttl = null): bool
        {
            $this->values[$key] = $value;
            return true;
        }
    };
    $app->instance('request', $request);
    $app->instance(app\services\system\config\SystemConfigServices::class, $systemConfig);
    $app->instance(crmeb\services\CacheService::class, $cache);

    $wechat = new crmeb\services\easywechat\Application([
        'app_id' => 'wx-neutral-app',
        'secret' => 'neutral-secret',
        'debug' => false,
        'cache' => new Doctrine\Common\Cache\ArrayCache(),
        'log' => ['level' => 'error'],
        'payment' => [
            'merchant_id' => '1900000109',
            'key' => 'neutral-payment-key-32-bytes',
        ],
        'mini_program' => [
            'app_id' => 'wx-neutral-mini',
            'secret' => 'neutral-mini-secret',
        ],
        'open3rd' => [
            'component_appid' => 'wx-neutral-component',
            'component_appsecret' => 'neutral-component-secret',
            'component_verify_ticket' => 'neutral-ticket',
            'authorizer_appid' => 'wx-neutral-authorizer',
        ],
    ]);
    $serviceIds = [
        'mini_program.wechat_live',
        'oauth2',
        'minipay',
        'mini_program.now_notice',
        'v3pay',
        'mini_program.open3rd',
        'order_ship',
        'new_notice',
        'mini_program.mini_scheme',
    ];
    $resolved = [];
    foreach ($serviceIds as $serviceId) {
        $service = $wechat[$serviceId];
        $resolved[$serviceId] = is_object($service) ? get_class($service) : gettype($service);
        probeCheck($checks, 'service_' . str_replace(['.', '-'], '_', $serviceId), is_object($service));
    }
    probeCheck($checks, 'all_service_ids_resolved', array_keys($resolved) === $serviceIds);
    probeCheck($checks, 'program_open3rd_constructed', ($resolved['mini_program.open3rd'] ?? '')
        === crmeb\services\easywechat\Open3rd\ProgramOpen3rd::class);

    return $checks;
}

function probePayment(): array
{
    $checks = [];
    probeNewApp('payment');
    $providers = [];
    foreach (['wechat_pay', 'ali_pay'] as $driver) {
        $providers[$driver] = probeInvokeManagerDriver(new crmeb\services\pay\Pay($driver, []), $driver);
    }
    probeCheck($checks, 'wechat_payment_provider_constructed', $providers['wechat_pay']
        instanceof crmeb\services\pay\storage\WechatPay);
    probeCheck($checks, 'ali_payment_provider_constructed', $providers['ali_pay']
        instanceof crmeb\services\pay\storage\AliPay);
    probeCheck($checks, 'payment_methods_available', method_exists($providers['wechat_pay'], 'create')
        && method_exists($providers['ali_pay'], 'create'));
    probeCheck($checks, 'refund_methods_available_without_call', method_exists($providers['wechat_pay'], 'refund')
        && method_exists($providers['ali_pay'], 'refund'));

    return $checks;
}

function probeSms(): array
{
    $checks = [];
    probeNewApp('sms');
    $configs = [
        'aliyun' => [
            'sms_account' => 'neutral-account',
            'sms_token' => 'neutral-token',
            'aliyun_SignName' => 'Example Shop',
            'aliyun_AccessKeyId' => 'neutral-key',
            'aliyun_AccessKeySecret' => 'neutral-secret',
        ],
        'chuanglan' => ['sms_account' => 'neutral-account', 'sms_token' => 'neutral-token'],
        'tencent' => [
            'sms_account' => 'neutral-account',
            'sms_token' => 'neutral-token',
            'tencent_sms_app_id' => '1400000000',
            'tencent_sms_secret_id' => 'neutral-id',
            'tencent_sms_secret_key' => 'neutral-key',
            'tencent_sms_sign_name' => 'Example Shop',
            'tencent_sms_region' => 'ap-guangzhou',
        ],
        'yihaotong' => ['sms_account' => 'neutral-account', 'sms_token' => 'neutral-token'],
    ];
    $expected = [
        'aliyun' => crmeb\services\sms\storage\Aliyun::class,
        'chuanglan' => crmeb\services\sms\storage\Chuanglan::class,
        'tencent' => crmeb\services\sms\storage\Tencent::class,
        'yihaotong' => crmeb\services\sms\storage\Yihaotong::class,
    ];
    foreach ($configs as $driver => $config) {
        $provider = probeInvokeManagerDriver(new crmeb\services\sms\Sms($driver, $config), $driver);
        probeCheck($checks, 'sms_' . $driver . '_constructed', $provider instanceof $expected[$driver]);
        probeCheck($checks, 'sms_' . $driver . '_send_not_invoked', method_exists($provider, 'send'));
    }

    return $checks;
}

function probeUploads(): array
{
    $checks = [];
    probeNewApp('uploads');
    $config = [
        'accessKey' => 'neutral-access-key',
        'secretKey' => 'neutral-secret-key',
        'uploadUrl' => 'storage.example.invalid',
        'storageName' => 'neutral-bucket',
        'storageRegion' => 'cn-north-1',
        'appid' => '1000000000',
        'cdn' => '',
    ];
    $providers = [
        'local' => new crmeb\services\upload\storage\Local('local', [], 'upload'),
        'oss' => new crmeb\services\upload\storage\Oss('oss', $config, 'upload'),
        'qiniu' => new crmeb\services\upload\storage\Qiniu('qiniu', $config, 'upload'),
        'cos' => new crmeb\services\upload\storage\Cos('cos', $config, 'upload'),
        'obs' => new crmeb\services\upload\storage\Obs('obs', $config, 'upload'),
        'tyoss' => new crmeb\services\upload\storage\Tyoss('tyoss', $config, 'upload'),
        'jdoss' => new crmeb\services\upload\storage\Jdoss('jdoss', $config, 'upload'),
    ];
    $expected = [
        'local' => crmeb\services\upload\storage\Local::class,
        'oss' => crmeb\services\upload\storage\Oss::class,
        'qiniu' => crmeb\services\upload\storage\Qiniu::class,
        'cos' => crmeb\services\upload\storage\Cos::class,
        'obs' => crmeb\services\upload\storage\Obs::class,
        'tyoss' => crmeb\services\upload\storage\Tyoss::class,
        'jdoss' => crmeb\services\upload\storage\Jdoss::class,
    ];
    foreach ($providers as $name => $provider) {
        probeCheck($checks, 'upload_' . $name . '_constructed', $provider instanceof $expected[$name]);
    }

    $appMethod = new ReflectionMethod($providers['jdoss'], 'app');
    $appMethod->setAccessible(true);
    $jdossClient = $appMethod->invoke($providers['jdoss']);
    probeCheck($checks, 'jdoss_lazy_client_is_s3', $jdossClient instanceof Aws\S3\S3Client);

    return $checks;
}

function probeServiceDiscovery(): array
{
    $checks = [];
    $writeTestPath = '/var/www/.php82-read-only-' . bin2hex(random_bytes(6));
    $childCode = '$result = @file_put_contents(' . var_export($writeTestPath, true)
        . ', "probe"); exit($result === false ? 0 : 1);';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $childCode], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start read-only filesystem check');
    }
    fclose($pipes[0]);
    $childStdout = stream_get_contents($pipes[1]);
    $childStderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $writeStatus = proc_close($process);
    probeCheck($checks, 'var_www_rejects_write', $writeStatus === 0 && !file_exists($writeTestPath));
    probeCheck($checks, 'write_check_silent', $childStdout === '' && $childStderr === '');

    $shadowRoot = '/tmp/php82-service-discovery-' . bin2hex(random_bytes(6));
    $composerDirectory = $shadowRoot . '/vendor/composer';
    if (!mkdir($composerDirectory, 0700, true) && !is_dir($composerDirectory)) {
        throw new RuntimeException('Unable to create service discovery shadow root');
    }
    if (!copy('/var/www/vendor/composer/installed.json', $composerDirectory . '/installed.json')) {
        throw new RuntimeException('Unable to copy Composer service metadata');
    }
    $metadata = json_decode((string) file_get_contents($composerDirectory . '/installed.json'), true);
    $packages = isset($metadata['packages']) ? $metadata['packages'] : $metadata;
    $expectedServices = [];
    foreach ($packages as $package) {
        $services = $package['extra']['think']['services'] ?? [];
        if ($services !== []) {
            $expectedServices = array_merge($expectedServices, (array) $services);
        }
    }

    $app = new think\App($shadowRoot);
    $command = new think\console\command\ServiceDiscover();
    $command->setApp($app);
    $input = new think\console\Input([]);
    $input->setInteractive(false);
    $output = new think\console\Output('buffer');
    $status = $command->run($input, $output);
    $commandOutput = $output->fetch();
    $generatedPath = $shadowRoot . '/vendor/services.php';
    $generatedServices = is_file($generatedPath) ? include $generatedPath : null;
    $missingClasses = array_values(array_filter(
        $expectedServices,
        static function (string $service): bool {
            return !class_exists($service);
        }
    ));
    $shadowFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($shadowRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $shadowFiles[] = str_replace('\\', '/', substr($file->getPathname(), strlen($shadowRoot) + 1));
        }
    }
    sort($shadowFiles);
    probeCheck($checks, 'service_discover_exit_zero', $status === 0);
    probeCheck($checks, 'service_discover_reported_success', strip_tags(trim($commandOutput)) === 'Succeed!');
    probeCheck($checks, 'metadata_services_nonempty', $expectedServices !== []);
    probeCheck($checks, 'generated_services_exact_match', $generatedServices === $expectedServices);
    probeCheck($checks, 'all_service_classes_exist', $missingClasses === []);
    probeCheck($checks, 'shadow_root_contains_only_metadata_and_services', $shadowFiles === [
        'vendor/composer/installed.json',
        'vendor/services.php',
    ]);

    return $checks;
}

function probeOrmSql(): array
{
    $checks = [];
    $connectorClass = 'crmeb\\compat\\think\\orm\\db\\connector\\Mysql';
    $connectorExists = class_exists($connectorClass);
    probeCheck($checks, 'project_connector_exists', $connectorExists);
    if (!$connectorExists) {
        return $checks;
    }

    $reflection = new ReflectionClass($connectorClass);
    $connector = $reflection->newInstanceWithoutConstructor();
    $named = $connector->getRealSql(
        'SELECT * FROM sample_records WHERE id = :id',
        ['id' => [7, PDO::PARAM_INT]]
    );
    $positional = $connector->getRealSql(
        'SELECT * FROM sample_records WHERE id = ?',
        [[11, PDO::PARAM_INT]]
    );
    probeCheck($checks, 'named_integer_binding_rendered', $named === 'SELECT * FROM sample_records WHERE id = 7');
    probeCheck($checks, 'positional_integer_binding_rendered', $positional === 'SELECT * FROM sample_records WHERE id = 11');
    probeCheck($checks, 'connector_extends_thinkorm_mysql', is_subclass_of($connectorClass, think\db\connector\Mysql::class));
    probeCheck($checks, 'mysql_builder_retained', $connector->getBuilderClass() === think\db\builder\Mysql::class);

    return $checks;
}

function probeFormBuilder(): array
{
    $checks = [];
    $component = FormBuilder\Factory\Elm::input('catalog_title', 'Catalog label', 'Catalog item');
    $baseClass = new ReflectionClass(FormBuilder\Driver\CustomComponent::class);
    $expectedClassFile = '/var/www/crmeb/compat/form-builder/Driver/CustomComponent.php';

    probeCheck($checks, 'project_compatibility_class_loaded', $baseClass->getFileName() === $expectedClassFile);
    probeCheck($checks, 'component_extends_custom_component', $component instanceof FormBuilder\Driver\CustomComponent);

    $expectedReturnTypes = [
        'jsonSerialize' => 'mixed',
        'offsetExists' => 'bool',
        'offsetGet' => 'mixed',
        'offsetSet' => 'void',
        'offsetUnset' => 'void',
    ];
    foreach ($expectedReturnTypes as $methodName => $expectedType) {
        $returnType = $baseClass->getMethod($methodName)->getReturnType();
        probeCheck(
            $checks,
            'return_type_' . $methodName,
            $returnType instanceof ReflectionNamedType && $returnType->getName() === $expectedType
        );
    }

    $built = $component->build();
    $serialized = $component->jsonSerialize();
    $encoded = json_decode(json_encode($component, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    $toJson = json_decode($component->toJson(), true, 512, JSON_THROW_ON_ERROR);
    $normalizedBuilt = json_decode(json_encode($built, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    $normalizedSerialized = json_decode(json_encode($serialized, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    probeCheck($checks, 'json_serialize_matches_build', $normalizedSerialized === $normalizedBuilt);
    probeCheck($checks, 'json_encode_matches_build', $encoded === $normalizedBuilt);
    probeCheck($checks, 'to_json_matches_build', $toJson === $normalizedBuilt);

    $component['disabled'] = true;
    probeCheck($checks, 'array_access_set_and_exists', isset($component['disabled']));
    probeCheck($checks, 'array_access_get', $component['disabled'] === true);
    unset($component['disabled']);
    probeCheck($checks, 'array_access_unset', !isset($component['disabled']));

    return $checks;
}

function probeRemoteUpgradeDisabled(): array
{
    $checks = [];
    $container = new think\Container();
    $container->instance('json', new class {
        public function success($data = null): array
        {
            return ['status' => 200, 'data' => $data];
        }

        public function fail($message = null, $data = null): array
        {
            return ['status' => 400, 'msg' => $message, 'data' => $data];
        }
    });
    think\Container::setInstance($container);

    $reflection = new ReflectionClass(app\adminapi\controller\UpgradeController::class);
    $controller = $reflection->newInstanceWithoutConstructor();
    $capability = $controller->capability();
    probeCheck($checks, 'capability_business_success', ($capability['status'] ?? null) === 200);
    probeCheck($checks, 'remote_upgrade_disabled', ($capability['data']['remote_enabled'] ?? null) === false);

    $disabledResponses = [
        $controller->upgradeList(),
        $controller->upgradeableList(),
        $controller->agreement(),
        $controller->packageDownload('offline-package'),
        $controller->upgradeStatus(),
    ];
    foreach ($disabledResponses as $index => $response) {
        probeCheck($checks, 'disabled_endpoint_' . $index . '_status', ($response['status'] ?? null) === 400);
        probeCheck(
            $checks,
            'disabled_endpoint_' . $index . '_message',
            ($response['msg'] ?? null) === '官方在线升级服务未启用'
        );
    }

    return $checks;
}

function probeEmptyFilterRules(): array
{
    $checks = [];
    require_once '/var/www/app/common.php';
    $container = new think\Container();
    $container->instance('sysConfig', new class {
        public function get(string $name)
        {
            return $name === 'param_filter_type' ? 2 : base64_encode('');
        }
    });
    think\Container::setInstance($container);

    $input = 'http://example.test/catalog/item';
    probeCheck($checks, 'empty_filter_rules_leave_input_unchanged', filter_str($input) === $input);

    return $checks;
}

function probeWorkermanTimer(): array
{
    $checks = [];
    $reflection = new ReflectionClass(Workerman\Events\Select::class);
    probeCheck(
        $checks,
        'project_compatibility_class_loaded',
        $reflection->getFileName() === '/var/www/crmeb/compat/workerman/Events/Select.php'
    );

    $timerFired = false;
    $loop = $reflection->newInstance();
    $loop->add(0, Workerman\Events\EventInterface::EV_TIMER_ONCE, static function () use (&$timerFired): void {
        $timerFired = true;
        throw new RuntimeException('workerman-timer-probe-complete');
    });
    try {
        $loop->loop();
    } catch (RuntimeException $exception) {
        probeCheck($checks, 'timer_exits_through_probe_callback', $exception->getMessage() === 'workerman-timer-probe-complete');
    }
    probeCheck($checks, 'zero_delay_timer_callback_fired', $timerFired);

    return $checks;
}

function probeDaoJoinAliases(): array
{
    $checks = [];

    foreach ([
        'user_user_brokerage' => app\dao\user\UserUserBrokerageDao::class,
        'user_user_bill' => app\dao\user\UserUserBillDao::class,
    ] as $name => $class) {
        $reflection = new ReflectionClass($class);
        $dao = $reflection->newInstanceWithoutConstructor();
        $writeAlias = Closure::bind(
            static function (object $target): string {
                $target->join_alis = 'b';

                return $target->join_alis;
            },
            null,
            $class
        );

        probeCheck($checks, $name . '_declares_join_alias', $reflection->hasProperty('join_alis'));
        probeCheck($checks, $name . '_join_alias_round_trip', $writeAlias($dao) === 'b');
    }

    return $checks;
}

$caseHandlers = [
    'jwt' => 'probeJwt',
    'spreadsheet' => 'probeSpreadsheet',
    'order-export' => 'probeOrderExport',
    'product-export' => 'probeProductExport',
    'easywechat' => 'probeEasyWechat',
    'payment' => 'probePayment',
    'sms' => 'probeSms',
    'uploads' => 'probeUploads',
    'service-discovery' => 'probeServiceDiscovery',
    'orm-sql' => 'probeOrmSql',
    'form-builder' => 'probeFormBuilder',
    'remote-upgrade-disabled' => 'probeRemoteUpgradeDisabled',
    'empty-filter-rules' => 'probeEmptyFilterRules',
    'workerman-timer' => 'probeWorkermanTimer',
    'dao-join-aliases' => 'probeDaoJoinAliases',
];

try {
    require '/var/www/vendor/autoload.php';
    require '/var/www/vendor/topthink/framework/src/helper.php';
    if (!isset($caseHandlers[$probeCase])) {
        throw new InvalidArgumentException('Unknown integration case: ' . $probeCase);
    }
    $probeState['checks'] = $caseHandlers[$probeCase]();
} catch (Throwable $throwable) {
    $probeState['throwable'] = probeThrowable($throwable);
}

probeEmit();
exit($probeState['status'] === 'pass' ? 0 : 1);
