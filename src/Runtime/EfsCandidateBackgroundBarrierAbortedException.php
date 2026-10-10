<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** @internal Controlled terminal result for an aborted EFS background barrier. */
final class EfsCandidateBackgroundBarrierAbortedException extends \RuntimeException {}
