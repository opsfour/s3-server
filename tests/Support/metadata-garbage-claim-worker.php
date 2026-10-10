<?php

declare(strict_types=1);

use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\FilesystemBackend;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (! in_array(count($_SERVER['argv']), [2, 3], true)) {
    fwrite(STDERR, "Usage: metadata-garbage-claim-worker.php SQLITE_PATH [STORAGE_PATH]\n");
    exit(2);
}

$metadata = new SqliteMetadataStore($_SERVER['argv'][1]);
$items = $metadata->dequeueStorageGarbage(1);
if (count($items) !== 1 || ! in_array($items[0]['operation'], ['abort_multipart', 'delete_path'], true)) {
    fwrite(STDERR, "Expected one queued multipart cleanup item.\n");
    exit(2);
}

if (isset($_SERVER['argv'][2])) {
    $item = $items[0];
    $storage = new FilesystemBackend($_SERVER['argv'][2]);
    if ($item['operation'] === 'delete_path') {
        $storage->deleteObjectByPath($item['storage_path'], $item['bucket']);
    } else {
        if ($item['key_name'] === null || $item['upload_id'] === null) {
            fwrite(STDERR, "Queued abort is missing its key or upload ID.\n");
            exit(2);
        }
        $storage->abortMultipartUpload(
            $item['bucket'],
            $item['key_name'],
            $item['upload_id'],
        );
    }
}

fwrite(STDOUT, (string) $items[0]['id']);
exit(86);
