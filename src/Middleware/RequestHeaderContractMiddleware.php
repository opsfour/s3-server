<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Http\RequestHeaderValues;

/** Rejects ambiguous repeated field lines before authentication or authorization. */
final class RequestHeaderContractMiddleware implements Middleware
{
    /** @var \WeakMap<object, true> */
    private readonly \WeakMap $poisonedClients;

    /** @var list<non-empty-string> */
    private const array SINGLETON_HEADERS = [
        'expect',
        'content-length',
        'content-md5',
        'content-type',
        'content-disposition',
        'expires',
        'user-agent',
        'x-amz-acl',
        'x-amz-bucket-object-lock-enabled',
        'x-amz-bypass-governance-retention',
        'x-amz-content-sha256',
        'x-amz-decoded-content-length',
        'x-amz-expected-bucket-owner',
        'x-amz-metadata-directive',
        'x-amz-object-lock-legal-hold',
        'x-amz-object-lock-mode',
        'x-amz-object-lock-retain-until-date',
        'x-amz-object-ownership',
        'x-amz-sdk-checksum-algorithm',
        'x-amz-server-side-encryption',
        'x-amz-storage-class',
        'x-amz-tagging',
        'x-amz-tagging-directive',
        'x-amz-trailer',
    ];

    public function __construct()
    {
        $this->poisonedClients = new \WeakMap();
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $client = $request->getClient();
        $isHttpOne = str_starts_with($request->getProtocolVersion(), '1.');
        if ($isHttpOne && isset($this->poisonedClients[$client])) {
            $request->setAttribute('s3.forceConnectionClose', true);
            throw new InvalidArgumentException('The HTTP connection cannot be reused after ambiguous framing.');
        }
        if ($request->hasHeader('content-length') && $request->hasHeader('transfer-encoding')) {
            if ($isHttpOne) {
                $this->poisonedClients[$client] = true;
                $request->setAttribute('s3.forceConnectionClose', true);
            }
            throw new InvalidArgumentException(
                'Content-Length and Transfer-Encoding cannot be combined.',
            );
        }
        foreach (self::SINGLETON_HEADERS as $header) {
            RequestHeaderValues::singleton($request, $header);
        }
        foreach (array_keys($request->getHeaders()) as $header) {
            if (str_starts_with(strtolower($header), 'x-amz-checksum-')) {
                RequestHeaderValues::singleton($request, $header);
            }
        }

        return $requestHandler->handleRequest($request);
    }
}
