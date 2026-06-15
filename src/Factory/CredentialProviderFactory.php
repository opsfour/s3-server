<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Factory;

use OpsFour\S3Server\Auth\ChainCredentialProvider;
use OpsFour\S3Server\Auth\ConfigFileCredentialProvider;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\DatabaseCredentialProvider;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Contracts\CredentialProvider;

/**
 * Creates CredentialProvider instances from configuration.
 *
 * No hardcoded defaults — the caller must provide all required values.
 */
final class CredentialProviderFactory
{
    /**
     * @param  string  $driver  One of: memory, database, file, chain.
     * @param  array<string, mixed>  $config  Driver-specific configuration.
     */
    public static function create(string $driver, array $config = []): CredentialProvider
    {
        return match ($driver) {
            'memory' => self::createInMemory($config),
            'database' => self::createDatabase($config),
            'file' => new ConfigFileCredentialProvider(
                $config['path'] ?? throw new \InvalidArgumentException('File credentials requires "path"'),
            ),
            'chain' => self::createChain($config),
            default => throw new \InvalidArgumentException("Unknown credentials driver: {$driver}"),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function createInMemory(array $config): InMemoryCredentialProvider
    {
        $required = ['access_key', 'secret_key', 'owner_id', 'display_name'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException("Memory credentials requires \"{$key}\"");
            }
        }

        return new InMemoryCredentialProvider(
            new Credential(
                accessKeyId: $config['access_key'],
                secretAccessKey: $config['secret_key'],
                ownerId: $config['owner_id'],
                displayName: $config['display_name'],
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function createDatabase(array $config): DatabaseCredentialProvider
    {
        return DatabaseCredentialProvider::fromDsn(
            $config['dsn'] ?? throw new \InvalidArgumentException('Database credentials requires "dsn"'),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function createChain(array $config): ChainCredentialProvider
    {
        if (! isset($config['providers']) || ! is_array($config['providers']) || count($config['providers']) === 0) {
            throw new \InvalidArgumentException('Chain credentials requires a non-empty "providers" array');
        }

        /** @var list<CredentialProvider> $providers */
        $providers = $config['providers'];

        return new ChainCredentialProvider(...$providers);
    }
}
