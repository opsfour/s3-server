# Development

## Prerequisites

- PHP 8.4+ with extensions: openssl, pdo_sqlite, pcntl
- Composer 2.x

## Setup

```bash
git clone https://github.com/opsfour/s3-server.git
cd s3-server/packages/s3-server
composer install
```

## Running Tests

```bash
# Full test suite
vendor/bin/phpunit

# With testdox output
vendor/bin/phpunit --testdox

# Specific test file
vendor/bin/phpunit tests/Unit/Parallel/ParallelEncryptionServiceTest.php

# Specific test method
vendor/bin/phpunit --filter test_small_payload_runs_inline_sse_s3
```

### Test Structure

```
tests/
├── Unit/                         # Fast, isolated tests
│   ├── Acl/                      # ACL evaluator tests
│   ├── Auth/                     # Signing key, canonical request tests
│   ├── Exception/                # S3 exception tests
│   ├── Parallel/                 # Worker pool tests (encryption, SQLite, S3 Select)
│   ├── Routing/                  # Operation resolver tests
│   └── Xml/                      # XML parsing and building tests
└── Functional/                   # End-to-end tests against a running server
    ├── S3FunctionalTestCase.php  # Base class (starts/stops server process)
    ├── ObjectCrudTest.php        # PUT, GET, HEAD, DELETE objects
    ├── VersioningTest.php        # Versioning, object lock, retention
    ├── MultipartUploadTest.php   # Multipart upload lifecycle
    ├── AclPolicyTaggingCorsTest.php
    ├── EncryptionLifecycleNotificationTest.php
    ├── WebsiteHostingTest.php
    ├── ChecksumValidationTest.php
    ├── ConditionalRequestsTest.php
    └── ...
```

### Functional Tests

Functional tests start a real S3 server process, connect to it with the AWS SDK, and exercise the full request path. The base class `S3FunctionalTestCase`:

1. Creates a temp storage directory
2. Finds a free port
3. Starts `bin/s3-server` as a background process
4. Waits for the server to accept connections
5. Creates an `S3Client` pointed at the server
6. After tests: kills the process, cleans up storage

## Starting a Dev Server

```bash
php bin/s3-server \
  --storage-path=/tmp/s3-dev \
  --access-key=dev \
  --secret-key=dev \
  --port=9000
```

## Project Structure

```
src/
├── Acl/                  # ACL types, evaluator, parser
├── Auth/                 # SigV4 verifier, presigned URLs, chunked sigs
├── Checksum/             # MD5, CRC32, CRC32C, SHA-1, SHA-256 utilities
├── Console/              # Standalone CLI (Symfony Console)
├── Contracts/            # CredentialProvider interface
├── Dto/                  # BucketInfo, ObjectInfo, ListObjectsResult
├── Encryption/           # SSE-S3, SSE-C, master key providers
├── Event/                # Event objects
├── Exception/            # S3Exception hierarchy (maps to HTTP status codes)
├── Factory/              # MetadataStore, Storage, Credential factories
├── Handler/              # 66 S3 operation handlers
├── Http/                 # HTTP utilities
├── Laravel/              # Service provider and Artisan commands
├── Lifecycle/            # Lifecycle rule evaluation and execution
├── Logging/              # Access log writer
├── Metadata/             # SQLite, Postgres, MySQL backends + schema
├── Middleware/            # Request processing pipeline
├── Notification/         # Dispatcher (enqueue) + Processor (deliver)
├── ObjectLock/           # Retention and legal hold logic
├── Parallel/             # Amp worker pool tasks
├── Policy/               # IAM policy evaluator
├── Routing/              # Router, S3 operation resolver, handler registry
├── Select/               # S3 Select SQL parser, evaluator, CSV/JSON processors
├── Storage/              # Filesystem, Flysystem, in-memory backends
├── Symfony/              # Bundle, DI extension, console commands, service adapters
├── Runtime/              # Shared runtime factory used by standalone, Laravel, Symfony
├── Xml/                  # XML request parsing and response building
├── S3Server.php          # Main server class
└── S3ServerConfig.php    # Configuration DTO with validation
```

## Adding a New Handler

1. Create a handler class in `src/Handler/`:

```php
namespace OpsFour\S3Server\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

final class MyCustomHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
    ) {}

    public function handle(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');

        // Your logic here

        return new Response(200, ['Content-Type' => 'application/xml'], $body);
    }
}
```

2. Register it in `HandlerRegistrar::registerAll()` or programmatically:

```php
$server->getHandlerRegistry()->register('MyCustomOperation', $handler->handle(...));
```

3. If the operation needs custom routing (not matched by existing query parameters), extend `OperationResolver`.

## Adding Custom Middleware

```php
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

final class RequestLoggingMiddleware implements Middleware
{
    public function handleRequest(Request $request, RequestHandler $handler): Response
    {
        $start = hrtime(true);
        $response = $handler->handleRequest($request);
        $duration = (hrtime(true) - $start) / 1e6;

        // Log the request duration
        error_log(sprintf('%s %s — %.1fms', $request->getMethod(), $request->getUri(), $duration));

        return $response;
    }
}

// Register before start()
$server->addMiddleware(new RequestLoggingMiddleware());
```

Custom middleware is inserted between the built-in middleware and the policy/ACL enforcement layer.

## Code Style

- PHP 8.4 features: property hooks, asymmetric visibility, typed class constants
- `declare(strict_types=1)` in every file
- `final` classes by default
- Constructor promotion for DTOs
- No abbreviations in variable/method names
- Exception-driven error handling (S3Exception subclasses)

## Static Analysis

```bash
vendor/bin/phpstan analyse
```

PHPStan level 8 covers both `src/` and `tests/`. CI also installs the lowest
and highest allowed Composer dependency sets: Laravel 12 with Symfony 7.4 LTS,
and Laravel 13 with Symfony 8.1 or newer.

## Current Test Baseline

```
745 tests, 3287 assertions, 9 skipped, 0 failures
```
