# Development

## Prerequisites

- 64-bit PHP 8.4+ with extensions: mbstring, openssl, pdo_sqlite, pcntl, posix
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

Before a release, run the complete quality gate:

```bash
composer quality
composer validate --strict
composer audit --locked
git diff --check
```

Tests that need an external database, object-storage provider, destructive
migration fixture, long soak, or other opt-in environment may skip in the
default local suite. A skip is acceptable only when that profile is outside the
current run. When a release advertises the profile, configure its dependencies
and treat every required skip as a failure. Review the skip list rather than
relying on a fixed expected count.

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
├── Checksum/             # MD5, CRC32, CRC32C, CRC64NVME, SHA-1, SHA-256 utilities
├── Console/              # Standalone CLI (Symfony Console)
├── Contracts/            # CredentialProvider interface
├── Dto/                  # BucketInfo, ObjectInfo, ListObjectsResult
├── Encryption/           # SSE-S3, SSE-C, master key providers
├── Event/                # Event objects
├── Exception/            # S3Exception hierarchy (maps to HTTP status codes)
├── Factory/              # MetadataStore, Storage, Credential factories
├── Handler/              # 69 routed S3 operations
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

## Release Profiles

The default suite is the universal starting point. Database, framework,
storage, proxy, load, and topology profiles add gates only when the release
advertises them. Do not copy test counts or timings into maintained docs;
record commit-specific evidence with the release artifacts. See the
[release checklist](release-checklist.md).

Run the framework entry-point and SSE-C server journeys directly:

```bash
vendor/bin/phpunit --fail-on-skipped \
  tests/Functional/EntrypointServeProfileTest.php \
  tests/Functional/LifecycleRunnerFunctionalTest.php \
  tests/Functional/SseCustomerEncryptionFunctionalTest.php
```

The local single-process reverse-proxy gate uses Apache `mod_proxy_http` with
filesystem storage and SQLite. It requires `httpd` or `apache2`, `curl`,
`pdo_sqlite`, and the Apache prefork, unixd, authz_core, headers, proxy, and
proxy_http modules. Override nonstandard installations with
`S3_TEST_HTTPD_BIN` and `S3_TEST_HTTPD_MODULE_DIR`.

```bash
scripts/run-local-proxy-gate
```

### Local Production Soak

The release soak runs on Linux with one API process and a bind-mounted local
filesystem. Install the locked Composer dependencies first. Build the soak
image once for the current Dockerfile, or load that image from a trusted cache;
the runner never builds or pulls it. Its default tag contains the Dockerfile
SHA-256:

```bash
dockerfile_sha=$(sha256sum docker/production-soak/Dockerfile | awk '{print $1}')
docker build \
  --file docker/production-soak/Dockerfile \
  --tag "opsfour-s3-production-soak:$dockerfile_sha" \
  docker/production-soak
```

Then choose a writable state directory on the exact local filesystem being
qualified. SQLite is the default metadata backend:

```bash
composer install
export S3_SOAK_STATE_DIR=/var/tmp/opsfour-s3-soak
scripts/run-production-soak start
scripts/run-production-soak status
scripts/run-production-soak cleanup
```

Select one of the external metadata profiles when that is the intended release
environment:

```bash
S3_SOAK_METADATA_DRIVER=postgres \
S3_SOAK_STATE_DIR=/var/tmp/opsfour-s3-soak-postgres \
scripts/run-production-soak start

S3_SOAK_METADATA_DRIVER=mysql \
S3_SOAK_STATE_DIR=/var/tmp/opsfour-s3-soak-mysql \
scripts/run-production-soak start
```

The default duration is 43,200 seconds. A failed, timed-out, OOM-killed, or
manually stopped run is not release evidence. `stop` records an abort and keeps
the local data and diagnostics. `cleanup` removes the containers, disposable
database volumes, and test object data while retaining the result and logs in
the state directory. Run the commands as a non-root user with Docker access and
use a new, empty, canonical state directory outside the checkout for every run.
A shorter configured duration is marked `qualification=smoke`; release evidence
requires `qualification=release`, `status=passed`, at least 43,200 requested and
elapsed seconds, `source=unchanged`, exit status zero, and `oom=false` from
`status`. Relative memory growth is measured from the server process-tree RSS
after 25 warm-up batches; override that warm-up only with
`S3_SOAK_WARMUP_BATCHES`. The container cgroup peak remains an absolute safety
limit. The bounded private `memory.tsv` evidence records the configured warm-up
and identifies its baseline row. It separates server RSS from cgroup current,
peak, anonymous, file, shared, kernel, and slab memory. Current and `memory.stat`
fields describe each sample instant; the peak remains the lifetime high-water
mark and is not attributed to that instant's categories. If the bounded series
rotates, it keeps the recent tail and writes a `memory-series-rotated` marker.
A short smoke may finish before its configured warm-up and then does not
qualify relative RSS growth; a release run fails in that state. The soak has no
remote object-storage dependency and does not qualify Flysystem, shared
filesystems, multiple processes, or multiple hosts.
