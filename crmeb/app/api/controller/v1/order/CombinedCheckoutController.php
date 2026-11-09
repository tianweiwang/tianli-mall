<?php
declare(strict_types=1);

namespace app\api\controller\v1\order;

use app\Request;
use app\services\marketplace\order\MarketplaceCombinedCheckoutCancellation;
use app\services\marketplace\order\MarketplaceCombinedCheckoutServices;
use think\Response;

final class CombinedCheckoutController
{
    public function __construct(
        private MarketplaceCombinedCheckoutServices $checkout,
        private MarketplaceCombinedCheckoutCancellation $cancellation,
    ) {}

    public function availability(): Response
    {
        return app('json')->success(['available' => MarketplaceCombinedCheckoutServices::available()]);
    }

    public function confirm(Request $request): Response
    {
        return app('json')->success($this->checkout->quote((int)$request->uid(),
            $request->user()->toArray(), $request->post(false)));
    }

    public function create(Request $request, string $orderNo): Response
    {
        return app('json')->success($this->checkout->create((int)$request->uid(), $orderNo, $request->post(false)));
    }

    public function pay(Request $request, string $orderNo): Response
    {
        return app('json')->success($this->checkout->pay((int)$request->uid(), $orderNo, $request->post(false)));
    }

    public function cancel(Request $request, string $orderNo): Response
    {
        $uid = (int)$request->uid();
        $this->cancellation->cancelForCustomer($uid, $orderNo);
        return app('json')->success($this->checkout->detail($uid, $orderNo));
    }

    public function detail(Request $request, string $orderNo): Response
    {
        return app('json')->success($this->checkout->detail((int)$request->uid(), $orderNo));
    }

    public function listing(Request $request): Response
    {
        return app('json')->success($this->checkout->listing((int)$request->uid()));
    }
}
