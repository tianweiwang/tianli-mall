<?php
declare(strict_types=1);

namespace app\api\controller\v1\order;

use app\Request;
use app\dao\marketplace\payment\MarketplacePaymentDao;
use app\services\marketplace\payment\MarketplacePaymentServices;
use crmeb\exceptions\AuthUnavailableException;
use crmeb\services\pay\marketplace\PaymentAccount;
use crmeb\services\pay\marketplace\PaymentAccountResolver;
use crmeb\services\pay\marketplace\PaymentAdapter;
use crmeb\services\pay\marketplace\TestPaymentDriver;
use PDO;
use think\facade\Config;
use think\facade\Db;
use think\exception\HttpException;
use think\Response;

/** Explicit testing only. Real institution payment remains unavailable. */
final class TestPaymentController
{
    private function candidate(Request $request): array
    {
        $uid = (int)$request->uid();
        $number = $request->route('order_no', null, null);
        if ($uid < 1 || !is_string($number) || preg_match('/\A[\x21-\x7e]{1,32}\z/D', $number) !== 1) {
            throw new HttpException(422, '订单参数不正确');
        }
        $connection = Db::connect();
        $pdo = $connection->getPdo();
        if (!$pdo instanceof PDO) { $connection->query('SELECT 1', [], true); $pdo = $connection->getPdo(); }
        if (!$pdo instanceof PDO || $pdo->inTransaction()) throw new AuthUnavailableException();
        // A real UID/order/V1 read supplies merchant identity; the client supplies none of these proposals.
        return app()->make(MarketplacePaymentDao::class)->customerPaymentCandidate($pdo, $uid, $number);
    }

    public function options(Request $request): Response
    {
        $candidate = $this->candidate($request);
        return app('json')->success([
            'schema' => 1, 'scope' => 'testing_payment_availability_v1', 'order_no' => $candidate['order_no'],
            'available' => getenv('APP_ENV') === 'testing' && Config::get('marketplace.payment.test_enabled', false) === true,
            'real_payment_available' => false,
        ]);
    }

    public function pay(Request $request): Response
    {
        PaymentAccount::assertTesting();
        $candidate = $this->candidate($request);
        $raw = $request->getContent();
        if (!is_string($raw) || $raw === '' || strlen($raw) > 512) throw new HttpException(422, '测试支付参数不正确');
        try { $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new HttpException(422, '测试支付参数不正确'); }
        if (!is_array($body) || array_keys($body) !== ['payment_idem'] || !is_string($body['payment_idem'])
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $body['payment_idem']) !== 1) {
            throw new HttpException(422, '测试支付参数不正确');
        }
        // Register only the actual merchant, in this App's existing in-memory test configuration.
        app()->make(PaymentAccountResolver::class)->registerTestAccount($candidate['mer_id'], 'test:isolated-preview', 1, 'CNY');
        $payments = app()->make(MarketplacePaymentServices::class);
        $driver = app()->make(PaymentAdapter::class);
        if (!$driver instanceof TestPaymentDriver) throw new AuthUnavailableException();
        $created = $payments->createForCustomer($candidate['uid'], $candidate['order_no'], $body['payment_idem']);
        // An already applied same-key replay is a saved fact, even in a fresh HTTP App/driver instance.
        if ($created['state'] === 'applied') return app('json')->success($created);
        if ($created['state'] !== 'pending' || !is_array($created['create_result'])) {
            throw new HttpException(409, '测试支付结果待确认，请保留原订单，不要另建支付请求');
        }
        // The original descriptor and same exact driver must survive create -> complete -> query in one request.
        $completed = $driver->completeTestPayment($created['create_result']);
        if (($completed['state'] ?? null) !== 'succeeded' || ($completed['scope'] ?? null) !== 'testing_process_only') {
            throw new HttpException(409, '测试支付结果待确认，请保留原订单，不要另建支付请求');
        }
        $result = $payments->queryForCustomer($candidate['uid'], $candidate['order_no'], $created['attempt_id']);
        if ($result['state'] !== 'applied') throw new HttpException(409, '测试支付结果尚未确认，请查看原订单');
        return app('json')->success($result);
    }
}
