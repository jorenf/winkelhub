<?php

declare(strict_types=1);

namespace LogicTradeSync\Api;

/**
 * Thrown when an API request to LogicTrade fails.
 */
final class RequestException extends \RuntimeException
{
    private int $httpStatus;
    private string $responseBody;

    public function __construct(string $message, int $httpStatus = 0, string $responseBody = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->httpStatus   = $httpStatus;
        $this->responseBody = $responseBody;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}
