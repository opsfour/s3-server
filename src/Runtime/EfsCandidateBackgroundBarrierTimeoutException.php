<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** @internal Distinguishes missing startup acknowledgements from an explicit abort. */
final class EfsCandidateBackgroundBarrierTimeoutException extends \RuntimeException {}
