<?php

namespace Zammpay\Http;

use Zammpay\ZammpayException;

/**
 * Sends a form POST to ZAMM PAY. The client uses CurlTransport unless another one is given,
 * which lets an application route requests through its own HTTP stack or fake them in tests.
 */
interface Transport
{
    /**
     * @param array<string, mixed> $fields
     *
     * @throws ZammpayException When no answer arrived.
     */
    public function post(string $url, array $fields): HttpResponse;
}
