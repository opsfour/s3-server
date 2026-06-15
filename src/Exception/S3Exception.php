<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/**
 * Base exception for all S3 API errors.
 *
 * Each concrete subclass maps to a specific S3 error code and HTTP status.
 * The ErrorHandlingMiddleware catches these and converts them to proper
 * S3 XML error responses.
 */
abstract class S3Exception extends \RuntimeException
{
    /** @var array<string, string> Extra headers to include in the error response. */
    private array $extraHeaders = [];

    public function __construct(
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $this->getHttpStatus(), $previous);
    }

    /**
     * The S3 error code string (e.g., 'NoSuchBucket', 'AccessDenied').
     */
    abstract public function getErrorCode(): string;

    /**
     * The HTTP status code for this error.
     */
    abstract public function getHttpStatus(): int;

    /**
     * @return array<string, string>
     */
    public function getExtraHeaders(): array
    {
        return $this->extraHeaders;
    }

    /**
     * Set extra headers to include in the error response (mutating).
     *
     * @param array<string, string> $headers
     */
    public function setExtraHeaders(array $headers): void
    {
        $this->extraHeaders = $headers;
    }

    /**
     * @param array<string, string> $headers
     * @deprecated Use setExtraHeaders() instead — Exceptions cannot be cloned in PHP.
     */
    public function withExtraHeaders(array $headers): static
    {
        $this->extraHeaders = $headers;

        return $this;
    }
}
