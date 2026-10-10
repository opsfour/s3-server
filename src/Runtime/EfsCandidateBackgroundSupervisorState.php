<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** @internal Monotone lifecycle of the listenerless EFS background supervisor. */
enum EfsCandidateBackgroundSupervisorState: string
{
    case PREPARED = 'prepared';
    case STARTING = 'starting';
    case READY = 'ready';
    case RELEASED = 'released';
    case FAILED = 'failed';
    case STOPPING = 'stopping';
    case STOPPED = 'stopped';
}
