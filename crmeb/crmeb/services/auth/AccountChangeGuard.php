<?php
declare(strict_types=1);

namespace crmeb\services\auth;

use crmeb\exceptions\AdminException;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthException;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Log;

/** Own the real outer database commit before completing account changes. */
class AccountChangeGuard
{
    /** Exists only while this invocation's managed database callback runs. */
    private static ?array $managedContext = null;

    public static function run(string $app, string $id, callable $dbChange): mixed
    {
        return self::runMany($app, [$id], $dbChange);
    }

    public static function runMany(string $app, array $ids, callable $dbChange): mixed
    {
        $phase = 'input';
        $count = count($ids);
        try {
            if (!in_array($app, ['adminapi', 'outapi', 'merchantapi'], true)) {
                throw new AuthUnavailableException();
            }
            $ids = self::normalizeIds($ids);
            $count = count($ids);
            $phase = 'connection';
            $connection = Db::connect();
            $pdo = $connection->connect();
            if (!$pdo instanceof PDO) {
                throw new AuthUnavailableException();
            }

            $context = self::$managedContext;
            if ($context !== null) {
                $phase = 'nested_context';
                if ($context['app'] !== $app || $context['connection'] !== $connection
                    || $context['pdo'] !== $pdo || !$pdo->inTransaction()
                    || array_diff($ids, $context['ids']) !== []) {
                    throw new AuthUnavailableException();
                }
                // Only a pre-protected subset can write inside this outer
                // transaction. Its original owner will complete after commit.
                $phase = 'database';
                return $dbChange();
            }
            if ($pdo->inTransaction()) {
                // An unmanaged outer transaction has no reliable commit hook.
                throw new AuthUnavailableException();
            }

            $phase = 'protect';
            $store = $ids === [] ? null : AuthSessionStore::fromConfig();
            $owners = [];
            foreach ($ids as $id) {
                $owners[$id] = $store->beginChange($app, $id);
            }

            $phase = 'database';
            $result = $connection->transaction(static function () use ($app, $ids, $connection, $pdo, $dbChange): mixed {
                if (self::$managedContext !== null || !$pdo->inTransaction()) {
                    throw new AuthUnavailableException();
                }
                self::$managedContext = ['app' => $app, 'ids' => $ids,
                    'connection' => $connection, 'pdo' => $pdo];
                try {
                    // A valid void result is permitted; business writers must
                    // throw on their own actual save failures instead of false.
                    return $dbChange();
                } finally {
                    self::$managedContext = null;
                }
            });

            $phase = 'commit_observation';
            if (Db::connect() !== $connection || $connection->getPdo() !== $pdo
                || $pdo->inTransaction()) {
                throw new AuthUnavailableException();
            }
            $phase = 'complete';
            foreach ($owners as $id => $owner) {
                $store->completeChange($app, (string)$id, $owner);
            }
            return $result;
        } catch (\Throwable $error) {
            if ($phase === 'database' && ($error instanceof AdminException
                || $error instanceof ApiException || $error instanceof ValidateException
                || ($error instanceof AuthException && !$error instanceof AuthUnavailableException))) {
                // Preserve controlled business validation and its original
                // response, while the normal transaction performs its rollback.
                throw $error;
            }
            self::recordUnavailable($app, $phase, $count);
            // Never return a success, retry a write, unblock in finally, or
            // preserve private driver messages / previous exceptions here.
            throw new AuthUnavailableException();
        }
    }

    private static function normalizeIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) {
                $id = (string)$id;
            }
            if (!is_string($id) || preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1) {
                throw new AuthUnavailableException();
            }
            $normalized[] = $id;
        }
        $normalized = array_values(array_unique($normalized, SORT_STRING));
        sort($normalized, SORT_STRING);
        return $normalized;
    }

    private static function recordUnavailable(string $app, string $phase, int $count): void
    {
        // Classification/counts only: no subject, owner, credential, SQL or
        // underlying exception text enters either log sink.
        $record = json_encode(['event' => 'auth_account_change_unavailable',
            'app' => in_array($app, ['adminapi', 'outapi', 'merchantapi'], true) ? $app : 'invalid',
            'phase' => $phase, 'subject_count' => $count], JSON_THROW_ON_ERROR);
        try {
            Log::record($record, 'warning', [], false);
        } catch (\Throwable $ignored) {
            error_log($record);
        }
    }
}
