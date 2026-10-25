<?php
declare(strict_types=1);

namespace Audit\Migration;

use PDO;
use JsonException;
use DOMDocument;
use RuntimeException;
use Throwable;

/** External four-command review candidate. No framework bootstrap or feature activation. */
final class MarketplaceMs2Migration
{
    private const MARKER = 'marketplace-ms2-structure-v1';
    private const SELF_COLUMNS = ['mer_id', 'name', 'logo', 'after_sale_contact', 'shipping_note', 'review_status', 'accept_orders', 'fulfillment_enabled', 'security_locked', 'closed_at', 'add_time', 'update_time'];
    private const ROOTS = ['system_attachment' => 'att_id', 'system_attachment_category' => 'id', 'shipping_templates' => 'id', 'store_product_rule' => 'id'];
    private const REVIEW = [
        'review_status' => ['tinyint unsigned', false, '0'],
        'review_reason' => ['varchar(512)', false, ''],
        'review_admin_id' => ['smallint unsigned', true, null],
        'review_time' => ['int unsigned', true, null],
    ];
    private PDO $pdo;
    private MarketplaceMs1Migration $ms1;
    private string $prefix;
    private string $database;
    private string $repoRoot;
    private ?int $configuredSelfId;
    private ?string $stateFile;
    /** @var resource|null */
    private $stateHandle = null;
    private ?string $stateBytesHash = null;
    private bool $changed = false;
    private bool $writeAttempted = false;
    private array $applied = [];

    public function __construct(PDO $pdo, MarketplaceMs1Migration $ms1, string $prefix, string $repoRoot, array $marketplace = [], ?string $stateFile = null)
    {
        if (array_diff(array_keys($marketplace), ['enabled', 'self_operated_mer_id']) !== [] || (array_key_exists('enabled', $marketplace) && $marketplace['enabled'] !== false)) {
            throw new RuntimeException('Configuration unavailable');
        }
        $id = $marketplace['self_operated_mer_id'] ?? null;
        if ($id !== null && (!is_int($id) || $id < 1 || $id > 4294967295)) { throw new RuntimeException('Configuration unavailable'); }
        if (PHP_INT_SIZE < 8 || preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1 || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Configuration unavailable');
        }
        $root = realpath($repoRoot);
        if ($root === false || !is_dir($root)) { throw new RuntimeException('Configuration unavailable'); }
        $this->pdo = $pdo;
        $this->ms1 = $ms1;
        $this->prefix = $prefix;
        $this->repoRoot = $root;
        $this->configuredSelfId = $id;
        $this->stateFile = $stateFile === null ? null : $this->privateStatePath($stateFile);
        if (!$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION) || !$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)) { throw new RuntimeException('Configuration unavailable'); }
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') { throw new RuntimeException('Configuration unavailable'); }
        $this->database = $database;
        foreach (array_merge(array_keys(self::ROOTS), ['store_product', 'store_product_attr_value', 'marketplace_stock_log', 'marketplace_merchant', 'marketplace_employee', 'mm2_stock_positive', 'mm2_stock_merchant_fk', 'mm2_stock_employee_fk']) as $name) {
            self::identifier($prefix . $name);
        }
    }

    public function check(?string $mappingFile = null): array
    {
        $this->changed = false;
        $this->writeAttempted = false;
        $this->applied = [];
        try {
            $view = $this->inspect();
            $identity = $view['issues'] === [] ? $this->identity($view) : ['kind' => 'unchecked', 'id' => null, 'issues' => []];
            $result = $this->receipt('check', $view, $identity);
            if ($view['issues'] === [] && $view['steps'] === [] && in_array($identity['kind'], ['configured', 'bound'], true)) {
                $catalog = $this->catalogRows();
                $binding = $this->mappingBinding($view, $identity);
                $mapping = $mappingFile === null ? null : $this->mapping($mappingFile, $binding, $catalog);
                $inventory = $this->ownershipInventory($catalog, $mapping['confirmed'] ?? [], $mapping['image_links'] ?? [], $mapping['attachment_category_links'] ?? []);
                $result['mapping_binding'] = $binding;
                $result['mapping_expectations'] = $this->catalogSummary($catalog);
                $result['ownership'] = $this->publicInventory($inventory);
                $result['strict_sql_mode'] = $this->strictSqlModes();
                $result['migration_complete'] = $view['ownership_phase'] === 'enforced' && $mapping !== null && $this->inventoryClear($inventory) && $mapping['all_confirmed_at_target'] && $result['strict_sql_mode']['session'] && $result['strict_sql_mode']['global'];
            }
            return $result;
        } catch (Throwable $ignored) {
            return $this->failure('check', 'inspection_failed');
        }
    }

    public function applyStructure(): array
    {
        $this->changed = false;
        $this->writeAttempted = false;
        $this->applied = [];
        $locked = false;
        $transaction = false;
        $phase = 'precheck_failed';
        $result = null;
        $lockName = 'marketplace-ms2:' . substr(hash('sha256', $this->database . "\0" . $this->prefix . "\0" . self::MARKER), 0, 48);
        try {
            if ($this->pdo->inTransaction()) { return $this->failure('apply-structure', 'active_transaction', 2); }
            $before = $this->inspect();
            $beforeIdentity = $before['issues'] === [] ? $this->identity($before) : ['kind' => 'unchecked', 'id' => null, 'issues' => []];
            if (array_merge($before['issues'], $beforeIdentity['issues']) !== []) { return $this->receipt('apply-structure', $before, $beforeIdentity); }
            if ($beforeIdentity['kind'] === 'binding_required') { return $this->failure('apply-structure', 'identity_binding_required', 2); }
            $phase = 'lock_failed';
            if ((string) $this->scalar('SELECT GET_LOCK(?, 10)', [$lockName]) !== '1') { throw new RuntimeException('Operation failed'); }
            $locked = true;
            $phase = 'locked_recheck_failed';
            $view = $this->inspect();
            $identity = $view['issues'] === [] ? $this->identity($view) : ['kind' => 'unchecked', 'id' => null, 'issues' => []];
            if (array_merge($view['issues'], $identity['issues']) !== []) {
                $result = $this->receipt('apply-structure', $view, $identity);
            } else {
                if ($this->stateFile !== null && ($identity['kind'] !== 'configured' || file_exists($this->stateFile))) {
                    $phase = 'private_state_lock_failed';
                    $newState = $identity['kind'] === 'new_required';
                    $this->openStateHandle($newState);
                    if ($newState) {
                        $phase = 'private_intent_write_failed';
                        $this->writeState($this->stateRecord($view, 'structure_pending'));
                    }
                    // Re-read through the same inode after the private file lock.
                    $identity = $this->identity($view);
                    if ($identity['issues'] !== []) { throw new RuntimeException('Operation failed'); }
                }
                $phase = 'structure_apply_failed';
                foreach ($view['steps'] as $step) {
                    $this->writeAttempted = true;
                    if ($this->pdo->exec($step['sql']) === false) { throw new RuntimeException('Operation failed'); }
                    $this->changed = true;
                    $this->applied[] = $step['code'];
                }
                $phase = 'structure_postcheck_failed';
                $after = $this->inspect();
                if ($after['issues'] !== [] || $after['steps'] !== [] || $after['structure_sha256'] !== $view['structure_sha256']) { throw new RuntimeException('Operation failed'); }
                if ($identity['kind'] === 'structure_pending') {
                    $phase = 'self_insert_intent_failed';
                    $this->writeState($this->stateRecord($after, 'insert_pending'));
                    $phase = 'self_insert_failed';
                    if (!$this->pdo->beginTransaction()) { throw new RuntimeException('Operation failed'); }
                    $transaction = true;
                    $now = time();
                    if ($now < 1 || $now > 4294967295) { throw new RuntimeException('Operation failed'); }
                    $this->writeAttempted = true;
                    $this->rows('INSERT INTO ' . $this->table('marketplace_merchant') . ' (name,logo,after_sale_contact,shipping_note,review_status,accept_orders,fulfillment_enabled,security_locked,closed_at,add_time,update_time) VALUES (?,?,?,?,?,?,?,?,?,?,?)', ['自营商家', '', '', '', 0, 0, 1, 0, null, $now, $now]);
                    $id = self::positiveUint($this->pdo->lastInsertId());
                    $row = $this->selfRow($id);
                    if ($row['name'] !== '自营商家' || $row['logo'] !== '' || $row['after_sale_contact'] !== '' || $row['shipping_note'] !== '' || (string) $row['review_status'] !== '0' || (string) $row['accept_orders'] !== '0' || (string) $row['fulfillment_enabled'] !== '1' || (string) $row['security_locked'] !== '0' || $row['closed_at'] !== null || (int) $row['add_time'] !== $now || (int) $row['update_time'] !== $now) { throw new RuntimeException('Operation failed'); }
                    $phase = 'self_exact_id_register_failed';
                    $state = $this->stateRecord($after, 'bound') + ['self_mer_id' => $id, 'created_at' => $now, 'row_shape_sha256' => $this->selfShapeHash($row)];
                    $this->writeState($state);
                    $phase = 'self_commit_failed';
                    if (!$this->pdo->commit()) { throw new RuntimeException('Operation failed'); }
                    $transaction = false;
                    $this->changed = true;
                    $this->applied[] = 'self_identity_created';
                }
                $phase = 'identity_postcheck_failed';
                $finalIdentity = $this->identity($after);
                if ($finalIdentity['issues'] !== [] || !in_array($finalIdentity['kind'], ['configured', 'bound'], true)) { throw new RuntimeException('Operation failed'); }
                $result = $this->receipt('apply-structure', $after, $finalIdentity);
            }
        } catch (Throwable $ignored) {
            if ($transaction) { try { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } } catch (Throwable $ignoredRollback) { /* DDL is not rolled back. */ } }
            $result = $this->failure('apply-structure', $phase);
        } finally {
            if (is_resource($this->stateHandle)) { @flock($this->stateHandle, LOCK_UN); @fclose($this->stateHandle); $this->stateHandle = null; }
            if ($locked) {
                try { if ((string) $this->scalar('SELECT RELEASE_LOCK(?)', [$lockName]) !== '1') { throw new RuntimeException('Operation failed'); } }
                catch (Throwable $ignoredRelease) {
                    $first = $result['issues'] ?? [];
                    $result = $this->failure('apply-structure', 'lock_release_failed');
                    $result['issues'] = array_merge($first, $result['issues']);
                }
            }
        }
        return $result ?? $this->failure('apply-structure', 'result_unavailable');
    }

    public function backfill(string $mappingFile, string $quarantineDir): array
    {
        return $this->ownershipCommand('backfill', $mappingFile, $quarantineDir, false);
    }

    public function enforce(string $mappingFile, string $quarantineDir, bool $maintenanceConfirmed): array
    {
        return $this->ownershipCommand('enforce', $mappingFile, $quarantineDir, $maintenanceConfirmed);
    }

    private function ownershipCommand(string $mode, string $mappingFile, string $quarantineDir, bool $maintenanceConfirmed): array
    {
        $this->changed = $this->writeAttempted = false;
        $this->applied = [];
        $locked = $transaction = false;
        $phase = 'ownership_precheck_failed';
        $result = null;
        $lockName = 'marketplace-ms2:' . substr(hash('sha256', $this->database . "\0" . $this->prefix . "\0" . self::MARKER), 0, 48);
        try {
            if ($this->pdo->inTransaction()) { return $this->failure($mode, 'active_transaction', 2); }
            if ($mode === 'enforce' && !$maintenanceConfirmed) { return $this->failure($mode, 'maintenance_confirmation_required', 2); }
            if ($mode === 'enforce') { $strict = $this->strictSqlModes(); if (!$strict['session'] || !$strict['global']) { return $this->failure($mode, 'strict_sql_mode_required', 2); } }
            $view = $this->inspect();
            $identity = $view['issues'] === [] ? $this->identity($view) : ['kind' => 'unchecked', 'id' => null, 'issues' => []];
            if ($view['issues'] !== [] || $identity['issues'] !== [] || $view['steps'] !== [] || !in_array($identity['kind'], ['bound', 'configured'], true)) { return $this->failure($mode, 'structure_or_identity_not_ready', 2); }
            $binding = $this->mappingBinding($view, $identity);
            $catalog = $this->catalogRows();
            $mapping = $this->mapping($mappingFile, $binding, $catalog);
            $selectedMappingHash = $mapping['sha256'];
            $this->privateReportDirectory($quarantineDir);
            $phase = 'ownership_lock_failed';
            if ((string) $this->scalar('SELECT GET_LOCK(?,10)', [$lockName]) !== '1') { throw new RuntimeException('Operation failed'); }
            $locked = true;
            $phase = 'ownership_locked_recheck_failed';
            $view = $this->inspect();
            $identity = $this->identity($view);
            if ($view['issues'] !== [] || $identity['issues'] !== [] || $view['steps'] !== [] || $this->mappingBinding($view, $identity) !== $binding) { throw new RuntimeException('Operation failed'); }
            // Protect the complete five-root selection for this DML transaction; external writers must be paused for DDL.
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            if (!$this->pdo->beginTransaction()) { throw new RuntimeException('Operation failed'); }
            $transaction = true;
            $before = $this->catalogRows(true);
            $mapping = $this->mapping($mappingFile, $binding, $before);
            if ($mapping['sha256'] !== $selectedMappingHash) { throw new RuntimeException('Mapping changed'); }
            $inventory = $this->ownershipInventory($before, $mapping['confirmed'], $mapping['image_links'], $mapping['attachment_category_links']);
            $reports = [$this->writeQuarantine($quarantineDir, $mode . '-before', $binding, $mapping['sha256'], $inventory)];
            if ($mode === 'enforce' && (!$mapping['all_confirmed_at_target'] || !$this->inventoryClear($inventory))) {
                if (!$this->pdo->rollBack()) { throw new RuntimeException('Operation failed'); }
                $transaction = false;
                $result = $this->receipt($mode, $view, $identity);
                $result['state'] = 'blocked'; $result['native_exit'] = 2;
                $result['issues'][] = self::issue('unresolved_ownership_or_references', 'migration');
                $result['ownership'] = $this->publicInventory($inventory);
                $result['quarantine'] = $reports;
            } elseif ($mode === 'backfill') {
                $phase = 'confirmed_owner_update_failed';
                $updates = 0;
                foreach ($mapping['updates'] as $update) {
                    $this->writeAttempted = true;
                    $statement = $this->pdo->prepare('UPDATE ' . $this->table($update['table']) . ' SET mer_id=? WHERE ' . self::quote($update['pk_name']) . '=? AND mer_id <=> ?');
                    if (!$statement->execute([$update['to'], $update['pk'], $update['from']]) || $statement->rowCount() !== 1) { throw new RuntimeException('Operation failed'); }
                    ++$updates;
                }
                $after = $this->catalogRows(true);
                $beforeSummary = $this->catalogSummary($before); $afterSummary = $this->catalogSummary($after);
                foreach ($this->catalogTables() as $table => $pk) {
                    $a = $beforeSummary[$table]; $b = $afterSummary[$table];
                    if ($a['count'] !== $b['count'] || $a['non_owner_rows_sha256'] !== $b['non_owner_rows_sha256'] || $b['groups'] !== $mapping['expected_after'][$table]) { throw new RuntimeException('Operation failed'); }
                }
                $afterMapping = $this->mapping($mappingFile, $binding, $after);
                if ($afterMapping['sha256'] !== $mapping['sha256']) { throw new RuntimeException('Mapping changed'); }
                if ($afterMapping['updates'] !== []) { throw new RuntimeException('Operation failed'); }
                $afterInventory = $this->ownershipInventory($after, $afterMapping['confirmed'], $afterMapping['image_links'], $afterMapping['attachment_category_links']);
                $reports[] = $this->writeQuarantine($quarantineDir, $mode . '-after', $binding, $mapping['sha256'], $afterInventory);
                $phase = 'confirmed_owner_commit_failed';
                if (!$this->pdo->commit()) { throw new RuntimeException('Operation failed'); }
                $transaction = false;
                $this->changed = $updates !== 0;
                $this->applied = $updates === 0 ? [] : ['confirmed_owner_rows'];
                $result = $this->receipt($mode, $view, $identity);
                $result['state'] = $this->inventoryClear($afterInventory) ? 'completed' : 'quarantined';
                $result['native_exit'] = $this->inventoryClear($afterInventory) ? 0 : 2;
                $result['updated_rows'] = $updates;
                $result['before'] = $this->catalogSummary($before);
                $result['after'] = $this->catalogSummary($after);
                $result['ownership_before'] = $this->publicInventory($inventory);
                $result['ownership_after'] = $this->publicInventory($afterInventory);
                $result['quarantine'] = $reports;
                $result['mapping_sha256'] = $mapping['sha256'];
                $result['self_mer_id'] = $identity['id'];
            } else {
                // MySQL DDL implicitly commits. A named migration lock does not stop unrelated business writers.
                if (!$this->pdo->commit()) { throw new RuntimeException('Operation failed'); }
                $transaction = false;
                $phase = 'owner_enforce_ddl_failed';
                foreach ($this->catalogTables() as $table => $pk) {
                    $meta = $this->metadata($table);
                    $owner = $meta['columns']['mer_id'];
                    if ($owner['IS_NULLABLE'] === 'NO' && $owner['COLUMN_DEFAULT'] === null) { continue; }
                    $comment = $this->scalar('SELECT COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?', [$this->database, $this->prefix . $table, 'mer_id']);
                    if (!is_string($comment)) { throw new RuntimeException('Operation failed'); }
                    $this->writeAttempted = true;
                    if ($this->pdo->exec('ALTER TABLE ' . $this->table($table) . ' MODIFY COLUMN `mer_id` INT UNSIGNED NOT NULL COMMENT ' . $this->pdo->quote($comment)) === false) { throw new RuntimeException('Operation failed'); }
                    $this->changed = true;
                    $this->applied[] = $table . '.mer_id.enforced';
                }
                $phase = 'owner_enforce_postcheck_failed';
                $final = $this->inspect(); $finalIdentity = $this->identity($final);
                if ($final['issues'] !== [] || $final['steps'] !== [] || $final['ownership_phase'] !== 'enforced' || $finalIdentity['id'] !== $identity['id'] || $finalIdentity['issues'] !== [] || $this->mappingBinding($final, $finalIdentity) !== $binding) { throw new RuntimeException('Operation failed'); }
                $after = $this->catalogRows();
                if ($this->catalogSummary($before) !== $this->catalogSummary($after)) { throw new RuntimeException('Operation failed'); }
                $afterMapping = $this->mapping($mappingFile, $binding, $after);
                if ($afterMapping['sha256'] !== $mapping['sha256']) { throw new RuntimeException('Mapping changed'); }
                $afterInventory = $this->ownershipInventory($after, $afterMapping['confirmed'], $afterMapping['image_links'], $afterMapping['attachment_category_links']);
                $reports[] = $this->writeQuarantine($quarantineDir, $mode . '-after', $binding, $mapping['sha256'], $afterInventory);
                if (!$afterMapping['all_confirmed_at_target'] || !$this->inventoryClear($afterInventory)) { throw new RuntimeException('Operation failed'); }
                $result = $this->receipt($mode, $final, $finalIdentity);
                $result['migration_complete'] = true;
                $result['ownership_before'] = $this->publicInventory($inventory);
                $result['ownership_after'] = $this->publicInventory($afterInventory);
                $result['before'] = $this->catalogSummary($before);
                $result['after'] = $this->catalogSummary($after);
                $result['mapping_sha256'] = $mapping['sha256'];
                $result['quarantine'] = $reports;
                $result['maintenance_confirmation'] = 'CALLER ASSERTION; external writers are not controlled by this CLI';
                $result['strict_sql_mode'] = $this->strictSqlModes();
                if (!$result['strict_sql_mode']['session'] || !$result['strict_sql_mode']['global']) { throw new RuntimeException('Operation failed'); }
            }
        } catch (Throwable $ignored) {
            $rolledBack = false;
            if ($transaction) { try { $rolledBack = $this->pdo->inTransaction() && $this->pdo->rollBack(); } catch (Throwable $ignoredRollback) { /* Unknown outcome stays unknown. */ } }
            if ($rolledBack) { $this->changed = $this->writeAttempted = false; $this->applied = []; }
            $result = $this->failure($mode, $phase);
            if (isset($reports)) { $result['quarantine'] = $reports; }
            if (isset($identity['id']) && is_int($identity['id'])) { $result['self_mer_id'] = $identity['id']; }
        } finally {
            if ($locked) {
                try { if ((string) $this->scalar('SELECT RELEASE_LOCK(?)', [$lockName]) !== '1') { throw new RuntimeException('Operation failed'); } }
                catch (Throwable $ignoredRelease) { $original = $result; $result = $this->failure($mode, 'lock_release_failed'); $result['prior_result'] = $original; }
            }
        }
        return $result ?? $this->failure($mode, 'result_unavailable');
    }

    private function catalogTables(): array { return ['store_product' => 'id'] + self::ROOTS; }

    private function catalogRows(bool $lock = false): array
    {
        $catalog = []; $total = 0;
        foreach ($this->catalogTables() as $table => $pk) {
            $rows = $this->rows('SELECT * FROM ' . $this->table($table) . ' ORDER BY ' . self::quote($pk) . ' LIMIT 200001' . ($lock ? ' FOR UPDATE' : ''));
            $total += count($rows);
            if ($total > 200000) { throw new RuntimeException('Inventory limit'); }
            $catalog[$table] = $rows;
        }
        return $catalog;
    }

    private function catalogSummary(array $catalog): array
    {
        $result = [];
        foreach ($this->catalogTables() as $table => $pk) {
            $hashes = [];
            foreach ($catalog[$table] as $row) { unset($row['mer_id']); $hashes[] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)); }
            $result[$table] = ['count' => count($catalog[$table]), 'non_owner_rows_sha256' => hash('sha256', implode("\n", $hashes)), 'groups' => $this->ownerGroups($catalog[$table])];
        }
        return $result;
    }

    private function ownerGroups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) { $id = $row['mer_id'] === null ? null : self::ownerValue($row['mer_id']); $key = $id === null ? 'null' : (string) $id; if (!isset($groups[$key])) { $groups[$key] = ['mer_id' => $id, 'count' => 0]; } ++$groups[$key]['count']; }
        $groups = array_values($groups);
        usort($groups, static fn(array $a, array $b): int => $a['mer_id'] === null ? ($b['mer_id'] === null ? 0 : -1) : ($b['mer_id'] === null ? 1 : $a['mer_id'] <=> $b['mer_id']));
        return $groups;
    }

    private static function ownerValue($value): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string) $value) !== 1 || (int) $value > 4294967295) { throw new RuntimeException('Owner unavailable'); }
        return (int) $value;
    }

    private static function exactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value); sort($actual); sort($keys);
        if ($actual !== $keys) { throw new RuntimeException('Mapping unavailable'); }
    }

    private function mappingBinding(array $view, array $identity): array
    {
        $source = $this->sourceBinding();
        $tables = array_keys($this->catalogTables());
        $tables = array_merge($tables, ['store_product_attr', 'store_product_attr_value', 'store_product_attr_result', 'store_product_description', 'store_product_cate', 'store_product_coupon', 'store_product_virtual', 'store_product_log', 'store_product_relation', 'store_product_reply', 'store_seckill', 'store_bargain', 'store_combination', 'store_integral', 'store_advance', 'shipping_templates_free', 'shipping_templates_region', 'shipping_templates_no_delivery', 'marketplace_merchant', 'marketplace_employee', 'marketplace_stock_log']);
        $schemas = [];
        foreach ($tables as $table) {
            $meta = $this->metadata($table);
            if ($meta === null || $meta['table']['TABLE_TYPE'] !== 'BASE TABLE' || $meta['triggers'] !== []) { throw new RuntimeException('Schema unavailable'); }
            $meta['column_details'] = $this->rows('SELECT COLUMN_NAME,ORDINAL_POSITION,COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$this->database, $this->prefix . $table]);
            $meta['table_details'] = $this->rows('SELECT ROW_FORMAT,CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$this->database, $this->prefix . $table]);
            if (isset(self::ROOTS[$table])) { $meta['columns']['mer_id']['IS_NULLABLE'] = 'YES'; $meta['columns']['mer_id']['COLUMN_DEFAULT'] = null; }
            if ($table === 'store_product') { $meta['columns']['mer_id']['COLUMN_DEFAULT'] = '0'; }
            $schemas[$table] = $meta;
        }
        ksort($schemas);
        return ['source' => $source, 'binding' => ['database_sha256' => $view['database_sha256'], 'schema_sha256' => hash('sha256', self::json($schemas)), 'self_mer_id' => $identity['id']]];
    }

    private function sourceBinding(): array
    {
        $files = [];
        foreach (['help/migrations/marketplace-ms1.php', 'crmeb/public/install/crmeb.sql', 'crmeb/app/services/product/product/StoreProductServices.php', 'crmeb/app/services/product/product/StoreDescriptionServices.php', 'crmeb/app/services/system/attachment/SystemAttachmentServices.php', 'crmeb/app/services/order/StoreOrderComputedServices.php', 'crmeb/app/services/activity/seckill/StoreSeckillServices.php', 'crmeb/app/services/activity/bargain/StoreBargainServices.php', 'crmeb/app/services/activity/combination/StoreCombinationServices.php', 'crmeb/app/model/product/product/StoreProductRelation.php', 'crmeb/app/services/activity/integral/StoreIntegralServices.php', 'crmeb/app/services/activity/advance/StoreAdvanceServices.php', 'crmeb/app/services/activity/integral/StoreIntegralOrderServices.php', 'crmeb/app/services/marketplace/PublicCatalogServices.php', 'crmeb/app/services/marketplace/freight/MerchantFreightPolicy.php', 'crmeb/app/services/order/StoreCartServices.php'] as $file) { $hash = hash_file('sha256', $this->repoRoot . '/' . $file); if (!is_string($hash)) { throw new RuntimeException('Source unavailable'); } $files[$file] = $hash; }
        return ['migration_sha256' => hash_file('sha256', __FILE__), 'catalog_source_sha256' => hash('sha256', self::json($files))];
    }

    private function mapping(string $path, array $binding, array $catalog): array
    {
        $private = $this->readPrivateJson($path, 8388608);
        $map = $private['value'];
        self::exactKeys($map, ['schema', 'version', 'source', 'binding', 'confirmation', 'tables', 'image_links', 'attachment_category_links']);
        if ($map['schema'] !== 1 || !is_string($map['version']) || trim($map['version']) === '' || strlen($map['version']) > 128 || !is_array($map['source']) || !is_array($map['binding'])) { throw new RuntimeException('Mapping unavailable'); }
        foreach (['source', 'binding'] as $key) { self::exactKeys($map[$key], array_keys($binding[$key])); foreach ($binding[$key] as $field => $value) { if ($map[$key][$field] !== $value) { throw new RuntimeException('Mapping source or schema changed'); } } }
        if (!is_array($map['confirmation'])) { throw new RuntimeException('Mapping unavailable'); }
        self::exactKeys($map['confirmation'], ['confirmed', 'by', 'at', 'reference']);
        if ($map['confirmation']['confirmed'] !== true) { throw new RuntimeException('Mapping unconfirmed'); }
        foreach (['by' => 128, 'at' => 64, 'reference' => 512] as $key => $maximum) { if (!is_string($map['confirmation'][$key]) || trim($map['confirmation'][$key]) === '' || strlen($map['confirmation'][$key]) > $maximum || preg_match('//u', $map['confirmation'][$key]) !== 1 || str_contains($map['confirmation'][$key], "\0")) { throw new RuntimeException('Mapping unconfirmed'); } }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $map['confirmation']['at']) !== 1) { throw new RuntimeException('Mapping unconfirmed'); }
        new \DateTimeImmutable($map['confirmation']['at']);
        $timeErrors = \DateTimeImmutable::getLastErrors(); if ($timeErrors !== false && ($timeErrors['warning_count'] !== 0 || $timeErrors['error_count'] !== 0)) { throw new RuntimeException('Mapping unconfirmed'); }
        if (!is_array($map['tables'])) { throw new RuntimeException('Mapping unavailable'); }
        self::exactKeys($map['tables'], array_keys($this->catalogTables()));
        $merchants = [];
        $merchantRows = $this->rows('SELECT mer_id FROM ' . $this->table('marketplace_merchant') . ' ORDER BY mer_id LIMIT 200001' . ($this->pdo->inTransaction() ? ' FOR UPDATE' : ''));
        if (count($merchantRows) > 200000) { throw new RuntimeException('Inventory limit'); }
        foreach ($merchantRows as $row) { $merchants[self::positiveUint($row['mer_id'])] = true; }
        $summary = $this->catalogSummary($catalog); $confirmed = $updates = $expectedAfter = []; $allAtTarget = true; $total = 0;
        foreach ($this->catalogTables() as $table => $pk) {
            $entry = $map['tables'][$table]; if (!is_array($entry)) { throw new RuntimeException('Mapping unavailable'); }
            self::exactKeys($entry, ['count', 'non_owner_rows_sha256', 'before_groups', 'after_groups', 'rows']);
            if (!is_int($entry['count']) || $entry['count'] !== $summary[$table]['count'] || $entry['non_owner_rows_sha256'] !== $summary[$table]['non_owner_rows_sha256'] || !is_array($entry['rows']) || !array_is_list($entry['rows'])) { throw new RuntimeException('Mapping count or rows changed'); }
            $beforeGroups = $this->mappingGroups($entry['before_groups'], $entry['count']); $afterGroups = $this->mappingGroups($entry['after_groups'], $entry['count']);
            if ($summary[$table]['groups'] !== $beforeGroups && $summary[$table]['groups'] !== $afterGroups) { throw new RuntimeException('Mapping owner groups changed'); }
            $actual = []; foreach ($catalog[$table] as $row) { $actual[self::positiveUint($row[$pk])] = $row; }
            $confirmed[$table] = []; $projected = $actual;
            foreach ($entry['rows'] as $decision) {
                if (++$total > 200000 || !is_array($decision)) { throw new RuntimeException('Mapping unavailable'); }
                self::exactKeys($decision, ['pk', 'from_mer_id', 'to_mer_id']);
                if (!is_int($decision['pk']) || $decision['pk'] < 1 || !isset($actual[$decision['pk']]) || isset($confirmed[$table][$decision['pk']]) || ($decision['from_mer_id'] !== null && (!is_int($decision['from_mer_id']) || $decision['from_mer_id'] < 0 || $decision['from_mer_id'] > 4294967295)) || !is_int($decision['to_mer_id']) || $decision['to_mer_id'] < 1 || $decision['to_mer_id'] > 4294967295 || !isset($merchants[$decision['to_mer_id']])) { throw new RuntimeException('Mapping target unavailable'); }
                $owner = $actual[$decision['pk']]['mer_id'] === null ? null : self::ownerValue($actual[$decision['pk']]['mer_id']);
                if ($owner !== $decision['from_mer_id'] && $owner !== $decision['to_mer_id']) { throw new RuntimeException('Mapping row owner changed'); }
                $confirmed[$table][$decision['pk']] = $decision['to_mer_id'];
                $projected[$decision['pk']]['mer_id'] = $decision['to_mer_id'];
                if ($owner !== $decision['to_mer_id']) { $updates[] = ['table' => $table, 'pk_name' => $pk, 'pk' => $decision['pk'], 'from' => $owner, 'to' => $decision['to_mer_id']]; $allAtTarget = false; }
            }
            if (count($confirmed[$table]) !== count($actual)) { $allAtTarget = false; }
            if ($this->ownerGroups(array_values($projected)) !== $afterGroups) { throw new RuntimeException('Mapping projected groups differ'); }
            $expectedAfter[$table] = $afterGroups;
        }
        if (!is_array($map['image_links']) || !array_is_list($map['image_links']) || !is_array($map['attachment_category_links']) || !array_is_list($map['attachment_category_links']) || count($map['image_links']) > 200000 || count($map['attachment_category_links']) > 200000) { throw new RuntimeException('Mapping unavailable'); }
        $imageKeys = []; foreach ($map['image_links'] as $link) { if (!is_array($link)) { throw new RuntimeException('Mapping unavailable'); } self::exactKeys($link, ['reference_sha256', 'attachment_id', 'attachment_value_sha256']); foreach (['reference_sha256', 'attachment_value_sha256'] as $key) { if (!is_string($link[$key]) || preg_match('/\A[a-f0-9]{64}\z/D', $link[$key]) !== 1) { throw new RuntimeException('Mapping unavailable'); } } if (!is_int($link['attachment_id']) || $link['attachment_id'] < 1 || isset($imageKeys[$link['reference_sha256']])) { throw new RuntimeException('Mapping unavailable'); } $imageKeys[$link['reference_sha256']] = true; }
        $categoryKeys = []; foreach ($map['attachment_category_links'] as $link) { if (!is_array($link)) { throw new RuntimeException('Mapping unavailable'); } self::exactKeys($link, ['attachment_id', 'pid', 'kind']); if (!is_int($link['attachment_id']) || $link['attachment_id'] < 1 || !is_int($link['pid']) || $link['pid'] < 1 || $link['pid'] > 2147483647 || !in_array($link['kind'], ['category', 'usage'], true) || ($link['kind'] === 'usage' && $link['pid'] > 6) || isset($categoryKeys[$link['attachment_id']])) { throw new RuntimeException('Mapping unavailable'); } $categoryKeys[$link['attachment_id']] = true; }
        return ['sha256' => $private['sha256'], 'confirmed' => $confirmed, 'updates' => $updates, 'expected_after' => $expectedAfter, 'all_confirmed_at_target' => $allAtTarget, 'image_links' => $map['image_links'], 'attachment_category_links' => $map['attachment_category_links']];
    }

    private function mappingGroups($groups, int $count): array
    {
        if (!is_array($groups) || !array_is_list($groups) || count($groups) > 200000) { throw new RuntimeException('Mapping unavailable'); }
        $seen = []; $total = 0;
        foreach ($groups as $group) { if (!is_array($group)) { throw new RuntimeException('Mapping unavailable'); } self::exactKeys($group, ['mer_id', 'count']); if (($group['mer_id'] !== null && (!is_int($group['mer_id']) || $group['mer_id'] < 0 || $group['mer_id'] > 4294967295)) || !is_int($group['count']) || $group['count'] < 1) { throw new RuntimeException('Mapping unavailable'); } $key = $group['mer_id'] === null ? 'null' : (string) $group['mer_id']; if (isset($seen[$key])) { throw new RuntimeException('Mapping unavailable'); } $seen[$key] = true; $total += $group['count']; }
        if ($total !== $count) { throw new RuntimeException('Mapping unavailable'); }
        usort($groups, static fn(array $a, array $b): int => $a['mer_id'] === null ? ($b['mer_id'] === null ? 0 : -1) : ($b['mer_id'] === null ? 1 : $a['mer_id'] <=> $b['mer_id']));
        return $groups;
    }

    private function readPrivateJson(string $path, int $limit): array
    {
        $path = $this->privateStatePath($path); clearstatcache(true, $path);
        if (!is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600 || fileowner($path) !== posix_geteuid() || (fileperms(dirname($path)) & 0777) !== 0700) { throw new RuntimeException('Private mapping unavailable'); }
        $handle = @fopen($path, 'rb'); if (!is_resource($handle)) { throw new RuntimeException('Private mapping unavailable'); }
        try { $a = lstat($path); $b = fstat($handle); if ($a['dev'] !== $b['dev'] || $a['ino'] !== $b['ino'] || !@flock($handle, LOCK_SH | LOCK_NB)) { throw new RuntimeException('Private mapping unavailable'); } $bytes = stream_get_contents($handle, $limit + 1); }
        finally { @flock($handle, LOCK_UN); fclose($handle); }
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > $limit) { throw new RuntimeException('Private mapping unavailable'); }
        $value = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR); if (!is_array($value) || array_is_list($value)) { throw new RuntimeException('Private mapping unavailable'); }
        return ['value' => $value, 'sha256' => hash('sha256', $bytes)];
    }

    private function privateReportDirectory(string $directory): string
    {
        $path = $this->privateStatePath(rtrim($directory, '/') . '/placeholder'); $parent = dirname($path);
        if ((fileperms($parent) & 0777) !== 0700 || is_link($directory)) { throw new RuntimeException('Private report unavailable'); }
        return $parent;
    }

    private function writeQuarantine(string $directory, string $phase, array $binding, string $mappingHash, array $inventory): array
    {
        $parent = $this->privateReportDirectory($directory);
        $report = ['schema' => 1, 'kind' => 'marketplace_ownership_quarantine', 'phase' => $phase, 'source_binding' => $binding, 'mapping_sha256' => $mappingHash, 'counts' => $inventory['counts'], 'orphan_child_rows' => $inventory['orphan_child_rows'], 'groups' => $inventory['groups'], 'table_counts' => $inventory['table_counts'], 'items' => $inventory['items'], 'reference_limits' => $inventory['reference_limits']];
        $bytes = self::json($report) . "\n";
        if (strlen($bytes) > 33554432) { throw new RuntimeException('Private report limit'); }
        $hash = hash('sha256', $bytes);
        $path = $parent . '/marketplace_ownership_quarantine-' . $hash . '.json';
        if (file_exists($path) || is_link($path)) { $existing = $this->readPrivateJson($path, 33554432); if ($existing['sha256'] !== $hash) { throw new RuntimeException('Private report differs'); } }
        else {
            $handle = @fopen($path, 'xb'); if (!is_resource($handle)) { throw new RuntimeException('Private report unavailable'); }
            try { if (!@chmod($path, 0600) || !@flock($handle, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Private report unavailable'); } $offset = 0; while ($offset < strlen($bytes)) { $written = fwrite($handle, substr($bytes, $offset)); if (!is_int($written) || $written < 1) { throw new RuntimeException('Private report unavailable'); } $offset += $written; } if (!fflush($handle) || !fsync($handle)) { throw new RuntimeException('Private report unavailable'); } }
            finally { @flock($handle, LOCK_UN); fclose($handle); }
            $actual = $this->readPrivateJson($path, 33554432); if ($actual['sha256'] !== $hash) { throw new RuntimeException('Private report differs'); }
        }
        return ['sha256' => $hash, 'bytes' => strlen($bytes), 'private' => true, 'report_written' => true, 'unresolved_count' => array_sum($inventory['counts'])];
    }

    private function inventoryClear(array $inventory): bool { return array_sum($inventory['counts']) === 0; }
    private function publicInventory(array $inventory): array { return ['counts' => $inventory['counts'], 'orphan_child_rows' => $inventory['orphan_child_rows'], 'table_counts' => $inventory['table_counts'], 'reference_limits' => $inventory['reference_limits'], 'detail_private' => true]; }

    private function strictSqlModes(): array
    {
        $modes = $this->rows('SELECT @@SESSION.sql_mode AS session_mode,@@GLOBAL.sql_mode AS global_mode');
        if (count($modes) !== 1) { throw new RuntimeException('SQL mode unavailable'); }
        $result = [];
        foreach (['session', 'global'] as $key) { $parts = explode(',', strtoupper((string) $modes[0][$key . '_mode'])); $result[$key] = in_array('STRICT_TRANS_TABLES', $parts, true) || in_array('STRICT_ALL_TABLES', $parts, true); }
        return $result;
    }

    /** Read-only inventory. Confirmation supplies prospective owners; current owners remain separately counted. */
    private function ownershipInventory(array $catalog, array $confirmed = [], array $imageLinks = [], array $categoryLinks = []): array
    {
        $limit = 200000;
        $read = 0;
        $references = 0;
        $counts = array_fill_keys(['unknown_owner', 'unconfirmed_owner', 'orphan', 'cross_merchant', 'unknown_reference'], 0);
        $orphanChildSeen = [];
        $orphanChildReasons = ['ACTIVITY_BASE_PRODUCT_MISSING', 'TYPED_PARENT_MISSING', 'TRANSPORT_TEMPLATE_MISSING', 'PRODUCT_PARENT_MISSING', 'VIRTUAL_SKU_PARENT_MISSING', 'STOCK_PRODUCT_MISSING', 'STOCK_SKU_MISSING', 'STOCK_EMPLOYEE_MISSING'];
        $items = [];
        $groups = [];
        $tableCounts = [];
        $roots = ['store_product' => 'id', 'system_attachment' => 'att_id', 'system_attachment_category' => 'id', 'shipping_templates' => 'id', 'store_product_rule' => 'id'];
        if (count($imageLinks) > $limit || count($categoryLinks) > $limit) throw new RuntimeException('Ownership inventory limit exceeded');
        if (count($catalog) !== count($roots) || array_diff(array_keys($catalog), array_keys($roots))) {
            throw new RuntimeException('Ownership inventory unavailable');
        }
        $uint = static function ($value, bool $zero = false): ?int {
            if ((!is_int($value) && !is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', (string)$value) !== 1 || (int)$value > 4294967295) return null;
            return ((int)$value === 0 && !$zero) ? null : (int)$value;
        };
        $emit = static function (string $kind, string $table, $pk, string $reason, array $reference = [], ?string $hash = null) use (&$items, &$counts, &$orphanChildSeen, $orphanChildReasons, $limit): void {
            if (!array_key_exists($kind, $counts) || count($items) >= $limit) throw new RuntimeException('Ownership inventory limit exceeded');
            $item = ['table' => $table, is_int($pk) ? 'pk' : 'pk_hash' => $pk, 'reason' => $reason];
            if ($reference) $item['reference'] = $reference;
            if ($hash !== null) $item['hash'] = $hash;
            $items[] = $item;
            ++$counts[$kind];
            if ($kind === 'orphan' && in_array($reason, $orphanChildReasons, true)) { $orphanChildSeen[$table][(is_int($pk) ? 'pk:' : 'hash:') . (string) $pk] = true; }
        };
        $maps = [];
        $owners = [];
        foreach ($roots as $table => $key) {
            if (!is_array($catalog[$table])) throw new RuntimeException('Ownership inventory unavailable');
            $read += count($catalog[$table]);
            if ($read > $limit) throw new RuntimeException('Ownership inventory limit exceeded');
            $tableCounts[$table] = count($catalog[$table]);
            $maps[$table] = [];
            $owners[$table] = [];
            $rootGroups = [];
            foreach ($catalog[$table] as $row) {
                if (!is_array($row) || ($pk = $uint($row[$key] ?? null)) === null || isset($maps[$table][$pk])) throw new RuntimeException('Ownership inventory unavailable');
                $maps[$table][$pk] = $row;
                $current = $row['mer_id'] ?? null;
                if ($current !== null && ($current = $uint($current, true)) === null) throw new RuntimeException('Ownership inventory unavailable');
                $groupKey = $current === null ? 'NULL' : 'N' . $current;
                if (!isset($rootGroups[$groupKey])) $rootGroups[$groupKey] = ['mer_id' => $current, 'count' => 0];
                ++$rootGroups[$groupKey]['count'];
            }
            ksort($maps[$table], SORT_NUMERIC);
            $groups[$table] = array_values($rootGroups);
            usort($groups[$table], static function (array $a, array $b): int {
                if ($a['mer_id'] === null) return $b['mer_id'] === null ? 0 : -1;
                if ($b['mer_id'] === null) return 1;
                return $a['mer_id'] <=> $b['mer_id'];
            });
        }
        $merchantRows = $this->ownershipInventoryRows('marketplace_merchant', 'mer_id', 'mer_id', $read, $tableCounts);
        $merchants = [];
        foreach ($merchantRows as $row) {
            $id = $uint($row['mer_id'] ?? null);
            if ($id === null || isset($merchants[$id])) throw new RuntimeException('Ownership inventory unavailable');
            $merchants[$id] = true;
        }
        if (array_diff(array_keys($confirmed), array_keys($roots))) throw new RuntimeException('Ownership inventory unavailable');
        foreach ($roots as $table => $key) {
            foreach (($confirmed[$table] ?? []) as $rawPk => $rawOwner) {
                $pk = $uint($rawPk);
                $owner = $uint($rawOwner);
                if ($pk === null || !isset($maps[$table][$pk]) || $owner === null || !isset($merchants[$owner])) throw new RuntimeException('Ownership inventory unavailable');
                $owners[$table][$pk] = $owner;
            }
            foreach ($maps[$table] as $pk => $row) {
                $current = $row['mer_id'] ?? null;
                $current = $current === null ? null : $uint($current, true);
                if ($current === null || $current === 0 || !isset($merchants[$current])) {
                    $emit('unknown_owner', $table, $pk, 'CURRENT_OWNER_UNKNOWN', $current ? ['table' => 'marketplace_merchant', 'pk' => $current] : []);
                } elseif (!isset($owners[$table][$pk])) {
                    $emit('unconfirmed_owner', $table, $pk, 'CURRENT_OWNER_NOT_CONFIRMED');
                }
            }
        }
        $sameOwner = static function (string $table, $pk, ?int $owner, string $targetTable, int $targetPk, string $reason) use (&$owners, $emit): void {
            $targetOwner = $owners[$targetTable][$targetPk] ?? null;
            $ref = ['table' => $targetTable, 'pk' => $targetPk];
            if ($owner === null || $targetOwner === null) $emit('unknown_reference', $table, $pk, $reason . '_OWNER_UNCONFIRMED', $ref);
            elseif ($owner !== $targetOwner) $emit('cross_merchant', $table, $pk, $reason . '_CROSS_MERCHANT', $ref);
        };
        $limits = [
            'historical_preset_source' => ['state' => 'UNKNOWN', 'blocking' => false, 'reason' => 'NO_PERSISTED_RULE_ID', 'independent_rule_owner_required' => true],
            'attachment_matching' => ['state' => 'EXACT_OR_EXPLICIT_HASH_LINK', 'url_normalization' => false],
            'activity_media' => ['state' => 'UNKNOWN', 'blocking' => false, 'scope' => 'activity_parent_and_consumed_template_only'],
            'rule_media' => ['state' => 'UNKNOWN', 'blocking' => false, 'scope' => 'independent_rule_owner_only'],
            'relation_parent' => ['product' => 'store_product', 'other_category' => 'UNKNOWN', 'type_is_action' => true],
            'html_media' => ['parser' => 'DOMDocument', 'decode' => 'legacy_htmlspecialchars_decode_once', 'network' => 'NONET', 'unsupported_content' => 'unknown_reference'],
            'unused_template_references' => [],
            'activity_freight_sources' => ['integral' => 'NO_NATIVE_TEMPLATE_IN_INTEGRAL_ORDER', 'advance' => 'CONFIRMED_BASE_AND_EXPLICIT_LEGACY_NATIVE_TEMPLATE'],
            'counts_unit' => 'issue_items',
            'orphan_child_rows_unit' => 'distinct_actual_child_table_and_PK_or_row_hash',
        ];
        $freight = static function (string $table, array $row, ?int $owner) use ($uint, &$maps, &$limits, $emit, $sameOwner): void {
            $pk = $uint($row['id'] ?? null);
            if ($pk === null) throw new RuntimeException('Ownership inventory unavailable');
            $mode = $uint($row['freight'] ?? null, true);
            if ($mode === 1 || $mode === 2) {
                if (($row['temp_id'] ?? null) !== null && (string)$row['temp_id'] !== '0') $limits['unused_template_references'][$table] = ($limits['unused_template_references'][$table] ?? 0) + 1;
                return;
            }
            if ($mode !== 3) {
                $emit('unknown_reference', $table, $pk, 'FREIGHT_MODE_UNKNOWN');
                return;
            }
            $id = $uint($row['temp_id'] ?? null);
            if ($id === null || !isset($maps['shipping_templates'][$id])) $emit('orphan', $table, $pk, 'CONSUMED_TEMPLATE_MISSING', $id === null ? [] : ['table' => 'shipping_templates', 'pk' => $id]);
            else $sameOwner($table, $pk, $owner, 'shipping_templates', $id, 'CONSUMED_TEMPLATE');
        };
        foreach ($maps['store_product'] as $pk => $row) $freight('store_product', $row, $owners['store_product'][$pk] ?? null);
        $activities = [];
        $activityTables = [1 => 'store_seckill', 2 => 'store_bargain', 3 => 'store_combination', 4 => 'store_integral', 6 => 'store_advance'];
        foreach ($activityTables as $type => $table) {
            $activities[$type] = [];
            // Integral has no freight/template columns; advance has temp_id but no freight.
            $columns = $type === 4 ? 'id,product_id' : ($type === 6 ? 'id,product_id,temp_id' : 'id,product_id,temp_id,freight');
            foreach ($this->ownershipInventoryRows($table, $columns, 'id', $read, $tableCounts) as $row) {
                $id = $uint($row['id'] ?? null);
                if ($id === null || isset($activities[$type][$id])) throw new RuntimeException('Ownership inventory unavailable');
                $activities[$type][$id] = $row;
                $product = $uint($row['product_id'] ?? null);
                if ($product === null || !isset($maps['store_product'][$product])) $emit('orphan', $table, $id, 'ACTIVITY_BASE_PRODUCT_MISSING', $product === null ? [] : ['table' => 'store_product', 'pk' => $product]);
                if ($type === 1 || $type === 2 || $type === 3) {
                    $freight($table, $row, $product === null ? null : ($owners['store_product'][$product] ?? null));
                } elseif ($type === 6 && $product !== null && isset($maps['store_product'][$product])) {
                    $base = $maps['store_product'][$product];
                    $mode = $uint($base['freight'] ?? null, true);
                    $virtual = $uint($base['is_virtual'] ?? null, true);
                    // freshCart inherits these missing inputs; the enabled policy revalidates the complete base.
                    if ($mode === 1 || $mode === 2 || $virtual === 1) {
                        if (($row['temp_id'] ?? null) !== null && (string) $row['temp_id'] !== '0') $limits['unused_template_references'][$table] = ($limits['unused_template_references'][$table] ?? 0) + 1;
                        continue;
                    }
                    if ($mode !== 3) continue; // The unchanged complete root inventory already records an unknown mode.
                    if ($virtual !== 0) { $emit('unknown_reference', $table, $id, 'ACTIVITY_VIRTUAL_MODE_UNKNOWN'); continue; }
                    $template = $uint($row['temp_id'] ?? null, true);
                    // A reachable legacy fallback is not an explicit confirmed template reference.
                    if ($template === null || $template === 0) { $emit('unknown_reference', $table, $id, 'ADVANCE_LEGACY_TEMPLATE_UNCONFIRMED'); continue; }
                    if (!isset($maps['shipping_templates'][$template])) $emit('orphan', $table, $id, 'CONSUMED_TEMPLATE_MISSING', ['table' => 'shipping_templates', 'pk' => $template]);
                    else $sameOwner($table, $id, $owners['store_product'][$product] ?? null, 'shipping_templates', $template, 'CONSUMED_TEMPLATE');
                }
            }
        }
        $parent = static function (string $table, $pk, array $row) use ($uint, &$maps, &$activities, &$activityTables, &$owners, $emit): ?int {
            $type = $uint($row['type'] ?? null, true);
            $id = $uint($row['product_id'] ?? null);
            if ($type === null || ($type !== 0 && !isset($activityTables[$type]))) {
                $emit('unknown_reference', $table, $pk, 'ACTIVITY_TYPE_UNKNOWN');
                return null;
            }
            $target = $type === 0 ? 'store_product' : $activityTables[$type];
            if ($id === null || ($type === 0 ? !isset($maps['store_product'][$id]) : !isset($activities[$type][$id]))) {
                $emit('orphan', $table, $pk, 'TYPED_PARENT_MISSING', $id === null ? [] : ['table' => $target, 'pk' => $id]);
                return null;
            }
            if ($type === 0) return $owners['store_product'][$id] ?? null;
            $base = $uint($activities[$type][$id]['product_id'] ?? null);
            if ($base === null || !isset($maps['store_product'][$base])) return null; // Activity's missing base is recorded once above.
            return $owners['store_product'][$base] ?? null;
        };
        foreach (['shipping_templates_free', 'shipping_templates_region', 'shipping_templates_no_delivery'] as $table) {
            foreach ($this->ownershipInventoryRows($table, 'id,temp_id', 'id', $read, $tableCounts) as $row) {
                $pk = $uint($row['id'] ?? null);
                if ($pk === null) throw new RuntimeException('Ownership inventory unavailable');
                $id = $uint($row['temp_id'] ?? null);
                if ($id === null || !isset($maps['shipping_templates'][$id])) $emit('orphan', $table, $pk, 'TRANSPORT_TEMPLATE_MISSING', $id === null ? [] : ['table' => 'shipping_templates', 'pk' => $id]);
            }
        }
        $categories = $maps['system_attachment_category'];
        foreach ($categories as $pk => $row) {
            $pid = $uint($row['pid'] ?? null, true);
            if ($pid === 0) continue;
            if ($pid === null) $emit('unknown_reference', 'system_attachment_category', $pk, 'CATEGORY_PARENT_INVALID');
            elseif (!isset($categories[$pid])) $emit('orphan', 'system_attachment_category', $pk, 'CATEGORY_PARENT_MISSING', ['table' => 'system_attachment_category', 'pk' => $pid]);
            else $sameOwner('system_attachment_category', $pk, $owners['system_attachment_category'][$pk] ?? null, 'system_attachment_category', $pid, 'CATEGORY_PARENT');
        }
        $done = [];
        foreach ($categories as $start => $row) {
            if (isset($done[$start])) continue;
            $path = [];
            $positions = [];
            $cursor = $start;
            while (isset($categories[$cursor]) && !isset($done[$cursor])) {
                if (isset($positions[$cursor])) {
                    foreach (array_slice($path, $positions[$cursor]) as $member) $emit('unknown_reference', 'system_attachment_category', $member, 'CATEGORY_ANCESTRY_CYCLE');
                    break;
                }
                $positions[$cursor] = count($path);
                $path[] = $cursor;
                $next = $uint($categories[$cursor]['pid'] ?? null);
                if ($next === null) break;
                $cursor = $next;
            }
            foreach ($path as $visited) $done[$visited] = true;
        }
        $categoryLinkMap = [];
        foreach ($categoryLinks as $link) {
            if (!is_array($link) || ($id = $uint($link['attachment_id'] ?? null)) === null || ($pid = $uint($link['pid'] ?? null)) === null || !in_array($link['kind'] ?? null, ['category', 'usage'], true) || isset($categoryLinkMap[$id])) throw new RuntimeException('Ownership inventory unavailable');
            $categoryLinkMap[$id] = $link;
        }
        foreach ($maps['system_attachment'] as $pk => $row) {
            $pid = $uint($row['pid'] ?? null, true);
            $link = $categoryLinkMap[$pk] ?? null;
            unset($categoryLinkMap[$pk]);
            if ($pid === 0) {
                if ($link !== null) $emit('unknown_reference', 'system_attachment', $pk, 'ATTACHMENT_CATEGORY_LINK_STALE');
                continue;
            }
            if ($pid === null || $link === null || $uint($link['pid']) !== $pid) {
                $emit('unknown_reference', 'system_attachment', $pk, 'ATTACHMENT_PID_SEMANTICS_UNCONFIRMED');
            } elseif ($link['kind'] === 'usage') {
                if ($pid < 1 || $pid > 6) $emit('unknown_reference', 'system_attachment', $pk, 'ATTACHMENT_USAGE_UNSUPPORTED');
            } elseif (!isset($categories[$pid])) {
                $emit('orphan', 'system_attachment', $pk, 'ATTACHMENT_CATEGORY_MISSING', ['table' => 'system_attachment_category', 'pk' => $pid]);
            } else {
                $sameOwner('system_attachment', $pk, $owners['system_attachment'][$pk] ?? null, 'system_attachment_category', $pid, 'ATTACHMENT_CATEGORY');
            }
        }
        foreach ($categoryLinkMap as $pk => $link) $emit('unknown_reference', 'system_attachment', $pk, 'ATTACHMENT_CATEGORY_LINK_UNUSED');
        $attachmentPaths = [];
        foreach ($maps['system_attachment'] as $pk => $row) {
            foreach (['att_dir', 'satt_dir'] as $field) {
                $value = $row[$field] ?? null;
                if (!is_string($value)) throw new RuntimeException('Ownership inventory unavailable');
                if ($value !== '') $attachmentPaths[$value][$pk] = true;
            }
        }
        $imageLinkMap = [];
        $seenImageLinks = [];
        foreach ($imageLinks as $link) {
            if (!is_array($link) || !is_string($link['reference_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $link['reference_sha256']) !== 1 || ($id = $uint($link['attachment_id'] ?? null)) === null || !is_string($link['attachment_value_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $link['attachment_value_sha256']) !== 1 || isset($imageLinkMap[$link['reference_sha256']])) throw new RuntimeException('Ownership inventory unavailable');
            $imageLinkMap[$link['reference_sha256']] = $link;
        }
        $media = function (string $table, $pk, string $field, int $ordinal, $value, ?int $owner) use (&$references, $limit, &$attachmentPaths, &$maps, &$owners, &$imageLinkMap, &$seenImageLinks, $uint, $emit): void {
            if ($value === '') return;
            if (++$references > $limit) throw new RuntimeException('Ownership inventory limit exceeded');
            if (!is_string($value)) {
                $emit('unknown_reference', $table, $pk, 'MEDIA_VALUE_SHAPE_UNKNOWN', ['field' => $field, 'ordinal' => $ordinal]);
                return;
            }
            $referenceHash = self::ownershipReferenceHash($table, $pk, $field, $ordinal, hash('sha256', $value));
            $ref = ['field' => $field, 'ordinal' => $ordinal, 'value_sha256' => hash('sha256', $value)];
            $link = $imageLinkMap[$referenceHash] ?? null;
            $matches = array_keys($attachmentPaths[$value] ?? []);
            $attachment = null;
            if ($link !== null) {
                $seenImageLinks[$referenceHash] = true;
                $attachment = $uint($link['attachment_id']);
                $actual = $attachment === null ? null : ($maps['system_attachment'][$attachment] ?? null);
                $pathVerified = $actual !== null && (($actual['att_dir'] !== '' && hash_equals(hash('sha256', $actual['att_dir']), $link['attachment_value_sha256'])) || ($actual['satt_dir'] !== '' && hash_equals(hash('sha256', $actual['satt_dir']), $link['attachment_value_sha256'])));
                if (!$pathVerified) {
                    $emit('unknown_reference', $table, $pk, 'IMAGE_LINK_CURRENT_ATTACHMENT_UNVERIFIED', $ref, $referenceHash);
                    return;
                }
            } elseif (count($matches) === 1) {
                $attachment = (int)$matches[0];
            } else {
                $emit('unknown_reference', $table, $pk, count($matches) ? 'ATTACHMENT_MATCH_AMBIGUOUS' : 'ATTACHMENT_MATCH_MISSING', $ref, $referenceHash);
                return;
            }
            $targetOwner = $owners['system_attachment'][$attachment] ?? null;
            $ref['table'] = 'system_attachment';
            $ref['pk'] = $attachment;
            if ($owner === null || $targetOwner === null) $emit('unknown_reference', $table, $pk, 'MEDIA_OWNER_UNCONFIRMED', $ref, $referenceHash);
            elseif ($owner !== $targetOwner) $emit('cross_merchant', $table, $pk, 'MEDIA_CROSS_MERCHANT', $ref, $referenceHash);
        };
        foreach ($maps['store_product'] as $pk => $row) {
            $owner = $owners['store_product'][$pk] ?? null;
            foreach (['image', 'recommend_image', 'video_link'] as $field) $media('store_product', $pk, $field, 0, $row[$field] ?? null, $owner);
            $slider = $row['slider_image'] ?? null;
            if (!is_string($slider) || strlen($slider) > 8388608) {
                $emit('unknown_reference', 'store_product', $pk, 'SLIDER_JSON_UNKNOWN');
                continue;
            }
            try { $slider = json_decode($slider, true, 32, JSON_THROW_ON_ERROR); }
            catch (JsonException $error) { $emit('unknown_reference', 'store_product', $pk, 'SLIDER_JSON_UNKNOWN', [], hash('sha256', $row['slider_image'])); continue; }
            if (!is_array($slider) || !array_is_list($slider)) {
                $emit('unknown_reference', 'store_product', $pk, 'SLIDER_JSON_SHAPE_UNKNOWN');
                continue;
            }
            foreach ($slider as $ordinal => $value) $media('store_product', $pk, 'slider_image', $ordinal, $value, $owner);
        }
        $skus = [];
        $skuUnique = [];
        foreach (['store_product_attr' => 'id,product_id,type', 'store_product_attr_value' => 'id,product_id,type,image,`unique`', 'store_product_attr_result' => 'id,product_id,type,result'] as $table => $columns) {
            foreach ($this->ownershipInventoryRows($table, $columns, 'id', $read, $tableCounts) as $row) {
                $pk = $uint($row['id'] ?? null);
                if ($pk === null) throw new RuntimeException('Ownership inventory unavailable');
                $owner = $parent($table, $pk, $row);
                $type = $uint($row['type'] ?? null, true);
                if ($table === 'store_product_attr_value') {
                    if (isset($skus[$pk])) throw new RuntimeException('Ownership inventory unavailable');
                    $skus[$pk] = $row;
                    if ($type === 0) {
                        $product = $uint($row['product_id'] ?? null);
                        if ($product !== null && is_string($row['unique'] ?? null)) $skuUnique[$product][$row['unique']][] = $pk;
                        $media($table, $pk, 'image', 0, $row['image'] ?? null, $owner);
                    }
                } elseif ($table === 'store_product_attr_result' && $type === 0) {
                    if (!is_string($row['result'] ?? null) || strlen($row['result']) > 8388608) {
                        $emit('unknown_reference', $table, $pk, 'ATTR_RESULT_JSON_UNKNOWN');
                        continue;
                    }
                    try { $result = json_decode($row['result'], true, 32, JSON_THROW_ON_ERROR); }
                    catch (JsonException $error) { $emit('unknown_reference', $table, $pk, 'ATTR_RESULT_JSON_UNKNOWN', [], hash('sha256', $row['result'])); continue; }
                    if (!is_array($result) || !isset($result['attr'], $result['value']) || !is_array($result['attr']) || !is_array($result['value'])) {
                        $emit('unknown_reference', $table, $pk, 'ATTR_RESULT_SHAPE_UNKNOWN');
                        continue;
                    }
                    $ordinal = 0;
                    $walk = null;
                    $walk = static function (array $node, int $depth) use (&$walk, &$ordinal, $media, $table, $pk, $owner): void {
                        if ($depth > 32) throw new RuntimeException('Ownership inventory limit exceeded');
                        foreach ($node as $key => $value) {
                            if ($key === 'pic' || $key === 'image') $media($table, $pk, 'result', $ordinal++, $value, $owner);
                            elseif (is_array($value)) $walk($value, $depth + 1);
                        }
                    };
                    $walk($result['attr'], 0);
                    $walk($result['value'], 0);
                    unset($walk);
                }
            }
        }
        $descriptionOccurrences = [];
        foreach ($this->ownershipInventoryRows('store_product_description', 'product_id,type,description', 'product_id,type,description', $read, $tableCounts) as $row) {
            if (!is_string($row['description'] ?? null)) throw new RuntimeException('Ownership inventory unavailable');
            $shape = hash('sha256', self::json(['product_id' => $row['product_id'], 'type' => $row['type'], 'description_sha256' => hash('sha256', $row['description'])]));
            $occurrence = $descriptionOccurrences[$shape] ?? 0;
            $descriptionOccurrences[$shape] = $occurrence + 1;
            $pk = hash('sha256', self::json(['row_sha256' => $shape, 'occurrence' => $occurrence]));
            $owner = $parent('store_product_description', $pk, $row);
            if ($uint($row['type'] ?? null, true) !== 0) continue;
            $html = $this->ownershipHtmlMedia($row['description']);
            foreach ($html['issues'] as $issue) $emit('unknown_reference', 'store_product_description', $pk, $issue['reason'], [], $issue['hash']);
            foreach ($html['media'] as $entry) $media('store_product_description', $pk, 'description', $entry['ordinal'], $entry['value'], $owner);
        }
        foreach (['store_product_cate' => 'id,product_id', 'store_product_coupon' => 'id,product_id', 'store_product_virtual' => 'id,product_id,attr_unique', 'store_product_log' => 'id,product_id', 'store_product_reply' => 'id,product_id,reply_type'] as $table => $columns) {
            foreach ($this->ownershipInventoryRows($table, $columns, 'id', $read, $tableCounts) as $row) {
                $pk = $uint($row['id'] ?? null);
                if ($pk === null) throw new RuntimeException('Ownership inventory unavailable');
                if ($table === 'store_product_reply' && ($row['reply_type'] ?? null) !== 'product') {
                    $emit('unknown_reference', $table, $pk, 'REPLY_PARENT_CATEGORY_UNKNOWN');
                    continue;
                }
                $product = $uint($row['product_id'] ?? null);
                if ($product === null || !isset($maps['store_product'][$product])) {
                    $emit('orphan', $table, $pk, 'PRODUCT_PARENT_MISSING', $product === null ? [] : ['table' => 'store_product', 'pk' => $product]);
                } elseif ($table === 'store_product_virtual') {
                    $unique = $row['attr_unique'] ?? null;
                    $matches = is_string($unique) ? ($skuUnique[$product][$unique] ?? []) : [];
                    if (!$matches) $emit('orphan', $table, $pk, 'VIRTUAL_SKU_PARENT_MISSING', ['table' => 'store_product', 'pk' => $product]);
                    elseif (count($matches) !== 1) $emit('unknown_reference', $table, $pk, 'VIRTUAL_SKU_PARENT_AMBIGUOUS', ['table' => 'store_product', 'pk' => $product]);
                }
            }
        }
        foreach ($this->ownershipInventoryRows('store_product_relation', 'uid,product_id,type,category', 'uid,product_id,type,category', $read, $tableCounts) as $row) {
            $pk = hash('sha256', self::json($row)); // Actual composite unique key; never expose uid or action/category content.
            if (($row['category'] ?? null) !== 'product') {
                $emit('unknown_reference', 'store_product_relation', $pk, 'RELATION_PARENT_CATEGORY_UNKNOWN');
                continue;
            }
            $product = $uint($row['product_id'] ?? null);
            if ($product === null || !isset($maps['store_product'][$product])) $emit('orphan', 'store_product_relation', $pk, 'PRODUCT_PARENT_MISSING', $product === null ? [] : ['table' => 'store_product', 'pk' => $product]);
        }
        foreach ($imageLinkMap as $hash => $link) {
            if (!isset($seenImageLinks[$hash])) $emit('unknown_reference', 'system_attachment', $uint($link['attachment_id']), 'IMAGE_LINK_UNUSED', [], $hash);
        }
        if (++$read > $limit) throw new RuntimeException('Ownership inventory limit exceeded');
        $stockExists = (int)$this->scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$this->prefix . 'marketplace_stock_log']);
        if ($stockExists !== 0 && $stockExists !== 1) throw new RuntimeException('Ownership inventory unavailable');
        $limits['stock_log'] = ['state' => $stockExists === 1 ? 'PRESENT' : 'ABSENT', 'references' => 'actual_product_sku_unique_owner_and_employee'];
        if ($stockExists === 1) {
            $stockRows = $this->ownershipInventoryRows('marketplace_stock_log', 'id,mer_id,actor_employee_id,product_id,attr_value_id,sku_unique', 'id', $read, $tableCounts);
            $employees = [];
            if ($stockRows) {
                foreach ($this->ownershipInventoryRows('marketplace_employee', 'id,mer_id', 'id', $read, $tableCounts) as $row) {
                    $id = $uint($row['id'] ?? null);
                    if ($id === null || isset($employees[$id])) throw new RuntimeException('Ownership inventory unavailable');
                    $employees[$id] = $row;
                }
            }
            foreach ($stockRows as $row) {
                if ((!is_string($row['id'] ?? null) && !is_int($row['id'] ?? null)) || preg_match('/\A[1-9][0-9]{0,19}\z/D', (string)$row['id']) !== 1 || (strlen((string)$row['id']) === 20 && strcmp((string)$row['id'], '18446744073709551615') > 0)) throw new RuntimeException('Ownership inventory unavailable');
                $pk = hash('sha256', self::json(['id' => (string)$row['id']])); // Supports the real BIGINT UNSIGNED PK without numeric truncation.
                $owner = $uint($row['mer_id'] ?? null);
                if ($owner === null || !isset($merchants[$owner])) {
                    $emit('unknown_reference', 'marketplace_stock_log', $pk, 'STOCK_OWNER_UNKNOWN');
                    $owner = null;
                }
                $product = $uint($row['product_id'] ?? null);
                if ($product === null || !isset($maps['store_product'][$product])) $emit('orphan', 'marketplace_stock_log', $pk, 'STOCK_PRODUCT_MISSING', $product === null ? [] : ['table' => 'store_product', 'pk' => $product]);
                else $sameOwner('marketplace_stock_log', $pk, $owner, 'store_product', $product, 'STOCK_PRODUCT');
                $skuId = $uint($row['attr_value_id'] ?? null);
                $sku = $skuId === null ? null : ($skus[$skuId] ?? null);
                if ($sku === null) $emit('orphan', 'marketplace_stock_log', $pk, 'STOCK_SKU_MISSING', $skuId === null ? [] : ['table' => 'store_product_attr_value', 'pk' => $skuId]);
                elseif ($uint($sku['type'] ?? null, true) !== 0 || $uint($sku['product_id'] ?? null) !== $product || !is_string($row['sku_unique'] ?? null) || $row['sku_unique'] !== ($sku['unique'] ?? null)) $emit('unknown_reference', 'marketplace_stock_log', $pk, 'STOCK_SKU_BINDING_MISMATCH', ['table' => 'store_product_attr_value', 'pk' => $skuId]);
                $actor = $uint($row['actor_employee_id'] ?? null);
                if ($actor === null || !isset($employees[$actor])) $emit('orphan', 'marketplace_stock_log', $pk, 'STOCK_EMPLOYEE_MISSING', $actor === null ? [] : ['table' => 'marketplace_employee', 'pk' => $actor]);
                else {
                    $employeeOwner = $uint($employees[$actor]['mer_id'] ?? null);
                    if ($owner === null || $employeeOwner === null || !isset($merchants[$employeeOwner])) $emit('unknown_reference', 'marketplace_stock_log', $pk, 'STOCK_EMPLOYEE_OWNER_UNKNOWN', ['table' => 'marketplace_employee', 'pk' => $actor]);
                    elseif ($owner !== $employeeOwner) $emit('cross_merchant', 'marketplace_stock_log', $pk, 'STOCK_EMPLOYEE_CROSS_MERCHANT', ['table' => 'marketplace_employee', 'pk' => $actor]);
                }
            }
        }
        $limits['rows_read'] = $read;
        $limits['media_references_examined'] = $references;
        $limits['maximum_rows_items_and_media_each'] = $limit;
        $orphanChildTables = ['store_product_attr', 'store_product_attr_value', 'store_product_attr_result', 'store_product_description', 'store_product_cate', 'store_product_coupon', 'store_product_virtual', 'store_product_log', 'store_product_reply', 'store_product_relation', 'shipping_templates_free', 'shipping_templates_region', 'shipping_templates_no_delivery', 'store_seckill', 'store_bargain', 'store_combination', 'store_integral', 'store_advance', 'marketplace_stock_log'];
        $orphanChildCounts = [];
        foreach ($orphanChildTables as $table) { if (array_key_exists($table, $tableCounts)) { $orphanChildCounts[$table] = count($orphanChildSeen[$table] ?? []); } }
        ksort($orphanChildCounts);
        return ['counts' => $counts, 'orphan_child_rows' => ['total' => array_sum($orphanChildCounts), 'tables' => $orphanChildCounts], 'items' => $items, 'groups' => $groups, 'table_counts' => $tableCounts, 'reference_limits' => $limits];
    }

    /** Only called with fixed source table/column/order identifiers; each fetch is bounded before fetchAll. */
    private function ownershipInventoryRows(string $logical, string $columns, string $order, int &$read, array &$tableCounts): array
    {
        $remaining = 200000 - $read;
        if ($remaining < 0) throw new RuntimeException('Ownership inventory limit exceeded');
        $rows = $this->rows('SELECT ' . $columns . ' FROM ' . $this->table($logical) . ' ORDER BY ' . $order . ' LIMIT ' . ($remaining + 1));
        if (count($rows) > $remaining) throw new RuntimeException('Ownership inventory limit exceeded');
        $read += count($rows);
        $tableCounts[$logical] = count($rows);
        return $rows;
    }

    /** Fixed ordered object and JSON flags: caller's explicit image_links use this identical contract. */
    private static function ownershipReferenceHash(string $table, $pk, string $field, int $ordinal, string $valueSha256): string
    {
        return hash('sha256', self::json(['table' => $table, 'pk' => $pk, 'field' => $field, 'ordinal' => $ordinal, 'value_sha256' => $valueSha256]));
    }

    /** Matches the legacy htmlspecialchars_decode consumer, then reads media without executing/fetching it. */
    private function ownershipHtmlMedia(string $raw): array
    {
        $result = ['media' => [], 'issues' => []];
        $unknown = static function (string $reason, string $value) use (&$result): void {
            if (count($result['issues']) >= 200000) throw new RuntimeException('Ownership inventory limit exceeded');
            $result['issues'][] = ['reason' => $reason, 'hash' => hash('sha256', $value)];
        };
        if ($raw === '') return $result;
        if (strlen($raw) > 1048576 || preg_match('//u', $raw) !== 1) {
            $unknown('HTML_SIZE_OR_ENCODING_UNKNOWN', $raw);
            return $result;
        }
        $html = htmlspecialchars_decode($raw);
        if (stripos($html, '<!DOCTYPE') !== false || stripos($html, '<?xml') !== false) {
            $unknown('HTML_DECLARATION_UNSUPPORTED', $raw);
            return $result;
        }
        if (!class_exists('DOMDocument') || !function_exists('libxml_use_internal_errors')) {
            $unknown('HTML_PARSER_UNAVAILABLE', $raw);
            return $result;
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $dom = new DOMDocument();
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $uncertain = !$loaded;
            foreach (libxml_get_errors() as $error) if ($error->level >= LIBXML_ERR_WARNING) $uncertain = true;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($uncertain) $unknown('HTML_PARSE_UNCERTAIN', $raw);
        if (!$loaded) return $result;
        $fields = ['img' => ['src'], 'video' => ['src', 'poster'], 'audio' => ['src'], 'source' => ['src'], 'iframe' => ['src'], 'object' => ['data'], 'embed' => ['src']];
        $ordinal = 0;
        $nodes = 0;
        foreach ($dom->getElementsByTagName('*') as $node) {
            if (++$nodes > 200000) throw new RuntimeException('Ownership inventory limit exceeded');
            $tag = strtolower($node->nodeName);
            foreach ($fields[$tag] ?? [] as $attribute) {
                if (!$node->hasAttribute($attribute)) continue;
                $value = $node->getAttribute($attribute);
                if ($value !== '') {
                    if (count($result['media']) >= 200000) throw new RuntimeException('Ownership inventory limit exceeded');
                    $result['media'][] = ['ordinal' => $ordinal++, 'value' => $value];
                }
            }
            foreach (['srcset', 'data-src', 'data-original', 'srcdoc'] as $attribute) {
                if ($node->hasAttribute($attribute) && $node->getAttribute($attribute) !== '') $unknown('HTML_MEDIA_ATTRIBUTE_UNSUPPORTED', $node->getAttribute($attribute));
            }
            if ($node->hasAttribute('style') && preg_match('/url\s*\(/i', $node->getAttribute('style')) === 1) $unknown('HTML_CSS_MEDIA_UNSUPPORTED', $node->getAttribute('style'));
            if ($tag === 'style' && preg_match('/url\s*\(|@import/i', $node->textContent) === 1) $unknown('HTML_CSS_MEDIA_UNSUPPORTED', $node->textContent);
            if ($tag === 'script') $unknown('HTML_SCRIPT_MEDIA_UNKNOWN', $node->textContent . $node->getAttribute('src'));
            if ($tag === 'svg' || $tag === 'image') $unknown('HTML_SVG_MEDIA_UNSUPPORTED', $node->textContent . $node->getAttribute('href') . $node->getAttribute('xlink:href'));
            if ($tag === 'link' && strtolower($node->getAttribute('rel')) === 'stylesheet') $unknown('HTML_STYLESHEET_MEDIA_UNKNOWN', $node->getAttribute('href'));
            foreach ($node->attributes as $attribute) if (preg_match('/\Aon[a-z]+\z/i', $attribute->nodeName) === 1) $unknown('HTML_EVENT_MEDIA_UNKNOWN', $attribute->nodeValue);
        }
        return $result;
    }

    private function inspect(): array
    {
        $issues = [];
        $steps = [];
        $pristine = true;
        $ownerStates = [];
        $version = $this->scalar('SELECT VERSION()');
        if (!is_string($version) || preg_match('/\A(8\.[0-9]+\.[0-9]+)(?:[-+].*)?\z/D', $version, $match) !== 1 || stripos($version, 'mariadb') !== false || version_compare($match[1], '8.0.16', '<')) {
            return ['issues' => [self::issue('mysql_version', 'migration')], 'steps' => [], 'pristine' => false];
        }
        if ($this->ms1->check()['state'] !== 'completed') { $issues[] = self::issue('ms1_schema_not_completed', 'migration'); }
        $baseline = [];
        foreach (self::ROOTS + ['store_product' => 'id', 'store_product_attr_value' => 'id'] as $name => $pk) {
            $meta = $this->metadata($name);
            $wantedId = $name === 'store_product' ? 'mediumint' : 'int';
            if ($meta === null || $meta['table']['TABLE_TYPE'] !== 'BASE TABLE' || strcasecmp((string) $meta['table']['ENGINE'], 'InnoDB') !== 0 || preg_match('/\Autf8mb4_[A-Za-z0-9_]+\z/D', (string) $meta['table']['TABLE_COLLATION']) !== 1 || $meta['triggers'] !== [] || !isset($meta['columns'][$pk]) || !$this->sameColumn($meta['columns'][$pk], [$wantedId, false, null], null, true) || !isset($meta['indexes']['PRIMARY']) || !$this->sameIndex($meta['indexes']['PRIMARY'], [$pk], true)) {
                $issues[] = self::issue('installed_base_schema', $name);
                continue;
            }
            $contractColumns = isset(self::ROOTS[$name]) ? ['mer_id' => ['int unsigned', true, null]] : ($name === 'store_product' ? self::REVIEW : []);
            if ($name === 'store_product') {
                foreach (['stock' => ['mediumint unsigned', false, '0'], 'is_show' => ['tinyint', false, '1'], 'is_del' => ['tinyint unsigned', false, '0']] as $column => $expected) {
                    if (!isset($meta['columns'][$column]) || !$this->sameColumn($meta['columns'][$column], $expected)) { $issues[] = self::issue('installed_product_schema', $column); }
                }
                $owner = $meta['columns']['mer_id'] ?? null;
                if ($owner === null || (!$this->sameColumn($owner, ['int unsigned', false, '0']) && !$this->sameColumn($owner, ['int unsigned', false, null]))) { $issues[] = self::issue('installed_product_schema', 'mer_id'); }
                else { $ownerStates[] = $owner['COLUMN_DEFAULT'] === null ? 'enforced' : 'structure'; if ($owner['COLUMN_DEFAULT'] === null) { $pristine = false; } }
            }
            if ($name === 'store_product_attr_value') {
                foreach (['stock' => ['int unsigned', false, '0'], 'unique' => ['char(8)', false, '']] as $column => $expected) {
                    if (!isset($meta['columns'][$column]) || !$this->sameColumn($meta['columns'][$column], $expected, $column === 'unique' ? $meta['table']['TABLE_COLLATION'] : null)) { $issues[] = self::issue('installed_sku_schema', $column); }
                }
            }
            foreach ($contractColumns as $column => $expected) {
                $collation = $column === 'review_reason' ? $meta['table']['TABLE_COLLATION'] : null;
                if (isset($meta['columns'][$column])) {
                    $pristine = false;
                    $legalOwner = isset(self::ROOTS[$name]) && $column === 'mer_id' && $this->sameColumn($meta['columns'][$column], ['int unsigned', false, null]);
                    if (!$this->sameColumn($meta['columns'][$column], $expected, $collation) && !$legalOwner) { $issues[] = self::issue('column_conflict', $name . '.' . $column); }
                    if (isset(self::ROOTS[$name]) && $column === 'mer_id') { $ownerStates[] = $legalOwner ? 'enforced' : 'structure'; }
                } else {
                    if (isset(self::ROOTS[$name]) && $column === 'mer_id') { $ownerStates[] = 'missing'; }
                    $steps[] = ['code' => $name . '.' . $column, 'sql' => 'ALTER TABLE ' . $this->table($name) . ' ADD COLUMN ' . self::quote($column) . ' ' . $this->columnSql($expected, $collation)];
                }
            }
            $indexes = isset(self::ROOTS[$name]) || $name === 'store_product' ? ['idx_ms2_owner' => ['mer_id', $pk]] : [];
            if ($name === 'store_product') { $indexes['idx_ms2_review'] = ['review_status', 'is_show', 'is_del', 'mer_id', 'id']; }
            foreach ($indexes as $index => $columns) {
                if (isset($meta['indexes'][$index]) && !$this->sameIndex($meta['indexes'][$index], $columns)) { $issues[] = self::issue('index_conflict', $name . '.' . $index); }
                $matching = false;
                foreach ($meta['indexes'] as $value) { if ($this->sameIndex($value, $columns)) { $matching = true; } }
                if ($matching) { $pristine = false; }
                elseif (!isset($meta['indexes'][$index])) { $steps[] = ['code' => $name . '.' . $index, 'sql' => 'ALTER TABLE ' . $this->table($name) . ' ADD KEY ' . self::quote($index) . ' (' . implode(',', array_map([self::class, 'quote'], $columns)) . ')']; }
            }
            foreach (array_keys($contractColumns) as $column) { unset($meta['columns'][$column]); }
            foreach ($meta['indexes'] as $index => $value) {
                foreach ($indexes as $columns) { if ($this->sameIndex($value, $columns)) { unset($meta['indexes'][$index]); } }
            }
            // Only this already-approved final constraint differs from the original identity fingerprint.
            if ($name === 'store_product' && isset($meta['columns']['mer_id'])) { $meta['columns']['mer_id']['COLUMN_DEFAULT'] = '0'; }
            $baseline[$name] = $meta;
        }
        foreach (['marketplace_merchant', 'marketplace_employee'] as $name) { $baseline[$name] = $this->metadata($name); }
        $stock = $this->metadata('marketplace_stock_log');
        if ($stock === null) { $steps[] = ['code' => 'marketplace_stock_log', 'sql' => $this->stockDdl()]; }
        else {
            $pristine = false;
            if (!$this->sameStock($stock)) { $issues[] = self::issue('stock_log_schema_conflict', 'marketplace_stock_log'); }
        }
        $uuid = $this->scalar('SELECT @@server_uuid');
        if (!is_string($uuid) || preg_match('/\A[0-9a-fA-F-]{36}\z/D', $uuid) !== 1) { $issues[] = self::issue('database_identity_unavailable', 'migration'); }
        $ownershipPhase = count($ownerStates) === 5 && count(array_unique($ownerStates)) === 1 && $ownerStates[0] === 'enforced' ? 'enforced' : (in_array('enforced', $ownerStates, true) ? 'enforcing' : 'structure');
        return ['issues' => $issues, 'steps' => $steps, 'pristine' => $pristine, 'ownership_phase' => $ownershipPhase, 'database_sha256' => hash('sha256', (string) $uuid . "\0" . $this->database . "\0" . $this->prefix), 'structure_sha256' => hash('sha256', self::json($baseline))];
    }

    private function metadata(string $logical): ?array
    {
        $table = $this->prefix . $logical;
        $found = $this->rows('SELECT TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$this->database, $table]);
        if ($found === []) { return null; }
        if (count($found) !== 1) { throw new RuntimeException('Inspection unavailable'); }
        $columns = [];
        foreach ($this->rows('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$this->database, $table]) as $row) {
            $row['COLUMN_TYPE'] = self::normalType($row['COLUMN_TYPE']);
            if ($row['COLUMN_DEFAULT'] !== null) { $row['COLUMN_DEFAULT'] = (string) $row['COLUMN_DEFAULT']; }
            if ($row['CHARACTER_SET_NAME'] === 'utf8') { $row['CHARACTER_SET_NAME'] = 'utf8mb3'; }
            if ($row['COLLATION_NAME'] === 'utf8_bin') { $row['COLLATION_NAME'] = 'utf8mb3_bin'; }
            $columns[$row['COLUMN_NAME']] = $row;
        }
        $indexes = [];
        foreach ($this->rows('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION,COLLATION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$this->database, $table]) as $row) { $indexes[$row['INDEX_NAME']][] = $row; }
        ksort($indexes);
        return ['table' => $found[0], 'columns' => $columns, 'indexes' => $indexes,
            'foreign' => $this->rows('SELECT rc.CONSTRAINT_NAME,rc.UPDATE_RULE,rc.DELETE_RULE,rc.MATCH_OPTION,kcu.ORDINAL_POSITION,kcu.COLUMN_NAME,kcu.REFERENCED_TABLE_SCHEMA,kcu.REFERENCED_TABLE_NAME,kcu.REFERENCED_COLUMN_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS rc JOIN information_schema.KEY_COLUMN_USAGE kcu ON kcu.CONSTRAINT_SCHEMA=rc.CONSTRAINT_SCHEMA AND kcu.TABLE_NAME=rc.TABLE_NAME AND kcu.CONSTRAINT_NAME=rc.CONSTRAINT_NAME WHERE rc.CONSTRAINT_SCHEMA=? AND rc.TABLE_NAME=? ORDER BY rc.CONSTRAINT_NAME,kcu.ORDINAL_POSITION', [$this->database, $table]),
            'checks' => $this->rows("SELECT tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=? AND tc.TABLE_NAME=? AND tc.CONSTRAINT_TYPE='CHECK' ORDER BY tc.CONSTRAINT_NAME", [$this->database, $table]),
            'triggers' => $this->rows('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', [$this->database, $table])];
    }

    private static function normalType(string $type): string
    {
        return preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower(trim($type)));
    }

    private function sameColumn(array $actual, array $expected, ?string $collation = null, bool $auto = false): bool
    {
        $text = preg_match('/\A(?:char|varchar)\(/', $expected[0]) === 1;
        return $actual['COLUMN_TYPE'] === $expected[0] && $actual['IS_NULLABLE'] === ($expected[1] ? 'YES' : 'NO') && $actual['COLUMN_DEFAULT'] === $expected[2]
            && $actual['CHARACTER_SET_NAME'] === ($text ? 'utf8mb4' : null) && $actual['COLLATION_NAME'] === ($text ? $collation : null)
            && $actual['EXTRA'] === ($auto ? 'auto_increment' : '') && (string) $actual['GENERATION_EXPRESSION'] === '';
    }

    private function sameIndex(array $actual, array $columns, bool $unique = false): bool
    {
        if (count($actual) !== count($columns)) { return false; }
        foreach ($actual as $offset => $row) {
            if ((int) $row['SEQ_IN_INDEX'] !== $offset + 1 || (string) $row['NON_UNIQUE'] !== ($unique ? '0' : '1') || $row['COLUMN_NAME'] !== $columns[$offset] || $row['SUB_PART'] !== null || $row['INDEX_TYPE'] !== 'BTREE' || $row['IS_VISIBLE'] !== 'YES' || $row['EXPRESSION'] !== null || $row['COLLATION'] !== 'A') { return false; }
        }
        return true;
    }

    private function columnSql(array $expected, ?string $collation = null): string
    {
        $sql = strtoupper($expected[0]);
        if ($collation !== null) { $sql .= ' CHARACTER SET utf8mb4 COLLATE ' . self::identifier($collation); }
        $sql .= $expected[1] ? ' NULL' : ' NOT NULL';
        if ($expected[2] !== null) { $sql .= ' DEFAULT ' . (str_starts_with($expected[0], 'varchar(') ? $this->pdo->quote($expected[2]) : $expected[2]); }
        return $sql;
    }

    private function stockColumns(): array
    {
        return ['id' => ['bigint unsigned', false, null], 'mer_id' => ['int unsigned', false, null], 'actor_employee_id' => ['int unsigned', false, null], 'product_id' => ['mediumint', false, null], 'attr_value_id' => ['int', false, null], 'sku_unique' => ['char(8)', false, null], 'action' => ['varchar(16)', false, null], 'delta' => ['bigint', false, null], 'stock_before' => ['int unsigned', false, null], 'stock_after' => ['int unsigned', false, null], 'root_stock_before' => ['mediumint unsigned', false, null], 'root_stock_after' => ['mediumint unsigned', false, null], 'reason' => ['varchar(255)', false, null], 'request_id' => ['varchar(64)', false, null], 'add_time' => ['int unsigned', false, null]];
    }

    private function stockIndexes(): array
    {
        return ['PRIMARY' => [true, ['id']], 'idx_mer_stock_id' => [false, ['mer_id', 'id']], 'idx_product_stock_id' => [false, ['product_id', 'id']], 'idx_mer_stock_actor' => [false, ['mer_id', 'actor_employee_id']]];
    }

    private function stockDdl(): string
    {
        $parts = [];
        foreach ($this->stockColumns() as $column => $expected) { $parts[] = self::quote($column) . ' ' . $this->columnSql($expected) . ($column === 'id' ? ' AUTO_INCREMENT' : ''); }
        foreach ($this->stockIndexes() as $index => [$unique, $columns]) { $parts[] = ($index === 'PRIMARY' ? 'PRIMARY KEY' : 'KEY ' . self::quote($index)) . ' (' . implode(',', array_map([self::class, 'quote'], $columns)) . ')'; }
        $parts[] = 'CONSTRAINT ' . self::quote($this->prefix . 'mm2_stock_positive') . ' CHECK (`mer_id` > 0 AND `actor_employee_id` > 0)';
        $parts[] = 'CONSTRAINT ' . self::quote($this->prefix . 'mm2_stock_merchant_fk') . ' FOREIGN KEY (`mer_id`) REFERENCES ' . $this->table('marketplace_merchant') . ' (`mer_id`) ON UPDATE RESTRICT ON DELETE RESTRICT';
        $parts[] = 'CONSTRAINT ' . self::quote($this->prefix . 'mm2_stock_employee_fk') . ' FOREIGN KEY (`mer_id`,`actor_employee_id`) REFERENCES ' . $this->table('marketplace_employee') . ' (`mer_id`,`id`) ON UPDATE RESTRICT ON DELETE RESTRICT';
        return 'CREATE TABLE ' . $this->table('marketplace_stock_log') . ' (' . implode(',', $parts) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin COMMENT='" . self::MARKER . "'";
    }

    private function sameStock(array $meta): bool
    {
        if ($meta['table']['TABLE_TYPE'] !== 'BASE TABLE' || strcasecmp((string) $meta['table']['ENGINE'], 'InnoDB') !== 0 || $meta['table']['TABLE_COLLATION'] !== 'utf8mb4_bin' || $meta['table']['TABLE_COMMENT'] !== self::MARKER || $meta['triggers'] !== [] || array_keys($meta['columns']) !== array_keys($this->stockColumns()) || array_keys($meta['indexes']) !== array_keys($this->sortedStockIndexes())) { return false; }
        foreach ($this->stockColumns() as $column => $expected) { if (!$this->sameColumn($meta['columns'][$column], $expected, 'utf8mb4_bin', $column === 'id')) { return false; } }
        foreach ($this->stockIndexes() as $index => [$unique, $columns]) { if (!$this->sameIndex($meta['indexes'][$index], $columns, $unique)) { return false; } }
        $checks = $meta['checks'];
        if (count($checks) !== 1 || $checks[0]['CONSTRAINT_NAME'] !== $this->prefix . 'mm2_stock_positive' || $checks[0]['ENFORCED'] !== 'YES' || !self::positiveStockCheck($checks[0]['CHECK_CLAUSE'])) { return false; }
        $expected = [$this->prefix . 'mm2_stock_employee_fk' => ['marketplace_employee', ['mer_id', 'actor_employee_id'], ['mer_id', 'id']], $this->prefix . 'mm2_stock_merchant_fk' => ['marketplace_merchant', ['mer_id'], ['mer_id']]];
        $foreign = [];
        foreach ($meta['foreign'] as $row) {
            $name = $row['CONSTRAINT_NAME'];
            if (!isset($expected[$name]) || $row['UPDATE_RULE'] !== 'RESTRICT' || $row['DELETE_RULE'] !== 'RESTRICT' || $row['MATCH_OPTION'] !== 'NONE' || $row['REFERENCED_TABLE_SCHEMA'] !== $this->database || $row['REFERENCED_TABLE_NAME'] !== $this->prefix . $expected[$name][0]) { return false; }
            $offset = count($foreign[$name] ?? []);
            if ((int) $row['ORDINAL_POSITION'] !== $offset + 1 || $row['COLUMN_NAME'] !== ($expected[$name][1][$offset] ?? null) || $row['REFERENCED_COLUMN_NAME'] !== ($expected[$name][2][$offset] ?? null)) { return false; }
            $foreign[$name][] = true;
        }
        return count($foreign[$this->prefix . 'mm2_stock_employee_fk'] ?? []) === 2 && count($foreign[$this->prefix . 'mm2_stock_merchant_fk'] ?? []) === 1 && count($foreign) === 2;
    }

    private function sortedStockIndexes(): array { $indexes = $this->stockIndexes(); ksort($indexes); return $indexes; }

    /** Only the fixed two-comparison AND tree is accepted; grouping is preserved. */
    private static function positiveStockCheck(string $expression): bool
    {
        if (strlen($expression) > 512) { return false; }
        $source = preg_replace('/[`\s]+/', '', strtolower($expression));
        preg_match_all('/actor_employee_id|mer_id|and|[0-9]+|[()>]/', $source, $matches);
        $tokens = $matches[0];
        if (implode('', $tokens) !== $source) { return false; }
        $offset = 0;
        $parse = null;
        $term = static function (int $depth) use (&$parse, &$offset, $tokens): array {
            if ($depth > 8) { throw new RuntimeException('Metadata unavailable'); }
            if (($tokens[$offset] ?? null) === '(') {
                ++$offset;
                $node = $parse($depth + 1);
                if (($tokens[$offset++] ?? null) !== ')') { throw new RuntimeException('Metadata unavailable'); }
                return $node;
            }
            $column = $tokens[$offset++] ?? null;
            if (!in_array($column, ['mer_id', 'actor_employee_id'], true) || ($tokens[$offset++] ?? null) !== '>' || ($tokens[$offset++] ?? null) !== '0') { throw new RuntimeException('Metadata unavailable'); }
            return ['gt', $column, 0];
        };
        $parse = static function (int $depth) use ($term, &$offset, $tokens): array {
            $node = $term($depth);
            if (($tokens[$offset] ?? null) === 'and') { ++$offset; return ['and', $node, $term($depth)]; }
            return $node;
        };
        try { $tree = $parse(0); }
        catch (Throwable $ignored) { return false; }
        return $offset === count($tokens) && $tree === ['and', ['gt', 'mer_id', 0], ['gt', 'actor_employee_id', 0]];
    }

    private function identity(array $view): array
    {
        $state = $this->readState();
        if ($state !== null) {
            $allowed = ['schema', 'marker', 'database_sha256', 'structure_sha256', 'phase'];
            if (($state['phase'] ?? '') === 'bound') { $allowed = array_merge($allowed, ['self_mer_id', 'created_at', 'row_shape_sha256']); }
            if (array_diff(array_keys($state), $allowed) !== [] || count($state) !== count($allowed) || ($state['schema'] ?? null) !== 1 || ($state['marker'] ?? null) !== self::MARKER || ($state['database_sha256'] ?? null) !== $view['database_sha256'] || ($state['structure_sha256'] ?? null) !== $view['structure_sha256']) { return $this->identityIssue('identity_state_mismatch'); }
            if ($state['phase'] === 'bound') {
                if (!is_int($state['self_mer_id']) || $state['self_mer_id'] < 1 || $state['self_mer_id'] > 4294967295 || !is_int($state['created_at']) || $state['created_at'] < 1 || !is_string($state['row_shape_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $state['row_shape_sha256']) !== 1 || ($this->configuredSelfId !== null && $this->configuredSelfId !== $state['self_mer_id'])) { return $this->identityIssue('identity_state_mismatch'); }
                $row = $this->selfRow($state['self_mer_id']);
                if ((int) $row['add_time'] !== $state['created_at'] || !hash_equals($state['row_shape_sha256'], $this->selfShapeHash($row))) { return $this->identityIssue('identity_row_mismatch'); }
                return ['kind' => $this->configuredSelfId === null ? 'bound' : 'configured', 'id' => $state['self_mer_id'], 'issues' => []];
            }
            if ($state['phase'] === 'structure_pending' && $this->configuredSelfId === null) { return ['kind' => 'structure_pending', 'id' => null, 'issues' => []]; }
            return $this->identityIssue('identity_incomplete_state');
        }
        if ($this->configuredSelfId !== null) { $this->selfRow($this->configuredSelfId); return ['kind' => 'configured', 'id' => $this->configuredSelfId, 'issues' => []]; }
        if ($this->stateFile === null) { return ['kind' => 'binding_required', 'id' => null, 'issues' => []]; }
        if (!$view['pristine']) { return $this->identityIssue('identity_state_missing'); }
        return ['kind' => 'new_required', 'id' => null, 'issues' => []];
    }

    private function selfRow(int $id): array
    {
        $rows = $this->rows('SELECT ' . implode(',', array_map([self::class, 'quote'], self::SELF_COLUMNS)) . ' FROM ' . $this->table('marketplace_merchant') . ' WHERE mer_id=? LIMIT 2', [$id]);
        if (count($rows) !== 1 || array_keys($rows[0]) !== self::SELF_COLUMNS || self::positiveUint($rows[0]['mer_id']) !== $id) { throw new RuntimeException('Identity unavailable'); }
        $row = $rows[0];
        foreach (['name' => 128, 'logo' => 512, 'after_sale_contact' => 255, 'shipping_note' => 1000] as $name => $limit) {
            if (!is_string($row[$name]) || preg_match('//u', $row[$name]) !== 1 || str_contains($row[$name], "\0") || preg_match_all('/./us', $row[$name]) > $limit || ($name === 'name' && trim($row[$name]) === '')) { throw new RuntimeException('Identity unavailable'); }
        }
        foreach (['review_status' => 2, 'accept_orders' => 1, 'fulfillment_enabled' => 1, 'security_locked' => 1] as $name => $maximum) {
            if (!preg_match('/\A[0-2]\z/D', (string) $row[$name]) || (int) $row[$name] > $maximum) { throw new RuntimeException('Identity unavailable'); }
        }
        self::positiveUint($row['add_time']); self::positiveUint($row['update_time']);
        if ($row['closed_at'] !== null) { self::positiveUint($row['closed_at']); }
        return $row;
    }

    private function selfShapeHash(array $row): string { return hash('sha256', self::json(['mer_id' => (int) $row['mer_id'], 'add_time' => (int) $row['add_time'], 'columns' => self::SELF_COLUMNS])); }
    private function stateRecord(array $view, string $phase): array { return ['schema' => 1, 'marker' => self::MARKER, 'database_sha256' => $view['database_sha256'], 'structure_sha256' => $view['structure_sha256'], 'phase' => $phase]; }

    private function privateStatePath(string $path): string
    {
        // Windows chmod cannot establish the required private ACL; do not pretend otherwise.
        if (DIRECTORY_SEPARATOR !== '/' || !str_starts_with($path, '/') || str_contains($path, "\0") || !function_exists('posix_geteuid') || !function_exists('fsync')) { throw new RuntimeException('Private state unavailable'); }
        $parent = realpath(dirname($path));
        if ($parent === false || !is_dir($parent) || ($parent === $this->repoRoot || str_starts_with($parent . '/', rtrim($this->repoRoot, '/') . '/')) || (fileperms($parent) & 0077) !== 0 || fileowner($parent) !== posix_geteuid()) { throw new RuntimeException('Private state unavailable'); }
        $name = basename($path);
        if ($name === '' || $name === '.' || $name === '..') { throw new RuntimeException('Private state unavailable'); }
        return $parent . '/' . $name;
    }

    private function readState(): ?array
    {
        if ($this->stateFile === null) { return null; }
        clearstatcache(true, $this->stateFile);
        if (is_link($this->stateFile)) { throw new RuntimeException('Private state unavailable'); }
        if (!file_exists($this->stateFile)) { $this->stateBytesHash = null; return null; }
        if (!is_file($this->stateFile) || (fileperms($this->stateFile) & 0077) !== 0 || fileowner($this->stateFile) !== posix_geteuid()) { throw new RuntimeException('Private state unavailable'); }
        $handle = $this->stateHandle ?? @fopen($this->stateFile, 'rb');
        if (!is_resource($handle)) { throw new RuntimeException('Private state unavailable'); }
        try {
            $actual = lstat($this->stateFile); $open = fstat($handle);
            if ($actual === false || $open === false || $actual['ino'] !== $open['ino'] || $actual['dev'] !== $open['dev']) { throw new RuntimeException('Private state unavailable'); }
            @rewind($handle); $bytes = @stream_get_contents($handle, 16385);
        }
        finally { if ($this->stateHandle === null) { fclose($handle); } }
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 16384) { throw new RuntimeException('Private state unavailable'); }
        $state = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($state) || array_is_list($state)) { throw new RuntimeException('Private state unavailable'); }
        $this->stateBytesHash = hash('sha256', $bytes);
        return $state;
    }

    private function openStateHandle(bool $new): void
    {
        if ($this->stateFile === null) { throw new RuntimeException('Private state unavailable'); }
        $handle = @fopen($this->stateFile, $new ? 'x+b' : 'r+b');
        if (!is_resource($handle)) { throw new RuntimeException('Private state unavailable'); }
        $this->stateHandle = $handle;
        if (($new && !@chmod($this->stateFile, 0600)) || !@flock($handle, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Private state unavailable'); }
        if ($new) {
            // Write the bound structure intent before parsing this newly created empty file.
            $this->stateBytesHash = hash('sha256', '');
        }
    }

    private function writeState(array $state): void
    {
        if (!is_resource($this->stateHandle) || $this->stateFile === null) { throw new RuntimeException('Private state unavailable'); }
        clearstatcache(true, $this->stateFile);
        $actual = lstat($this->stateFile);
        $open = fstat($this->stateHandle);
        if ($actual === false || $open === false || is_link($this->stateFile) || $actual['ino'] !== $open['ino'] || $actual['dev'] !== $open['dev'] || (fileperms($this->stateFile) & 0077) !== 0) { throw new RuntimeException('Private state unavailable'); }
        rewind($this->stateHandle);
        $old = stream_get_contents($this->stateHandle, 16385);
        if (!is_string($old) || strlen($old) > 16384 || $this->stateBytesHash === null || !hash_equals($this->stateBytesHash, hash('sha256', $old))) { throw new RuntimeException('Private state unavailable'); }
        $bytes = self::json($state) . "\n";
        if (strlen($bytes) > 16384 || !@ftruncate($this->stateHandle, 0) || !@rewind($this->stateHandle)) { throw new RuntimeException('Private state unavailable'); }
        $offset = 0;
        while ($offset < strlen($bytes)) { $written = @fwrite($this->stateHandle, substr($bytes, $offset)); if ($written === false || $written < 1) { throw new RuntimeException('Private state unavailable'); } $offset += $written; }
        if (!@fflush($this->stateHandle) || !@fsync($this->stateHandle)) { throw new RuntimeException('Private state unavailable'); }
        $this->stateBytesHash = hash('sha256', $bytes);
    }

    private function receipt(string $mode, array $view, array $identity): array
    {
        $issues = array_merge($view['issues'], $identity['issues']);
        $state = $issues !== [] ? 'incompatible' : ($view['steps'] === [] && in_array($identity['kind'], ['bound', 'configured'], true) ? 'completed' : 'applicable');
        return ['mode' => $mode, 'state' => $state, 'native_exit' => $issues === [] ? 0 : 2, 'changed' => $this->changed, 'applied_steps' => $this->applied, 'pending_steps' => array_column($view['steps'], 'code'), 'self_identity' => $identity['kind'], 'self_mer_id' => $identity['id'], 'enabled' => false, 'issues' => $issues, 'ownership_phase' => $view['ownership_phase'] ?? 'unknown', 'migration_complete' => false, 'history_mapping' => 'REQUIRES EXPLICIT CONFIRMED FILE', 'acceptance' => 'NOT RUN'];
    }

    private function failure(string $mode, string $kind, int $exit = 1): array { return ['mode' => $mode, 'state' => 'incompatible', 'native_exit' => $exit, 'changed' => $this->changed ? true : ($this->writeAttempted ? null : false), 'applied_steps' => $this->applied, 'issues' => [self::issue($kind, 'migration')], 'enabled' => false, 'migration_complete' => false, 'history_mapping' => 'REQUIRES EXPLICIT CONFIRMED FILE', 'acceptance' => 'NOT RUN']; }
    private function identityIssue(string $kind): array { return ['kind' => 'invalid', 'id' => null, 'issues' => [self::issue($kind, 'self_identity')]]; }
    private static function issue(string $kind, string $target): array { return compact('kind', 'target'); }
    private function rows(string $sql, array $parameters = []): array { $statement = $this->pdo->prepare($sql); if (!$statement->execute($parameters)) { throw new RuntimeException('Operation failed'); } return $statement->fetchAll(PDO::FETCH_ASSOC); }
    private function scalar(string $sql, array $parameters = []) { $statement = $this->pdo->prepare($sql); if (!$statement->execute($parameters)) { throw new RuntimeException('Operation failed'); } return $statement->fetchColumn(); }
    private static function positiveUint($value): int { if ((!is_string($value) && !is_int($value)) || preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $value) !== 1 || (int) $value > 4294967295) { throw new RuntimeException('Identity unavailable'); } return (int) $value; }
    private function table(string $name): string { return self::quote($this->prefix . $name); }
    private static function identifier(string $name): string { if (strlen($name) > 64 || preg_match('/\A[A-Za-z0-9_]+\z/D', $name) !== 1) { throw new RuntimeException('Configuration unavailable'); } return $name; }
    private static function quote(string $name): string { return '`' . self::identifier($name) . '`'; }
    private static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}

/** Config is private input only; no values, path or connection strings are exposed. */
function marketplaceMs2Configuration(string $file): array
{
    $handle = @fopen($file, 'rb');
    if ($handle === false) { throw new RuntimeException('Configuration unavailable'); }
    try { $raw = stream_get_contents($handle, 65537); } finally { fclose($handle); }
    if (!is_string($raw) || strlen($raw) > 65536) { throw new RuntimeException('Configuration unavailable'); }
    $config = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($config) || array_is_list($config) || array_diff(array_keys($config), ['hostname', 'hostport', 'database', 'username', 'password', 'prefix', 'marketplace']) !== []) { throw new RuntimeException('Configuration unavailable'); }
    foreach (['hostname', 'database', 'username', 'password', 'prefix'] as $key) { if (!isset($config[$key]) || !is_string($config[$key])) { throw new RuntimeException('Configuration unavailable'); } }
    $port = $config['hostport'] ?? '3306';
    if ((!is_string($port) && !is_int($port)) || preg_match('/\A[1-9][0-9]{0,4}\z/D', (string) $port) !== 1 || (int) $port > 65535 || $config['username'] === '' || preg_match('/\A[A-Za-z0-9_.:\[\]-]+\z/D', $config['hostname']) !== 1 || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $config['database']) !== 1 || preg_match('/\A[A-Za-z0-9_]*\z/D', $config['prefix']) !== 1 || (array_key_exists('marketplace', $config) && (!is_array($config['marketplace']) || ($config['marketplace'] !== [] && array_is_list($config['marketplace']))))) { throw new RuntimeException('Configuration unavailable'); }
    $config['hostport'] = (string) $port;
    $config['marketplace'] = $config['marketplace'] ?? [];
    return $config;
}

function marketplaceMs2Main(array $arguments): int
{
    if ($arguments === ['help'] || $arguments === ['--help']) {
        echo "Usage: php marketplace-ms2.php [check|apply-structure|backfill|enforce] --repo-root=PATH --config-file=PRIVATE_JSON [--state-file=PRIVATE_ABSOLUTE_PATH]\nbackfill/enforce require --mapping=CONFIRMED_PRIVATE_JSON --quarantine-dir=PRIVATE_OUTSIDE_REPO_DIR.\ncheck may read --mapping. enforce additionally requires --maintenance-confirmed (caller asserts all other writers are paused).\nDefault check is read-only. No .env is read. Feature stays disabled. Real historical mapping and acceptance are not implied.\n";
        return 0;
    }
    $modes = ['check', 'apply-structure', 'backfill', 'enforce'];
    $mode = in_array($arguments[0] ?? null, $modes, true) ? $arguments[0] : 'check';
    $result = null;
    try {
        $options = [];
        $selected = $maintenance = false;
        foreach ($arguments as $argument) {
            if (in_array($argument, $modes, true) && !$selected) { $mode = $argument; $selected = true; continue; }
            if ($argument === '--maintenance-confirmed' && !$maintenance) { $maintenance = true; continue; }
            if (!is_string($argument) || preg_match('/\A--(repo-root|config-file|state-file|mapping|quarantine-dir)=(.+)\z/Ds', $argument, $match) !== 1 || isset($options[$match[1]])) { throw new RuntimeException('Configuration unavailable'); }
            $options[$match[1]] = $match[2];
        }
        if (!isset($options['repo-root'], $options['config-file'])) { throw new RuntimeException('Configuration unavailable'); }
        if (in_array($mode, ['backfill', 'enforce'], true) && !isset($options['mapping'], $options['quarantine-dir'])) { throw new RuntimeException('Configuration unavailable'); }
        if ($mode === 'enforce' && !$maintenance) { throw new RuntimeException('Configuration unavailable'); }
        if ($maintenance && $mode !== 'enforce') { throw new RuntimeException('Configuration unavailable'); }
        if (isset($options['quarantine-dir']) && !in_array($mode, ['backfill', 'enforce'], true)) { throw new RuntimeException('Configuration unavailable'); }
        if (isset($options['mapping']) && $mode === 'apply-structure') { throw new RuntimeException('Configuration unavailable'); }
        $root = realpath($options['repo-root']);
        if ($root === false || !is_file($root . '/help/migrations/marketplace-ms1.php')) { throw new RuntimeException('Configuration unavailable'); }
        require_once $root . '/help/migrations/marketplace-ms1.php';
        $config = marketplaceMs2Configuration($options['config-file']);
        $pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3]);
        $migration = new MarketplaceMs2Migration($pdo, new MarketplaceMs1Migration($pdo, $config['prefix']), $config['prefix'], $root, $config['marketplace'], $options['state-file'] ?? null);
        $result = match ($mode) {
            'check' => $migration->check($options['mapping'] ?? null),
            'apply-structure' => $migration->applyStructure(),
            'backfill' => $migration->backfill($options['mapping'], $options['quarantine-dir']),
            'enforce' => $migration->enforce($options['mapping'], $options['quarantine-dir'], $maintenance),
        };
        $output = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($output) > 33554432) { echo json_encode(['mode' => $mode, 'state' => 'incompatible', 'native_exit' => 1, 'changed' => $result['changed'], 'applied_steps' => $result['applied_steps'], 'self_mer_id' => $result['self_mer_id'] ?? null, 'issues' => [['kind' => 'public_receipt_limit', 'target' => 'migration']], 'enabled' => false, 'acceptance' => 'NOT RUN'], JSON_THROW_ON_ERROR) . "\n"; return 1; }
        echo $output . "\n";
        return $result['native_exit'];
    } catch (Throwable $ignored) {
        echo json_encode(['mode' => $mode, 'state' => 'incompatible', 'native_exit' => 1, 'changed' => is_array($result) && array_key_exists('changed', $result) ? $result['changed'] : false, 'applied_steps' => is_array($result) ? ($result['applied_steps'] ?? []) : [], 'self_mer_id' => is_array($result) ? ($result['self_mer_id'] ?? null) : null, 'issues' => [['kind' => 'configuration_connection_or_receipt_failed', 'target' => 'migration']], 'enabled' => false, 'acceptance' => 'NOT RUN'], JSON_THROW_ON_ERROR) . "\n";
        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { exit(marketplaceMs2Main(array_slice($argv, 1))); }
