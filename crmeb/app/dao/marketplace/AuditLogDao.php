<?php
declare(strict_types=1);

namespace app\dao\marketplace;

use app\model\marketplace\AuditLog;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\facade\Db;
use Throwable;

/** A finite insert only; no inherited global read/update/delete entry points. */
final class AuditLogDao
{
    /**
     * The audit service supplies validated server metadata and a real merchant ID.
     * This method never opens or commits a transaction of its own.
     */
    public function insertValidated(
        int $merId,
        string $actorType,
        int $actorId,
        string $action,
        string $resourceType,
        string $resourceId,
        string $result,
        array $detail,
        int $now
    ): int {
        try {
            if ($merId <= 0 || $merId > 4294967295 || $actorId <= 0 || $actorId > 4294967295
                || $now <= 0 || $now > 4294967295) {
                throw new AuthUnavailableException();
            }

            $query = (new AuditLog())->db();
            $connection = $query->getConnection();
            $pdo = $connection->getPdo();
            if ($connection !== Db::connect() || !$pdo instanceof PDO || !$pdo->inTransaction()) {
                throw new AuthUnavailableException();
            }

            // The real Builder leaves a scalar JSON string unchanged. Encoding an
            // object here also preserves {} for the empty validated detail array.
            $detailJson = json_encode((object) $detail, JSON_THROW_ON_ERROR);
            $affected = $query->insert([
                'mer_id' => $merId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'result' => $result,
                'detail' => $detailJson,
                'add_time' => $now,
            ]);
            if ($affected !== 1) {
                throw new AuthUnavailableException();
            }

            $id = $query->getLastInsID();
            if ((!is_int($id) && !is_string($id))
                || preg_match('/\A[1-9][0-9]*\z/', (string) $id) !== 1
                || strlen((string) $id) > 10
                || (int) $id > 4294967295) {
                throw new AuthUnavailableException();
            }
            return (int) $id;
        } catch (Throwable $error) {
            // Preserve neither the driver message nor the previous exception.
            throw new AuthUnavailableException();
        }
    }
}
