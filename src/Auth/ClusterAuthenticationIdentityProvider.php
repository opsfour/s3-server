<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

/** Secret-free identity of the authentication and owner-mapping contract. */
interface ClusterAuthenticationIdentityProvider
{
    public function clusterAuthenticationIdentity(): string;
}
