<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\Http\Server\Request;

/** Authentication mechanism established by successful cryptographic verification. */
enum AuthType: string
{
    public const string REQUEST_ATTRIBUTE = 's3.authType';

    case RestHeader = 'REST-HEADER';
    case RestQueryString = 'REST-QUERY-STRING';
    case Post = 'POST';

    public static function set(Request $request, self $authType): void
    {
        $request->setAttribute(self::REQUEST_ATTRIBUTE, $authType);
    }

    public static function get(Request $request): ?self
    {
        if (! $request->hasAttribute(self::REQUEST_ATTRIBUTE)) {
            return null;
        }

        $authType = $request->getAttribute(self::REQUEST_ATTRIBUTE);

        return $authType instanceof self ? $authType : null;
    }

    public static function clear(Request $request): void
    {
        if ($request->hasAttribute(self::REQUEST_ATTRIBUTE)) {
            $request->removeAttribute(self::REQUEST_ATTRIBUTE);
        }
    }

    public static function copy(Request $source, Request $target): void
    {
        self::clear($target);
        $authType = self::get($source);
        if ($authType !== null) {
            self::set($target, $authType);
        }
    }

    /** @param array<string, string|list<string>> $conditions */
    public static function addToPolicyContext(Request $request, array &$conditions): void
    {
        $authType = self::get($request);
        if ($authType !== null) {
            $conditions['s3:authType'] = $authType->value;
        }
    }
}
