<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Notification;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as HttpRequest;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\StaticSocketConnector;
use League\Uri\BaseUri;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\FencedQueueStore;
use OpsFour\S3Server\Metadata\ClusterWorkFencingStore;
use OpsFour\S3Server\Metadata\ClusterWorkToken;
use OpsFour\S3Server\Metadata\QueueLeaseHeartbeat;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\ClusterAdmissionController;
use OpsFour\S3Server\Runtime\ClusterRoleController;
use OpsFour\S3Server\Runtime\ClusterRoleSet;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Background processor that delivers queued notifications with retry and dead-letter.
 *
 * Polls the notification queue, delivers webhooks with SSRF protection,
 * and applies exponential backoff on failures.
 */
final class NotificationProcessor
{
    private bool $running = false;

    private bool $stopping = false;

    private const int MAX_REDIRECTS = 3;

    private readonly ?HttpClient $httpClient;

    private ?DeferredCancellation $deferredCancellation = null;

    /** @var Future<void>|null */
    private ?Future $loopFuture = null;

    /** @var array<string, array{failures: int, cooldownUntil: float}> Per-destination circuit breakers. */
    private array $circuitBreakers = [];

    private const int CIRCUIT_BREAKER_THRESHOLD = 5;

    private const float CIRCUIT_BREAKER_COOLDOWN = 60.0;

    private const int MAX_CIRCUIT_BREAKERS = 10_000;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly MetadataStore $metadata,
        LoggerInterface $logger = new NullLogger(),
        private readonly float $pollInterval = 2.0,
        ?HttpClient $httpClient = null,
        private readonly ?MetricsCollector $metrics = null,
        private readonly bool $requireHttps = true,
        private readonly ?ClusterAdmissionController $clusterAdmission = null,
        private readonly ?ClusterWorkFencingStore $clusterFencing = null,
        private readonly ?string $clusterWorkerId = null,
        private readonly ?ClusterRoleController $clusterRoles = null,
    ) {
        if (($clusterFencing === null) !== ($clusterWorkerId === null)) {
            throw new \InvalidArgumentException('Cluster notification fencing requires both store and worker identity.');
        }
        $this->httpClient = $httpClient;
        $this->logger = new FailureIsolatingLogger($logger);
    }

    public function start(): void
    {
        if ($this->stopping) {
            throw new \LogicException('Notification processor cannot start before its previous loop has stopped.');
        }
        if ($this->running) {
            if ($this->loopFuture !== null && ! $this->loopFuture->isComplete()) {
                return;
            }
            $this->running = false;
            $this->loopFuture = null;
            $this->deferredCancellation = null;
        }

        $this->running = true;
        $this->deferredCancellation = new DeferredCancellation();
        $this->loopFuture = \Amp\async(
            $this->loop(...),
            $this->deferredCancellation->getCancellation(),
        );
        $this->loopFuture->ignore();
    }

    public function isRunning(): bool
    {
        return $this->running && ! $this->stopping
            && $this->loopFuture !== null && ! $this->loopFuture->isComplete();
    }

    public function stop(float $timeoutSeconds = 10): void
    {
        if ($this->stopping) {
            throw new \LogicException('Notification processor stop is already in progress.');
        }
        $this->stopping = true;
        $this->running = false;
        $loopFuture = $this->loopFuture;
        $deferredCancellation = $this->deferredCancellation;

        try {
            $deferredCancellation?->cancel();
            $loopFuture?->await(new \Amp\TimeoutCancellation(max(0.001, $timeoutSeconds)));
        } catch (\Amp\CancelledException) {
            $this->logger->warning('Notification processor did not stop within {timeout}s; waiting before dependency shutdown.', $this->logContext([
                'event' => 'processor_stop_timeout',
                'timeout' => $timeoutSeconds,
            ]));

            try {
                $loopFuture?->await();
            } catch (\Throwable $e) {
                $this->logger->error('Notification processor did not stop cleanly.', $this->logContext([
                    'event' => 'processor_stop_failed',
                    'exception' => $e::class,
                ]));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Notification processor did not stop cleanly.', $this->logContext([
                'event' => 'processor_stop_failed',
                'exception' => $e::class,
            ]));
        } finally {
            if ($loopFuture === null || $loopFuture->isComplete()) {
                $this->loopFuture = null;
                $this->deferredCancellation = null;
                $this->stopping = false;
            }
        }
    }

    private function loop(Cancellation $cancellation): void
    {
        $idleCount = 0;

        try {
            while ($this->running) {
                $cancellation->throwIfRequested();
                if (! ($this->clusterAdmission?->admitNewClaim($cancellation) ?? true)) {
                    $this->clusterRoles?->withdraw();
                    \Amp\delay($this->pollInterval, cancellation: $cancellation);
                    continue;
                }
                if (! ($this->clusterRoles?->canClaim(ClusterRoleSet::WEBHOOK) ?? true)) {
                    \Amp\delay($this->pollInterval, cancellation: $cancellation);
                    continue;
                }
                try {
                    // Cluster mode claims exactly one row so admission is
                    // revalidated before every subsequent shared claim. Keep
                    // the established standalone batch concurrency unchanged.
                    if ($this->clusterFencing !== null && $this->clusterWorkerId !== null) {
                        $claim = $this->clusterFencing->claimClusterNotification($this->clusterWorkerId, 300.0);
                        $items = $claim === null ? [] : [(array) $claim->work + ['cluster_work_token' => $claim->token]];
                    } else {
                        $items = $this->metadata->dequeueNotifications(
                            $this->clusterAdmission === null ? 50 : 1,
                        );
                    }
                } catch (\Throwable $e) {
                    $this->metrics?->recordNotificationEvent('dequeue', 'failed');
                    $this->logger->error('Notification dequeue failed.', $this->logContext([
                        'event' => 'dequeue_failed',
                        'exception' => $e::class,
                    ]));
                    \Amp\delay($this->pollInterval * 5, cancellation: $cancellation);

                    continue;
                }

                if ($items === []) {
                    // Exponential backoff when idle: 2s → 4s → 8s → max 30s.
                    $idleCount = min($idleCount + 1, 10);
                    $delay = min(30.0, $this->pollInterval * (2 ** min($idleCount - 1, 4)));
                    \Amp\delay($delay, cancellation: $cancellation);

                    continue;
                }

                $idleCount = 0;

                $futures = [];
                foreach ($items as $item) {
                    $futures[] = \Amp\async(fn() => $this->process($item, $cancellation));
                }

                // Cancellation reaches each delivery. Keep the loop alive
                // until every started delivery has returned its leased row
                // or committed its status; stop() must not abandon children.
                [$errors] = \Amp\Future\awaitAll($futures);
                foreach ($errors as $error) {
                    $this->logger->error('Notification batch processing failed.', $this->logContext([
                        'event' => 'batch_failed',
                        'exception' => $error::class,
                    ]));
                }
            }
        } catch (\Amp\CancelledException) {
            // Normal shutdown.
        }
    }

    /** @param array<string, mixed> $item */
    private function process(array $item, ?Cancellation $cancellation = null): void
    {
        $id = (int) $item['id'];
        $destination = $item['destination_url'];
        $attempts = (int) $item['attempts'];
        $maxAttempts = (int) $item['max_attempts'];
        $leaseExpiresAt = $item['lease_expires_at'];
        $token = ($item['cluster_work_token'] ?? null) instanceof ClusterWorkToken
            ? $item['cluster_work_token'] : null;
        $heartbeat = null;

        // Check circuit breaker.
        if ($this->isCircuitOpen($destination)) {
            // Put back as pending with backoff — do NOT increment attempts (no delivery was attempted).
            $nextAttempt = $this->queueNow() + min(300, 2 ** $attempts);
            if (! $this->updateClaimStatus($id, $item, 'pending', 'Circuit breaker open', $nextAttempt, incrementAttempts: false, expectedLeaseExpiresAt: $leaseExpiresAt)) {
                return;
            }
            $this->metrics?->recordNotificationDelivery('circuit_open');
            $this->logger->warning('Notification delivery deferred because the circuit breaker is open.', $this->itemLogContext($item, [
                'event' => 'delivery_deferred_circuit_open',
                'next_attempt_at' => $nextAttempt,
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
            ]));

            return;
        }

        if ($token !== null && $this->clusterFencing !== null) {
            $heartbeat = new QueueLeaseHeartbeat(function (float $_) use (&$token): bool {
                if ($this->clusterFencing === null) {
                    return false;
                }
                $renewed = $this->clusterFencing->renewClusterWork($token, 300.0);
                if ($renewed === null) {
                    return false;
                }
                $token = $renewed;

                return true;
            });
        }

        try {
            $success = $this->sendWebhook($destination, $item['payload_json'], cancellation: $cancellation);
            $heartbeat?->stop();
            if ($token !== null) {
                $item['cluster_work_token'] = $token;
            }

            if ($success) {
                if (! $this->updateClaimStatus($id, $item, 'sent', expectedLeaseExpiresAt: $leaseExpiresAt)) {
                    return;
                }
                $this->recordSuccess($destination);
                $this->metrics?->recordNotificationDelivery('sent');
                $this->logger->info('Notification delivered.', $this->itemLogContext($item, [
                    'event' => 'delivery_sent',
                    'status' => 'sent',
                    'attempt' => $attempts + 1,
                    'max_attempts' => $maxAttempts,
                ]));
            } else {
                $this->recordFailure($destination);
                $nextAttempts = $attempts + 1;
                if ($nextAttempts >= $maxAttempts) {
                    if (! $this->updateClaimStatus($id, $item, 'dead_letter', 'Max attempts exceeded', expectedLeaseExpiresAt: $leaseExpiresAt)) {
                        return;
                    }
                    $this->metrics?->recordNotificationDelivery('dead_letter');
                    $this->logger->warning('Notification moved to dead letter.', $this->itemLogContext($item, [
                        'event' => 'delivery_dead_letter',
                        'reason' => 'max_attempts_exceeded',
                        'status' => 'dead_letter',
                        'attempt' => $nextAttempts,
                        'max_attempts' => $maxAttempts,
                    ]));
                } else {
                    $backoff = min(300.0, 2 ** $nextAttempts);
                    $nextAttemptAt = $this->queueNow() + $backoff;
                    if (! $this->updateClaimStatus(
                        $id,
                        $item,
                        'pending',
                        'Delivery failed',
                        $nextAttemptAt,
                        expectedLeaseExpiresAt: $leaseExpiresAt,
                    )) {
                        return;
                    }
                    $this->metrics?->recordNotificationDelivery('retry');
                    $this->logger->warning('Notification delivery failed and will be retried.', $this->itemLogContext($item, [
                        'event' => 'delivery_retry_scheduled',
                        'reason' => 'delivery_failed',
                        'status' => 'pending',
                        'attempt' => $nextAttempts,
                        'max_attempts' => $maxAttempts,
                        'backoff_seconds' => $backoff,
                        'next_attempt_at' => $nextAttemptAt,
                    ]));
                }
            }
        } catch (\Amp\CancelledException) {
            try {
                $heartbeat?->stop();
            } catch (\Throwable) {
                return;
            }
            // Shutdown can interrupt an in-flight request without telling us
            // whether the receiver accepted it. Keep the message retriable
            // without exhausting its delivery budget.
            $nextAttemptAt = $this->queueNow() + max(1.0, $this->pollInterval);
            $this->updateClaimStatus(
                $id,
                $item,
                'pending',
                'Delivery interrupted',
                $nextAttemptAt,
                incrementAttempts: false,
                expectedLeaseExpiresAt: $leaseExpiresAt,
            );

            return;
        } catch (UnsafeNotificationDestination $e) {
            try {
                $heartbeat?->stop();
            } catch (\Throwable) {
                return;
            }
            $this->recordFailure($destination);
            if (! $this->updateClaimStatus($id, $item, 'dead_letter', $e->getMessage(), expectedLeaseExpiresAt: $leaseExpiresAt)) {
                return;
            }
            $this->metrics?->recordNotificationDelivery('blocked');
            $this->metrics?->recordNotificationDelivery('dead_letter');
            $this->logger->warning('Notification delivery blocked by destination safety policy.', $this->itemLogContext($item, [
                'event' => 'delivery_blocked',
                'reason' => 'unsafe_destination',
                'status' => 'dead_letter',
                'error' => $e->getMessage(),
            ]));
        } catch (\Throwable $e) {
            try {
                $heartbeat?->stop();
            } catch (\Throwable) {
                return;
            }
            $nextAttempts = $attempts + 1;
            if ($nextAttempts >= $maxAttempts) {
                if (! $this->updateClaimStatus($id, $item, 'dead_letter', 'Notification processing failed.', expectedLeaseExpiresAt: $leaseExpiresAt)) {
                    return;
                }
                $this->metrics?->recordNotificationDelivery('dead_letter');
                $this->logger->error('Notification processing failed and moved to dead letter.', $this->itemLogContext($item, [
                    'event' => 'delivery_dead_letter',
                    'reason' => 'processing_exception',
                    'status' => 'dead_letter',
                    'attempt' => $nextAttempts,
                    'max_attempts' => $maxAttempts,
                    'exception' => $e::class,
                ]));
            } else {
                $backoff = min(300.0, 2 ** $nextAttempts);
                $nextAttemptAt = $this->queueNow() + $backoff;
                if (! $this->updateClaimStatus($id, $item, 'pending', 'Notification processing failed.', $nextAttemptAt, expectedLeaseExpiresAt: $leaseExpiresAt)) {
                    return;
                }
                $this->metrics?->recordNotificationDelivery('retry');
                $this->logger->error('Notification processing failed and will be retried.', $this->itemLogContext($item, [
                    'event' => 'delivery_retry_scheduled',
                    'reason' => 'processing_exception',
                    'status' => 'pending',
                    'attempt' => $nextAttempts,
                    'max_attempts' => $maxAttempts,
                    'backoff_seconds' => $backoff,
                    'next_attempt_at' => $nextAttemptAt,
                    'exception' => $e::class,
                ]));
            }
        }
    }

    private function sendWebhook(
        string $url,
        string $payload,
        ?Cancellation $cancellation = null,
    ): bool {
        $currentUrl = $url;

        try {
            for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
                $resolvedIp = $this->resolveAndValidateUrl($currentUrl, $cancellation);
                $request = new HttpRequest($currentUrl, 'POST');
                $request->setBody($payload);
                $request->setHeader('Content-Type', 'application/json');
                $request->setTransferTimeout(10);
                $request->setTcpConnectTimeout(5);
                $request->setTlsHandshakeTimeout(5);

                $client = $this->httpClient ?? $this->createPinnedHttpClient($currentUrl, $resolvedIp);
                $response = $client->request($request, $cancellation);
                $status = $response->getStatus();

                if ($status >= 200 && $status < 300) {
                    $response->getBody()->close();

                    return true;
                }

                // Only 307/308 preserve POST and its body. A 2xx response to
                // a redirected GET does not prove the event was delivered.
                if (! in_array($status, [307, 308], true)) {
                    $response->getBody()->close();

                    return false;
                }

                $locations = $response->getHeaderArray('location');
                $response->getBody()->close();
                if (count($locations) !== 1 || $redirects === self::MAX_REDIRECTS) {
                    return false;
                }

                $currentUrl = BaseUri::from($currentUrl)->resolve($locations[0])->getUriString();
            }
        } catch (UnsafeNotificationDestination $e) {
            throw $e;
        } catch (\Amp\CancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('Webhook request failed.', $this->logContext([
                'event' => 'webhook_request_failed',
                'destination' => $this->sanitizeUrlForLog($currentUrl),
                'exception' => $e::class,
            ]));
        }

        return false;
    }

    private function resolveAndValidateUrl(string $url, ?Cancellation $cancellation = null): string
    {
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new UnsafeNotificationDestination('Notification destination must not contain whitespace or control characters.');
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeNotificationDestination('Notification destination must be an absolute HTTP(S) URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeNotificationDestination('Notification destination URL scheme must be HTTP or HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeNotificationDestination('Notification destination must not contain user information.');
        }

        if (isset($parts['port']) && $parts['port'] < 1) {
            throw new UnsafeNotificationDestination('Notification destination port is invalid.');
        }

        if ($this->requireHttps && $scheme !== 'https') {
            throw new UnsafeNotificationDestination('Notification destination must use HTTPS.');
        }

        $host = $parts['host'];
        if ($host === '') {
            throw new UnsafeNotificationDestination('Notification destination host is empty.');
        }

        // parse_url() retains the brackets around IPv6 URL literals. Validate
        // their contents as an address instead of sending them to DNS as a name.
        if (str_starts_with($host, '[')) {
            if (! str_ends_with($host, ']')) {
                throw new UnsafeNotificationDestination('Notification destination IP address is invalid.');
            }
            $host = substr($host, 1, -1);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new UnsafeNotificationDestination('Notification destination IP address is invalid.');
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (self::isUnsafeIp($host)) {
                throw new UnsafeNotificationDestination('Notification destination resolves to a private or reserved IP address.');
            }

            return $host;
        }

        try {
            $cancellation?->throwIfRequested();
            $records = \Amp\Dns\resolve($host, cancellation: $cancellation);
        } catch (\Amp\CancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Notification destination DNS resolution failed.', 0, $e);
        }

        if (empty($records)) {
            throw new \RuntimeException('Notification destination DNS resolution returned no addresses.');
        }

        foreach ($records as $record) {
            $ip = $record->getValue();
            if (self::isUnsafeIp($ip)) {
                throw new UnsafeNotificationDestination('Notification destination resolves to a private or reserved IP address.');
            }
        }

        return $records[0]->getValue();
    }

    private static function isUnsafeIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        $binary = inet_pton($ip);
        if ($binary === false) {
            return true;
        }
        if (strlen($binary) === 4) {
            $first = ord($binary[0]);
            $second = ord($binary[1]);
            $third = ord($binary[2]);

            // PHP's range flags leave several IANA non-global blocks open.
            // Shared and benchmark networks may be routed inside a cluster;
            // documentation addresses must never be webhook targets.
            return ($first === 100 && $second >= 64 && $second <= 127)
                || ($first === 198 && ($second === 18 || $second === 19))
                || ($first === 192 && $second === 0 && ($third === 0 || $third === 2))
                || ($first === 198 && $second === 51 && $third === 100)
                || ($first === 203 && $second === 0 && $third === 113);
        }

        // PHP's range flags accept these IPv6 prefixes. 6to4, Teredo, and
        // NAT64 can carry an IPv4 destination that bypasses a plain IPv6
        // public-address check. Documentation addresses are not deliverable.
        foreach ([
            ['64:ff9b::', 12],
            ['64:ff9b:1::', 6],
            ['2002::', 2],
            ['2001::', 4],
            ['2001:db8::', 4],
        ] as [$prefix, $bytes]) {
            $prefixBytes = inet_pton($prefix);
            if ($prefixBytes !== false && substr($binary, 0, $bytes) === substr($prefixBytes, 0, $bytes)) {
                return true;
            }
        }

        return false;
    }

    private function createPinnedHttpClient(string $url, string $ip): HttpClient
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'])) {
            throw new UnsafeNotificationDestination('Notification destination URL is invalid.');
        }

        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $address = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? "tcp://[{$ip}]:{$port}"
            : "tcp://{$ip}:{$port}";
        $connector = new StaticSocketConnector($address, new DnsSocketConnector());
        $pool = new UnlimitedConnectionPool(new DefaultConnectionFactory($connector));

        return (new HttpClientBuilder())
            ->usingPool($pool)
            ->followRedirects(0)
            ->retry(0)
            ->build();
    }

    private function sanitizeUrlForLog(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return '[invalid notification destination]';
        }

        $scheme = $parts['scheme'] . '://';
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        // A webhook path may contain a bearer token just like its query.
        // The origin is enough to identify the failing destination in logs.
        return $scheme . $host . $port;
    }

    private function isCircuitOpen(string $destination): bool
    {
        if (! isset($this->circuitBreakers[$destination])) {
            return false;
        }

        $cb = $this->circuitBreakers[$destination];

        if ($cb['failures'] < self::CIRCUIT_BREAKER_THRESHOLD) {
            return false;
        }

        if (microtime(true) >= $cb['cooldownUntil']) {
            unset($this->circuitBreakers[$destination]);

            return false;
        }

        return true;
    }

    private function recordFailure(string $destination): void
    {
        if (! isset($this->circuitBreakers[$destination])) {
            if (count($this->circuitBreakers) >= self::MAX_CIRCUIT_BREAKERS) {
                array_shift($this->circuitBreakers);
                $this->metrics?->recordNotificationEvent('circuit_breaker', 'evicted');
            }
            $this->circuitBreakers[$destination] = ['failures' => 0, 'cooldownUntil' => 0.0];
        }

        $this->circuitBreakers[$destination]['failures']++;

        if ($this->circuitBreakers[$destination]['failures'] >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $this->circuitBreakers[$destination]['cooldownUntil'] = microtime(true) + self::CIRCUIT_BREAKER_COOLDOWN;
            $this->metrics?->recordNotificationEvent('circuit_breaker', 'opened');
            $this->logger->warning('Notification destination circuit breaker opened.', $this->logContext([
                'event' => 'circuit_breaker_opened',
                'destination' => $this->sanitizeUrlForLog($destination),
                'failures' => $this->circuitBreakers[$destination]['failures'],
                'cooldown_until' => $this->circuitBreakers[$destination]['cooldownUntil'],
                'cooldown_seconds' => self::CIRCUIT_BREAKER_COOLDOWN,
            ]));
        }
    }

    private function recordSuccess(string $destination): void
    {
        unset($this->circuitBreakers[$destination]);
    }

    private function queueNow(): float
    {
        return $this->metadata instanceof MultipartWriteIntentStore
            ? $this->metadata->multipartWriteIntentNow() : microtime(true);
    }

    /** @param array<string, mixed> $item */
    private function updateClaimStatus(
        int $id,
        array $item,
        string $status,
        ?string $error = null,
        ?float $nextAttemptAt = null,
        bool $incrementAttempts = true,
        ?float $expectedLeaseExpiresAt = null,
    ): bool {
        $token = $item['cluster_work_token'] ?? null;
        if ($token instanceof ClusterWorkToken && $this->clusterFencing !== null) {
            try {
                return $this->clusterFencing->transactionWithClusterWork(
                    $token,
                    function () use ($id, $status, $error, $nextAttemptAt, $incrementAttempts, $token): bool {
                        $updated = $this->metadata->updateNotificationStatus(
                            $id,
                            $status,
                            $error,
                            $nextAttemptAt,
                            $incrementAttempts,
                        );
                        if ($updated && in_array($status, ['pending', 'dead_letter'], true)
                            && $this->clusterFencing instanceof \OpsFour\S3Server\Metadata\ClusterWorkOutcomeRecorder) {
                            $this->clusterFencing->recordClusterWorkOutcome(
                                $token,
                                \OpsFour\S3Server\Metadata\ClusterWorkOutcomeRecorder::FAILED_TAKEOVER,
                            );
                        }

                        return $updated;
                    },
                );
            } catch (\RuntimeException) {
                return false;
            }
        }
        if ($expectedLeaseExpiresAt !== null && $this->metadata instanceof FencedQueueStore && isset($item['lease_generation'])) {
            return $this->metadata->updateNotificationStatusLease(
                $id,
                $status,
                $error,
                $nextAttemptAt,
                $incrementAttempts,
                $expectedLeaseExpiresAt,
                (int) $item['lease_generation'],
            );
        }

        return $this->metadata->updateNotificationStatus(
            $id,
            $status,
            $error,
            $nextAttemptAt,
            $incrementAttempts,
            $expectedLeaseExpiresAt,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function itemLogContext(array $item, array $context = []): array
    {
        return $this->logContext([
            'notification_id' => (int) $item['id'],
            'bucket' => (string) $item['bucket'],
            'key' => (string) $item['key_name'],
            'event_name' => (string) $item['event_name'],
            'destination' => $this->sanitizeUrlForLog((string) $item['destination_url']),
        ] + $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function logContext(array $context = []): array
    {
        return ['component' => 'notification'] + $context;
    }
}
