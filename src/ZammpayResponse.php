<?php

namespace Zammpay;

/**
 * The outcome of a ZAMM PAY API call.
 *
 * An unsuccessful response carries ZAMM PAY's message, the field errors of a failed validation
 * and the HTTP status, so the reason can be shown or logged.
 */
class ZammpayResponse
{
    /** @var bool */
    private $success;

    /** @var array<mixed> */
    private $data;

    /** @var string|null */
    private $message;

    /** @var array<mixed> */
    private $errors;

    /** @var int|null */
    private $statusCode;

    /**
     * @param array<mixed> $data
     * @param array<mixed> $errors Field errors of a failed validation, keyed by field name.
     */
    public function __construct(
        bool $success,
        array $data = [],
        ?string $message = null,
        array $errors = [],
        ?int $statusCode = null
    ) {
        $this->success = $success;
        $this->data = $data;
        $this->message = $message;
        $this->errors = $errors;
        $this->statusCode = $statusCode;
    }

    /**
     * Whether the API call succeeded. For verifyPayment() this does not mean the payment is
     * paid: check isCompleted() for that.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return array<mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * One field of the response data.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    /**
     * The error message of an unsuccessful response.
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Field errors of a failed validation, e.g. ['amount' => ['The amount field is required.']].
     *
     * @return array<mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * The HTTP status ZAMM PAY answered with, or null for a response built without a request.
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * The ZAMM PAY transaction ID, from createPayment() or verifyPayment().
     */
    public function getTransactionId(): ?string
    {
        return $this->stringField('transaction_id') ?? $this->stringField('trx_id');
    }

    /**
     * The checkout page to send the customer to, from createPayment().
     */
    public function getPaymentUrl(): ?string
    {
        return $this->stringField('payment_url');
    }

    /**
     * The payment status from verifyPayment(): one of the PaymentStatus constants.
     */
    public function getStatus(): ?string
    {
        return $this->stringField('status');
    }

    /**
     * The payment amount from verifyPayment(), as the decimal string ZAMM PAY returned.
     */
    public function getAmount(): ?string
    {
        return $this->stringField('amount');
    }

    public function isCompleted(): bool
    {
        return $this->success && $this->getStatus() === PaymentStatus::COMPLETED;
    }

    public function isPending(): bool
    {
        return $this->success && $this->getStatus() === PaymentStatus::PENDING;
    }

    public function isCanceled(): bool
    {
        return $this->success && $this->getStatus() === PaymentStatus::CANCELED;
    }

    private function stringField(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_scalar($value) && !is_bool($value) ? (string) $value : null;
    }
}
