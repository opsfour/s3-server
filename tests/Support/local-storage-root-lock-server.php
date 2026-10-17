<?php

declare(strict_types=1);

use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\FilesystemBackend;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = $_SERVER['argv'][1] ?? '';
$port = filter_var($_SERVER['argv'][2] ?? null, FILTER_VALIDATE_INT);
if ($root === '' || $port === false) {
    fwrite(STDERR, "Invalid worker arguments.\n");
    exit(2);
}

$metadata = new SqliteMetadataStore(':memory:');
$metadata->initialize();
$server = new S3Server(
    new S3ServerConfig(
        host: '127.0.0.1',
        port: $port,
        storagePath: $root,
        notificationWorkerEnabled: false,
    ),
    $metadata,
    new FilesystemBackend($root),
);
$server->addMiddleware(new AuthMiddleware(
    new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
    'us-east-1',
));

try {
    $server->start();
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    fgets(STDIN);
    $server->stop();
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
    exit(1);
}
