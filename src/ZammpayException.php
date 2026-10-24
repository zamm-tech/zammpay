<?php

namespace Zammpay;

use RuntimeException;
use Throwable;

/**
 * Thrown when ZAMM PAY could not be reached or did not answer with valid JSON.
 *
 * API errors such as an invalid key or a failed validation are not exceptions: they come back
 * as an unsuccessful ZammpayResponse, so they can be shown to the customer or logged.
 */
class ZammpayException extends RuntimeException
{
    /** @var int|null */
    private $httpStatus;

    public function __construct(string $message, ?int $httpStatus = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->httpStatus = $httpStatus;
    }

    /**
     * The HTTP status ZAMM PAY answered with, or null when no answer arrived.
     */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
