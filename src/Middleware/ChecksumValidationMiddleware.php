<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\BadDigestException;

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
        if ($expected === null || in_array($expected, self::SKIP_VALUES, true)) {
            return $requestHandler->handleRequest($request);
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $expected) !== 1) {
            throw new BadDigestException('x-amz-content-sha256 must be a 64-character hexadecimal SHA-256 digest.');
        }

        $tempPath = $this->spool->create('s3-sha256-');
        $reader = null;

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
        } finally {
            $reader?->close();
            try {
                $this->spool->delete($tempPath);
            } catch (\Throwable) {
            }
        }
    }
}
