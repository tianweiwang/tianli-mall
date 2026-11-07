<?php
declare(strict_types=1);
namespace app\services\marketplace\finance;

use app\services\marketplace\payment\MarketplaceRefundEvidence as Facts;
use crmeb\exceptions\AuthUnavailableException;
use PDO;
use think\exception\HttpException;

/** Immutable imported material and append-only snapshot results. Never posts to a payment or settlement ledger. */
final class FinanceBillStore
{
    public const BILL='marketplace_finance_bill';
    public const RESULT='marketplace_finance_reconciliation';
    public const MARKER='marketplace-finance-bills-v1';

    public function __construct(private PDO $pdo,private string $prefix)
    {
        if(preg_match('/\A[A-Za-z0-9_]*\z/D',$prefix)!==1||strlen($prefix.self::RESULT)>64
            ||$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$pdo->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            ||!in_array($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),[false,0],true))throw new AuthUnavailableException();
    }

    /** One finite structure description shared with the explicit migrator; application code never executes DDL. */
    public static function tables():array
    {
        $id=['bigint unsigned',true];$u=['int unsigned'];$json=['json'];$sha=['binary(32)'];
        return [self::BILL=>['columns'=>[
            'id'=>$id,'version'=>['tinyint unsigned'],'identity_digest'=>$sha,'anchor_attempt_id'=>['bigint unsigned'],
            'mer_id'=>$u,'account_snapshot'=>$json,'date_from'=>['char(10)','ascii'],'date_to'=>['char(10)','ascii'],
            'period_start'=>$u,'period_end'=>$u,'filename'=>['varchar(120)','utf8mb4'],'source_sha'=>$sha,
            'source_csv'=>['mediumblob'],'normalized_rows'=>$json,'created_by'=>$u,'created_at'=>$u],
            'indexes'=>['PRIMARY'=>[true,['id']],'uq_finance_bill_identity'=>[true,['identity_digest']],
                'idx_finance_bill_merchant'=>[false,['mer_id','id']]]],
            self::RESULT=>['columns'=>['id'=>$id,'bill_id'=>['bigint unsigned'],'version'=>['tinyint unsigned'],'request_key'=>['varchar(128)','ascii'],
                'source_sha'=>$sha,'local_rows'=>$json,'source_issues'=>$json,'comparison'=>$json,
                'matched_count'=>$u,'difference_count'=>$u,'issue_count'=>$u,'snapshot_at'=>$u,'created_by'=>$u,'created_at'=>$u],
                'indexes'=>['PRIMARY'=>[true,['id']],'uq_finance_reconcile_request'=>[true,['bill_id','request_key']],
                    'idx_finance_reconcile_bill'=>[false,['bill_id','id']]]]];
    }

    public function listing(?string $before,int $limit):array
    {
        $sql='SELECT b.id,b.mer_id,b.date_from,b.date_to,b.filename,b.created_at,JSON_LENGTH(b.normalized_rows) AS row_count,'
            .' r.id AS latest_result_id,r.matched_count,r.difference_count,r.issue_count,r.snapshot_at FROM '.$this->table(self::BILL).' b'
            .' LEFT JOIN '.$this->table(self::RESULT).' r ON r.id=(SELECT id FROM '.$this->table(self::RESULT).' WHERE bill_id=b.id ORDER BY id DESC LIMIT 1)';
        $args=[];if($before!==null){$sql.=' WHERE b.id<?';$args[]=$before;}
        $rows=$this->rows($sql.' ORDER BY b.id DESC LIMIT '.($limit+1),$args);$more=count($rows)>$limit;if($more)array_pop($rows);
        foreach($rows as &$row){
            $row['id']=Facts::u64($row['id']);$row['latest_result_id']=Facts::u64($row['latest_result_id']);
            foreach(['mer_id','created_at','snapshot_at','row_count','matched_count','difference_count','issue_count'] as $key)$row[$key]=(int)$row[$key];
            $row['status']=self::status($row['difference_count'],$row['issue_count']);
        }unset($row);
        return ['list'=>$rows,'next_before_id'=>$more?$rows[count($rows)-1]['id']:null];
    }

    /** Internal original material, including its exact CSV. Never return this whole object through a controller. */
    public function load(string $id):array
    {
        $rows=$this->rows('SELECT * FROM '.$this->table(self::BILL).' WHERE id=?',[Facts::u64($id)]);
        if(count($rows)!==1)throw new HttpException(404,'账单不存在');
        $row=$rows[0];if((string)$row['version']!=='1')throw new AuthUnavailableException();
        $row['id']=Facts::u64($row['id']);$row['anchor_attempt_id']=Facts::u64($row['anchor_attempt_id']);
        $row['account_snapshot']=Facts::account(self::json($row['account_snapshot']));
        $row['normalized_rows']=self::json($row['normalized_rows']);
        $parsed=FinanceBillReconciliation::parse($row['source_csv']);
        if(!hash_equals($row['source_sha'],hex2bin($parsed['file_sha256']))
            ||Facts::canonical($row['normalized_rows'])!==Facts::canonical($parsed['rows']))throw new AuthUnavailableException();
        $period=FinanceBillInput::period($row);
        if((string)$period['period_start']!==(string)$row['period_start']||(string)$period['period_end']!==(string)$row['period_end']
            ||(string)$row['account_snapshot']['mer_id']!==(string)$row['mer_id']
            ||!hash_equals($row['identity_digest'],self::identity($row['account_snapshot'],$period,$parsed['file_sha256'])))throw new AuthUnavailableException();
        return $row;
    }

    public function detail(string $id,?string $resultId=null):array
    {
        $bill=$this->load($id);
        $results=$this->rows('SELECT id,snapshot_at,created_at,created_by,matched_count,difference_count,issue_count FROM '
            .$this->table(self::RESULT).' WHERE bill_id=? ORDER BY id DESC LIMIT 20',[$id]);
        if($results===[])throw new AuthUnavailableException();
        foreach($results as &$item){
            $item['id']=Facts::u64($item['id']);
            foreach(['snapshot_at','created_at','created_by','matched_count','difference_count','issue_count'] as $key)$item[$key]=(int)$item[$key];
            $item['status']=self::status($item['difference_count'],$item['issue_count']);
        }unset($item);
        $savedRows=$this->rows('SELECT * FROM '.$this->table(self::RESULT).' WHERE id=? AND bill_id=?',[$resultId??$results[0]['id'],$id]);
        if(count($savedRows)!==1)throw new AuthUnavailableException();$saved=$savedRows[0];
        if((string)$saved['version']!=='1')throw new AuthUnavailableException();
        $local=self::json($saved['local_rows']);$issues=self::json($saved['source_issues']);$comparison=self::json($saved['comparison']);
        $fresh=FinanceBillReconciliation::reconcile($bill['normalized_rows'],$local);
        if(!hash_equals($saved['source_sha'],hash('sha256',Facts::canonical(['rows'=>$local,'issues'=>$issues]),true))
            ||Facts::canonical($fresh)!==Facts::canonical($comparison)
            ||$fresh['counts']['matched']!==(int)$saved['matched_count']||$fresh['counts']['differences']!==(int)$saved['difference_count']
            ||count($issues)!==(int)$saved['issue_count'])throw new AuthUnavailableException();
        $account=$bill['account_snapshot'];
        return ['id'=>$bill['id'],'filename'=>$bill['filename'],'mer_id'=>(int)$bill['mer_id'],
            'anchor_attempt_id'=>$bill['anchor_attempt_id'],'account'=>['channel'=>$account['channel'],'account_ref'=>$account['account_ref'],
                'version'=>$account['version'],'currency'=>$account['currency']],
            'date_from'=>$bill['date_from'],'date_to'=>$bill['date_to'],'created_by'=>(int)$bill['created_by'],
            'created_at'=>(int)$bill['created_at'],'file_sha256'=>bin2hex($bill['source_sha']),'row_count'=>count($bill['normalized_rows']),
            'result_id'=>Facts::u64($saved['id']),'status'=>self::status((int)$saved['difference_count'],(int)$saved['issue_count']),
            'snapshot_at'=>(int)$saved['snapshot_at'],'period_open'=>(int)$saved['snapshot_at']<(int)$bill['period_end'],
            'source_issues'=>$issues,'comparison'=>$comparison,'results'=>$results];
    }

    public function import(array $input,array $account,array $source,int $snapshotAt,int $actor):array
    {
        $digest=self::identity($account,$input,$input['parsed']['file_sha256']);
        $existing=$this->rows('SELECT id FROM '.$this->table(self::BILL).' WHERE identity_digest=? FOR UPDATE',[$digest]);
        if($existing!==[])return ['id'=>Facts::u64($existing[0]['id']),'reused_batch'=>true];
        $values=['version'=>1,'identity_digest'=>$digest,'anchor_attempt_id'=>$input['anchor_attempt_id'],
            'mer_id'=>$account['mer_id'],'account_snapshot'=>Facts::canonical($account),'date_from'=>$input['date_from'],
            'date_to'=>$input['date_to'],'period_start'=>$input['period_start'],'period_end'=>$input['period_end'],
            'filename'=>$input['filename'],'source_sha'=>hex2bin($input['parsed']['file_sha256']),'source_csv'=>$input['csv'],
            'normalized_rows'=>Facts::canonical($input['parsed']['rows']),'created_by'=>$actor,'created_at'=>time()];
        $id=$this->insert(self::BILL,$values);
        $this->append($this->load($id),$source,$snapshotAt,$actor,'import-v1');
        return ['id'=>$id,'reused_batch'=>false];
    }

    public function append(array $original,array $source,int $snapshotAt,int $actor,string $requestKey):array
    {
        $bill=$this->load($original['id']);
        if(!hash_equals($bill['identity_digest'],$original['identity_digest']))throw new AuthUnavailableException();
        $sha=hash('sha256',Facts::canonical(['rows'=>$source['rows'],'issues'=>$source['issues']]),true);
        if(!hash_equals(bin2hex($sha),$source['source_sha256']))throw new AuthUnavailableException();
        if(preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D',$requestKey)!==1)throw new HttpException(422,'对账请求标识不正确');
        $existing=$this->rows('SELECT id FROM '.$this->table(self::RESULT).' WHERE bill_id=? AND request_key=? FOR UPDATE',[$bill['id'],$requestKey]);
        if($existing!==[])return ['result_id'=>Facts::u64($existing[0]['id']),'reused_result'=>true];
        $comparison=FinanceBillReconciliation::reconcile($bill['normalized_rows'],$source['rows']);
        $id=$this->insert(self::RESULT,['bill_id'=>$bill['id'],'version'=>1,'request_key'=>$requestKey,'source_sha'=>$sha,
            'local_rows'=>Facts::canonical($source['rows']),'source_issues'=>Facts::canonical($source['issues']),
            'comparison'=>Facts::canonical($comparison),'matched_count'=>$comparison['counts']['matched'],
            'difference_count'=>$comparison['counts']['differences'],'issue_count'=>count($source['issues']),
            'snapshot_at'=>$snapshotAt,'created_by'=>$actor,'created_at'=>time()]);
        return ['result_id'=>$id,'reused_result'=>false];
    }

    private static function identity(array $account,array $period,string $sha):string
    {return hash('sha256',Facts::canonical(['version'=>1,'account'=>$account,'date_from'=>$period['date_from'],
        'date_to'=>$period['date_to'],'file_sha256'=>$sha]),true);}
    private static function status(int $differences,int $issues):string
    {return $issues>0?'SOURCE_REVIEW_REQUIRED':($differences>0?'DIFFERENCES':'MATCHED');}
    private static function json(string $value):array
    {$data=json_decode($value,true,64,JSON_THROW_ON_ERROR);if(!is_array($data))throw new AuthUnavailableException();return $data;}
    private function table(string $name):string {return '`'.$this->prefix.$name.'`';}
    private function rows(string $sql,array $args=[]):array
    {
        if(!$this->pdo->inTransaction())throw new AuthUnavailableException();
        $s=$this->pdo->prepare($sql);try{if(!$s||!$s->execute($args))throw new AuthUnavailableException();return $s->fetchAll(PDO::FETCH_ASSOC);}
        finally{if($s)$s->closeCursor();}
    }
    private function insert(string $table,array $values):string
    {
        if(!$this->pdo->inTransaction())throw new AuthUnavailableException();
        $s=$this->pdo->prepare('INSERT INTO '.$this->table($table).' (`'.implode('`,`',array_keys($values)).'`) VALUES ('
            .implode(',',array_fill(0,count($values),'?')).')');
        try{if(!$s||!$s->execute(array_values($values))||$s->rowCount()!==1)throw new AuthUnavailableException();return Facts::u64($this->pdo->lastInsertId());}
        finally{if($s)$s->closeCursor();}
    }
}
