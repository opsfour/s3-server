<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Aws\S3\S3Client;

/** The exact client and profile that passed the provider admission probes. */
final readonly class AwsS3ProviderValidatedBinding
{
    private function __construct(
        public AwsS3ProviderAdmission $admission,
        public ?AwsS3ProviderProfile $profile,
        public ?S3Client $client,
    ) {}

    /** @param array<string, mixed> $configuration */
    public static function fromConfiguration(array $configuration, AwsS3ProviderValidator $validator): self
    {
        try {
            $profile = AwsS3ProviderProfile::fromConfig($configuration);
        } catch (\InvalidArgumentException) {
            return new self($validator->validate($configuration), null, null);
        }
        $client = null;
        $admission = $validator->validate($profile, static function (AwsS3ProviderProfile $profile) use ($validator, &$client): S3Client {
            $client = $validator->createClient($profile);
            return $client;
        });
        return new self(
            $admission,
            $admission->providerAdmitted ? $profile : null,
            $admission->providerAdmitted ? $client : null,
        );
    }

    /** Re-run bounded read-only probes with the exact admitted profile and client. */
    public function revalidate(AwsS3ProviderValidator $validator): AwsS3ProviderAdmission
    {
        $profile = $this->profile;
        $client = $this->client;
        if ($profile === null || $client === null) {
            return $this->admission;
        }

        return $validator->validate($profile, static fn(AwsS3ProviderProfile $candidate): S3Client
            => $candidate === $profile
                ? $client
                : throw new \LogicException('AWS revalidation profile identity changed.'));
    }
}
