<?php
declare(strict_types=1);

namespace app\dao\marketplace;

use app\model\marketplace\PasswordSetup;
use app\services\marketplace\context\MerchantContext;
use crmeb\exceptions\AuthUnavailableException;
use InvalidArgumentException;
use LogicException;
use PDO;
use think\db\Query;
use think\Model;

/** A fixed-purpose capability read never accepts a client-supplied merchant selector. */
final class PasswordSetupDao
{
    private const FIELDS = ['id', 'mer_id', 'employee_id', 'expires_at', 'consumed_at', 'add_time'];

    public function findForSetup(string $tokenHash): ?array
    {
        return $this->read($this->lookup($tokenHash));
    }

    public function lockForSetup(string $tokenHash): ?array
    {
        $query = $this->lookup($tokenHash);
        self::assertTransaction($query);
        return $this->read($query->lock(true));
    }

    /** The caller must check affected rows = 1 and commit only with the password update. */
    public function consumeForSetup(int $setupId, int $merId, int $employeeId, int $now): int
    {
        if ($setupId <= 0 || $merId <= 0 || $employeeId <= 0 || $now <= 0 || $now > 4294967295) {
            throw new InvalidArgumentException('Invalid password setup identity');
        }
        $query = (new PasswordSetup())->db()->where('id', '=', $setupId)->where('mer_id', '=', $merId)
            ->where('employee_id', '=', $employeeId)->whereNull('consumed_at')->where('expires_at', '>', $now);
        self::assertTransaction($query);
        return $query->update(['consumed_at' => $now]);
    }

    public function createForEmployee(MerchantContext $ctx, int $employeeId, string $raw32Hash, int $now): int
    {
        if ($employeeId <= 0 || strlen($raw32Hash) !== 32 || $now <= 0 || $now > 4294967295 - 86400) {
            throw new InvalidArgumentException('Invalid password setup creation');
        }
        $query = (new PasswordSetup())->db();
        self::assertTransaction($query);
        $id = (int) $query->insertGetId([
            'mer_id' => $ctx->merId,
            'employee_id' => $employeeId,
            'token_hash' => $raw32Hash,
            'expires_at' => $now + 86400,
            'consumed_at' => null,
            'add_time' => $now,
        ]);
        if ($id <= 0) {
            throw new AuthUnavailableException();
        }
        return $id;
    }

    private function lookup(string $tokenHash): Query
    {
        // The service supplies the raw 32-byte SHA-256 of its canonical capability.
        if (strlen($tokenHash) !== 32) {
            throw new InvalidArgumentException('Invalid password setup digest');
        }
        return (new PasswordSetup())->db()->where('token_hash', '=', $tokenHash)->field(self::FIELDS);
    }

    private function read(Query $query): ?array
    {
        $row = $query->find();
        $data = $row instanceof Model ? $row->getData() : $row;
        return is_array($data) && $data !== [] ? $data : null;
    }

    private static function assertTransaction(Query $query): void
    {
        $pdo = $query->getConnection()->connect();
        if (!$pdo instanceof PDO || !$pdo->inTransaction()) {
            throw new LogicException('Password setup requires an active transaction');
        }
    }
}
