<?php

namespace Zammpay;

class ZammpayResponse
{
    private $success;
    private $data;
    private $message;

    /**
     * ZammpayResponse constructor.
     *
     * @param bool $success
     * @param array $data
     * @param string|null $message
     */
    public function __construct(bool $success, array $data = [], string $message = null)
    {
        $this->success = $success;
        $this->data = $data;
        $this->message = $message;
    }

    /**
     * Check if the response is successful.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get the response data.
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Get the response message.
     *
     * @return string|null
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }
}
