<?php

namespace Zammpay\Http;

use Zammpay\ZammpayClient;
use Zammpay\ZammpayException;

/**
 * The default transport, built on the cURL extension.
 *
 * Timeouts are always set: without them a slow gateway would hold the merchant's checkout page
 * open indefinitely, because PHP's max_execution_time does not count time spent waiting on cURL.
 */
final class CurlTransport implements Transport
{
    /** @var int */
    private $timeout;

    /** @var int */
    private $connectTimeout;

    /**
     * @param int $timeout        Seconds the whole request may take.
     * @param int $connectTimeout Seconds connecting may take.
     */
    public function __construct(int $timeout = 30, int $connectTimeout = 10)
    {
        if (!extension_loaded('curl')) {
            throw new ZammpayException('The cURL PHP extension is required by zamm-tech/zammpay.');
        }

        $this->timeout = max(1, $timeout);
        $this->connectTimeout = max(1, $connectTimeout);
    }

    public function post(string $url, array $fields): HttpResponse
    {
        if ($url === '') {
            throw new ZammpayException('The request URL must not be empty.');
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'zammpay-php/' . ZammpayClient::VERSION . ' PHP/' . PHP_VERSION,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        // A no-op since PHP 8.0 and deprecated in 8.5: the handle is freed when it goes out of scope.
        if (PHP_VERSION_ID < 80000) {
            curl_close($curl);
        }

        if ($errno !== 0 || !is_string($body)) {
            throw new ZammpayException(
                $errno === CURLE_OPERATION_TIMEDOUT
                    ? 'ZAMM PAY did not answer within ' . $this->timeout . ' seconds.'
                    : 'Could not connect to ZAMM PAY: ' . ($error !== '' ? $error : 'unknown cURL error') . '.'
            );
        }

        return new HttpResponse($status, $body);
    }
}
