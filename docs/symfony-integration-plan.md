# Symfony Integration Plan

This document tracks the Symfony Bundle work for OpsFour S3 Server. The
production integration is implemented; the remaining items are optional
ecosystem conveniences.

## Goals

- Provide a production-ready Symfony Bundle for running the S3 server inside a
  Symfony application.
- Keep runtime behavior identical across standalone, Laravel, and Symfony
  integrations.
- Support the same production features exposed by the core server:
  authentication, quotas, lifecycle, notifications, tiering, restore,
  encryption, metrics, and metadata/storage backends.
- Make configuration predictable through standard Symfony config files and
  environment variables.
- Add automated tests that prove the Symfony container compiles and the server
  can be started through Symfony commands.

## Non-Goals

- Implement the AWS STS Query API as part of the Symfony integration.
- Replace the existing standalone or Laravel integrations.
- Require Symfony for non-Symfony users of this package.
- Introduce Symfony-specific behavior that changes the core S3 compatibility
  surface.

## Release Target

- Target version: `v1.1.0`
- Current baseline: `v1.0.0`
- Status: implemented and covered by tests.

## Completed Work

### Shared Runtime Layer

- [x] Extract shared server bootstrapping into `S3ServerRuntimeFactory`.
- [x] Move common wiring for `S3Server`, middleware, handler registration,
  admin APIs, lifecycle, notifications, quotas, encryption, metrics, and tier
  registry into the runtime layer.
- [x] Keep framework-specific code limited to configuration loading, dependency
  injection, console command integration, logging, and signal handling.
- [x] Refactor standalone and Laravel entry points onto the shared runtime.
- [x] Preserve existing standalone and Laravel behavior.

### Symfony Bundle Skeleton

- [x] Add `OpsFour\S3Server\Symfony\S3ServerBundle`.
- [x] Add `OpsFour\S3Server\Symfony\DependencyInjection\S3ServerExtension`.
- [x] Add `OpsFour\S3Server\Symfony\DependencyInjection\Configuration`.
- [x] Register services through Symfony DI definitions.
- [x] Keep Symfony dependencies optional for non-Symfony installations.
- [x] Add Composer `suggest` and `require-dev` entries for Symfony usage and
  test coverage.

### Symfony Configuration

- [x] Add `opsfour_s3_server` configuration namespace.
- [x] Support server settings: host, port, region, base domain, TLS,
  connection limits, body size limits, timeouts, rate limit, strict bucket
  naming, website hosting, encryption/select limits, and graceful shutdown.
- [x] Support storage settings: filesystem, Flysystem, memory, service-backed
  Flysystem, temp dirs, physical tiers, and hot/cold restore semantics.
- [x] Support metadata settings: SQLite, PostgreSQL, MySQL, DSN/path, and
  metadata cache TTL.
- [x] Support credential settings: memory, file, and database providers.
- [x] Support quota settings and metadata-backed per-account overrides.
- [x] Support lifecycle runner settings: interval, batch size, action budget,
  and metadata-backed lease TTL.
- [x] Support notifications through Symfony service listeners and
  EventDispatcher bridge. Webhook destinations remain configured through the
  S3 `PutBucketNotification` API, matching the core server behavior.
- [x] Support encryption through default env-based config, custom encryption
  services, master key provider services, and optional parallel encryption.
- [x] Keep environment variable names aligned with standalone and Laravel where
  possible.

### Symfony Console Commands

- [x] Add `opsfour:s3:serve`.
- [x] Add `opsfour:s3:credentials`.
- [x] Add `opsfour:s3:quotas`.
- [x] Wire console commands through Symfony services.
- [x] Support safe runtime overrides for host, port, admin token, credentials,
  and quotas.
- [x] Add signal handling for `SIGINT` and `SIGTERM`.
- [x] Reuse the shared runtime factory.

### Symfony Service Wiring

- [x] Register `S3ServerConfig`.
- [x] Register `StorageBackend`.
- [x] Register `StorageTierRegistry`.
- [x] Register `MetadataStore`.
- [x] Register `CredentialProvider`.
- [x] Register `MetricsCollector`.
- [x] Register lifecycle runtime wiring.
- [x] Register quota manager/admin wiring through the shared runtime and quota
  console command.
- [x] Register notification listener adapters.
- [x] Register encryption services.
- [x] Register shared runtime factory.
- [x] Allow advanced users to override Flysystem storage, notification
  listeners, EventDispatcher bridge, encryption service, and master key
  provider through Symfony DI.

### Documentation

- [x] Add [Symfony Integration](symfony-integration.md).
- [x] Document installation, bundle registration, minimal YAML config, and
  production YAML config.
- [x] Document environment variable mapping.
- [x] Document `bin/console opsfour:s3:serve`.
- [x] Document credentials and quota commands.
- [x] Document AWS SDK client configuration against the Symfony-hosted server.
- [x] Document Supervisor and systemd examples.
- [x] Document PostgreSQL/MySQL metadata setup.
- [x] Document remote Flysystem storage backend setup.
- [x] Document quotas, lifecycle, tiering, restore, notifications, encryption,
  and metrics in Symfony context.

### Test Coverage

- [x] Symfony bundle extension exposure test.
- [x] Container compile and core service resolution test.
- [x] Config default and override tests.
- [x] Invalid config test.
- [x] Service wiring coverage for storage, metadata, credentials, quotas,
  notifications, encryption, metrics, tier registry, and runtime factory.
- [x] Console command registration tests.
- [x] Credential and quota command tests.
- [x] Flysystem service reference tests for default storage and tiered storage.
- [x] Symfony notification listener and EventDispatcher bridge tests.
- [x] Symfony encryption service and master key provider override tests.
- [x] Functional smoke test proving the Symfony serve command starts and stops
  with `SIGTERM`.
- [x] Runtime factory tests proving the shared handler registration includes
  production operations such as `RestoreObject`.
- [x] Full PHPUnit suite remains green after the runtime refactor.

## Production Verification

- [x] Full PHPUnit suite: `745 tests, 3288 assertions, 9 skipped`.
- [x] Composer metadata validation passes.
- [x] Targeted Symfony unit and functional tests pass.
- [x] Existing AWS SDK functional tests continue to exercise the shared runtime
  behavior.
- [x] Reliability profiles cover large objects, multipart uploads, concurrent
  requests, lifecycle, remote storage, and long-running responsiveness.

## Optional Follow-Ups

- [ ] Add a Symfony Flex recipe for automatic bundle registration and example
  config.
- [ ] Add additional convenience commands only if operators need them.
- [ ] Add framework-specific adapters for optional broker destinations if
  real-world Symfony deployments benefit from config-driven Redis/Kafka/AMQP/NATS
  wiring.
- [ ] Add an optional Symfony-hosted AWS SDK smoke test that starts through a
  full application kernel instead of the minimal test container.

## Decisions

- [x] Command naming uses `opsfour:s3:serve`, `opsfour:s3:credentials`, and
  `opsfour:s3:quotas` to avoid collisions with application commands.
- [x] AWS STS Query API compatibility stays optional and outside this Symfony
  integration scope.
- [x] Webhook notification destinations remain configured through the S3 bucket
  notification API; Symfony config is used for in-process application listeners.
