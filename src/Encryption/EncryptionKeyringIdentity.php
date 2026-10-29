<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

final class EncryptionKeyringIdentity
{
    /** @param array<string, string> $keys */
    public static function create(string $provider, string $activeKeyId, array $keys): string
    {
        ksort($keys, SORT_STRING);
        $hash = hash_init('sha256');
        hash_update($hash, "opsfour-s3-cluster-keyring-v1\0{$provider}\0{$activeKeyId}\0");
        foreach ($keys as $keyId => $key) {
            hash_update($hash, pack('N', strlen($keyId)) . $keyId);
            hash_update($hash, hash('sha256', $key, true));
        }

        return "{$provider}:" . hash_final($hash);
    }
}
