<?php
declare(strict_types=1);

namespace app\services\marketplace\finance;

use app\services\marketplace\MerchantManagementInput as Input;
use app\services\marketplace\payment\MarketplaceRefundEvidence as Facts;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use PDOException;
use think\exception\HttpException;

/** Append-only internal follow-up. Financial evidence remains in the original reconciliation. */
final class FinanceBillNoteStore
{
    public const TABLE = 'marketplace_finance_bill_note';

    public function __construct(private PDO $pdo, private string $prefix)
    {
        if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1 || strlen($prefix . self::TABLE) > 64
            || !$pdo->inTransaction()) throw new AuthUnavailableException();
    }

    public static function tables(): array
    {
        return [self::TABLE => ['columns' => [
            'id' => ['bigint unsigned', true], 'version' => ['tinyint unsigned'],
            'bill_id' => ['bigint unsigned'], 'result_id' => ['bigint unsigned'], 'row_number' => ['smallint unsigned'],
            'row_sha' => ['binary(32)'], 'note' => ['varchar(1000)', 'utf8mb4'],
            'request_key' => ['varchar(128)', 'ascii'], 'body_sha' => ['binary(32)'],
            'created_by' => ['int unsigned'], 'created_at' => ['int unsigned'],
        ], 'indexes' => [
            'PRIMARY' => [true, ['id']], 'uq_finance_note_request' => [true, ['created_by','request_key']],
            'idx_finance_note_result' => [false, ['bill_id','result_id','id']],
        ]]];
    }

    public static function input(array $body): array
    {
        Input::body($body, ['bill_id','result_id','row_number','note','request_key'], ['bill_id','result_id','row_number','note','request_key']);
        $key = $body['request_key'];
        $note = Input::text($body['note'], 1000, true, true);
        if (!is_string($key) || preg_match('/\Abill-note:[a-f0-9]{32}\z/D', $key) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $note) === 1) {
            throw new HttpException(422, '核查说明或请求标识不正确');
        }
        return ['bill_id' => Facts::u64($body['bill_id']), 'result_id' => Facts::u64($body['result_id']),
            'row_number' => Input::integer($body['row_number'], 0, 2000), 'note' => $note, 'request_key' => $key];
    }

    public static function rowDigest(array $detail, int $number): string
    {
        $rows = $detail['comparison']['rows'];
        if ($number < 0 || $number > count($rows)) throw new HttpException(422, '所选对账行不存在，请重新读取');
        $selected = $number === 0 ? ['source_sha256' => $detail['source_sha256'], 'comparison' => $detail['comparison'],
            'source_issues' => $detail['source_issues']] : $rows[$number - 1];
        return hash('sha256', Facts::canonical(['bill_id' => $detail['id'], 'result_id' => $detail['result_id'],
            'row_number' => $number, 'selected' => $selected]), true);
    }

    public function listing(array $detail, ?string $before, int $limit): array
    {
        $sql = 'SELECT * FROM ' . $this->table() . ' WHERE bill_id=? AND result_id=?';
        $args = [$detail['id'], $detail['result_id']];
        if ($before !== null) { $sql .= ' AND id<?'; $args[] = $before; }
        $rows = $this->rows($sql . ' ORDER BY id DESC LIMIT ' . ($limit + 1), $args);
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);
        $list = array_map(fn(array $row): array => $this->record($row, $detail), $rows);
        return ['list' => $list, 'next_before_id' => $more ? $list[count($list) - 1]['id'] : null];
    }

    public function replay(array $input, array $detail, int $actor): ?array
    {
        $rows = $this->rows('SELECT * FROM ' . $this->table() . ' WHERE created_by=? AND request_key=?', [$actor, $input['request_key']]);
        if ($rows === []) return null;
        $row = $rows[0];
        if ((string)$row['bill_id'] !== $input['bill_id'] || (string)$row['result_id'] !== $input['result_id']
            || (int)$row['row_number'] !== $input['row_number'] || $row['note'] !== $input['note']) {
            throw new HttpException(422, '该请求已对应另一条处理记录，请读取原记录');
        }
        return $this->record($row, $detail);
    }

    public function append(array $input, array $detail, int $actor): array
    {
        $rows = $this->rows('SELECT id FROM `' . $this->prefix . FinanceBillStore::BILL . '` WHERE id=? FOR UPDATE', [$input['bill_id']]);
        if (count($rows) !== 1) throw new HttpException(404, '账单不存在');
        $current = (new FinanceBillStore($this->pdo, $this->prefix))->detail($input['bill_id'], $input['result_id']);
        $rowSha = self::rowDigest($detail, $input['row_number']);
        if (!hash_equals($rowSha, self::rowDigest($current, $input['row_number']))) throw new AuthUnavailableException();
        $replay = $this->replay($input, $current, $actor);
        if ($replay !== null) return ['record' => $replay, 'reused_note' => true];

        $statement = $this->pdo->prepare('INSERT INTO ' . $this->table()
            . ' (version,bill_id,result_id,row_number,row_sha,note,request_key,body_sha,created_by,created_at) VALUES (1,?,?,?,?,?,?,?,?,?)');
        try {
            $statement->execute([$input['bill_id'], $input['result_id'], $input['row_number'], $rowSha, $input['note'],
                $input['request_key'], self::bodyDigest($input), $actor, time()]);
            if ($statement->rowCount() !== 1) throw new AuthUnavailableException();
            $id = Facts::u64($this->pdo->lastInsertId());
        } catch (PDOException $error) {
            if (($error->errorInfo[0] ?? null) === '23000' && (string)($error->errorInfo[1] ?? null) === '1062') {
                throw new HttpException(422, '该请求已被使用，请读取原处理记录');
            }
            throw $error;
        } finally { $statement->closeCursor(); }
        $saved = $this->rows('SELECT * FROM ' . $this->table() . ' WHERE id=?', [$id]);
        if (count($saved) !== 1) throw new AuthUnavailableException();
        return ['record' => $this->record($saved[0], $current), 'reused_note' => false];
    }

    private function record(array $row, array $detail): array
    {
        $input = self::input(['bill_id' => $row['bill_id'], 'result_id' => $row['result_id'],
            'row_number' => (int)$row['row_number'], 'note' => $row['note'], 'request_key' => $row['request_key']]);
        if ((int)$row['version'] !== 1 || $input['bill_id'] !== $detail['id'] || $input['result_id'] !== $detail['result_id']
            || $input['note'] !== $row['note'] || !hash_equals($row['row_sha'], self::rowDigest($detail, $input['row_number']))
            || !hash_equals($row['body_sha'], self::bodyDigest($input))) throw new AuthUnavailableException();
        return ['id' => Facts::u64($row['id']), 'bill_id' => $input['bill_id'], 'result_id' => $input['result_id'],
            'row_number' => $input['row_number'], 'note' => $input['note'],
            'created_by' => Input::integer($row['created_by'], 1, 4294967295, true),
            'created_at' => Input::integer($row['created_at'], 1, 4294967295, true)];
    }

    private static function bodyDigest(array $input): string
    {
        unset($input['request_key']);
        return hash('sha256', Facts::canonical($input), true);
    }

    private function table(): string { return '`' . $this->prefix . self::TABLE . '`'; }

    private function rows(string $sql, array $args = []): array
    {
        if (!$this->pdo->inTransaction()) throw new AuthUnavailableException();
        $statement = $this->pdo->prepare($sql);
        try {
            if (!$statement->execute($args)) throw new AuthUnavailableException();
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } finally { $statement->closeCursor(); }
    }
}
