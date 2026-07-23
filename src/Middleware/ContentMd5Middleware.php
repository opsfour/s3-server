<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\BadDigestException;

/**
 * Validates Content-MD5 before dispatch while keeping object data off the heap.
 */
final class ContentMd5Middleware implements Middleware
{
    public function __construct(private readonly RequestBodySpool $spool) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $contentMd5 = $request->getHeader('Content-MD5');
        if ($contentMd5 === null || ! in_array($request->getMethod(), ['PUT', 'POST'], true)) {
            return $requestHandler->handleRequest($request);
        }

        $expected = base64_decode($contentMd5, strict: true);
        if ($expected === false || strlen($expected) !== 16) {
            throw new BadDigestException('Content-MD5 must be a Base64-encoded 128-bit MD5 digest.');
        }

        $tempPath = $this->spool->create('s3-md5-');
        $reader = null;

        try {
            $hash = hash_init('md5');

            while (($chunk = $request->getBody()->read()) !== null) {
                hash_update($hash, $chunk);
                $this->spool->append($tempPath, $chunk);
            }

            $computed = hash_final($hash, binary: true);
            if (! hash_equals($expected, $computed)) {
                throw new BadDigestException(
                    "The Content-MD5 you specified did not match what we received. Expected: {$contentMd5}, Computed: "
                    . base64_encode($computed),
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
