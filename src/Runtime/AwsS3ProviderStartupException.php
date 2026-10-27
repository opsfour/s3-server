<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use OpsFour\S3Server\Storage\AwsS3ProviderAdmission;

/** Package-owned, secret-free startup diagnostic for the explicit AWS profile. */
final class AwsS3ProviderStartupException extends \RuntimeException
{
    public function __construct(public readonly AwsS3ProviderAdmission $admission)
    {
        parent::__construct(self::messageFor($admission));
    }

    public function safeMessage(): string
    {
        return self::messageFor($this->admission);
    }

    private static function messageFor(AwsS3ProviderAdmission $admission): string
    {
        if (! $admission->providerAdmitted) {
            return sprintf(
                'Refusing to start: aws-s3 provider admission was denied (%s).',
                $admission->reason,
            );
        }

        return 'Refusing to start: the admitted aws-s3 provider writer fence is incomplete and the profile is not production-qualified.';
    }
}
