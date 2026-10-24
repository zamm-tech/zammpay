<?php

namespace Zammpay;

use InvalidArgumentException;
use Zammpay\Http\CurlTransport;
use Zammpay\Http\Transport;

/**
 * Creates and verifies payments with the ZAMM PAY API.
 */
class ZammpayClient
{
    public const VERSION = '2.1.0';

    public const DEFAULT_BASE_URL = 'https://pay.zammbd.com/api';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $apiBaseUrl;

    /** @var Transport */
    private $transport;

    /**
     * @param string         $apiKey     The API key of your domain in the ZAMM PAY dashboard.
     * @param string         $apiBaseUrl The API address; only change it for testing.
     * @param Transport|null $transport  Sends the requests; defaults to cURL with a 30 second timeout.
     *
     * @throws InvalidArgumentException When the API key is empty or the base URL is not an http(s) URL.
     */
    public function __construct(string $apiKey, string $apiBaseUrl = self::DEFAULT_BASE_URL, ?Transport $transport = null)
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new InvalidArgumentException('The ZAMM PAY API key must not be empty.');
        }

        if (!preg_match('#^https?://[^/\s]+#i', $apiBaseUrl)) {
            throw new InvalidArgumentException('The ZAMM PAY base URL must be an http or https URL.');
        }

        $this->apiKey = $apiKey;
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * Open a payment and get the checkout page to send the customer to.
     *
     * Required: amount, success_url, cancel_url, callback_url.
     * Optional: cus_name, cus_email, cus_number, cus_address, currency, extra.
     *
     * @param array<string, mixed> $paymentData
     *
     * @throws ZammpayException When ZAMM PAY could not be reached or did not answer with JSON.
     */
    public function createPayment(array $paymentData): ZammpayResponse
    {
        list($status, $body) = $this->post('/payment/create', $paymentData);

        $trxId = $body['trx_id'] ?? null;

        if (!self::succeeded($status, $body) || !is_scalar($trxId) || (string) $trxId === '') {
            return self::failure($status, $body, 'The payment could not be created.');
        }

        $trxId = (string) $trxId;
        $paymentUrl = $body['payment_url'] ?? null;

        return new ZammpayResponse(true, [
            'transaction_id' => $trxId,
            'payment_url' => is_string($paymentUrl) && $paymentUrl !== ''
                ? $paymentUrl
                : $this->apiBaseUrl . '/payment/' . rawurlencode($trxId),
        ], null, [], $status);
    }

    /**
     * Ask ZAMM PAY for a payment's current status, amount and customer details.
     *
     * A successful response only means the payment was found: check isCompleted() before
     * fulfilling the order.
     *
     * @throws InvalidArgumentException When the transaction ID is empty.
     * @throws ZammpayException         When ZAMM PAY could not be reached or did not answer with JSON.
     */
    public function verifyPayment(string $transactionId): ZammpayResponse
    {
        $transactionId = trim($transactionId);

        if ($transactionId === '') {
            throw new InvalidArgumentException('The transaction ID must not be empty.');
        }

        list($status, $body) = $this->post('/payment/verify', ['trx_id' => $transactionId]);

        if (!self::succeeded($status, $body)) {
            return self::failure($status, $body, 'The payment could not be verified.');
        }

        return new ZammpayResponse(true, $body, null, [], $status);
    }

    /**
     * Check a callback that arrived at your callback_url, e.g. verifyCallback($_POST).
     *
     * Anyone can POST to a callback URL, so nothing in the payload is trusted: it is only used to
     * find the transaction, whose real status is then fetched from ZAMM PAY with verifyPayment().
     * A payload carrying a different API key is refused without a request.
     *
     * @param array<string, mixed> $payload
     *
     * @throws ZammpayException When ZAMM PAY could not be reached or did not answer with JSON.
     */
    public function verifyCallback(array $payload): ZammpayResponse
    {
        $trxId = $payload['trx_id'] ?? null;

        if (!is_string($trxId) || trim($trxId) === '') {
            return new ZammpayResponse(false, [], 'The callback has no transaction ID.');
        }

        if (array_key_exists('api_key', $payload)
            && (!is_string($payload['api_key']) || !hash_equals($this->apiKey, $payload['api_key']))) {
            return new ZammpayResponse(false, [], 'The callback was not sent for this API key.');
        }

        return $this->verifyPayment($trxId);
    }

    /**
     * Keeps the API key out of var_dump() and debug output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => substr($this->apiKey, 0, 4) . '…',
            'apiBaseUrl' => $this->apiBaseUrl,
            'transport' => get_class($this->transport),
        ];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{0: int, 1: array<mixed>}
     *
     * @throws ZammpayException
     */
    private function post(string $path, array $fields): array
    {
        // Set last, so a stray api_key in the caller's data can never replace the client's own.
        $fields['api_key'] = $this->apiKey;

        $response = $this->transport->post($this->apiBaseUrl . $path, $fields);
        $status = $response->getStatus();
        $body = json_decode($response->getBody(), true);

        if (!is_array($body)) {
            throw new ZammpayException(
                'ZAMM PAY answered with HTTP ' . $status . ' and a body that is not JSON.',
                $status
            );
        }

        return [$status, $body];
    }

    /**
     * @param array<mixed> $body
     */
    private static function succeeded(int $status, array $body): bool
    {
        return $status >= 200 && $status < 300 && ($body['success'] ?? false) === true;
    }

    /**
     * @param array<mixed> $body
     */
    private static function failure(int $status, array $body, string $fallbackMessage): ZammpayResponse
    {
        $message = $body['message'] ?? null;
        $errors = $body['errors'] ?? null;

        return new ZammpayResponse(
            false,
            [],
            is_string($message) && $message !== '' ? $message : $fallbackMessage,
            is_array($errors) ? $errors : [],
            $status
        );
    }
}
