<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Contracts;

use OpsFour\S3Server\Auth\Credential;

/**
 * Provides credential management for S3 request authentication.
 *
 * Implementations resolve access key IDs to their corresponding
 * secret keys and owner identities for SigV4 verification, and
 * support full CRUD operations on the credential store.
 */
interface CredentialProvider
{
    /**
     * Resolve an access key to its credential record.
     *
     * @param  string  $accessKeyId  The AWS access key ID from the request.
     * @return Credential|null The credential if found, null otherwise.
     */
    public function getCredential(string $accessKeyId): ?Credential;

    /**
     * List all credentials in the store.
     *
     * @return array<Credential> All stored credentials.
     */
    public function listCredentials(): array;

    /**
     * Store or update a credential.
     *
     * If a credential with the same accessKeyId already exists, it is replaced.
     *
     * @param  Credential  $credential  The credential to store.
     */
    public function putCredential(Credential $credential): void;

    /**
     * Remove a credential from the store.
     *
     * @param  string  $accessKeyId  The access key ID of the credential to remove.
     */
    public function deleteCredential(string $accessKeyId): void;
}
