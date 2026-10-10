<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** An internally qualified startup identity; this does not admit EFS serving. */
final readonly class EfsStartupIdentity
{
    public function __construct(
        public EfsQualificationContext $context,
        public EfsNodeLocalScratch $scratch,
    ) {}
}
