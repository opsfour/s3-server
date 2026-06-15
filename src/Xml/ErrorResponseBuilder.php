<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Xml;

/**
 * Builds standard S3 XML error responses.
 */
final class ErrorResponseBuilder
{
    /**
     * Build an S3 error XML response body.
     *
     * @param  string  $code  The S3 error code (e.g., 'NoSuchBucket').
     * @param  string  $message  The human-readable error message.
     * @param  string  $resource  The resource that triggered the error.
     * @param  string  $requestId  The unique request identifier.
     * @return string The complete XML error response.
     */
    public static function build(string $code, string $message, string $resource, string $requestId): string
    {
        $c = htmlspecialchars($code, ENT_XML1, 'UTF-8');
        $m = htmlspecialchars($message, ENT_XML1, 'UTF-8');
        $r = htmlspecialchars($resource, ENT_XML1, 'UTF-8');
        $id = htmlspecialchars($requestId, ENT_XML1, 'UTF-8');

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Error><Code>{$c}</Code><Message>{$m}</Message><Resource>{$r}</Resource><RequestId>{$id}</RequestId></Error>";
    }
}
