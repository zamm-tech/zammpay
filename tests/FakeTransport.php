<?php

namespace Zammpay\Tests;

use Zammpay\Http\HttpResponse;
use Zammpay\Http\Transport;
use Zammpay\ZammpayException;

/**
 * Answers requests from a queue and records them, so tests never reach the network.
 */
final class FakeTransport implements Transport
{
    /** @var list<HttpResponse|ZammpayException> */
    private $queue = [];

    /** @var list<array{url: string, fields: array<string, mixed>}> */
    public $requests = [];

    /**
     * @param array<string, mixed> $body
     */
    public function json(int $status, array $body): self
    {
        return $this->raw($status, (string) json_encode($body));
    }

    public function raw(int $status, string $body): self
    {
        $this->queue[] = new HttpResponse($status, $body);

        return $this;
    }

    public function fail(ZammpayException $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function post(string $url, array $fields): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'fields' => $fields];

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new \LogicException('FakeTransport received an unexpected request to ' . $url);
        }

        if ($next instanceof ZammpayException) {
            throw $next;
        }

        return $next;
    }
}
