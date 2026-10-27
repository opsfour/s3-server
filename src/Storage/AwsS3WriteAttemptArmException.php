<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Safe package-owned failure before an AWS attempt can be dispatched. */
final class AwsS3WriteAttemptArmException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('AWS write attempt could not be durably armed.');
    }
}
