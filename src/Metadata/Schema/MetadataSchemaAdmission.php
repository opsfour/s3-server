<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata\Schema;

/** Read-only exact-version admission using an already injected metadata connection. */
interface MetadataSchemaAdmission
{
    public function assertCurrentSchema(): void;
}
