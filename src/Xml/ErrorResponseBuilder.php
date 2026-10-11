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
     * @param  string  $hostId  The extended request identifier.
     * @return string The complete XML error response.
     */
    public static function build(string $code, string $message, string $resource, string $requestId, string $hostId = ''): string
    {
        $flags = ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED;
        $c = htmlspecialchars($code, $flags, 'UTF-8');
        $m = htmlspecialchars($message, $flags, 'UTF-8');
        $r = htmlspecialchars($resource, $flags, 'UTF-8');
        $id = htmlspecialchars($requestId, $flags, 'UTF-8');
        $host = htmlspecialchars($hostId, $flags, 'UTF-8');

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Error><Code>{$c}</Code><Message>{$m}</Message><Resource>{$r}</Resource><RequestId>{$id}</RequestId><HostId>{$host}</HostId></Error>";
    }
}
