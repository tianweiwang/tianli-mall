<?php
declare(strict_types=1);

namespace app\services\marketplace\finance;

use app\services\marketplace\MerchantManagementInput as Input;
use app\services\marketplace\payment\MarketplaceRefundEvidence as Facts;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\exception\HttpException;

/** Immutable test estimates, scoped to the authenticated shop when read by a merchant. */
final class FinanceStatementStore
{
    public const TABLE = 'marketplace_finance_statement';
    public const AMOUNTS = ['sales_amount','refund_amount','channel_fee_amount','platform_fee_amount',
        'platform_fee_reversal','platform_fee_net','merchant_receivable','settled_amount'];
    private const HEADER = 'id,bill_id,result_id,rule_revision,mer_id,date_from,date_to,status,created_at';
    private const FEE_FIELDS = ['platform_fee_enabled','platform_fee_basis','platform_fee_rate',
        'platform_fee_payer','channel_fee_payer','refund_platform_fee'];

    public function __construct(private PDO $pdo, private string $prefix)
    {
        if (preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) !== 1 || strlen($prefix . self::TABLE) > 64
            || !$pdo->inTransaction()) throw new AuthUnavailableException();
    }

    public static function tables(): array
    {
        $id = ['bigint unsigned'];
        $number = ['int unsigned'];
        return [self::TABLE => ['columns' => [
            'id' => ['bigint unsigned', true], 'version' => ['tinyint unsigned'],
            'bill_id' => $id, 'result_id' => $id, 'rule_revision' => $number, 'mer_id' => $number,
            'date_from' => ['char(10)', 'ascii'], 'date_to' => ['char(10)', 'ascii'], 'status' => ['varchar(24)', 'ascii'],
            'report' => ['json'], 'report_sha' => ['binary(32)'], 'created_by' => $number, 'created_at' => $number,
        ], 'indexes' => [
            'PRIMARY' => [true, ['id']], 'uq_finance_statement_inputs' => [true, ['bill_id','result_id','rule_revision']],
            'idx_finance_statement_shop' => [false, ['mer_id','id']], 'idx_finance_statement_bill' => [false, ['bill_id','id']],
        ]]];
    }

    public function listing(?int $merchant, ?string $bill, ?string $before, int $limit): array
    {
        $where = []; $args = [];
        foreach (['mer_id' => $merchant, 'bill_id' => $bill, 'id' => $before] as $column => $value) {
            if ($value === null) continue;
            $where[] = $column . ($column === 'id' ? '<?' : '=?');
            $args[] = $value;
        }
        $sql = 'SELECT ' . self::HEADER . ",JSON_EXTRACT(report,'$.projection.totals') AS totals FROM " . $this->table();
        if ($where !== []) $sql .= ' WHERE ' . implode(' AND ', $where);
        $rows = $this->rows($sql . ' ORDER BY id DESC LIMIT ' . ($limit + 1), $args);
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);
        $list = array_map(fn(array $row): array => $this->header($row) + ['totals' => self::amounts(self::json($row['totals']))], $rows);
        return ['list' => $list, 'next_before_id' => $more ? $list[count($list) - 1]['id'] : null];
    }

    public function detail(string $id, ?int $merchant): array
    {
        $sql = 'SELECT * FROM ' . $this->table() . ' WHERE id=?';
        $args = [$id];
        if ($merchant !== null) { $sql .= ' AND mer_id=?'; $args[] = $merchant; }
        $rows = $this->rows($sql, $args);
        if (count($rows) !== 1) throw new HttpException(404, '结算试算单不存在');
        $row = $rows[0];
        $header = $this->header($row);
        $report = self::json($row['report']);
        if ((int)$row['version'] !== 1 || !hash_equals($row['report_sha'], self::digest($header, (int)$row['created_by'], $report))) {
            throw new AuthUnavailableException();
        }
        $projection = $report['projection'];
        if ($projection['status'] !== $header['status'] || !array_is_list($projection['orders']) || count($projection['orders']) > 1000
            || !array_is_list($projection['reasons'])) throw new AuthUnavailableException();
        $projection['totals'] = self::amounts($projection['totals']);
        foreach ($projection['orders'] as &$order) $order['amounts'] = self::amounts($order['amounts']);
        unset($order);
        return $header + $projection + ['fee_rule' => $report['fee_rule'], 'source_sha256' => $report['source_sha256'],
            'rule_sha256' => $report['rule_sha256']];
    }

    public function find(string $bill, string $result, int $revision, bool $lock = false): ?string
    {
        $rows = $this->rows('SELECT id FROM ' . $this->table() . ' WHERE bill_id=? AND result_id=? AND rule_revision=?'
            . ($lock ? ' FOR UPDATE' : ''), [$bill, $result, $revision]);
        return $rows === [] ? null : Facts::u64($rows[0]['id']);
    }

    public function save(array $header, array $report, int $actor): array
    {
        // One real parent lock serializes creations for this immutable bill, including identical inputs.
        $rows = $this->rows('SELECT id,mer_id,date_from,date_to FROM `' . $this->prefix . FinanceBillStore::BILL . '` WHERE id=? FOR UPDATE', [$header['bill_id']]);
        if (count($rows) !== 1 || (int)$rows[0]['mer_id'] !== $header['mer_id'] || $rows[0]['date_from'] !== $header['date_from']
            || $rows[0]['date_to'] !== $header['date_to']) throw new AuthUnavailableException();
        $existing = $this->find($header['bill_id'], $header['result_id'], $header['rule_revision'], true);
        if ($existing !== null) return ['record' => $this->detail($existing, null), 'reused_statement' => true];
        $sql = 'INSERT INTO ' . $this->table() . ' (version,bill_id,result_id,rule_revision,mer_id,date_from,date_to,status,report,report_sha,created_by,created_at)'
            . ' VALUES (1,?,?,?,?,?,?,?,?,?,?,?)';
        $statement = $this->pdo->prepare($sql);
        try {
            $statement->execute([$header['bill_id'], $header['result_id'], $header['rule_revision'], $header['mer_id'],
                $header['date_from'], $header['date_to'], $header['status'], Facts::canonical($report), self::digest($header, $actor, $report),
                $actor, $header['created_at']]);
            if ($statement->rowCount() !== 1) throw new AuthUnavailableException();
            $id = Facts::u64($this->pdo->lastInsertId());
        } finally { $statement->closeCursor(); }
        return ['record' => $this->detail($id, null), 'reused_statement' => false];
    }

    public function versions(): array
    {
        $rows = $this->rows('SELECT revision,created_at FROM `' . $this->prefix . 'marketplace_finance_settings_history` ORDER BY revision DESC LIMIT 20');
        $versions = array_map(static fn(array $row): array => ['revision' => Input::integer($row['revision'], 1, 4294967295, true),
            'created_at' => Input::integer($row['created_at'], 1, 4294967295, true)], $rows);
        $versions[] = ['revision' => 0, 'created_at' => 0];
        return $versions;
    }

    public function draft(int $revision): array
    {
        if ($revision === 0) return FinanceSettingsInput::emptyDraft();
        $rows = $this->rows('SELECT payload FROM `' . $this->prefix . 'marketplace_finance_settings_history` WHERE revision=?', [$revision]);
        if (count($rows) !== 1) throw new HttpException(422, '所选资金规则版本不存在');
        $saved = self::json($rows[0]['payload']);
        $normalized = FinanceSettingsInput::draft($saved);
        if (Facts::canonical($normalized) !== Facts::canonical($saved)) throw new AuthUnavailableException();
        return $normalized;
    }

    public static function feeRule(array $draft): array { return array_intersect_key($draft, array_flip(self::FEE_FIELDS)); }

    private function header(array $row): array
    {
        $header = [];
        foreach (['id','bill_id','result_id'] as $key) $header[$key] = Facts::u64($row[$key]);
        foreach (['rule_revision','mer_id','created_at'] as $key) $header[$key] = Input::integer($row[$key], $key === 'rule_revision' ? 0 : 1, 4294967295, true);
        foreach (['date_from','date_to'] as $key) $header[$key] = $row[$key];
        if (!in_array($row['status'], ['ESTIMATED','PENDING_REVIEW'], true)) throw new AuthUnavailableException();
        $header['status'] = $row['status'];
        return $header;
    }

    private static function amounts(array $values): array
    {
        if (count($values) !== count(self::AMOUNTS) || array_diff(self::AMOUNTS, array_keys($values)) !== []
            || $values['settled_amount'] !== null) throw new AuthUnavailableException();
        foreach ($values as $value) {
            if ($value !== null && (!is_string($value) || preg_match('/\A-?(?:0|[1-9][0-9]{0,13})\.[0-9]{2}\z/D', $value) !== 1)) {
                throw new AuthUnavailableException();
            }
        }
        return $values;
    }

    private static function digest(array $header, int $actor, array $report): string
    {
        unset($header['id']);
        return hash('sha256', Facts::canonical(['header' => $header, 'actor' => $actor, 'report' => $report]), true);
    }

    private static function json(string $value): array
    {
        $data = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new AuthUnavailableException();
        return $data;
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
