<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Executes one bounded, read-only revalidation of an immutable AWS profile. */
interface AwsS3ProviderProbe
{
    public function revalidate(AwsS3ProviderProfile $profile): AwsS3ProviderAdmission;
}
