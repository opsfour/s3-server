<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Contracts;

use Amp\Http\Server\Middleware;

/**
 * Marker contract for middleware that authenticates S3 requests.
 */
interface AuthenticationMiddleware extends Middleware {}
