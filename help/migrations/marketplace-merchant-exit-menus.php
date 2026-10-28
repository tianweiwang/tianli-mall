<?php
declare(strict_types=1);

require_once __DIR__ . '/marketplace-ms2-task7-submit-menu.php';

/** One additive permission leaf; no role grants, DDL or application bootstrap. */
final class MarketplaceMerchantExitMenuLeaf
{
    private string $marker;
    private string $database;
    private string $table;
    private MarketplaceMs2Task7SubmitMenu $structure;

    public function __construct(private PDO $pdo, string $prefix, private string $permission)
    {
        if (!in_array($permission, ['marketplace-merchants-exit-precheck', 'marketplace-merchants-close'], true)) throw new RuntimeException('Permission unavailable');
        $this->marker = $permission . '-v1';
        $this->structure = new MarketplaceMs2Task7SubmitMenu($pdo, $prefix);
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || !in_array($pdo->getAttribute(PDO::ATTR_PERSISTENT), [false, 0], true)
            || !is_string($database) || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database) !== 1
            || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $prefix . 'system_menus') !== 1) {
            throw new RuntimeException('Configuration unavailable');
        }
        $this->database = $database;
        $this->table = $prefix . 'system_menus';
    }

    private function menu(): array
    {
        [$label,$method,$route] = $this->permission === 'marketplace-merchants-exit-precheck'
            ? ['商家退出责任预检','GET','exit-precheck'] : ['关闭商家','PUT','close'];
        return ['pid'=>0, 'icon'=>'', 'menu_name'=>$label, 'module'=>'', 'controller'=>'',
            'action'=>'', 'api_url'=>'marketplace/merchants/<id>/' . $route, 'methods'=>$method,
            'params'=>'[]', 'sort'=>1, 'is_show'=>1, 'is_show_path'=>1, 'access'=>1, 'menu_path'=>'',
            'path'=>'', 'auth_type'=>2, 'header'=>'', 'is_header'=>0, 'unique_auth'=>$this->permission,
            'is_del'=>0, 'mark'=>$this->marker];
    }

    public function check(): array { return $this->inspect('check'); }

    private function inspect(string $mode, bool $lock = false): array
    {
        // Reuse the frozen exact table/schema checker, without applying its unrelated permission.
        $structure = $this->structure->check();
        if (!in_array($structure['state'] ?? null, ['applicable', 'completed'], true)
            || ($structure['issues'] ?? null) !== []) {
            return $this->receipt($mode, 'incompatible', null, false, ['menu_structure_or_existing_leaf']);
        }
        if ($lock && !$this->pdo->inTransaction()) throw new RuntimeException('Transaction unavailable');
        $expected = $this->menu(); $matches = []; $issues = []; $suffix = $lock ? ' FOR UPDATE' : '';
        $rows = $this->rows('SELECT * FROM `' . $this->table . '` WHERE auth_type=2 ORDER BY id' . $suffix, []);
        // Retain every DB-collation match, so differently cased/conflicting keys cannot be bypassed.
        foreach ($this->rows('SELECT * FROM `' . $this->table . '` WHERE unique_auth=? ORDER BY id' . $suffix,
            [$this->permission]) as $row) $matches[(string)$row['id']] = $row;
        foreach ($rows as $row) {
            $sameRoute = strtoupper(trim((string)$row['methods'])) === $expected['methods']
                && strtolower(str_replace(' ', '', trim((string)$row['api_url']))) === $expected['api_url'];
            if ($sameRoute) $matches[(string)$row['id']] = $row;
        }
        foreach ($this->rows('SELECT * FROM `' . $this->table . '` WHERE mark=? ORDER BY id' . $suffix,
            [$this->marker]) as $row) if ($row['unique_auth'] !== $this->permission) $issues[] = 'marker_conflict';
        $id = null;
        if (count($matches) > 1) $issues[] = 'permission_or_route_collision';
        elseif ($matches !== []) {
            $row = array_values($matches)[0]; $native = (string)$row['id'];
            $same = count($row) === 22 && preg_match('/\A[1-9][0-9]{0,4}\z/D', $native) === 1 && (int)$native <= 65535;
            foreach ($expected as $key=>$value) {
                $same = $same && array_key_exists($key, $row) && $row[$key] !== null && (string)$row[$key] === (string)$value;
            }
            if ($same) $id = (int)$native;
            else $issues[] = 'menu_fields_conflict';
        }
        return $this->receipt($mode, $issues ? 'incompatible' : ($id === null ? 'applicable' : 'completed'),
            $id, false, array_values(array_unique($issues)));
    }

    public function apply(): array
    {
        if ($this->pdo->inTransaction()) return $this->receipt('apply', 'incompatible', null, false, ['active_transaction']);
        $lockName = 'merchant-exit:' . substr(hash('sha256', $this->database . "\0" . $this->table . "\0" . $this->marker), 0, 40);
        $locked = false; $ownedTransaction = false; $attempted = false; $committed = false;
        $rollback = null; $created = null; $phase = 'precheck'; $result = null;
        try {
            $before = $this->inspect('apply');
            if ($before['state'] === 'incompatible') return $before;
            $phase = 'named_lock';
            $owner = $this->rows('SELECT IS_USED_LOCK(?) AS owner, CONNECTION_ID() AS connection_id', [$lockName])[0];
            if ($owner['owner'] !== null && (string)$owner['owner'] === (string)$owner['connection_id']) {
                return $this->receipt('apply', 'incompatible', null, false, ['lock_already_owned']);
            }
            if ((string)$this->rows('SELECT GET_LOCK(?,10) AS acquired', [$lockName])[0]['acquired'] !== '1') {
                throw new RuntimeException('Lock unavailable');
            }
            $locked = true; $phase = 'begin_transaction';
            if (!$this->pdo->beginTransaction()) throw new RuntimeException('Transaction unavailable');
            $ownedTransaction = true; $phase = 'locked_recheck';
            $current = $this->inspect('apply', true);
            if ($current['state'] === 'incompatible') {
                if (!$this->pdo->rollBack()) throw new RuntimeException('Rollback unavailable');
                $rollback = true; $ownedTransaction = false;
                $result = $current;
            } else {
                $id = $current['menu_ids'][$this->permission] ?? null;
                if ($id === null) {
                    $phase = 'insert_leaf'; $leaf = $this->menu();
                    $columns = implode(',', array_map(static fn(string $key): string => '`' . $key . '`', array_keys($leaf)));
                    $statement = $this->pdo->prepare('INSERT INTO `' . $this->table . '` (' . $columns . ') VALUES ('
                        . implode(',', array_fill(0, count($leaf), '?')) . ')');
                    $attempted = true;
                    if (!$statement->execute(array_values($leaf)) || $statement->rowCount() !== 1) throw new RuntimeException('Insert unavailable');
                    $native = $this->pdo->lastInsertId();
                    if (!is_string($native) || preg_match('/\A[1-9][0-9]{0,4}\z/D', $native) !== 1 || (int)$native > 65535) {
                        throw new RuntimeException('Identity unavailable');
                    }
                    $id = $created = (int)$native;
                }
                $phase = 'transaction_readback';
                $after = $this->inspect('apply', true);
                if ($after['state'] !== 'completed' || $after['menu_ids'] !== [$this->permission=>$id]) throw new RuntimeException('Readback unavailable');
                $phase = 'commit';
                if (!$this->pdo->commit()) throw new RuntimeException('Commit unavailable');
                $committed = true; $ownedTransaction = false;
                if ($this->pdo->inTransaction()) throw new RuntimeException('Commit unavailable');
                $phase = 'committed_readback';
                $after = $this->inspect('apply');
                if ($after['state'] !== 'completed' || $after['menu_ids'] !== [$this->permission=>$id]) throw new RuntimeException('Readback unavailable');
                $result = $this->receipt('apply', 'completed', $id, $created !== null, []);
            }
        } catch (Throwable $error) {
            try {
                if ($ownedTransaction && $this->pdo->inTransaction()) $rollback = $this->pdo->rollBack();
            } catch (Throwable $ignored) {}
            $changed = $committed ? $created !== null : (!$attempted || $rollback === true ? false : null);
            $result = $this->receipt('apply', 'failed', $committed ? $created : null, $changed, [$phase . '_failed']);
        } finally {
            if ($locked) {
                try {
                    if ((string)$this->rows('SELECT RELEASE_LOCK(?) AS released', [$lockName])[0]['released'] !== '1') {
                        throw new RuntimeException('Release unavailable');
                    }
                } catch (Throwable $ignored) {
                    $changed = $committed ? $created !== null : (!$attempted || $rollback === true ? false : null);
                    $result = $this->receipt('apply', 'failed', $committed ? $created : null, $changed, ['lock_release_failed']);
                }
            }
        }
        if (!is_array($result)) throw new RuntimeException('Result unavailable');
        $result['write_attempted'] = $attempted;
        $result['commit_observed'] = $committed;
        $result['rollback_observed'] = $rollback;
        $result['created_menu_ids'] = $committed && $created !== null ? [$this->permission=>$created] : [];
        return $result;
    }

    private function rows(string $sql, array $arguments): array
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement->execute($arguments)) throw new RuntimeException('Inspection unavailable');
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function receipt(string $mode, string $state, ?int $id, ?bool $changed, array $issues): array
    {
        return ['mode'=>$mode, 'state'=>$state, 'changed'=>$changed, 'tables'=>[],
            'menu_ids'=>$id === null ? [] : [$this->permission=>$id], 'roles_modified'=>false,
            'issues'=>array_map(fn(string $kind): array => ['kind'=>$kind, 'path'=>$this->table], $issues)];
    }
}

/** Two independent additive leaves. Partial native outcomes remain visible; no role is granted. */
final class MarketplaceMerchantExitMenus
{
    public function __construct(private PDO $pdo,private string $prefix) {}
    public function check(): array { return $this->run(false); }
    public function apply(): array { return $this->run(true); }
    private function run(bool $apply): array
    {
        $ids=[];$receipts=[];$changed=false;$state='completed';
        foreach(['marketplace-merchants-exit-precheck','marketplace-merchants-close'] as $permission){
            $leaf=new MarketplaceMerchantExitMenuLeaf($this->pdo,$this->prefix,$permission);
            $receipt=$apply?$leaf->apply():$leaf->check();$receipts[$permission]=$receipt;
            $ids += $receipt['menu_ids'];
            $changed=$changed===null||$receipt['changed']===null?null:($changed||$receipt['changed']);
            if(in_array($receipt['state'],['failed','incompatible'],true)){$state=$receipt['state'];break;}
            if($receipt['state']!=='completed')$state='applicable';
        }
        return ['mode'=>$apply?'apply':'check','state'=>$state,'changed'=>$changed,'tables'=>[],
            'menu_ids'=>$ids,'roles_modified'=>false,'atomic_scope'=>'one_permission_leaf','leaves'=>$receipts,
            'issues'=>in_array($state,['failed','incompatible'],true)?[['kind'=>'permission_leaf_unavailable','path'=>'system_menus']]:[]];
    }
}

function marketplaceMerchantExitMenusMain(array $arguments): int
{
    if ($arguments === ['help'] || $arguments === ['--help']) {
        echo "Usage: php help/migrations/marketplace-merchant-exit-menus.php [check|apply] [--config-file=PATH]\nDefault check is read-only; apply inserts only only the two missing permission leaves and never grants roles.\n";
        return 0;
    }
    $mode = 'check'; $selected = false; $file = null;
    try {
        foreach ($arguments as $argument) {
            if (in_array($argument, ['check','apply','--check','--apply'], true) && !$selected) {
                $mode = ltrim($argument, '-'); $selected = true;
            } elseif (str_starts_with($argument, '--config-file=') && $file === null && strlen($argument) > 14) {
                $file = substr($argument, 14);
            } else throw new RuntimeException('Arguments unavailable');
        }
        $config = \Audit\Migration\marketplaceMs1Configuration($file);
        $pdo = new PDO('mysql:host=' . $config['hostname'] . ';port=' . $config['hostport'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
            $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_TIMEOUT=>3, PDO::ATTR_PERSISTENT=>false]);
        $migration = new MarketplaceMerchantExitMenus($pdo, $config['prefix']);
        $receipt = $mode === 'apply' ? $migration->apply() : $migration->check();
        echo json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        return $receipt['state'] === 'completed' ? 0 : 1;
    } catch (Throwable $error) {
        echo json_encode(['mode'=>$mode, 'state'=>'failed', 'changed'=>null, 'tables'=>[], 'menu_ids'=>[],
            'roles_modified'=>false, 'issues'=>[['kind'=>'configuration_or_inspection_failed', 'path'=>'migration']]], JSON_THROW_ON_ERROR) . "\n";
        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(marketplaceMerchantExitMenusMain(array_slice($argv, 1)));
}
