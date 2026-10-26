<?php
declare(strict_types=1);

namespace app\services\kefu;

use app\dao\marketplace\order\MarketplaceOrderFulfillmentDao as Facts;
use app\services\marketplace\order\MarketplaceOrderSnapshotProjection as Projection;
use app\services\marketplace\order\MarketplaceTradePolicy;
use crmeb\compat\think\orm\db\connector\Mysql;
use crmeb\exceptions\ApiException;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\workerman\chat\ChatIdentity;
use PDO;
use PDOStatement;
use Throwable;
use think\db\ConnectionInterface;
use think\facade\Db;

/** A saved customer message shares an order summary, never a customer/admin session. */
final class PlatformChatOrderCardServices
{
    public function __construct(private Facts $facts) {}

    /** The caller has just authenticated this connection and selected its real service room. */
    public function prepareSend(ChatIdentity $sender, int $serviceUid, string $orderNo): ?array
    {
        $message = ['id'=>0, 'uid'=>$sender->uid, 'to_uid'=>$serviceUid, 'is_tourist'=>0, 'msn_type'=>6, 'msn'=>$orderNo];
        $result = $this->read(fn($c,$p,$prefix) => $this->cards($c,$p,$prefix,[$message],$sender->kind,$sender->uid,true));
        if (!$result[0]['handled']) return null;
        if ($result[0]['card'] === null) throw new ApiException('此订单暂不能发送咨询摘要');
        return $this->images($result[0]['card']);
    }

    /** Reader arguments come only from the existing authenticated history callers, never request data. */
    public function projectHistory(array $selectedLogs, string $readerKind, int $actualReaderUid): array
    {
        $messages = [];
        foreach ($selectedLogs as $log) {
            if ((int)($log['msn_type'] ?? 0) === 6) $messages[] = $log;
        }
        $result = [];
        foreach (array_chunk($messages,100) as $batch) {
            $cards = $this->read(fn($c,$p,$prefix) => $this->cards($c,$p,$prefix,$batch,$readerKind,$actualReaderUid,false));
            foreach ($cards as $id=>$entry) {
                if ($entry['card'] !== null) $entry['card'] = $this->images($entry['card']);
                $result[$id] = $entry;
            }
        }
        return $result;
    }

    /** Root/items and participant records are read from the same owned, read-only snapshot. */
    private function cards(ConnectionInterface $c, PDO $p, string $prefix, array $messages, string $kind, int $reader, bool $sending): array
    {
        $locators = [];
        foreach ($messages as $message) {
            $locator = $message['msn'] ?? null;
            if (is_string($locator) && $locator !== '') $locators[] = $locator;
        }
        $locators = array_values(array_unique($locators));
        $roots = [];
        if ($locators) {
            $marks = implode(',',array_fill(0,count($locators),'?'));
            $rows = $this->rows($p,'SELECT * FROM `'.$prefix.'store_order` WHERE order_id IN ('.$marks.') OR `unique` IN ('.$marks.')',array_merge($locators,$locators));
            foreach ($rows as $root) {
                foreach (array_unique([$root['order_id'],$root['unique']]) as $key) {
                    if (!in_array($key,$locators,true)) continue;
                    if (isset($roots[$key]) && (string)$roots[$key]['id'] !== (string)$root['id']) self::unavailable();
                    $roots[$key] = $root;
                }
            }
        }
        $participants = [$reader];
        foreach ($messages as $message) $participants[] = (int)($message['to_uid'] ?? 0);
        $participants = array_values(array_unique(array_filter($participants,fn($uid) => $uid > 0)));
        $users = $services = [];
        if ($participants) {
            $marks = implode(',',array_fill(0,count($participants),'?'));
            foreach ($this->rows($p,'SELECT uid,status,is_del FROM `'.$prefix.'user` WHERE uid IN ('.$marks.')',$participants) as $user) $users[(int)$user['uid']] = $user;
            foreach ($this->rows($p,'SELECT id,uid,status FROM `'.$prefix.'store_service` WHERE uid IN ('.$marks.')',$participants) as $service) {
                $uid = (int)$service['uid'];
                if (isset($services[$uid])) self::unavailable();
                $services[$uid] = $service;
            }
        }
        $result = $authorized = $messageRoots = [];
        foreach ($messages as $message) {
            $id = Facts::integer($message['id'] ?? null,$sending?0:1,2147483647);
            $result[$id] = ['handled'=>true,'card'=>null];
            $root = $roots[$message['msn'] ?? ''] ?? null;
            // Missing/deleted V1 messages retain their log, and can never fall back to raw tidyOrder.
            if ($root === null) continue;
            // SELECT * supplies the complete real row, including installations predating this column.
            if (MarketplaceTradePolicy::savedOrderIsLegacy($root)) {
                $result[$id]['handled'] = false;
                continue;
            }
            if (!in_array($root['marketplace_trade_version'],[1,'1'],true)) continue;
            if ((int)($message['is_tourist'] ?? -1) !== 0) continue;
            $sender = Facts::integer($message['uid'] ?? null,1,4294967295);
            $recipient = Facts::integer($message['to_uid'] ?? null,1,4294967295);
            if ($sender === $recipient
                || (string)$root['order_id'] !== $message['msn'] || (string)$root['uid'] !== (string)$sender
                || (string)$root['pid'] !== '0' || (string)$root['is_del'] !== '0' || (string)$root['is_system_del'] !== '0'
                || !isset($services[$recipient]) || !$this->activeUser($users[$reader] ?? null)) continue;
            if ($sending) {
                if ($kind !== 'api' || $reader !== $sender || (int)$services[$recipient]['status'] !== 1
                    || !$this->activeUser($users[$recipient] ?? null)) continue;
            } elseif ($kind === 'kefu') {
                if ($reader !== $recipient || (int)$services[$recipient]['status'] !== 1) continue;
            } elseif ($kind === 'api') {
                // Preserve the original v1 UserAuth service-history reader without changing its identity.
                if ($reader !== $sender && ($reader !== $recipient || (int)$services[$recipient]['status'] !== 1)) continue;
            } else continue;
            $rootId = Facts::integer($root['id'],1,2147483647);
            $authorized[$rootId] = $root;
            $messageRoots[$id] = $rootId;
        }
        if ($authorized) {
            $items = $this->facts->readOriginalRootItemsForRoots($c,$p,array_values($authorized));
            $projected = [];
            foreach ($authorized as $rootId=>$root) $projected[$rootId] = Projection::platformChatOrderCard($root,$items[$rootId]);
            foreach ($messageRoots as $id=>$rootId) $result[$id]['card'] = $projected[$rootId];
        }
        return $result;
    }

    private function activeUser(?array $user): bool
    {
        return $user !== null && (string)$user['status'] === '1' && (string)$user['is_del'] === '0';
    }

    private function images(array $card): array
    {
        foreach ($card['cartInfo'] as &$cart) $cart['productInfo']['image'] = set_file_url($cart['productInfo']['image']);
        unset($cart);
        return $card;
    }

    private function read(callable $work): array
    {
        $c = Db::connect();
        if (!$c instanceof Mysql || $c->transactionDepth() !== 0) self::unavailable();
        $prefix = $c->getConfig('prefix');
        if (!is_string($prefix) || preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix) !== 1) self::unavailable();
        $p = $c->getPdo();
        if (!$p instanceof PDO) { $c->query('SELECT 1',[],true); $p = $c->getPdo(); }
        if (!$p instanceof PDO) self::unavailable();
        $this->connection($c,$p,$prefix,false);
        $set = $began = $committing = false;
        try {
            $set = true;
            if ($p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY') === false) self::unavailable();
            $this->connection($c,$p,$prefix,false);
            if ($p->beginTransaction() !== true) self::unavailable();
            $began = true;
            $this->connection($c,$p,$prefix,true);
            $result = $work($c,$p,$prefix);
            $this->connection($c,$p,$prefix,true);
            $committing = true;
            if ($p->commit() !== true) self::unavailable();
            $this->connection($c,$p,$prefix,false);
            return $result;
        } catch (Throwable $error) {
            $clean = false;
            if ($began && !$committing) {
                try {
                    if ($p->inTransaction() && $p->rollBack() === true) {
                        $this->connection($c,$p,$prefix,false); $clean = true;
                    }
                } catch (Throwable $ignored) {}
            }
            if ($set && !$clean) { try { $c->close(); } catch (Throwable $ignored) {} }
            throw $error;
        }
    }

    private function connection(ConnectionInterface $c, PDO $p, string $prefix, bool $transaction): void
    {
        if (PHP_INT_SIZE < 8 || !$c instanceof Mysql || Db::connect() !== $c || $c->getPdo() !== $p
            || $c->transactionDepth() !== 0 || $c->getConfig('prefix') !== $prefix || $c->getConfig('deploy') !== 0
            || $c->getConfig('rw_separate') !== false || $p->inTransaction() !== $transaction
            || $p->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $p->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || !in_array($p->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true)) self::unavailable();
    }

    private function rows(PDO $p, string $sql, array $parameters): array
    {
        $statement = $p->prepare($sql);
        if (!$statement instanceof PDOStatement) self::unavailable();
        foreach ($parameters as $index=>$value) {
            if (!is_int($value) && !is_string($value)) self::unavailable();
            if (!$statement->bindValue($index+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR)) self::unavailable();
        }
        if (!$statement->execute()) self::unavailable();
        try { $rows = $statement->fetchAll(PDO::FETCH_ASSOC); }
        finally { if (!$statement->closeCursor()) self::unavailable(); }
        if (!is_array($rows) || !array_is_list($rows)) self::unavailable();
        return $rows;
    }

    private static function unavailable(): never { throw new AuthUnavailableException(); }
}
