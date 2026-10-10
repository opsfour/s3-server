<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Security\RequestNetworkContext;

/** Accept forwarded transport and client IP only from configured peer CIDRs. */
final class TrustedProxyMiddleware implements Middleware
{
    /** @var list<array{string, int}> */
    private array $trustedRanges = [];

    /**
     * @param string|list<string> $value
     * @return list<string>
     */
    public static function parseCidrs(string|array $value): array
    {
        if ($value === '') {
            return [];
        }
        $cidrs = is_string($value) ? explode(',', $value) : $value;
        if (! array_is_list($cidrs)) {
            throw new \InvalidArgumentException('Trusted proxy CIDRs must be a list.');
        }
        foreach ($cidrs as &$cidr) {
            if (! is_string($cidr) || trim($cidr) === '') {
                throw new \InvalidArgumentException('Trusted proxy CIDRs must be non-empty strings.');
            }
            $cidr = trim($cidr);
        }

        return $cidrs;
    }

    /** @param list<string> $trustedProxyCidrs */
    public function __construct(array $trustedProxyCidrs = [], private readonly bool $enforceForwardedFor = true)
    {
        foreach ($trustedProxyCidrs as $cidr) {
            if (! is_string($cidr)) {
                throw new \InvalidArgumentException('Trusted proxy CIDRs must be strings.');
            }
            $parts = explode('/', $cidr, 2);
            $address = $parts[0];
            $prefix = $parts[1] ?? null;
            $binary = @inet_pton($address);
            if ($binary === false || ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix > strlen($binary) * 8 || (int) $prefix === 0))) {
                throw new \InvalidArgumentException("Invalid trusted proxy CIDR: {$cidr}.");
            }
            $this->trustedRanges[] = [$binary, $prefix === null ? strlen($binary) * 8 : (int) $prefix];
        }
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $peer = RequestNetworkContext::peerIp($request);
        if ($peer !== null && $this->isTrusted($peer)) {
            $proto = $request->getHeaderArray('x-forwarded-proto');
            // Backend TLS says nothing about client-to-proxy TLS. Missing,
            // repeated, or comma-separated values may never assert HTTPS.
            $value = count($proto) === 1 ? strtolower(trim($proto[0])) : '';
            $request->setAttribute(RequestNetworkContext::SECURE_TRANSPORT_ATTRIBUTE, $value === 'https');

            $forwardedFor = $request->getHeaderArray('x-forwarded-for');
            if ($forwardedFor === [] && $this->enforceForwardedFor) {
                throw new AccessDeniedException('Missing forwarded client address.');
            }
            if ($forwardedFor !== []) {
                $clientIp = $this->resolveClientIp($forwardedFor);
                if ($clientIp === null && $this->enforceForwardedFor) {
                    throw new AccessDeniedException('Invalid forwarded client address.');
                }
                if ($clientIp !== null) {
                    $request->setAttribute(
                        RequestNetworkContext::CLIENT_IP_ATTRIBUTE,
                        $clientIp,
                    );
                }
            }
        }

        return $requestHandler->handleRequest($request);
    }

    /** @param list<string> $headers */
    private function resolveClientIp(array $headers): ?string
    {
        $addresses = [];
        foreach ($headers as $header) {
            foreach (explode(',', $header) as $part) {
                $ip = trim($part);
                if ($ip === '' || @inet_pton($ip) === false) {
                    return null;
                }
                $addresses[] = $ip;
            }
        }
        for ($index = count($addresses) - 1; $index >= 0; $index--) {
            if (! $this->isTrusted($addresses[$index])) {
                return $addresses[$index];
            }
        }

        // No untrusted hop can be identified, so no forwarded source IP is
        // safe to use for an authorization condition.
        return null;
    }

    private function isTrusted(string $ip): bool
    {
        $address = @inet_pton($ip);
        if ($address === false) {
            return false;
        }
        foreach ($this->trustedRanges as [$network, $prefix]) {
            if (strlen($network) !== strlen($address)) {
                continue;
            }
            $wholeBytes = intdiv($prefix, 8);
            $remainingBits = $prefix % 8;
            if (substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
                continue;
            }
            if ($remainingBits === 0 || (ord($address[$wholeBytes]) & (0xff << (8 - $remainingBits)))
                === (ord($network[$wholeBytes]) & (0xff << (8 - $remainingBits)))) {
                return true;
            }
        }

        return false;
    }
}
