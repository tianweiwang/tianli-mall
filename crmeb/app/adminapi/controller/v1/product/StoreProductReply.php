<?php
// +----------------------------------------------------------------------
// | CRMEB [ CRMEB赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2026 https://www.crmeb.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed under the Apache License, Version 2.0
// +----------------------------------------------------------------------
// | Author: CRMEB Team <admin@crmeb.com>
// +----------------------------------------------------------------------
namespace app\adminapi\controller\v1\product;

use app\adminapi\controller\AuthController;
use app\services\product\product\StoreProductReplyServices;
use app\services\marketplace\product\LegacyProductWriteServices;
use crmeb\exceptions\AdminException;
use crmeb\exceptions\AuthUnavailableException;
use think\db\Query;
use think\facade\App;
use think\facade\Db;

/**
 * 评论管理 控制器
 * Class StoreProductReply
 * @package app\admin\controller\store
 */
class StoreProductReply extends AuthController
{
    /**
     * @var StoreProductReplyServices
     */
    protected $services;
    
    /**
     * 构造方法
     * @param App $app
     * @param StoreProductReplyServices $service
     * @var StoreProductReplyServices $services
     */
    public function __construct(App $app, StoreProductReplyServices $service)
    {
        parent::__construct($app);
        $this->services = $service;
    }

    /**
     * 显示资源列表
     *
     * @return \think\Response
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['is_reply', ''],
            ['store_name', ''],
            ['account', ''],
            ['data', ''],
            ['product_id', 0],
            ['key', ''],
            ['order', ''],
            ['status', ''],
        ]);
        $list = $this->services->sysPage($where);
        return app('json')->success($list);
    }

    /**
     * 删除评论
     * @param $id
     * @return mixed
     */
    public function delete($id)
    {
        $id = LegacyProductWriteServices::id($id,2147483647);
        app()->make(LegacyProductWriteServices::class)->run(LegacyProductWriteServices::replyParents([$id]),function()use($id):void{
            $rows = LegacyProductWriteServices::lockReplies([$id]);
            $this->writeReply($rows[$id],['is_del'=>1]);
        });
        return app('json')->success('删除成功');
    }

    /**
     * 回复评论
     * @param $id
     * @return mixed
     */
    public function set_reply($id)
    {
        [$content] = $this->request->postMore([
            ['content', '']
        ], true);
        $id = LegacyProductWriteServices::id($id,2147483647);
        $content = self::replyText($content,300);
        if ($content === '') throw new AdminException('请输入回复内容');
        app()->make(LegacyProductWriteServices::class)->run(LegacyProductWriteServices::replyParents([$id]),function()use($id,$content):void{
            $rows = LegacyProductWriteServices::lockReplies([$id]); $row = $rows[$id];
            if ($row['merchant_reply_content'] === $content && (string)$row['is_reply'] === '1') return;
            $this->writeReply($row,['merchant_reply_content'=>$content,'merchant_reply_time'=>self::replyTime(),'is_reply'=>1]);
        });
        return app('json')->success('回复成功');
    }

    /**
     * 创建虚拟评论表单
     * @return mixed
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function fictitious_reply()
    {
        list($product_id) = $this->request->postMore([
            ['product_id', 0],
        ], true);
        return app('json')->success($this->services->createForm($product_id));
    }

    /**
     * 保存虚拟评论
     * @return mixed
     */
    public function save_fictitious_reply()
    {
        $data = $this->request->postMore([
            ['image', ''],
            ['nickname', ''],
            ['avatar', ''],
            ['comment', ''],
            ['pics', []],
            ['product_score', 0],
            ['service_score', 0],
            ['product_id', 0],
            ['add_time', 0],
            ['suk', ''],
        ]);
        if (!$data['product_id']) {
            $data['product_id'] = $data['image']['product_id'] ?? '';
        }
        $this->validate(['product_id' => $data['product_id'], 'nickname' => $data['nickname'], 'avatar' => $data['avatar'], 'comment' => $data['comment'], 'product_score' => $data['product_score'], 'service_score' => $data['service_score']], \app\adminapi\validate\product\StoreProductReplyValidate::class, 'save');
        $productId = LegacyProductWriteServices::id($data['product_id']);
        app()->make(LegacyProductWriteServices::class)->run([$productId],function()use($productId,$data):void{
            LegacyProductWriteServices::assertParent($productId);
            $now = self::replyTime();
            $added = empty($data['add_time']) ? $now : (is_string($data['add_time']) ? strtotime($data['add_time']) : false);
            if (!is_int($added) || $added < -2147483648 || $added > $now) throw new AdminException('评论时间不正确');
            if (!is_array($data['pics'])) throw new AdminException('评价图片不正确');
            $pics = json_encode($data['pics'],JSON_THROW_ON_ERROR);
            if (strlen($pics) > 65535) throw new AdminException('评价图片不正确');
            $insert = ['uid'=>0,'oid'=>0,'unique'=>uniqid(),'product_id'=>$productId,'reply_type'=>'product',
                'product_score'=>LegacyProductWriteServices::id($data['product_score'],5),
                'service_score'=>LegacyProductWriteServices::id($data['service_score'],5),
                'comment'=>self::replyText($data['comment'],512),'pics'=>$pics,'add_time'=>$added,
                'merchant_reply_content'=>'','merchant_reply_time'=>0,'is_del'=>0,'is_reply'=>0,
                'nickname'=>self::replyText($data['nickname'],64),'avatar'=>self::replyText($data['avatar'],255),
                'suk'=>self::replyText($data['suk'],255),'status'=>1];
            $query = Db::name('store_product_reply'); LegacyProductWriteServices::query($query);
            $replyId = LegacyProductWriteServices::id($query->insertGetId($insert),2147483647);
            $after = $this->replyQuery(['id'=>$replyId,'product_id'=>$productId])->lock(true)->find();
            if (!is_array($after)) throw new AuthUnavailableException();
            foreach ($insert as $field=>$value) if (!array_key_exists($field,$after) || !self::sameReplyValue($after[$field],$value)) throw new AuthUnavailableException();
        });
        return app('json')->success('保存成功');
    }

    /**
     * 商品评论审核
     * @param $id
     * @param $status
     * @return \think\Response
     * @author wuhaotian
     * @email 442384644@qq.com
     * @date 2024/4/22
     */
    public function set_status($id, $status)
    {
        $id = LegacyProductWriteServices::id($id,2147483647); $status = self::replyStatus($status);
        app()->make(LegacyProductWriteServices::class)->run(LegacyProductWriteServices::replyParents([$id]),function()use($id,$status):void{
            $rows = LegacyProductWriteServices::lockReplies([$id]); $this->writeReply($rows[$id],['status'=>$status]);
        });
        return app('json')->success($status == 1 ? '审核通过' : '拒绝成功');
    }

    /**
     * 批量商品评论审核
     * @return \think\Response
     * @author wuhaotian
     * @email 442384644@qq.com
     * @date 2025/6/18
     */
    public function batch_set_status()
    {
        list($ids, $status) = $this->request->postMore([
            ['ids', []],
            ['status', 0]
        ], true);
        if (!is_array($ids)) throw new AdminException('请求参数不正确');
        $replyIds = []; foreach ($ids as $id) $replyIds[] = LegacyProductWriteServices::id($id,2147483647);
        $replyIds = array_values(array_unique($replyIds)); sort($replyIds,SORT_NUMERIC); $status = self::replyStatus($status);
        if (!$replyIds) throw new AdminException('请选择评论');
        app()->make(LegacyProductWriteServices::class)->run(LegacyProductWriteServices::replyParents($replyIds),function()use($replyIds,$status):void{
            $rows = LegacyProductWriteServices::lockReplies($replyIds);
            foreach ($replyIds as $id) $this->writeReply($rows[$id],['status'=>$status]);
        });
        return app('json')->success($status == 1 ? '审核通过' : '拒绝成功');
    }

    /** The comment table has no mer_id: its locked real product supplies ownership. */
    private function replyQuery(array $row): Query
    {
        $id = LegacyProductWriteServices::id($row['id'],2147483647);
        $productId = LegacyProductWriteServices::id($row['product_id']); LegacyProductWriteServices::assertParent($productId);
        $query = Db::name('store_product_reply')->where('id',$id)->where('product_id',$productId)->where('reply_type','product');
        LegacyProductWriteServices::query($query); return $query;
    }
    private function writeReply(array $row, array $patch): void
    {
        $effective = []; foreach ($patch as $field=>$value) if (!self::sameReplyValue($row[$field],$value)) $effective[$field] = $value;
        if ($effective) {
            $affected = $this->replyQuery($row)->update($effective);
            if (!is_int($affected) || $affected < 0 || $affected > 1) throw new AuthUnavailableException();
        }
        $after = $this->replyQuery($row)->lock(true)->find(); $expected = array_replace($row,$effective);
        if (!is_array($after) || array_keys($after) !== array_keys($expected)) throw new AuthUnavailableException();
        foreach ($expected as $field=>$value) if (!self::sameReplyValue($after[$field],$value)) throw new AuthUnavailableException();
    }
    private static function sameReplyValue(mixed $a, mixed $b): bool
    { return ($a === null) === ($b === null) && ($a === null || (string)$a === (string)$b); }
    private static function replyText(mixed $value, int $maximum): string
    {
        if (!is_string($value) || preg_match('//u',$value) !== 1 || str_contains($value,"\0") || mb_strlen($value,'UTF-8') > $maximum)
            throw new AdminException('评论内容不正确'); return $value;
    }
    private static function replyStatus(mixed $value): int
    {
        if (is_string($value) && preg_match('/\A(?:0|-?[1-9][0-9]{0,2})\z/D',$value) === 1) $value = (int)$value;
        if (!is_int($value) || $value < -128 || $value > 127) throw new AdminException('请求参数不正确'); return $value;
    }
    private static function replyTime(): int
    { $now = time();if ($now <= 0 || $now > 2147483647) throw new AuthUnavailableException();return $now; }
}
