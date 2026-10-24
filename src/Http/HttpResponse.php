<?php

namespace Zammpay\Http;

/**
 * A raw HTTP answer: its status code and unparsed body.
 */
final class HttpResponse
{
    /** @var int */
    private $status;

    /** @var string */
    private $body;

    public function __construct(int $status, string $body)
    {
        $this->status = $status;
        $this->body = $body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
