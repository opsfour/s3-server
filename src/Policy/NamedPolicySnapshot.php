<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Policy;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Metadata\MetadataStore;

/** Carries the current named-policy documents locked by a write transaction. */
final class NamedPolicySnapshot
{
    /** @param array<string, string> $policies */
    public static function attach(Request $request, array $policies): void
    {
        $request->setAttribute('s3.lockedNamedPolicies', $policies);
    }

    public static function get(Request $request, MetadataStore $metadata, string $name): ?string
    {
        if ($request->hasAttribute('s3.lockedNamedPolicies')) {
            $policies = $request->getAttribute('s3.lockedNamedPolicies');
            if (! is_array($policies)) {
                throw new \LogicException('Invalid locked named-policy snapshot.');
            }

            $policy = $policies[$name] ?? null;

            return is_string($policy) ? $policy : null;
        }

        return $metadata->getNamedPolicy($name);
    }
}
