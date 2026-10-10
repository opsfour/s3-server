<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Aws\CommandInterface;
use Aws\ResultInterface;
use Aws\S3\ObjectUploader;
use Aws\S3\S3Client;
use GuzzleHttp\TransferStats;

/**
 * Publishes one caller-chosen physical key without replacing an existing key.
 *
 * This only fences the provider request. The caller must durably register its
 * exact key before calling publish(), and must reconcile ambiguous outcomes and
 * install a permanent seal when retiring an abandoned write. No worker or S3
 * handler uses this class yet.
 */
final readonly class AwsS3CreateOnlyWriter
{
    private const int MIN_MULTIPART_THRESHOLD = 5 * 1024 * 1024;
    private const int MAX_COMPLETE_RESPONSE_BYTES = 64 * 1024;

    public function __construct(
        private S3Client $client,
        private string $bucket,
        private int $multipartThreshold = 16 * 1024 * 1024,
    ) {
        if ($bucket === '' || $multipartThreshold < self::MIN_MULTIPART_THRESHOLD) {
            throw new \InvalidArgumentException('An AWS bucket and a multipart threshold of at least 5 MiB are required.');
        }
    }

    /**
     * @param resource|string|\Psr\Http\Message\StreamInterface $body
     */
    public function publish(string $key, mixed $body): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException('A physical AWS object key is required.');
        }

        // ObjectUploader invokes before_upload for PutObject or UploadPart. Only
        // PutObject accepts If-None-Match; multipart needs the complete hook.
        // ObjectUploader adds its default ACL to PutObject and initiation.
        // Omit that header so bucket-owner-enforced backing buckets work.
        $singlePutWasIssued = false;
        $multipartCompleteWasIssued = false;
        $multipartCompletedWithExpectedXml = false;
        $result = (new ObjectUploader($this->client, $this->bucket, $key, $body, 'private', [
            'mup_threshold' => $this->multipartThreshold,
            'before_initiate' => static function (CommandInterface $command): void {
                unset($command['ACL']);
                $command['@retries'] = 0;
            },
            'before_upload' => static function (CommandInterface $command) use (&$singlePutWasIssued): void {
                $command['@retries'] = 0;
                if ($command->getName() === 'PutObject') {
                    $singlePutWasIssued = true;
                    unset($command['ACL']);
                    $command['IfNoneMatch'] = '*';
                }
            },
            'before_complete' => static function (CommandInterface $command) use (&$multipartCompleteWasIssued, &$multipartCompletedWithExpectedXml): void {
                $multipartCompleteWasIssued = true;
                $command['IfNoneMatch'] = '*';
                $command['@retries'] = 0;

                $httpOptions = $command['@http'] ?: [];
                $previousOnStats = $httpOptions['on_stats'] ?? null;
                $httpOptions['on_stats'] = static function (TransferStats $stats) use (&$multipartCompletedWithExpectedXml, $previousOnStats): void {
                    $multipartCompletedWithExpectedXml = self::hasExpectedCompleteResponse($stats);
                    if (is_callable($previousOnStats)) {
                        $previousOnStats($stats);
                    }
                };
                $command['@http'] = $httpOptions;
            },
        ]))->upload();

        // The installed SDK only probes the first 64 response bytes for an
        // embedded Complete error. A crafted Error document can contain all
        // success-shaped fields, so verify the raw XML root as well.
        if (! $result instanceof ResultInterface
            || ! is_string($result['ETag'] ?? null)
            || ! self::isExactEtag($result['ETag'])
            || ($singlePutWasIssued === $multipartCompleteWasIssued)
            || ($multipartCompleteWasIssued && (! $multipartCompletedWithExpectedXml
                || ($result['Bucket'] ?? null) !== $this->bucket
                || ($result['Key'] ?? null) !== $key))
        ) {
            throw new \RuntimeException('AWS S3 create-only upload returned an invalid completion result.');
        }
    }

    private static function isExactEtag(string $etag): bool
    {
        return $etag !== '"*"' && preg_match('/^"[^"\s,]+"$/D', $etag) === 1;
    }

    private static function hasExpectedCompleteResponse(TransferStats $stats): bool
    {
        $response = $stats->getResponse();
        if ($response === null || $response->getStatusCode() !== 200) {
            return false;
        }

        $body = $response->getBody();
        if (! $body->isSeekable()) {
            return false;
        }

        $position = $body->tell();
        try {
            $body->rewind();
            $xml = '';
            while (! $body->eof() && strlen($xml) <= self::MAX_COMPLETE_RESPONSE_BYTES) {
                $chunk = $body->read(self::MAX_COMPLETE_RESPONSE_BYTES + 1 - strlen($xml));
                if ($chunk === '') {
                    break;
                }
                $xml .= $chunk;
            }
            if (! $body->eof() || strlen($xml) > self::MAX_COMPLETE_RESPONSE_BYTES) {
                return false;
            }
        } finally {
            $body->seek($position);
        }

        $root = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        return $root !== false
            && $root->getName() === 'CompleteMultipartUploadResult'
            && $root->xpath('//*[local-name()="Error"]') === [];
    }
}
