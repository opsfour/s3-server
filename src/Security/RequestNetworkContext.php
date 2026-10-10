<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Security;

use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;

/** Network facts established by the direct connection or a trusted proxy. */
final class RequestNetworkContext
{
    public const string CLIENT_IP_ATTRIBUTE = 's3.network.clientIp';

    public const string SECURE_TRANSPORT_ATTRIBUTE = 's3.network.secureTransport';

    public static function sourceIp(Request $request): ?string
    {
        if ($request->hasAttribute(self::CLIENT_IP_ATTRIBUTE)) {
            $value = $request->getAttribute(self::CLIENT_IP_ATTRIBUTE);

            return is_string($value) ? $value : null;
        }

        return self::peerIp($request);
    }

    public static function rateLimitIp(Request $request): string
    {
        return self::sourceIp($request)
            ?? self::peerIp($request)
            ?? $request->getClient()->getRemoteAddress()->toString();
    }

    public static function secureTransport(Request $request): bool
    {
        if ($request->hasAttribute(self::SECURE_TRANSPORT_ATTRIBUTE)) {
            return $request->getAttribute(self::SECURE_TRANSPORT_ATTRIBUTE) === true;
        }

        return $request->getClient()->getTlsInfo() !== null;
    }

    public static function peerIp(Request $request): ?string
    {
        $address = $request->getClient()->getRemoteAddress();

        return $address instanceof InternetAddress ? $address->getAddress() : null;
    }
}
