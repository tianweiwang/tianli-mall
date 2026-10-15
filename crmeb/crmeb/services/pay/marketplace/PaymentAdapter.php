<?php
declare(strict_types=1);

namespace crmeb\services\pay\marketplace;

/** Order-level contract. A driver must not treat missing/unknown evidence as unpaid. */
interface PaymentAdapter
{
    public function createPayment(array $actualOrder, PaymentAccount $account, string $idempotencyKey): array;

    public function queryPayment(array $paymentAttempt): array;

    public function closePayment(array $paymentAttempt): array;

    public function createRefund(array $originalPaymentAttempt, array $actualRefund, string $idempotencyKey): array;

    public function queryRefund(array $refundAttempt): array;

    public function verifyAndParseNotification(array $rawRequest): array;
}
