<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

/** Outcome of deleting the exact credential identity observed by the caller. */
enum CredentialDeletionResult
{
    case Deleted;
    case NotFound;
    case Changed;
}
