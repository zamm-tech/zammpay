<?php

namespace Zammpay;

/**
 * The statuses a ZAMM PAY payment can have, as returned by verifyPayment().
 */
final class PaymentStatus
{
    public const PENDING = 'Pending';
    public const COMPLETED = 'Completed';
    public const CANCELED = 'Canceled';

    private function __construct()
    {
    }
}
