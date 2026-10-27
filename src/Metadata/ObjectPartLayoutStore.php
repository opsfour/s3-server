<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use OpsFour\S3Server\Dto\ObjectInfo;

/** Explicit capability required by every serving metadata path. */
interface ObjectPartLayoutStore
{
    public function supportsObjectPartLayouts(): bool;

    /** May assemble a newly created incarnation only in its publication transaction; committed layouts are immutable. */
    public function putObjectPartLayout(string $bucket, string $key, string $versionId, ObjectPartLayout $layout): void;

    /** Reads and verifies the immutable child layout against the snapshot's parent digest. */
    public function getObjectPartLayout(ObjectInfo $object): ObjectPartLayout;
}
