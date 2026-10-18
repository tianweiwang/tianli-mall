<?php
declare(strict_types=1);

require_once __DIR__ . '/marketplace-ms4-fulfillment.php';

/** Task7 structure only; no App, DML, credentials, files, backfill or writer activation. */
final class MarketplaceMs4AftersaleMigration
{
    public const MARKER = 'marketplace-ms4-aftersale-v1';
    public const FILE_MARKER = 'marketplace-ms4-aftersale-file-v1';
    public const SCOPE = 'MS4_AFTERSALE_STRUCTURE_ONLY';
    private bool $writeAttempted = false;
    private array $applied = [];
    private string $database;
    private array $sourceHashes;

    public function __construct(private PDO $pdo, private string $prefix, string $database)
    {
        self::quote($database);
        if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1 || PHP_INT_SIZE < 8
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || !$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)
            || !$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)
            || $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new RuntimeException('Migration configuration unavailable');
        }
        foreach ($this->contracts(20) as $name => $definition) {
            self::quote($prefix . $name);
            foreach (array_keys($definition['foreign'] + $definition['checks']) as $constraint) self::quote($prefix . $constraint);
        }
        foreach (array_keys(self::producerContracts()) as $name) self::quote($prefix . $name);
        $this->database = $database;
        $this->sourceHashes = self::sourceHashes();
    }

    private static function sourceHashes(): array
    {
        $out = [];
        foreach (['marketplace-ms1.php', 'marketplace-ms4.php', 'marketplace-ms5-payment.php', 'marketplace-ms4-fulfillment.php'] as $name) {
            $path = __DIR__ . '/' . $name;
            if (!is_file($path) || is_link($path) || !is_string($hash = hash_file('sha256', $path))) throw new RuntimeException('Prerequisite source unavailable');
            $out['help/migrations/' . $name] = $hash;
        }
        $install = __DIR__ . '/../../crmeb/public/install/crmeb.sql';
        if (!is_file($install) || is_link($install) || !is_string($hash = hash_file('sha256', $install))) throw new RuntimeException('Installation source unavailable');
        $out['crmeb/public/install/crmeb.sql'] = $hash;
        if (!is_file(__FILE__) || is_link(__FILE__) || !is_string($hash = hash_file('sha256', __FILE__))) throw new RuntimeException('Migration source unavailable');
        $out['self'] = $hash;
        return $out;
    }

    private function assertSources(): void
    {
        if (self::sourceHashes() !== $this->sourceHashes) throw new RuntimeException('Migration source changed');
    }

    private static function column(string $type, string $comment = '', bool $nullable = false, ?string $default = null, ?string $charset = null, bool $auto = false): array
    {
        return compact('type', 'comment', 'nullable', 'default', 'charset', 'auto');
    }

    /** CHECK accepts TRUE only: both FALSE and SQL UNKNOWN are rejected. */
    private static function truth(string $predicate): string { return 'COALESCE((' . $predicate . '),0)=1'; }

    public static function newTables(): array
    {
        $c = static fn(string $type, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, '', $null, $default, $charset, $auto);
        $actor = "(`actor_type`='customer' AND `actor_id` IS NOT NULL AND `actor_id`=`uid` AND `actor_ref`=CONCAT('customer:',`actor_id`) AND `action`<>'finance_observe')"
            . " OR (`actor_type`='employee' AND `actor_id` IS NOT NULL AND `actor_id`>0 AND `actor_ref`=CONCAT('employee:',`actor_id`) AND `action`<>'finance_observe')"
            . " OR (`actor_type`='admin' AND `actor_id` IS NOT NULL AND `actor_id`>0 AND `actor_ref`=CONCAT('admin:',`actor_id`) AND `action`<>'finance_observe')"
            . " OR (`actor_type`='payment' AND `actor_id` IS NULL AND `actor_ref`=CONCAT('payment:',`payment_attempt_id`) AND `action`='finance_observe')";
        $unbound = '`refund_id` IS NULL AND `image_position` IS NULL AND `claimed_at` IS NULL';
        return [
            'marketplace_aftersale_action' => [
                'table_collation' => 'utf8mb4_unicode_ci', 'table_comment' => self::MARKER,
                'columns' => [
                    'id' => $c('bigint unsigned', false, null, null, true),
                    'contract_version' => $c('tinyint unsigned', false, '1'),
                    'root_order_id' => $c('int unsigned'), 'uid' => $c('int unsigned'), 'mer_id' => $c('int unsigned'),
                    'refund_id' => $c('int'), 'payment_attempt_id' => $c('bigint unsigned'),
                    'action' => $c('varchar(24)', false, null, 'ascii'), 'actor_type' => $c('varchar(16)', false, null, 'ascii'),
                    'actor_id' => $c('int unsigned', true), 'actor_ref' => $c('varchar(64)', false, null, 'ascii'),
                    'idem_key' => $c('varchar(128)', false, null, 'ascii'), 'request_digest' => $c('binary(32)'),
                    'request_payload' => $c('json'), 'result_payload' => $c('json', true), 'state' => $c('tinyint unsigned', false, '0'),
                    'previous_refund_action_id' => $c('bigint unsigned', true), 'fulfillment_cut_id' => $c('bigint unsigned', true),
                    'apply_refund_id' => $c('int', true), 'created_at' => $c('int unsigned'), 'committed_at' => $c('int unsigned', true),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']], 'uq_ms4a_actor_key' => [true, ['actor_ref', 'idem_key']],
                    'uq_ms4a_apply_refund' => [true, ['apply_refund_id']], 'uq_ms4a_previous' => [true, ['previous_refund_action_id']],
                    'idx_ms4a_root' => [false, ['root_order_id', 'id']], 'idx_ms4a_refund' => [false, ['refund_id', 'id']],
                    'idx_ms4a_payment' => [false, ['payment_attempt_id']], 'idx_ms4a_fcut' => [false, ['fulfillment_cut_id']],
                ],
                'foreign' => [
                    'mm4a_root_fk' => ['store_order', ['root_order_id'], ['id'], 'PRIMARY'],
                    'mm4a_refund_fk' => ['store_order_refund', ['refund_id'], ['id'], 'PRIMARY'],
                    'mm4a_payment_fk' => ['marketplace_payment_attempt', ['payment_attempt_id'], ['id'], 'PRIMARY'],
                    'mm4a_previous_fk' => ['marketplace_aftersale_action', ['previous_refund_action_id'], ['id'], 'PRIMARY'],
                    'mm4a_fcut_fk' => ['marketplace_fulfillment_operation', ['fulfillment_cut_id'], ['id'], 'PRIMARY'],
                ],
                'checks' => [
                    'mm4a_ids' => self::truth('`contract_version`=1 AND `root_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `refund_id`>0 AND `payment_attempt_id`>0 AND `created_at`>0 AND (`previous_refund_action_id` IS NULL OR (`previous_refund_action_id` IS NOT NULL AND `previous_refund_action_id`>0)) AND (`fulfillment_cut_id` IS NULL OR (`fulfillment_cut_id` IS NOT NULL AND `fulfillment_cut_id`>0))'),
                    'mm4a_actor' => self::truth($actor),
                    'mm4a_action' => self::truth("`action` IN ('apply','withdraw','approve','reject','express','accept_return','finance_observe') AND ((`action`='apply' AND `apply_refund_id` IS NOT NULL AND `apply_refund_id`=`refund_id` AND `previous_refund_action_id` IS NULL) OR (`action`<>'apply' AND `apply_refund_id` IS NULL AND `previous_refund_action_id` IS NOT NULL))"),
                    'mm4a_state' => self::truth('(`state`=0 AND `result_payload` IS NULL AND `committed_at` IS NULL) OR (`state`=1 AND `result_payload` IS NOT NULL AND `committed_at` IS NOT NULL AND `committed_at`>=`created_at`)'),
                    'mm4a_json' => self::truth("JSON_TYPE(`request_payload`)='OBJECT' AND (`result_payload` IS NULL OR (JSON_TYPE(`result_payload`)='OBJECT'))"),
                ],
            ],
            'marketplace_aftersale_file' => [
                'table_collation' => 'utf8mb4_unicode_ci', 'table_comment' => self::FILE_MARKER,
                'columns' => [
                    'id' => $c('bigint unsigned', false, null, null, true),
                    'uid' => $c('int unsigned'), 'root_order_id' => $c('int unsigned'), 'mer_id' => $c('int unsigned'),
                    'refund_id' => $c('int', true), 'image_position' => $c('tinyint unsigned', true),
                    'object_key' => $c('char(64)', false, null, 'ascii'), 'media_type' => $c('varchar(32)', false, null, 'ascii'),
                    'byte_size' => $c('int unsigned'), 'width' => $c('smallint unsigned'), 'height' => $c('smallint unsigned'),
                    'content_sha256' => $c('binary(32)'), 'upload_key' => $c('varchar(128)', false, null, 'ascii'),
                    'state' => $c('varchar(16)', false, null, 'ascii'), 'created_at' => $c('int unsigned'),
                    'claim_expires_at' => $c('int unsigned'), 'claimed_at' => $c('int unsigned', true), 'purged_at' => $c('int unsigned', true),
                ],
                'indexes' => [
                    'PRIMARY' => [true, ['id']], 'uq_ms4af_object_key' => [true, ['object_key']],
                    'uq_ms4af_upload' => [true, ['uid', 'upload_key']], 'uq_ms4af_refund_position' => [true, ['refund_id', 'image_position']],
                    'idx_ms4af_root' => [false, ['root_order_id', 'id']], 'idx_ms4af_expiry' => [false, ['state', 'claim_expires_at', 'id']],
                ],
                'foreign' => [
                    'mm4af_root_fk' => ['store_order', ['root_order_id'], ['id'], 'PRIMARY'],
                    'mm4af_refund_fk' => ['store_order_refund', ['refund_id'], ['id'], 'PRIMARY'],
                ],
                'checks' => [
                    'mm4af_values' => self::truth("`uid` BETWEEN 1 AND 2147483647 AND `root_order_id` BETWEEN 1 AND 2147483647 AND `mer_id`>0 AND `byte_size` BETWEEN 1 AND 5242880 AND `width` BETWEEN 1 AND 4096 AND `height` BETWEEN 1 AND 4096 AND `media_type` IN ('image/jpeg','image/png','image/webp') AND OCTET_LENGTH(`content_sha256`)=32 AND OCTET_LENGTH(`object_key`)=64 AND REGEXP_LIKE(`object_key`,'[^0-9a-f]','c')=0 AND OCTET_LENGTH(`upload_key`) BETWEEN 1 AND 128 AND REGEXP_LIKE(`upload_key`,'^[!-~]([ -~]{0,126}[!-~])?$','c')=1 AND REGEXP_LIKE(`upload_key`,'[^ -~]','c')=0 AND `created_at` BETWEEN 1 AND 4294880895 AND `claim_expires_at`=`created_at`+86400"),
                    'mm4af_state' => self::truth("(`state`='STAGED' AND " . $unbound . " AND `purged_at` IS NULL) OR (`state`='CLAIMED' AND `refund_id` IS NOT NULL AND `refund_id`>0 AND `image_position` IS NOT NULL AND `image_position` BETWEEN 0 AND 8 AND `claimed_at` IS NOT NULL AND `claimed_at`>=`created_at` AND `claimed_at`<=`claim_expires_at` AND `purged_at` IS NULL) OR (`state`='DELETE_PENDING' AND " . $unbound . " AND `purged_at` IS NULL) OR (`state`='DELETED' AND " . $unbound . " AND `purged_at` IS NOT NULL AND `purged_at`>=`created_at`)"),
                ],
            ],
        ];
    }

    public static function refundAdditions(): array
    {
        $c = static fn(string $type, ?string $charset = null): array => self::column($type, '', true, null, $charset);
        $legacy = [];
        foreach (['marketplace_root_order_id','marketplace_payment_attempt_id','marketplace_kind','marketplace_state',
            'marketplace_physical_state','marketplace_finance_state','marketplace_apply_action_id','marketplace_last_action_id'] as $name) $legacy[] = self::quote($name) . ' IS NULL';
        $nonnull = [];
        foreach (['marketplace_root_order_id','marketplace_payment_attempt_id','marketplace_kind','marketplace_state',
            'marketplace_physical_state','marketplace_finance_state','marketplace_apply_action_id','marketplace_last_action_id','mer_id','marketplace_refund_mode'] as $name) $nonnull[] = self::quote($name) . ' IS NOT NULL';
        $valid = implode(' AND ', $nonnull) . ' AND `marketplace_root_order_id` BETWEEN 1 AND 2147483647 AND `uid` BETWEEN 1 AND 2147483647 AND `marketplace_payment_attempt_id`>0 AND `marketplace_apply_action_id`>0 AND `marketplace_last_action_id`>=`marketplace_apply_action_id` AND `mer_id`>0 AND `refund_price`>0 AND `refunded_price`>=0 AND `refunded_price`<=`refund_price`'
            . " AND ((`marketplace_kind`='money_only' AND `marketplace_refund_mode`='money_only') OR (`marketplace_kind`='cancel_unshipped' AND `marketplace_refund_mode`='money_only') OR (`marketplace_kind`='return_goods' AND `marketplace_refund_mode`='return_goods'))"
            . " AND `marketplace_finance_state` IN ('NOT_SUBMITTED','UNSUPPORTED','PROCESSING','UNKNOWN','PARTIAL','SUCCEEDED','FAILED')"
            . " AND ((`marketplace_state`='REQUESTED' AND `refunded_price`=0 AND `marketplace_finance_state`='NOT_SUBMITTED' AND ((`marketplace_kind`='money_only' AND `marketplace_physical_state`='NONE') OR (`marketplace_kind`='cancel_unshipped' AND `marketplace_physical_state`='CANCEL_PENDING') OR (`marketplace_kind`='return_goods' AND `marketplace_physical_state`='RETURN_PENDING')))"
            . " OR (`marketplace_state`='APPROVED' AND ((`marketplace_kind`='money_only' AND `marketplace_physical_state`='NONE') OR (`marketplace_kind`='cancel_unshipped' AND `marketplace_physical_state`='CANCELLED') OR (`marketplace_kind`='return_goods' AND `marketplace_physical_state` IN ('RETURN_PENDING','RETURN_PARTIAL','RETURN_ACCEPTED'))))"
            . " OR (`marketplace_state` IN ('WITHDRAWN','REJECTED') AND `refunded_price`=0 AND `marketplace_physical_state`='NONE' AND `marketplace_finance_state` IN ('NOT_SUBMITTED','UNSUPPORTED'))"
            . " OR (`marketplace_state`='COMPLETED' AND `refunded_price`=`refund_price` AND `marketplace_finance_state`='SUCCEEDED' AND ((`marketplace_kind`='money_only' AND `marketplace_physical_state`='NONE') OR (`marketplace_kind`='cancel_unshipped' AND `marketplace_physical_state`='CANCELLED') OR (`marketplace_kind`='return_goods' AND `marketplace_physical_state`='RETURN_ACCEPTED'))))";
        return [
            'columns' => [
                'marketplace_root_order_id' => $c('int unsigned'), 'marketplace_payment_attempt_id' => $c('bigint unsigned'),
                'marketplace_kind' => $c('varchar(24)', 'ascii'), 'marketplace_state' => $c('varchar(16)', 'ascii'),
                'marketplace_physical_state' => $c('varchar(24)', 'ascii'), 'marketplace_finance_state' => $c('varchar(16)', 'ascii'),
                'marketplace_apply_action_id' => $c('bigint unsigned'), 'marketplace_last_action_id' => $c('bigint unsigned'),
            ],
            'indexes' => ['idx_ms4a_refund_root' => [false, ['marketplace_root_order_id','id']],
                'uq_ms4a_refund_apply' => [true, ['marketplace_apply_action_id']], 'idx_ms4a_refund_last' => [false, ['marketplace_last_action_id']]],
            'foreign' => ['mm4a_refund_apply_fk' => ['marketplace_aftersale_action', ['marketplace_apply_action_id'], ['id'], 'PRIMARY'],
                'mm4a_refund_last_fk' => ['marketplace_aftersale_action', ['marketplace_last_action_id'], ['id'], 'PRIMARY']],
            'checks' => ['mm4a_refund_v1' => self::truth('(' . implode(' AND ', $legacy) . ') OR (' . $valid . ')')],
        ];
    }

    /** Original installation's 24 columns plus the two exact original MS4 additions. */
    private static function refundBase(): array
    {
        $c = static fn(string $type, string $comment, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, $comment, $null, $default, $charset, $auto);
        $old = MarketplaceMs4Migration::additions()['store_order_refund'];
        $old['columns']['mer_id']['auto'] = false;
        $old['columns']['marketplace_refund_mode']['auto'] = false;
        return ['table_collation' => null, 'table_comment' => '退款订单表',
            'columns' => [
                'id' => $c('int', '自增ID', false, null, null, true), 'store_order_id' => $c('int', '订单表ID', false, '0'),
                'store_id' => $c('int', '门店ID', false, '0'), 'order_id' => $c('varchar(50)', '订单号', false, '', 'utf8mb4'),
                'uid' => $c('int', '用户UID', false, '0'), 'refund_type' => $c('tinyint', '退款申请类型', false, '0'),
                'refund_num' => $c('int', '退款件数', false, '0'), 'refund_price' => $c('decimal(12,2)', '退款金额', false, '0.00'),
                'refunded_price' => $c('decimal(12,2)', '已退款金额', false, '0.00'), 'refund_phone' => $c('varchar(32)', '退款电话', false, '', 'utf8mb4'),
                'refund_express' => $c('varchar(100)', '退货快递单号', false, '', 'utf8mb4'), 'refund_express_name' => $c('varchar(255)', '退货快递名称', false, '', 'utf8mb4'),
                'refund_explain' => $c('varchar(255)', '退款用户说明', false, '', 'utf8mb4'), 'refund_img' => $c('text', '退款图片', true, null, 'utf8mb4'),
                'refund_reason' => $c('varchar(255)', '不退款的理由', false, '', 'utf8mb4'), 'refuse_reason' => $c('varchar(255)', '拒绝原因', false, '', 'utf8mb4'),
                'remark' => $c('varchar(255)', '备注', false, '', 'utf8mb4'), 'refunded_time' => $c('int', '处理时间', false, '0'),
                'cart_info' => $c('longtext', '退款商品信息', true, null, 'utf8mb4'), 'is_cancel' => $c('tinyint', '用户是否取消', false, '0'),
                'is_pink_cancel' => $c('tinyint', '是否拼团自动取消', false, '0'), 'is_del' => $c('tinyint', '取消申请', true, '0'),
                'add_time' => $c('int', '申请退款时间', false, '0'), 'is_system_del' => $c('tinyint', '系统删除', true, '0'),
            ] + $old['columns'], 'indexes' => ['PRIMARY' => [true, ['id']]] + $old['indexes'], 'foreign' => [], 'checks' => []];
    }

    /** Exactly one state for every prefix of the 20 fixed, atomic DDL steps. */
    private function contracts(int $stage): array
    {
        if ($stage < 0 || $stage > 20) throw new RuntimeException('Unknown structure stage');
        $tables = MarketplaceMs4FulfillmentMigration::newTables();
        foreach ($tables as &$table) { $table['table_collation'] = 'utf8mb4_bin'; $table['table_comment'] = MarketplaceMs4FulfillmentMigration::MARKER; } unset($table);
        $refund = self::refundBase(); $add = self::refundAdditions();
        foreach (['columns' => 2, 'indexes' => 10, 'foreign' => 13, 'checks' => 15] as $kind => $first) {
            $position = $first;
            foreach ($add[$kind] as $name => $value) { if ($stage >= $position) $refund[$kind][$name] = $value; ++$position; }
        }
        $op = &$tables['marketplace_fulfillment_operation'];
        if ($stage >= 16) $op['columns']['aftersale_cut_id'] = self::column('bigint unsigned', '', true);
        if ($stage >= 17) $op['indexes']['idx_fop_aftersale_cut'] = [false, ['aftersale_cut_id']];
        if ($stage >= 18) $op['foreign']['mm4a_fop_cut_fk'] = ['marketplace_aftersale_action', ['aftersale_cut_id'], ['id'], 'PRIMARY'];
        if ($stage >= 19) {
            $old = $op['checks']['mm4f_op_ids'];
            if (substr_count($old, '`contract_version`=1') !== 1) throw new RuntimeException('Original version check unavailable');
            $op['checks']['mm4f_op_ids'] = self::truth(str_replace('`contract_version`=1', '`contract_version` IN (1,2)', $old));
            $op['checks']['mm4a_op_cut'] = self::truth('(`contract_version`=1 AND `aftersale_cut_id` IS NULL) OR (`contract_version`=2 AND `aftersale_cut_id` IS NOT NULL AND `aftersale_cut_id`>0)');
        }
        unset($op);
        $new = self::newTables();
        return ['marketplace_aftersale_action' => $stage >= 1 ? $new['marketplace_aftersale_action'] : null,
            'store_order_refund' => $refund] + $tables + ['marketplace_aftersale_file' => $stage >= 20 ? $new['marketplace_aftersale_file'] : null];
    }

    private function foreignSql(string $name, array $contract): string
    {
        [$target, $columns, $references] = $contract;
        return 'CONSTRAINT ' . self::quote($this->prefix . $name) . ' FOREIGN KEY (' . implode(',', array_map([self::class, 'quote'], $columns))
            . ') REFERENCES ' . $this->table($target) . ' (' . implode(',', array_map([self::class, 'quote'], $references)) . ') ON UPDATE RESTRICT ON DELETE RESTRICT';
    }

    private function checkSql(string $name, string $expression): string
    {
        if (self::checkExpression($expression) === null) throw new RuntimeException('Declared check unavailable');
        return 'CONSTRAINT ' . self::quote($this->prefix . $name) . ' CHECK (' . $expression . ') ENFORCED';
    }

    private function plan(): array
    {
        $new = self::newTables(); $add = self::refundAdditions();
        $steps = [['code' => 'create.marketplace_aftersale_action', 'sql' => $this->tableDdl('marketplace_aftersale_action', $new['marketplace_aftersale_action'])]];
        foreach ($add['columns'] as $name => $column) $steps[] = ['code' => 'refund.column.' . $name,
            'sql' => 'ALTER TABLE ' . $this->table('store_order_refund') . ' ADD COLUMN ' . self::quote($name) . ' ' . $this->columnSql($column, $column['charset'] === 'ascii' ? 'ascii_bin' : null)];
        foreach ($add['indexes'] as $name => $index) $steps[] = ['code' => 'refund.index.' . $name, 'sql' => 'ALTER TABLE ' . $this->table('store_order_refund') . ' ADD ' . $this->indexSql($name, $index)];
        foreach ($add['foreign'] as $name => $foreign) $steps[] = ['code' => 'refund.foreign.' . $name, 'sql' => 'ALTER TABLE ' . $this->table('store_order_refund') . ' ADD ' . $this->foreignSql($name, $foreign)];
        foreach ($add['checks'] as $name => $expression) $steps[] = ['code' => 'refund.check.' . $name, 'sql' => 'ALTER TABLE ' . $this->table('store_order_refund') . ' ADD ' . $this->checkSql($name, $expression)];
        $op = $this->contracts(20)['marketplace_fulfillment_operation'];
        $steps[] = ['code' => 'operation.column.aftersale_cut_id', 'sql' => 'ALTER TABLE ' . $this->table('marketplace_fulfillment_operation') . ' ADD COLUMN `aftersale_cut_id` ' . $this->columnSql($op['columns']['aftersale_cut_id'], null)];
        $steps[] = ['code' => 'operation.index.idx_fop_aftersale_cut', 'sql' => 'ALTER TABLE ' . $this->table('marketplace_fulfillment_operation') . ' ADD ' . $this->indexSql('idx_fop_aftersale_cut', $op['indexes']['idx_fop_aftersale_cut'])];
        $steps[] = ['code' => 'operation.foreign.mm4a_fop_cut_fk', 'sql' => 'ALTER TABLE ' . $this->table('marketplace_fulfillment_operation') . ' ADD ' . $this->foreignSql('mm4a_fop_cut_fk', $op['foreign']['mm4a_fop_cut_fk'])];
        $steps[] = ['code' => 'operation.checks.replace_known_ids_and_add_cut', 'sql' => 'ALTER TABLE ' . $this->table('marketplace_fulfillment_operation')
            . ' DROP CHECK ' . self::quote($this->prefix . 'mm4f_op_ids') . ', ADD ' . $this->checkSql('mm4f_op_ids', $op['checks']['mm4f_op_ids']) . ', ADD ' . $this->checkSql('mm4a_op_cut', $op['checks']['mm4a_op_cut'])];
        $steps[] = ['code' => 'create.marketplace_aftersale_file', 'sql' => $this->tableDdl('marketplace_aftersale_file', $new['marketplace_aftersale_file'])];
        if (count($steps) !== 20) throw new RuntimeException('Fixed structure plan unavailable');
        return $steps;
    }

    public function check(): array
    {
        $this->writeAttempted = false; $this->applied = [];
        try { return $this->receipt('check', $this->inspect()); }
        catch (Throwable $error) { return $this->failure('check', 'inspection_failed'); }
    }

    public function applyStructure(): array
    {
        $this->writeAttempted = false; $this->applied = []; $locked = false; $phase = 'precheck_failed';
        $name = 'ms4-aftersale:' . substr(hash('sha256', $this->database . "\0" . $this->prefix . "\0" . self::MARKER), 0, 48);
        try {
            $view = $this->inspect();
            if ($view['issues'] !== []) return $this->receipt('apply-structure', $view);
            $phase = 'lock_failed';
            if ((string)$this->scalar('SELECT GET_LOCK(?,10)', [$name]) !== '1') throw new RuntimeException('Migration lock unavailable');
            $locked = true; $phase = 'locked_recheck_failed'; $view = $this->inspect();
            if ($view['issues'] !== []) $result = $this->receipt('apply-structure', $view);
            else {
                $stage = $view['stage'];
                foreach ($view['steps'] as $step) {
                    $phase = 'structure_apply_failed'; $this->assertSources(); $this->writeAttempted = true;
                    if ($this->pdo->exec($step['sql']) === false) throw new RuntimeException('Structure apply failed');
                    $this->applied[] = $step['code']; $this->assertSources();
                    $phase = 'step_readback_failed'; $after = $this->inspect();
                    if ($after['issues'] !== [] || $after['stage'] !== ++$stage) throw new RuntimeException('Structure step readback failed');
                }
                $phase = 'postcheck_failed'; $after = $this->inspect();
                if ($after['issues'] !== [] || $after['steps'] !== [] || $after['stage'] !== 20) throw new RuntimeException('Structure postcheck failed');
                $result = $this->receipt('apply-structure', $after);
            }
        } catch (Throwable $error) { $result = $this->failure('apply-structure', $phase); }
        finally {
            if ($locked) {
                try { if ((string)$this->scalar('SELECT RELEASE_LOCK(?)', [$name]) !== '1') throw new RuntimeException('Migration lock release failed'); }
                catch (Throwable $error) { $result = $this->failure('apply-structure', 'lock_release_failed'); }
            }
        }
        return $result;
    }

    private function inspect(): array
    {
        $this->assertSources(); $issues = []; $steps = [];
        if ($this->pdo->inTransaction()) $issues[] = self::issue('active_transaction', 'migration');
        if ($this->scalar('SELECT DATABASE()') !== $this->database) $issues[] = self::issue('database_changed', 'migration');
        if ($issues !== []) return ['issues' => $issues, 'steps' => [], 'known' => false, 'stage' => null];
        $version = $this->scalar('SELECT VERSION()');
        if (!is_string($version) || preg_match('/\A(8\.[0-9]+\.[0-9]+)(?:[-+].*)?\z/D', $version, $match) !== 1
            || stripos($version, 'mariadb') !== false || version_compare($match[1], '8.0.16', '<')) {
            return ['issues' => [self::issue('mysql_version', 'migration')], 'steps' => [], 'known' => false, 'stage' => null];
        }
        $modes = $this->rows('SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode');
        foreach (['session_mode','global_mode'] as $key) {
            $flags = explode(',', strtoupper((string)($modes[0][$key] ?? '')));
            if (!in_array('STRICT_TRANS_TABLES', $flags, true) && !in_array('STRICT_ALL_TABLES', $flags, true)) $issues[] = self::issue('strict_sql_mode', $key);
        }
        foreach (['ms4' => new MarketplaceMs4Migration($this->pdo, $this->prefix, $this->database),
            'ms5' => new MarketplaceMs5PaymentMigration($this->pdo, $this->prefix, $this->database)] as $name => $migration) {
            $receipt = $migration->check();
            if (($receipt['native_exit'] ?? null) === 1) throw new RuntimeException('Prerequisite inspection unavailable');
            if (($receipt['native_exit'] ?? null) !== 0 || ($receipt['state'] ?? null) !== 'completed'
                || ($receipt['changed'] ?? null) !== false || ($receipt['issues'] ?? null) !== []) $issues[] = self::issue($name . '_schema_not_completed', 'migration');
        }
        $this->checkProducer($issues);
        $actual = []; $final = $this->contracts(20);
        foreach ($final as $name => $contract) {
            $actual[$name] = $this->metadata($name);
            foreach (['foreign' => 'FOREIGN KEY', 'checks' => 'CHECK'] as $kind => $type) foreach (array_keys($contract[$kind]) as $constraint) {
                $occupied = $this->rows('SELECT CONSTRAINT_NAME,TABLE_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND CONSTRAINT_NAME=? ORDER BY BINARY CONSTRAINT_NAME,BINARY TABLE_NAME', [$this->database, $this->prefix . $constraint]);
                if (count($occupied) > 1 || ($occupied !== [] && ($occupied[0]['CONSTRAINT_NAME'] !== $this->prefix . $constraint
                    || $occupied[0]['TABLE_NAME'] !== $this->prefix . $name || $occupied[0]['CONSTRAINT_TYPE'] !== $type || $actual[$name] === null))) $issues[] = self::issue('constraint_name_conflict', $constraint);
            }
        }
        $matches = [];
        for ($stage = 0; $stage <= 20; ++$stage) {
            $same = true;
            foreach ($this->contracts($stage) as $name => $contract) {
                $meta = $actual[$name];
                if ($contract === null ? $meta !== null : ($meta === null || !$this->sameNewTable($meta, $contract))) { $same = false; break; }
            }
            if ($same) $matches[] = $stage;
        }
        if (count($matches) !== 1) {
            $issues[] = self::issue('not_an_exact_fixed_structure_prefix', 'aftersale');
            return ['issues' => $issues, 'steps' => [], 'known' => false, 'stage' => null];
        }
        $stage = $matches[0]; $steps = array_slice($this->plan(), $stage); $this->assertSources();
        return ['issues' => $issues, 'steps' => $steps, 'known' => true, 'stage' => $stage];
    }

    private static function producerContracts(): array
    {
        $c = static fn(string $type, bool $null = false, ?string $default = null, ?string $charset = null, bool $auto = false): array => self::column($type, '', $null, $default, $charset, $auto);
        $stock = MarketplaceMs4Migration::newTables()['marketplace_stock_movement'];
        foreach ($stock['columns'] as $name => &$column) $column['auto'] = $name === 'id';
        unset($column);
        return [
            'user' => ['columns' => ['uid' => $c('int unsigned', false, null, null, true)], 'indexes' => ['PRIMARY' => [true, ['uid']]]],
            'store_order' => [
                'columns' => ['id' => $c('int unsigned', false, null, null, true), 'uid' => $c('int unsigned', false, '0'),
                    'mer_id' => $c('int unsigned', false, '0'), 'pid' => $c('int', false, '0'),
                    'marketplace_trade_version' => $c('tinyint unsigned', true, '0')],
                'indexes' => ['PRIMARY' => [true, ['id']], 'owner' => [false, ['mer_id', 'id']]],
            ],
            'store_order_cart_info' => [
                'columns' => ['id' => $c('int', false, null, null, true), 'oid' => $c('int', false, '0'),
                    'uid' => $c('int', false, '0'), 'mer_id' => $c('int unsigned', true), 'product_id' => $c('int', false, '0'),
                    'cart_num' => $c('int', false, '0'), 'refund_num' => $c('int', false, '0'),
                    'surplus_num' => $c('int', false, '0'), 'split_status' => $c('int', false, '0'),
                    'cart_id' => $c('varchar(50)', false, '0', 'utf8mb4'), 'cart_info' => $c('text', true, null, 'utf8mb4'),
                    'unique' => $c('char(32)', false, '', 'utf8mb4')],
                'indexes' => ['PRIMARY' => [true, ['id']], 'item_identity' => [true, ['oid', 'unique']], 'item_owner' => [false, ['mer_id', 'oid', 'id']]],
            ],
            'marketplace_merchant' => ['columns' => ['mer_id' => $c('int unsigned', false, null, null, true)],
                'indexes' => ['PRIMARY' => [true, ['mer_id']]]],
            'marketplace_stock_movement' => $stock,
        ];
    }

    private function checkProducer(array &$issues): void
    {
        foreach (self::producerContracts() as $name => $contract) {
            $meta = $this->metadata($name);
            if (!$this->baseTable($meta)) { $issues[] = self::issue('producer_table', $name); continue; }
            foreach ($contract['columns'] as $column => $expected) {
                $collation = $expected['charset'] === 'ascii' ? 'ascii_bin'
                    : ($expected['charset'] === 'utf8mb4' ? $meta['table']['TABLE_COLLATION'] : null);
                if (!isset($meta['columns'][$column]) || !$this->sameColumn($meta['columns'][$column], $expected, $collation)) $issues[] = self::issue('producer_column', $name . '.' . $column);
            }
            foreach ($contract['indexes'] as $index => $expected) {
                $found = $index === 'PRIMARY' && $this->sameIndex($meta['indexes']['PRIMARY'] ?? [], $expected);
                if ($index !== 'PRIMARY') foreach ($meta['indexes'] as $actual) if ($this->sameIndex($actual, $expected)) $found = true;
                if (!$found) $issues[] = self::issue('producer_index', $name . '.' . $index);
            }
        }
    }

    private function baseTable(?array $meta): bool
    {
        return $meta !== null && $meta['table']['TABLE_TYPE'] === 'BASE TABLE'
            && strcasecmp((string) $meta['table']['ENGINE'], 'InnoDB') === 0
            && preg_match('/\Autf8mb4_[A-Za-z0-9_]+\z/D', (string) $meta['table']['TABLE_COLLATION']) === 1
            && $meta['triggers'] === [];
    }

    private function sameNewTable(array $meta, array $contract): bool
    {
        if (!$this->baseTable($meta) || ($contract['table_collation'] !== null && $meta['table']['TABLE_COLLATION'] !== $contract['table_collation'])
            || $meta['table']['TABLE_COMMENT'] !== $contract['table_comment']
            || array_keys($meta['columns']) !== array_keys($contract['columns'])) return false;
        foreach ($contract['columns'] as $name => $expected) {
            $collation = $expected['charset'] === 'ascii' ? 'ascii_bin' : ($expected['charset'] === 'utf8mb4' ? $meta['table']['TABLE_COLLATION'] : null);
            if (!$this->sameColumn($meta['columns'][$name], $expected, $collation, true)) return false;
        }
        $indexes = $contract['indexes'];
        ksort($indexes, SORT_STRING);
        if (array_keys($meta['indexes']) !== array_keys($indexes)) return false;
        foreach ($indexes as $name => $expected) if (!$this->sameIndex($meta['indexes'][$name], $expected)) return false;
        if (!$this->sameForeign($meta['foreign'], $contract['foreign'])) return false;
        $checks = [];
        foreach ($meta['checks'] as $row) {
            $name = $row['CONSTRAINT_NAME'];
            $tree = self::checkMetadataExpression($row['CHECK_CLAUSE']);
            if (isset($checks[$name]) || $row['ENFORCED'] !== 'YES' || $tree === null) return false;
            $checks[$name] = $tree;
        }
        $wanted = [];
        foreach ($contract['checks'] as $name => $expression) {
            $tree = self::checkExpression($expression);
            if ($tree === null) throw new RuntimeException('Declared check unavailable');
            $wanted[$this->prefix . $name] = $tree;
        }
        ksort($checks, SORT_STRING); ksort($wanted, SORT_STRING);
        if ($checks !== $wanted) return false;
        $wantedConstraints = [];
        foreach ($contract['indexes'] as $name => [$unique]) if ($unique) $wantedConstraints[$name] = $name === 'PRIMARY' ? 'PRIMARY KEY' : 'UNIQUE';
        foreach ($contract['foreign'] as $name => $_) $wantedConstraints[$this->prefix . $name] = 'FOREIGN KEY';
        foreach ($contract['checks'] as $name => $_) $wantedConstraints[$this->prefix . $name] = 'CHECK';
        $actualConstraints = [];
        foreach ($meta['constraints'] as $row) {
            if (isset($actualConstraints[$row['CONSTRAINT_NAME']])) return false;
            $actualConstraints[$row['CONSTRAINT_NAME']] = $row['CONSTRAINT_TYPE'];
        }
        ksort($actualConstraints, SORT_STRING); ksort($wantedConstraints, SORT_STRING);
        return $actualConstraints === $wantedConstraints;
    }

    private function sameForeign(array $rows, array $expected): bool
    {
        $actual = [];
        foreach ($rows as $row) $actual[$row['CONSTRAINT_NAME']][] = $row;
        $wanted = [];
        foreach ($expected as $name => $contract) $wanted[$this->prefix . $name] = $contract;
        ksort($actual, SORT_STRING); ksort($wanted, SORT_STRING);
        if (array_keys($actual) !== array_keys($wanted)) return false;
        foreach ($wanted as $name => [$target, $columns, $references, $unique]) {
            if (count($actual[$name]) !== count($columns)) return false;
            foreach ($actual[$name] as $offset => $row) {
                if ((string)$row['ORDINAL_POSITION'] !== (string)($offset + 1)
                    || (string)$row['POSITION_IN_UNIQUE_CONSTRAINT'] !== (string)($offset + 1)
                    || $row['COLUMN_NAME'] !== $columns[$offset] || $row['REFERENCED_COLUMN_NAME'] !== $references[$offset]
                    || $row['REFERENCED_TABLE_SCHEMA'] !== $this->database || $row['REFERENCED_TABLE_NAME'] !== $this->prefix . $target
                    || $row['UNIQUE_CONSTRAINT_SCHEMA'] !== $this->database || $row['UNIQUE_CONSTRAINT_NAME'] !== $unique
                    || $row['UPDATE_RULE'] !== 'RESTRICT' || $row['DELETE_RULE'] !== 'RESTRICT'
                    || $row['MATCH_OPTION'] !== 'NONE') return false;
            }
        }
        return true;
    }

    private function sameColumn(array $actual, array $expected, ?string $collation, bool $comment = false): bool
    {
        return $actual['COLUMN_TYPE'] === $expected['type']
            && $actual['IS_NULLABLE'] === ($expected['nullable'] ? 'YES' : 'NO')
            && $actual['COLUMN_DEFAULT'] === $expected['default']
            && $actual['CHARACTER_SET_NAME'] === $expected['charset']
            && $actual['COLLATION_NAME'] === $collation
            && $actual['EXTRA'] === ($expected['auto'] ? 'auto_increment' : '')
            && (string) $actual['GENERATION_EXPRESSION'] === ''
            && (!$comment || $actual['COLUMN_COMMENT'] === $expected['comment']);
    }

    private function sameIndex(array $actual, array $expected): bool
    {
        [$unique, $columns] = $expected;
        if (count($actual) !== count($columns)) return false;
        foreach ($actual as $i => $row) if ((int) $row['SEQ_IN_INDEX'] !== $i + 1
            || (string) $row['NON_UNIQUE'] !== ($unique ? '0' : '1') || $row['COLUMN_NAME'] !== $columns[$i]
            || $row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES'
            || $row['EXPRESSION'] !== null || $row['COLLATION'] !== 'A') return false;
        return true;
    }

    private function metadata(string $name): ?array
    {
        $args = [$this->database, $this->prefix . $name];
        $tables = $this->rows('SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', $args);
        if ($tables === []) return null;
        if (count($tables) !== 1) throw new RuntimeException('Metadata unavailable');
        $columns = [];
        foreach ($this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION,COLUMN_COMMENT,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', $args) as $column) {
            if ((string)$column['ORDINAL_POSITION'] !== (string)(count($columns) + 1) || isset($columns[$column['COLUMN_NAME']])) {
                throw new RuntimeException('Column metadata unavailable');
            }
            $column['COLUMN_TYPE'] = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower($column['COLUMN_TYPE']));
            if ($column['COLUMN_DEFAULT'] !== null) $column['COLUMN_DEFAULT'] = (string)$column['COLUMN_DEFAULT'];
            $columns[$column['COLUMN_NAME']] = $column;
        }
        $indexes = [];
        foreach ($this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY BINARY INDEX_NAME,SEQ_IN_INDEX', $args) as $index) $indexes[$index['INDEX_NAME']][] = $index;
        ksort($indexes, SORT_STRING);
        return ['table' => $tables[0], 'columns' => $columns, 'indexes' => $indexes,
            'constraints' => $this->rows('SELECT CONSTRAINT_NAME,CONSTRAINT_TYPE,ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY BINARY CONSTRAINT_NAME', $args),
            'foreign' => $this->rows('SELECT rc.CONSTRAINT_NAME,rc.UPDATE_RULE,rc.DELETE_RULE,rc.MATCH_OPTION,rc.UNIQUE_CONSTRAINT_SCHEMA,rc.UNIQUE_CONSTRAINT_NAME,kcu.ORDINAL_POSITION,kcu.POSITION_IN_UNIQUE_CONSTRAINT,kcu.COLUMN_NAME,kcu.REFERENCED_TABLE_SCHEMA,kcu.REFERENCED_TABLE_NAME,kcu.REFERENCED_COLUMN_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS rc JOIN information_schema.KEY_COLUMN_USAGE kcu ON kcu.CONSTRAINT_SCHEMA=rc.CONSTRAINT_SCHEMA AND kcu.TABLE_NAME=rc.TABLE_NAME AND kcu.CONSTRAINT_NAME=rc.CONSTRAINT_NAME WHERE rc.CONSTRAINT_SCHEMA=? AND rc.TABLE_NAME=? ORDER BY BINARY rc.CONSTRAINT_NAME,kcu.ORDINAL_POSITION', $args),
            'checks' => $this->rows("SELECT tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=? AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY BINARY tc.CONSTRAINT_NAME", $args),
            'triggers' => $this->rows('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=? ORDER BY BINARY TRIGGER_NAME', $args)];
    }

    /** Exact literals used by the original checks and these finite Task7 checks. */
    private const CHECK_LITERALS = ['OBJECT','ship','receive','employee','customer','system','level','member',
        'admin','payment','customer:','employee:','admin:','payment:','finance_observe','apply','withdraw','approve','reject','express','accept_return',
        'money_only','cancel_unshipped','return_goods','NOT_SUBMITTED','UNSUPPORTED','PROCESSING','UNKNOWN','PARTIAL','SUCCEEDED','FAILED',
        'REQUESTED','APPROVED','WITHDRAWN','REJECTED','COMPLETED','NONE','CANCEL_PENDING','CANCELLED','RETURN_PENDING','RETURN_PARTIAL','RETURN_ACCEPTED',
        'image/jpeg','image/png','image/webp','[^0-9a-f]','^[!-~]([ -~]{0,126}[!-~])?$','[^ -~]','c','STAGED','CLAIMED','DELETE_PENDING','DELETED'];

    /** Only the original observed escaped-literal presentation is decoded, for the finite literals above. */
    private static function checkMetadataExpression(string $expression): ?array
    {
        foreach (self::CHECK_LITERALS as $literal) foreach (['_utf8mb4','_ascii'] as $charset)
            $expression = str_replace($charset . "\\'" . $literal . "\\'", $charset . "'" . $literal . "'", $expression);
        return self::checkExpression($expression);
    }

    /** Original finite grammar with only the fixed Task7 functions/arity; never evaluated as SQL/PHP. */
    private static function checkExpression(string $expression): ?array
    {
        if (strlen($expression) > 16384) return null;
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($expression)) {
            if (!preg_match("/\\G(?:\\s+|`([A-Za-z0-9_]+)`|('(?:[^'\\\\]|'')*')|([A-Za-z_][A-Za-z0-9_]*)|([0-9]+)|(<>|<=|>=|[(),+\\-=<>]))/A", $expression, $match, 0, $offset)) return null;
            $offset += strlen($match[0]);
            if (trim($match[0]) === '') continue;
            if (($match[1] ?? '') !== '') $tokens[] = ['id', strtolower($match[1])];
            elseif (($match[2] ?? '') !== '') $tokens[] = ['string', str_replace("''", "'", substr($match[2], 1, -1))];
            elseif (($match[3] ?? '') !== '') $tokens[] = ['word', strtolower($match[3])];
            elseif (($match[4] ?? '') !== '') $tokens[] = ['number', $match[4]];
            else $tokens[] = ['symbol', $match[5]];
            if (count($tokens) > 4096) return null;
        }
        $position = 0; $depth = 0;
        $peek = static function (string $value) use (&$tokens, &$position): bool {
            return isset($tokens[$position]) && $tokens[$position][1] === $value && in_array($tokens[$position][0], ['word', 'symbol'], true);
        };
        $take = static function (string $value) use (&$position, $peek): bool {
            if (!$peek($value)) return false;
            ++$position; return true;
        };
        $or = $and = $comparison = $addition = $primary = null;
        $primary = static function () use (&$tokens, &$position, &$depth, &$or, $take): array {
            if (++$depth > 128) throw new RuntimeException('Check grammar');
            try {
                if ($take('(')) {
                    $node = $or();
                    if (!$take(')')) throw new RuntimeException('Check grammar');
                    return $node;
                }
                $token = $tokens[$position++] ?? null;
                if ($token === null) throw new RuntimeException('Check grammar');
                if (in_array($token, [['word', '_utf8mb4'], ['word', '_ascii']], true)) {
                    $token = $tokens[$position++] ?? null;
                    if (($token[0] ?? '') !== 'string') throw new RuntimeException('Check grammar');
                }
                if ($token[0] === 'string') {
                    if (!in_array($token[1], self::CHECK_LITERALS, true)) throw new RuntimeException('Check grammar');
                    return $token;
                }
                if ($token[0] === 'number') {
                    if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $token[1]) !== 1) throw new RuntimeException('Check grammar');
                    return $token;
                }
                if ($token === ['word','true'] || $token === ['word','false']) return ['number', $token[1] === 'true' ? '1' : '0'];
                if ($token[0] !== 'id' && $token[0] !== 'word') throw new RuntimeException('Check grammar');
                if ($take('(')) {
                    $arity = ['json_type'=>1,'coalesce'=>2,'concat'=>2,'octet_length'=>1,'regexp_like'=>3][$token[1]] ?? null;
                    if ($arity === null) throw new RuntimeException('Check grammar');
                    $arguments = [$or()];
                    while ($take(',')) $arguments[] = $or();
                    if (!$take(')')) throw new RuntimeException('Check grammar');
                    if (count($arguments) !== $arity) throw new RuntimeException('Check grammar');
                    // Keep the original single-argument JSON tree byte contract.
                    return $token[1] === 'json_type' ? ['function','json_type',$arguments[0]] : ['function',$token[1],$arguments];
                }
                return ['id', $token[1]];
            } finally { --$depth; }
        };
        $addition = static function () use (&$primary, $peek, $take): array {
            $node = $primary();
            while ($peek('+') || $peek('-')) {
                $operator = $take('+') ? '+' : '-';
                if ($operator === '-' && !$take('-')) throw new RuntimeException('Check grammar');
                $node = [$operator, $node, $primary()];
            }
            return $node;
        };
        $comparison = static function () use (&$addition, $take): array {
            $node = $addition();
            foreach (['=', '<>', '<=', '>=', '<', '>'] as $operator) if ($take($operator)) return [$operator, $node, $addition()];
            if ($take('is')) {
                $negative = $take('not');
                if (!$take('null')) throw new RuntimeException('Check grammar');
                return [$negative ? 'is_not_null' : 'is_null', $node];
            }
            if ($take('between')) {
                $lower = $addition();
                if (!$take('and')) throw new RuntimeException('Check grammar');
                return ['between', $node, $lower, $addition()];
            }
            if ($take('in')) {
                if (!$take('(')) throw new RuntimeException('Check grammar');
                $values = [$addition()];
                while ($take(',')) $values[] = $addition();
                if (!$take(')')) throw new RuntimeException('Check grammar');
                return ['in', $node, $values];
            }
            return $node;
        };
        $flatten = static function (string $operator, array $nodes): array {
            if (count($nodes) === 1) return $nodes[0];
            $flat = [];
            foreach ($nodes as $node) foreach ($node[0] === $operator ? $node[1] : [$node] as $part) $flat[] = $part;
            return [$operator, $flat];
        };
        $and = static function () use (&$comparison, $take, $flatten): array {
            $nodes = [$comparison()];
            while ($take('and')) $nodes[] = $comparison();
            return $flatten('and', $nodes);
        };
        $or = static function () use (&$and, $take, $flatten): array {
            $nodes = [$and()];
            while ($take('or')) $nodes[] = $and();
            return $flatten('or', $nodes);
        };
        try {
            $tree = $or();
            return $position === count($tokens) ? $tree : null;
        } catch (Throwable $error) { return null; }
    }

    private function columnSql(array $column, ?string $collation): string
    {
        $sql = strtoupper($column['type']);
        if ($column['charset'] !== null) $sql .= ' CHARACTER SET ' . $column['charset'] . ' COLLATE ' . $collation;
        $sql .= $column['nullable'] ? ' NULL' : ' NOT NULL';
        if ($column['default'] !== null) $sql .= ' DEFAULT ' . $this->pdo->quote($column['default']);
        elseif ($column['nullable']) $sql .= ' DEFAULT NULL';
        if ($column['auto']) $sql .= ' AUTO_INCREMENT';
        return $sql . ' COMMENT ' . $this->pdo->quote($column['comment']);
    }

    private function tableDdl(string $name, array $definition): string
    {
        $parts = [];
        foreach ($definition['columns'] as $column => $contract) {
            $collation = $contract['charset'] === 'ascii' ? 'ascii_bin' : null;
            $parts[] = self::quote($column) . ' ' . $this->columnSql($contract, $collation);
        }
        foreach ($definition['indexes'] as $index => $contract) $parts[] = $this->indexSql($index, $contract);
        foreach ($definition['checks'] as $constraint => $expression) {
            if (self::checkExpression($expression) === null) throw new RuntimeException('Declared check unavailable');
            $parts[] = 'CONSTRAINT ' . self::quote($this->prefix . $constraint) . ' CHECK (' . $expression . ') ENFORCED';
        }
        foreach ($definition['foreign'] as $constraint => [$target, $columns, $referenced]) {
            $parts[] = 'CONSTRAINT ' . self::quote($this->prefix . $constraint) . ' FOREIGN KEY ('
                . implode(',', array_map([self::class, 'quote'], $columns)) . ') REFERENCES ' . $this->table($target)
                . ' (' . implode(',', array_map([self::class, 'quote'], $referenced)) . ') ON UPDATE RESTRICT ON DELETE RESTRICT';
        }
        return 'CREATE TABLE ' . $this->table($name) . " (\n  " . implode(",\n  ", $parts)
            . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=" . $definition['table_collation'] . ' COMMENT=' . $this->pdo->quote($definition['table_comment']);
    }

    private function indexSql(string $name, array $contract): string
    {
        return ($name === 'PRIMARY' ? 'PRIMARY KEY' : ($contract[0] ? 'UNIQUE KEY ' : 'KEY ') . self::quote($name))
            . ' (' . implode(',', array_map([self::class, 'quote'], $contract[1])) . ')';
    }

    private function receipt(string $mode, array $view, ?string $phase = null): array
    {
        try { $this->assertSources(); }
        catch (Throwable $error) {
            $phase ??= 'source_changed';
            $view['issues'][] = self::issue('source_changed', 'migration');
            $view['known'] = false;
        }
        $state = $view['issues'] !== [] ? 'incompatible' : ($view['steps'] === [] ? 'completed' : 'applicable');
        $prerequisites = $this->sourceHashes;
        unset($prerequisites['self']);
        return ['mode' => $mode, 'state' => $state, 'native_exit' => $phase !== null ? 1 : ($state === 'incompatible' ? 2 : 0),
            'changed' => $this->writeAttempted, 'applied_steps' => $this->applied,
            'pending_steps' => array_column($view['steps'], 'code'), 'pending_steps_known' => $view['known'] ?? true,
            'structure_stage' => $view['stage'] ?? null, 'structure_step_count' => 20,
            'issues' => $view['issues'], 'failure_phase' => $phase, 'marker' => self::MARKER,
            'source_sha256' => $this->sourceHashes['self'], 'scope' => self::SCOPE,
            'prerequisite_source_sha256' => $prerequisites, 'writer' => 'NOT RUN', 'acceptance' => 'NOT RUN'];
    }

    private function failure(string $mode, string $phase): array
    {
        try { $view = $this->inspect(); }
        catch (Throwable $error) { $view = ['issues' => [], 'steps' => [], 'known' => false, 'stage' => null]; }
        $view['issues'][] = self::issue('operation_failed', 'migration');
        return $this->receipt($mode, $view, $phase);
    }

    private function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        if (!($statement instanceof PDOStatement) || !$statement->execute($parameters)) throw new RuntimeException('Metadata read failed');
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) throw new RuntimeException('Metadata unavailable');
        return $rows;
    }

    private function scalar(string $sql, array $parameters = []): mixed
    {
        $rows = $this->rows($sql, $parameters);
        return $rows === [] ? null : $rows[0][array_key_first($rows[0])];
    }

    private function table(string $name): string { return self::quote($this->prefix . $name); }
    private static function issue(string $kind, string $target): array { return compact('kind', 'target'); }
    public static function quote(string $name): string
    {
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) !== 1) throw new RuntimeException('Identifier unavailable');
        return '`' . $name . '`';
    }
}

/** Bounded explicit JSON parser; private-file owner/mode/tmpfs are the isolated caller's responsibility. */
function marketplaceMs4AftersaleConfiguration(string $path): array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('Configuration unavailable');
    try { $raw = stream_get_contents($handle, 65537); } finally { fclose($handle); }
    if (!is_string($raw) || strlen($raw) > 65536) throw new RuntimeException('Configuration unavailable');
    $config = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    $keys = ['hostname', 'hostport', 'database', 'username', 'password', 'prefix'];
    if (!is_array($config) || array_is_list($config) || array_diff(array_keys($config), $keys) !== []) throw new RuntimeException('Configuration unavailable');
    foreach (['hostname', 'database', 'username', 'password', 'prefix'] as $key) if (!isset($config[$key]) || !is_string($config[$key])) throw new RuntimeException('Configuration unavailable');
    $port = $config['hostport'] ?? '3306';
    if ((!is_string($port) && !is_int($port)) || preg_match('/\A[1-9][0-9]{0,4}\z/D', (string) $port) !== 1 || (int) $port > 65535
        || $config['username'] === '' || preg_match('/\A[A-Za-z0-9_.:\[\]-]+\z/D', $config['hostname']) !== 1
        || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $config['database']) !== 1 || preg_match('/\A[A-Za-z0-9_]*\z/D', $config['prefix']) !== 1) throw new RuntimeException('Configuration unavailable');
    $config['hostport'] = (string) $port;
    return $config;
}

function marketplaceMs4AftersaleMain(array $arguments): int
{
    if ($arguments === ['help'] || $arguments === ['--help']) {
        echo "Usage: php help/migrations/marketplace-ms4-aftersale.php [check|apply-structure] --config-file=PRIVATE_JSON\nDefault check is read-only. Require exact original MS4/MS5/fulfillment sources and structure. Only the fixed 20-step Task7 prefix may be applied: two new tables, eight nullable refund columns, one nullable operation cut, exact indexes/FKs/checks. The only DROP is the atomic exact replacement of the known mm4f_op_ids CHECK. No .env, App, DML, backfill, GRANT, private-object writes, historical conversion or writer activation. DDL may partially commit; preserve real applied/pending facts.\n";
        return 0;
    }
    $mode = 'check'; $file = null; $selected = false;
    try {
        foreach ($arguments as $argument) {
            if (in_array($argument, ['check', 'apply-structure'], true) && !$selected) { $mode = $argument; $selected = true; }
            elseif (str_starts_with($argument, '--config-file=') && $file === null && strlen($argument) > 14) $file = substr($argument, 14);
            else throw new RuntimeException('CLI configuration unavailable');
        }
        if ($file === null) throw new RuntimeException('Explicit private configuration required');
        $config = marketplaceMs4AftersaleConfiguration($file);
        $pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3]);
        $migration = new MarketplaceMs4AftersaleMigration($pdo, $config['prefix'], $config['database']);
        $result = $mode === 'check' ? $migration->check() : $migration->applyStructure();
    } catch (Throwable $error) {
        // No SQL, driver error, private path, configuration value or credential is emitted.
        $result = ['mode' => $mode, 'state' => 'incompatible', 'native_exit' => 1, 'changed' => false,
            'applied_steps' => [], 'pending_steps' => [], 'pending_steps_known' => false, 'structure_stage' => null, 'structure_step_count' => 20,
            'issues' => [['kind' => 'configuration_or_connection_failed', 'target' => 'migration']],
            'failure_phase' => 'configuration_or_connection_failed', 'marker' => MarketplaceMs4AftersaleMigration::MARKER,
            'source_sha256' => hash_file('sha256', __FILE__), 'scope' => MarketplaceMs4AftersaleMigration::SCOPE,
            'prerequisite_source_sha256' => [], 'writer' => 'NOT RUN', 'acceptance' => 'NOT RUN'];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    return $result['native_exit'];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) exit(marketplaceMs4AftersaleMain(array_slice($argv, 1)));
