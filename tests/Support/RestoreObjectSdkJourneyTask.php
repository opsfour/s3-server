<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Aws\S3\S3Client;

/**
 * Run the synchronous AWS SDK in a worker so the in-process Amp server and
 * restore worker keep receiving event-loop time in the parent process.
 *
 * @implements Task<array{
 *     restoreStatus: int,
 *     renewedRestoreStatus: int,
 *     headStatus: int,
 *     getStatus: int,
 *     headRestore: string,
 *     getRestore: string,
 *     body: string,
 *     storageClass: string
 * }, never, never>
 */
final readonly class RestoreObjectSdkJourneyTask implements Task
{
    public function __construct(
        private string $endpoint,
        private string $accessKey,
        private string $secretKey,
        private string $bucket,
        private string $key,
    ) {}

    /**
     * @return array{
     *     restoreStatus: int,
     *     renewedRestoreStatus: int,
     *     headStatus: int,
     *     getStatus: int,
     *     headRestore: string,
     *     getRestore: string,
     *     body: string,
     *     storageClass: string
     * }
     */
    public function run(Channel $channel, Cancellation $cancellation): array
    {
        $cancellation->throwIfRequested();
        $s3 = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $this->accessKey, 'secret' => $this->secretKey],
            'retries' => 0,
            'http' => ['connect_timeout' => 2, 'timeout' => 10],
        ]);

        $restore = $s3->restoreObject([
            'Bucket' => $this->bucket,
            'Key' => $this->key,
            'RestoreRequest' => ['Days' => 1],
        ]);

        $deadline = microtime(true) + 10.0;
        do {
            $cancellation->throwIfRequested();
            $head = $s3->headObject(['Bucket' => $this->bucket, 'Key' => $this->key]);
            $headRestore = (string) ($head['Restore'] ?? '');
            if (str_contains($headRestore, 'ongoing-request="false"')) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        if (! str_contains($headRestore, 'ongoing-request="false"')) {
            throw new \RuntimeException("Restore did not complete before the local test deadline: {$headRestore}");
        }

        $renewedRestore = $s3->restoreObject([
            'Bucket' => $this->bucket,
            'Key' => $this->key,
            'RestoreRequest' => ['Days' => 2],
        ]);
        $head = $s3->headObject(['Bucket' => $this->bucket, 'Key' => $this->key]);
        $headRestore = (string) ($head['Restore'] ?? '');
        $get = $s3->getObject(['Bucket' => $this->bucket, 'Key' => $this->key]);

        return [
            'restoreStatus' => (int) $restore['@metadata']['statusCode'],
            'renewedRestoreStatus' => (int) $renewedRestore['@metadata']['statusCode'],
            'headStatus' => (int) $head['@metadata']['statusCode'],
            'getStatus' => (int) $get['@metadata']['statusCode'],
            'headRestore' => $headRestore,
            'getRestore' => (string) ($get['Restore'] ?? ''),
            'body' => (string) $get['Body'],
            'storageClass' => (string) ($head['StorageClass'] ?? ''),
        ];
    }
}
