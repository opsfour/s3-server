<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** @internal Monotone lifecycle of the still-inert production EFS serving gate. */
enum EfsCandidateServingAdmissionState: string
{
    case PREPARED = 'prepared';
    case ACTIVATING = 'activating';
    case ACTIVE = 'active';
    case REVOKED = 'revoked';
}
