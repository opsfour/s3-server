<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** @internal Monotone lifecycle of the inert EFS background startup barrier. */
enum EfsCandidateBackgroundBarrierState: string
{
    case PREPARED = 'prepared';
    case READY = 'ready';
    case RELEASED = 'released';
    case ABORTED = 'aborted';
}
