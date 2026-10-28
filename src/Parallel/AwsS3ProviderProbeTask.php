<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmission;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;

/** @implements Task<AwsS3ProviderAdmission, never, never> */
final readonly class AwsS3ProviderProbeTask implements Task
{
    public function __construct(private AwsS3ProviderProfile $profile) {}

    public function run(Channel $channel, Cancellation $cancellation): AwsS3ProviderAdmission
    {
        $cancellation->throwIfRequested();

        // The worker deliberately reconstructs the SDK client through the
        // default credential chain. No credential or live client is copied
        // from the API process.
        return (new AwsS3ProviderValidator())->validate($this->profile);
    }
}
