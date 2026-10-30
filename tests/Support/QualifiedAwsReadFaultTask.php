<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Aws\S3\S3Client;
use OpsFour\S3Server\Parallel\AwsS3PayloadReadTask;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptPath;

/** @implements Task<null, string, string|null> */
final readonly class QualifiedAwsReadFaultTask implements Task
{
    public const string BEFORE_ATTEMPT = 'dddddddddddddddddddddddddddddddd';
    public const string MIDSTREAM_ATTEMPT = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
    public const string FAST_ATTEMPT = 'ffffffffffffffffffffffffffffffff';

    private function __construct(
        private AwsS3ProviderProfile $profile,
        private string $endpoint,
        private string $path,
        private int $size,
    ) {}

    public static function create(
        AwsS3ProviderProfile $profile,
        string $endpoint,
        string $attempt,
    ): self {
        $size = match ($attempt) {
            self::BEFORE_ATTEMPT => strlen('before-body'),
            self::MIDSTREAM_ATTEMPT => 131_072,
            self::FAST_ATTEMPT => strlen('fast-body'),
            default => throw new \InvalidArgumentException('Unknown qualified AWS fault attempt.'),
        };

        return new self(
            $profile,
            $endpoint,
            AwsS3WriteAttemptPath::forProfileObject(
                $profile,
                'bucket',
                'key',
                str_repeat('a', 32),
                'STANDARD',
                $attempt,
            ),
            $size,
        );
    }

    public function run(Channel $channel, Cancellation $cancellation): null
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => $this->profile->region,
            'endpoint' => 'http://' . $this->endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'local-key', 'secret' => 'local-secret'],
            'retries' => 0,
            'http' => ['connect_timeout' => 1, 'read_timeout' => 1, 'timeout' => 1],
        ]);
        $task = new AwsS3PayloadReadTask(
            $this->profile,
            AwsS3PayloadReadTask::COMMITTED,
            'bucket',
            str_repeat('a', 32),
            'key',
            $this->path,
            'STANDARD',
            'owner',
            $this->size,
            $this->size,
        );

        return $task->runWithClient($client, $channel, $cancellation);
    }
}
