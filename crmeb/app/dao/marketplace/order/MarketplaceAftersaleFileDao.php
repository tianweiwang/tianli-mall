<?php
declare(strict_types=1);

namespace app\dao\marketplace\order;

use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOStatement;
use think\db\ConnectionInterface;
use Throwable;

/** Native metadata seams only. The caller owns authentication, root/action lock order and the one transaction. */
final class MarketplaceAftersaleFileDao
{
    private const INT_MAX = 2147483647;
    private const UINT_MAX = 4294967295;
    private const COLUMNS = ['id', 'uid', 'root_order_id', 'mer_id', 'refund_id', 'image_position', 'object_key',
        'media_type', 'byte_size', 'width', 'height', 'content_sha256', 'upload_key', 'state', 'created_at',
        'claim_expires_at', 'claimed_at', 'purged_at'];
    private const OBJECT_COLUMNS = ['root_order_id', 'object_key', 'media_type', 'byte_size', 'width', 'height', 'content_sha256'];

    /** Upload/retry only, after the caller locks and authorizes the real root. Never revives expired/deleted keys. */
    public function findUploadForRoot(ConnectionInterface $connection, PDO $pdo, array $fullRoot, int $actualUid, string $uploadKey): ?array
    {
        $this->gate($connection, $pdo, true);
        $root = $this->root($connection, $pdo, $fullRoot, true);
        if (self::integer($actualUid, 1, self::INT_MAX) !== $root['uid']) self::unavailable();
        self::uploadKey($uploadKey);
        $rows = $this->rows($connection, $pdo, true, 'SELECT * FROM ' . $this->table($connection, 'marketplace_aftersale_file')
            . ' WHERE uid=? AND upload_key=?', [$actualUid, $uploadKey]);
        if (count($rows) > 1) self::unavailable();
        if ($rows === []) return null;
        $row = self::row($rows[0]); self::owner($row, $root);
        return $row;
    }

    /** Insert the real seven-key Storage observation; no filesystem write or commit is claimed here. */
    public function insertStaged(ConnectionInterface $connection, PDO $pdo, array $fullRoot, int $actualUid, string $uploadKey, array $storedObject): array
    {
        $this->gate($connection, $pdo, true);
        $root = $this->root($connection, $pdo, $fullRoot, true);
        if (self::integer($actualUid, 1, self::INT_MAX) !== $root['uid']) self::unavailable();
        self::uploadKey($uploadKey); self::keys($storedObject, self::OBJECT_COLUMNS);
        if (self::integer($storedObject['root_order_id'], 1, self::INT_MAX) !== $root['id']) self::unavailable();
        self::object($storedObject);
        $now = self::now();
        if ($now > self::UINT_MAX - 86400) self::unavailable();
        $data = ['uid' => $root['uid'], 'root_order_id' => $root['id'], 'mer_id' => $root['mer_id'],
            'refund_id' => null, 'image_position' => null, 'object_key' => $storedObject['object_key'],
            'media_type' => $storedObject['media_type'], 'byte_size' => self::integer($storedObject['byte_size'], 1, 5242880),
            'width' => self::integer($storedObject['width'], 1, 4096), 'height' => self::integer($storedObject['height'], 1, 4096),
            'content_sha256' => $storedObject['content_sha256'], 'upload_key' => $uploadKey, 'state' => 'STAGED',
            'created_at' => $now, 'claim_expires_at' => $now + 86400, 'claimed_at' => null, 'purged_at' => null];
        $columns = array_keys($data);
        $sql = 'INSERT INTO ' . $this->table($connection, 'marketplace_aftersale_file') . ' ('
            . implode(',', array_map([self::class, 'quote'], $columns)) . ') VALUES (' . self::marks(count($columns)) . ')';
        if ($this->write($connection, $pdo, $sql, array_values($data)) !== 1) self::unavailable();
        $id = self::u64($pdo->lastInsertId());
        $rows = $this->rows($connection, $pdo, true, 'SELECT * FROM ' . $this->table($connection, 'marketplace_aftersale_file')
            . ' WHERE id=?', [$id]);
        if (count($rows) !== 1) self::unavailable();
        $row = self::row($rows[0]); self::owner($row, $root);
        if (self::u64($row['id']) !== $id) self::unavailable();
        foreach ($data as $key => $value) {
            $actual = $row[$key];
            if (is_int($value)) $actual = self::integer($actual, 0, self::UINT_MAX);
            if ($actual !== $value) self::unavailable();
        }
        return $row;
    }

    /** After existing root/family/actions locks: ascending file locks, output in original images order. */
    public function lockStagedForClaim(ConnectionInterface $connection, PDO $pdo, array $fullRoot, int $actualUid, array $images): array
    {
        $this->gate($connection, $pdo, true);
        $root = $this->root($connection, $pdo, $fullRoot, true);
        if (self::integer($actualUid, 1, self::INT_MAX) !== $root['uid']) self::unavailable();
        $ids = self::images($images);
        if ($ids === []) return [];
        $sorted = $ids; usort($sorted, [self::class, 'compareId']);
        $rows = $this->rows($connection, $pdo, true, 'SELECT * FROM ' . $this->table($connection, 'marketplace_aftersale_file')
            . ' WHERE id IN (' . self::marks(count($sorted)) . ') ORDER BY id FOR UPDATE', $sorted);
        if (count($rows) !== count($ids)) self::unavailable();
        $map = []; $observed = []; $now = self::now();
        foreach ($rows as $row) {
            $row = self::row($row); self::owner($row, $root); $id = self::u64($row['id']);
            if (isset($map['id:' . $id]) || $row['state'] !== 'STAGED'
                || self::integer($row['created_at'], 1, self::UINT_MAX) > $now
                || self::integer($row['claim_expires_at'], 1, self::UINT_MAX) < $now) self::unavailable();
            $map['id:' . $id] = $row; $observed[] = $id;
        }
        if ($observed !== $sorted) self::unavailable();
        return array_map(static function (string $id) use ($map): array { return $map['id:' . $id]; }, $ids);
    }

    /** Real refund/mirror already written in this same transaction; Storage must recheck every locked object's bytes before this call. */
    public function claimForRefund(ConnectionInterface $connection, PDO $pdo, array $fullRoot, array $fullRefund, array $lockedFiles): array
    {
        $this->gate($connection, $pdo, true);
        $root = $this->root($connection, $pdo, $fullRoot, true);
        $refunds = $this->refunds($connection, $pdo, $root, [$fullRefund], true);
        $refund = reset($refunds); $images = self::refundImages($refund);
        if (!array_is_list($lockedFiles) || count($lockedFiles) !== count($images)) self::unavailable();
        $fresh = $this->lockStagedForClaim($connection, $pdo, $fullRoot, $root['uid'], $images);
        if ($fresh !== $lockedFiles) self::unavailable();
        $now = self::now(); $refundId = self::integer($refund['id'], 1, self::INT_MAX);
        foreach ($fresh as $position => $row) {
            if (self::integer($row['created_at'], 1, self::UINT_MAX) > $now
                || self::integer($row['claim_expires_at'], 1, self::UINT_MAX) < $now) self::unavailable();
            $changed = $this->write($connection, $pdo, 'UPDATE ' . $this->table($connection, 'marketplace_aftersale_file')
                . ' SET refund_id=?,image_position=?,claimed_at=?,state=? WHERE id=? AND uid=? AND root_order_id=? AND mer_id=?'
                . ' AND state=? AND refund_id IS NULL AND image_position IS NULL AND claimed_at IS NULL AND purged_at IS NULL'
                . ' AND created_at<=? AND claim_expires_at>=?', [$refundId, $position, $now, 'CLAIMED', self::u64($row['id']),
                    $root['uid'], $root['id'], $root['mer_id'], 'STAGED', $now, $now]);
            if ($changed !== 1) self::unavailable();
        }
        // Fresh complete set, not the pre-claim input; original images order is checked both ways.
        $after = $this->bound($connection, $pdo, $root, $refunds, true); $result = $after[$refundId];
        foreach ($result as $position => $row) {
            $before = $fresh[$position];
            foreach (self::COLUMNS as $key) {
                if (in_array($key, ['refund_id', 'image_position', 'claimed_at', 'state'], true)) continue;
                if ($row[$key] !== $before[$key]) self::unavailable();
            }
            if (self::integer($row['claimed_at'], 1, self::UINT_MAX) !== $now) self::unavailable();
        }
        return $result;
    }

    /** Saved replay/finalOriginal in the writer-owned transaction. Today's STAGED deadline never invalidates CLAIMED replay. */
    public function readClaimedForRefund(ConnectionInterface $connection, PDO $pdo, array $fullRoot, array $fullRefund): array
    {
        $this->gate($connection, $pdo, true);
        $root = $this->root($connection, $pdo, $fullRoot, true);
        $refunds = $this->refunds($connection, $pdo, $root, [$fullRefund], true);
        $result = $this->bound($connection, $pdo, $root, $refunds, true);
        return $result[self::integer($fullRefund['id'] ?? null, 1, self::INT_MAX)];
    }

    /** R2 native nonlocking batch, inside the caller-owned RR READ ONLY snapshot. Complete set even for images=[]. */
    public function readClaimedForRefunds(ConnectionInterface $connection, PDO $pdo, array $fullRoot, array $fullRefunds): array
    {
        $this->gate($connection, $pdo, false);
        $root = $this->root($connection, $pdo, $fullRoot, false);
        $refunds = $this->refunds($connection, $pdo, $root, $fullRefunds, false);
        return $this->bound($connection, $pdo, $root, $refunds, false);
    }

    /** Locator only: no actor, ticket or access permission is proved by a returned row. Never SELECT FOR UPDATE. */
    public function readLocatedFile(ConnectionInterface $connection, PDO $pdo, string $fileId): array
    {
        $this->gate($connection, $pdo, false); self::wireId($fileId);
        $rows = $this->rows($connection, $pdo, false, 'SELECT * FROM ' . $this->table($connection, 'marketplace_aftersale_file')
            . ' WHERE id=?', [$fileId]);
        if (count($rows) !== 1) self::unavailable();
        $row = self::row($rows[0]);
        if (self::u64($row['id']) !== $fileId) self::unavailable();
        return $row;
    }

    /** Content seam after the caller's fresh real actor/root authorization and root lock; not an authorization result. */
    public function lockFileForRoot(ConnectionInterface $connection, PDO $pdo, array $fullRoot, string $fileId): array
    {
        $this->gate($connection, $pdo, true); self::wireId($fileId);
        $root = $this->root($connection, $pdo, $fullRoot, true);
        $rows = $this->rows($connection, $pdo, true, 'SELECT * FROM ' . $this->table($connection, 'marketplace_aftersale_file')
            . ' WHERE id=? ORDER BY id FOR UPDATE', [$fileId]);
        if (count($rows) !== 1) self::unavailable();
        $row = self::row($rows[0]); self::owner($row, $root);
        if (self::u64($row['id']) !== $fileId) self::unavailable();
        return $row;
    }

    private function root(ConnectionInterface $connection, PDO $pdo, array $fullRoot, bool $write): array
    {
        self::nativeRow($fullRoot);
        $id = self::integer($fullRoot['id'] ?? null, 1, self::INT_MAX);
        $uid = self::integer($fullRoot['uid'] ?? null, 1, self::INT_MAX);
        $merchant = self::integer($fullRoot['mer_id'] ?? null, 1, self::UINT_MAX);
        if (self::integer($fullRoot['pid'] ?? null, 0, 0) !== 0
            || self::integer($fullRoot['marketplace_trade_version'] ?? null, 1, 1) !== 1) self::unavailable();
        $rows = $this->rows($connection, $pdo, $write, 'SELECT * FROM ' . $this->table($connection, 'store_order') . ' WHERE id=?', [$id]);
        if (count($rows) !== 1 || $rows[0] !== $fullRoot) self::unavailable();
        return ['id' => $id, 'uid' => $uid, 'mer_id' => $merchant];
    }

    private function refunds(ConnectionInterface $connection, PDO $pdo, array $root, array $fullRefunds, bool $write): array
    {
        if (!array_is_list($fullRefunds) || count($fullRefunds) > 100) self::unavailable();
        $expected = []; $ids = []; $orderIds = [];
        foreach ($fullRefunds as $row) {
            if (!is_array($row)) self::unavailable(); self::nativeRow($row);
            $id = self::integer($row['id'] ?? null, 1, self::INT_MAX);
            if (isset($expected[$id])) self::unavailable();
            if (self::integer($row['uid'] ?? null, 1, self::INT_MAX) !== $root['uid']
                || self::integer($row['mer_id'] ?? null, 1, self::UINT_MAX) !== $root['mer_id']
                || self::integer($row['marketplace_root_order_id'] ?? null, 1, self::INT_MAX) !== $root['id']) self::unavailable();
            self::u64($row['marketplace_payment_attempt_id'] ?? null);
            $apply = self::u64($row['marketplace_apply_action_id'] ?? null);
            $last = self::u64($row['marketplace_last_action_id'] ?? null);
            if (self::compareId($last, $apply) < 0) self::unavailable();
            $kind = $row['marketplace_kind'] ?? null; $mode = $row['marketplace_refund_mode'] ?? null;
            if (!in_array($kind, ['money_only', 'cancel_unshipped', 'return_goods'], true)
                || $mode !== ($kind === 'return_goods' ? 'return_goods' : 'money_only')) self::unavailable();
            self::refundImages($row);
            $orderId = self::integer($row['store_order_id'] ?? null, 1, self::INT_MAX);
            $orderIds[$orderId] = true; $expected[$id] = $row; $ids[] = $id;
        }
        if ($ids === []) return [];
        $observed = $this->rows($connection, $pdo, $write, 'SELECT * FROM ' . $this->table($connection, 'store_order_refund')
            . ' WHERE id IN (' . self::marks(count($ids)) . ') ORDER BY id', $ids);
        if (count($observed) !== count($expected)) self::unavailable();
        $seen = [];
        foreach ($observed as $row) {
            $id = self::integer($row['id'] ?? null, 1, self::INT_MAX);
            if (!isset($expected[$id]) || isset($seen[$id]) || $row !== $expected[$id]) self::unavailable();
            $seen[$id] = true;
        }
        $oids = array_keys($orderIds);
        $headers = $this->rows($connection, $pdo, $write, 'SELECT * FROM ' . $this->table($connection, 'store_order')
            . ' WHERE id IN (' . self::marks(count($oids)) . ') ORDER BY id', $oids);
        if (count($headers) !== count($oids)) self::unavailable();
        $seenOrders = [];
        foreach ($headers as $row) {
            $id = self::integer($row['id'] ?? null, 1, self::INT_MAX);
            if (!isset($orderIds[$id]) || isset($seenOrders[$id])
                || self::integer($row['uid'] ?? null, 1, self::INT_MAX) !== $root['uid']
                || self::integer($row['mer_id'] ?? null, 1, self::UINT_MAX) !== $root['mer_id']
                || self::integer($row['marketplace_trade_version'] ?? null, 1, 1) !== 1
                || self::integer($row['pid'] ?? null, 0, self::INT_MAX) !== ($id === $root['id'] ? 0 : $root['id'])) self::unavailable();
            $seenOrders[$id] = true;
        }
        return $expected;
    }

    private function bound(ConnectionInterface $connection, PDO $pdo, array $root, array $refunds, bool $write): array
    {
        $output = []; $images = []; $map = [];
        foreach ($refunds as $id => $row) { $output[$id] = []; $images[$id] = self::refundImages($row); }
        if ($refunds === []) return $output;
        $ids = array_keys($refunds);
        $rows = $this->rows($connection, $pdo, $write, 'SELECT * FROM ' . $this->table($connection, 'marketplace_aftersale_file')
            . ' WHERE refund_id IN (' . self::marks(count($ids)) . ') ORDER BY refund_id,image_position,id', $ids);
        if (count($rows) > count($refunds) * 9) self::unavailable();
        foreach ($rows as $row) {
            $row = self::row($row); self::owner($row, $root);
            $refundId = self::integer($row['refund_id'], 1, self::INT_MAX);
            $position = self::integer($row['image_position'], 0, 8); $id = self::u64($row['id']);
            if (!isset($refunds[$refundId]) || $row['state'] !== 'CLAIMED' || isset($map['id:' . $id])
                || $position !== count($output[$refundId]) || ($images[$refundId][$position] ?? null) !== $id) self::unavailable();
            $map['id:' . $id] = true; $output[$refundId][] = $row;
        }
        foreach ($output as $id => $list) if (count($list) !== count($images[$id])) self::unavailable();
        $this->gate($connection, $pdo, $write);
        return $output;
    }

    private static function row(array $row): array
    {
        self::keys($row, self::COLUMNS); self::nativeRow($row); self::u64($row['id']);
        self::integer($row['uid'], 1, self::INT_MAX); self::integer($row['root_order_id'], 1, self::INT_MAX);
        self::integer($row['mer_id'], 1, self::UINT_MAX); self::uploadKey($row['upload_key']); self::object($row);
        $created = self::integer($row['created_at'], 1, self::UINT_MAX);
        $expires = self::integer($row['claim_expires_at'], 1, self::UINT_MAX);
        if ($created > self::UINT_MAX - 86400 || $expires !== $created + 86400) self::unavailable();
        $state = $row['state'];
        if (!in_array($state, ['STAGED', 'CLAIMED', 'DELETE_PENDING', 'DELETED'], true)) self::unavailable();
        if ($state === 'CLAIMED') {
            self::integer($row['refund_id'], 1, self::INT_MAX); self::integer($row['image_position'], 0, 8);
            $claimed = self::integer($row['claimed_at'], 1, self::UINT_MAX);
            if ($claimed < $created || $claimed > $expires || $row['purged_at'] !== null) self::unavailable();
        } else {
            if ($row['refund_id'] !== null || $row['image_position'] !== null || $row['claimed_at'] !== null) self::unavailable();
            if ($state === 'DELETED') {
                if (self::integer($row['purged_at'], 1, self::UINT_MAX) < $created) self::unavailable();
            } elseif ($row['purged_at'] !== null) self::unavailable();
        }
        return $row;
    }
    private static function object(array $row): void
    {
        if (!is_string($row['object_key']) || preg_match('/\A[a-f0-9]{64}\z/D', $row['object_key']) !== 1
            || !in_array($row['media_type'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || !is_string($row['content_sha256']) || strlen($row['content_sha256']) !== 32) self::unavailable();
        self::integer($row['byte_size'], 1, 5242880); self::integer($row['width'], 1, 4096); self::integer($row['height'], 1, 4096);
    }
    private static function owner(array $row, array $root): void
    {
        if (self::integer($row['uid'], 1, self::INT_MAX) !== $root['uid']
            || self::integer($row['root_order_id'], 1, self::INT_MAX) !== $root['id']
            || self::integer($row['mer_id'], 1, self::UINT_MAX) !== $root['mer_id']) self::unavailable();
    }
    private static function refundImages(array $refund): array
    {
        $raw = $refund['refund_img'] ?? null;
        if (!is_string($raw)) self::unavailable();
        try { $value = json_decode($raw, false, 16, JSON_THROW_ON_ERROR); } catch (Throwable $error) { self::unavailable(); }
        if (!is_array($value) || !array_is_list($value)) self::unavailable();
        return self::images($value);
    }
    private static function images(array $ids): array
    {
        if (!array_is_list($ids) || count($ids) > 9) self::unavailable();
        $seen = [];
        foreach ($ids as $id) { self::wireId($id); if (isset($seen['id:' . $id])) self::unavailable(); $seen['id:' . $id] = true; }
        return $ids;
    }
    private static function wireId($value): string { if (!is_string($value)) self::unavailable(); return self::u64($value); }
    private static function u64($value): string
    {
        if (is_int($value) && $value > 0) $value = (string)$value;
        if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,19}\z/D', $value) !== 1
            || self::compareId($value, '18446744073709551615') > 0) self::unavailable();
        return $value;
    }
    private static function compareId(string $left, string $right): int { return strlen($left) <=> strlen($right) ?: strcmp($left, $right); }
    private static function integer($value, int $minimum, int $maximum): int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
            && strlen($value) <= 10 && (string)(int)$value === $value) $value = (int)$value;
        if (!is_int($value) || $value < $minimum || $value > $maximum) self::unavailable();
        return $value;
    }
    private static function uploadKey($value): void
    {
        if (!is_string($value) || preg_match('/\A[\x20-\x7e]{1,128}\z/D', $value) !== 1
            || $value[0] === ' ' || substr($value, -1) === ' ') self::unavailable();
    }
    private static function keys(array $value, array $expected): void
    {
        $actual = array_keys($value); sort($actual, SORT_STRING); sort($expected, SORT_STRING);
        if ($actual !== $expected) self::unavailable();
    }
    private static function nativeRow(array $row): void
    {
        foreach ($row as $key => $value) if (!is_string($key) || ($value !== null && !is_string($value) && !is_int($value))) self::unavailable();
    }
    private static function now(): int { return self::integer(time(), 1, self::UINT_MAX); }

    /** Matches existing native read/ORM writer ownership without opening or joining a transaction. */
    private function gate(ConnectionInterface $connection, PDO $pdo, bool $write): void
    {
        if (PHP_INT_SIZE < 8 || !$connection instanceof Mysql || $connection->getPdo() !== $pdo
            || $connection->transactionDepth() !== ($write ? 1 : 0) || $connection->getConfig('deploy') !== 0
            || $connection->getConfig('rw_separate') !== false || !$pdo->inTransaction()
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || !in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), [false, 0], true)) self::unavailable();
        $this->table($connection, 'marketplace_aftersale_file');
    }
    private function rows(ConnectionInterface $connection, PDO $pdo, bool $write, string $sql, array $parameters): array
    {
        $this->gate($connection, $pdo, $write); $statement = $this->statement($pdo, $sql, $parameters);
        try {
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || !array_is_list($rows)) self::unavailable();
            foreach ($rows as $row) { if (!is_array($row)) self::unavailable(); self::nativeRow($row); }
        } catch (Throwable $error) { self::unavailable(); }
        finally { if (!$statement->closeCursor()) self::unavailable(); }
        $this->gate($connection, $pdo, $write);
        return $rows;
    }
    private function write(ConnectionInterface $connection, PDO $pdo, string $sql, array $parameters): int
    {
        $this->gate($connection, $pdo, true); $statement = $this->statement($pdo, $sql, $parameters);
        try { $count = $statement->rowCount(); }
        finally { if (!$statement->closeCursor()) self::unavailable(); }
        $this->gate($connection, $pdo, true);
        return $count;
    }
    private function statement(PDO $pdo, string $sql, array $parameters): PDOStatement
    {
        if (!array_is_list($parameters)) self::unavailable();
        try {
            $statement = $pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) self::unavailable();
            foreach ($parameters as $offset => $value) {
                if ($value !== null && !is_string($value) && !is_int($value)) self::unavailable();
                $type = $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
                if (!$statement->bindValue($offset + 1, $value, $type)) self::unavailable();
            }
            if (!$statement->execute()) self::unavailable();
            return $statement;
        } catch (Throwable $error) { self::unavailable(); }
    }
    private function table(ConnectionInterface $connection, string $name): string
    {
        if (!in_array($name, ['marketplace_aftersale_file', 'store_order', 'store_order_refund'], true)) self::unavailable();
        $prefix = $connection->getConfig('prefix');
        if (!is_string($prefix) || preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1) self::unavailable();
        return self::quote($prefix . $name);
    }
    private static function quote(string $name): string
    {
        if ($name === '' || strlen($name) > 64 || preg_match('/\A[A-Za-z0-9_]+\z/D', $name) !== 1) self::unavailable();
        return chr(96) . $name . chr(96);
    }
    private static function marks(int $count): string
    {
        if ($count < 1 || $count > 100) self::unavailable(); return implode(',', array_fill(0, $count, '?'));
    }
    private static function unavailable(): never { throw new AuthUnavailableException(); }
}
