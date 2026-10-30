<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Identifies database-global object-intent retirement or claim failures. */
final class AwsS3ObjectIntentMetadataException extends \RuntimeException {}
