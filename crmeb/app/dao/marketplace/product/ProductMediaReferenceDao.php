<?php
declare(strict_types=1);
namespace app\dao\marketplace\product;

use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\facade\Db;

/** Read the complete attachment on the product writer's existing transaction. */
final class ProductMediaReferenceDao
{
    /**
     * Owner and aliases are produced by ProductMediaReferenceServices, never a request body.
     * Content/type qualification remains the service's responsibility after this lock.
     */
    public function lockNative14(PDO $pdo, int $actualOwner, array $canonicalCandidates): array
    {
        $connection = Db::connect();
        $this->assertTransaction($connection, $pdo);
        if ($actualOwner < 1 || $actualOwner > 4294967295
            || !array_is_list($canonicalCandidates) || $canonicalCandidates === []
            || count($canonicalCandidates) > 8) self::unavailable();
        $seen = [];
        foreach ($canonicalCandidates as $candidate) {
            if (!is_string($candidate) || $candidate === '' || strlen($candidate) > 2048
                || str_contains($candidate, "\0") || preg_match('//u', $candidate) !== 1
                || isset($seen[$candidate])) self::unavailable();
            $seen[$candidate] = true;
        }
        $prefix = $connection->getConfig('prefix');
        if (!is_string($prefix) || preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1
            || strlen($prefix . 'system_attachment') > 64) self::unavailable();
        $quote = chr(96);
        $table = $quote . $prefix . 'system_attachment' . $quote;
        $placeholders = implode(',', array_fill(0, count($canonicalCandidates), '?'));
        $sql = 'SELECT * FROM ' . $table . ' WHERE mer_id=? AND (BINARY att_dir IN ('
            . $placeholders . ') OR BINARY satt_dir IN (' . $placeholders
            . ')) ORDER BY att_id LIMIT 2 FOR UPDATE';
        $parameters = array_merge([$actualOwner], $canonicalCandidates, $canonicalCandidates);
        $statement = $pdo->prepare($sql);
        if ($statement === false) self::unavailable();
        try {
            if ($statement->execute($parameters) !== true) self::unavailable();
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > 2) self::unavailable();
        } finally {
            if ($statement->closeCursor() !== true) self::unavailable();
        }
        $this->assertTransaction($connection, $pdo);
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== PlatformProductMediaDao::ATTACHMENT_FIELDS
                || self::integer($row['att_id'], 1, 2147483647) < 1
                || self::integer($row['mer_id'], 1, 4294967295) !== $actualOwner) self::unavailable();
            // Retain the complete native row. The typed service must qualify module,
            // media type, MIME, size, aliases and the actual original/thumbnail bytes.
        }
        return $rows;
    }

    private function assertTransaction(object $connection, PDO $pdo): void
    {
        if (Db::connect() !== $connection || $connection->getPdo() !== $pdo || !$pdo->inTransaction()
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || !in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES), [false, 0], true)) self::unavailable();
    }

    private static function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
            && strlen($value) <= strlen((string)$maximum)
            && (strlen($value) < strlen((string)$maximum) || strcmp($value, (string)$maximum) <= 0)) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) self::unavailable();
        return $value;
    }

    private static function unavailable(): never
    {
        throw new AuthUnavailableException();
    }
}
