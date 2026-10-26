<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Auth\AwsChunkedContentLength;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Http\RequestBody;

/**
 * Validates literal SigV4 payload hashes without buffering object data in RAM.
 */
final class ChecksumValidationMiddleware implements Middleware
{
    /** @var list<string> Special values validated by the SigV4 streaming decoder. */
    private const array SKIP_VALUES = [
        'UNSIGNED-PAYLOAD',
        'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
        'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER',
        'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
    ];

    public function __construct(private readonly RequestBodySpool $spool) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $expected = $request->getHeader('x-amz-content-sha256');
        if (RequestBody::isBodylessOperation($request)) {
            if ($expected !== null && ! in_array($expected, self::SKIP_VALUES, true)) {
                if (preg_match('/^[a-f0-9]{64}$/i', $expected) !== 1) {
                    throw new BadDigestException('x-amz-content-sha256 must be a 64-character hexadecimal SHA-256 digest.');
                }

                $computed = hash('sha256', '');
                if (! hash_equals(strtolower($expected), $computed)) {
                    throw new BadDigestException(
                        "The SHA-256 checksum you specified did not match what we received. Expected: {$expected}, Computed: {$computed}",
                    );
                }
            }

            $decodedLength = AwsChunkedContentLength::fromRequest($request);
            if ($decodedLength !== null && $decodedLength !== 0) {
                self::rejectBodylessPayload($request);
            }
            if (RequestBody::hasPayloadBytes($request)) {
                self::rejectBodylessPayload($request);
            }

            return $requestHandler->handleRequest($request);
        }

        if ($expected === null || in_array($expected, self::SKIP_VALUES, true)) {
            return $requestHandler->handleRequest($request);
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $expected) !== 1) {
            throw new BadDigestException('x-amz-content-sha256 must be a 64-character hexadecimal SHA-256 digest.');
        }

        $tempPath = $this->spool->create('s3-sha256-');
        $reader = null;
        $primaryFailure = null;

        try {
            $hash = hash_init('sha256');

            while (($chunk = $request->getBody()->read()) !== null) {
                hash_update($hash, $chunk);
                $this->spool->append($tempPath, $chunk);
            }

            $computed = hash_final($hash);
            if (! hash_equals(strtolower($expected), $computed)) {
                throw new BadDigestException(
                    "The SHA-256 checksum you specified did not match what we received. Expected: {$expected}, Computed: {$computed}",
                );
            }

            $reader = $this->spool->openReadable($tempPath);
            $request->setBody($reader);

            return $requestHandler->handleRequest($request);
        } catch (\Throwable $error) {
            $primaryFailure = $error;
            throw $error;
        } finally {
            $closeFailure = null;
            try {
                $reader?->close();
            } catch (\Throwable $error) {
                $closeFailure = $error;
            }
            $deleteCancellation = null;
            try {
                $this->spool->delete($tempPath);
            } catch (\Amp\CancelledException $error) {
                $deleteCancellation = $error;
            } catch (\Throwable) {
            }
            if ($primaryFailure === null) {
                if ($closeFailure !== null) {
                    throw $closeFailure;
                }
                if ($deleteCancellation !== null) {
                    throw $deleteCancellation;
                }
            }
        }
    }

    private static function rejectBodylessPayload(Request $request): never
    {
        try {
            $request->getBody()->close();
        } catch (\Throwable) {
        }

        throw new BadDigestException('A bodyless S3 operation cannot contain a payload.');
    }
}
