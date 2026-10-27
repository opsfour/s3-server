<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

interface AwsS3ProviderAdmissionCapability
{
    public function awsS3ProviderAdmission(): AwsS3ProviderAdmission;
}
