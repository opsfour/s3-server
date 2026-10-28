<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Provider state contradicts the immutable evidence recorded before dispatch. */
final class AwsS3SealIntegrityException extends \RuntimeException {}
