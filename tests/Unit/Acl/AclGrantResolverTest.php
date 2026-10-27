<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Acl;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Metadata\MetadataStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AclGrantResolverTest extends TestCase
{
    public function test_unknown_canned_acl_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported canned ACL');

        AclGrantResolver::fromHeaders(
            $this->request(['x-amz-acl' => 'made-up']),
            'owner',
            'object',
            'bucket-owner',
        );
    }

    #[DataProvider('objectOnlyCannedAcls')]
    public function test_object_only_canned_acl_is_rejected_for_bucket(string $cannedAcl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported canned ACL');

        AclGrantResolver::fromHeaders(
            $this->request(['x-amz-acl' => $cannedAcl]),
            'owner',
            'bucket',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function objectOnlyCannedAcls(): iterable
    {
        yield 'bucket owner read' => ['bucket-owner-read'];
        yield 'bucket owner full control' => ['bucket-owner-full-control'];
        yield 'EC2 bundle read' => ['aws-exec-read'];
    }

    public function test_canned_acl_and_explicit_grants_are_mutually_exclusive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mutually exclusive');

        AclGrantResolver::fromHeaders(
            $this->request([
                'x-amz-acl' => 'private',
                'x-amz-grant-read' => 'id="reader"',
            ]),
            'owner',
            'object',
            'bucket-owner',
        );
    }

    public function test_repeated_grant_field_lines_are_combined(): void
    {
        $grants = AclGrantResolver::fromHeaders(
            $this->request([
                'x-amz-grant-read' => ['id="first-reader"', 'id="second-reader"'],
            ]),
            'owner',
            'object',
            'bucket-owner',
        );

        self::assertNotNull($grants);
        self::assertSame(
            ['owner', 'first-reader', 'second-reader'],
            array_column($grants, 'granteeId'),
        );
        self::assertSame(['FULL_CONTROL', 'READ', 'READ'], array_column($grants, 'permission'));
    }

    public function test_explicit_grant_headers_cannot_exceed_the_acl_grant_limit(): void
    {
        $readers = [];
        for ($index = 0; $index < 100; ++$index) {
            $readers[] = 'id="reader-' . $index . '"';
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than 100 grants');

        // The resource owner's FULL_CONTROL grant is also part of the ACL.
        AclGrantResolver::fromHeaders(
            $this->request(['x-amz-grant-read' => implode(',', $readers)]),
            'owner',
            'bucket',
        );
    }

    public function test_grantee_identifier_cannot_exceed_the_portable_metadata_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed 255 bytes');

        AclGrantResolver::fromHeaders(
            $this->request(['x-amz-grant-read' => 'id="' . str_repeat('a', 256) . '"']),
            'owner',
            'bucket',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCanonicalIds(): iterable
    {
        yield 'invalid UTF-8' => ["reader-\xFF"];
        yield 'tab control' => ["reader-\t"];
        yield 'line-feed control' => ["reader-\n"];
        yield 'carriage-return control' => ["reader-\r"];
        yield 'XML control' => ["reader-\x0B"];
        yield 'DEL control' => ["reader-\x7F"];
        yield 'XML noncharacter' => ["reader-\u{FFFF}"];
    }

    #[DataProvider('invalidCanonicalIds')]
    public function test_canonical_grantee_ids_must_be_xml_safe(string $granteeId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid UTF-8');

        AclGrantResolver::validateGrants([[
            'granteeType' => 'CanonicalUser',
            'granteeId' => $granteeId,
            'permission' => 'READ',
        ]]);
    }

    /** @return iterable<string, array{string}> */
    public static function validCanonicalIds(): iterable
    {
        yield 'portable byte boundary' => [str_repeat('a', 255)];
        yield 'Unicode' => ['café-讀者'];
        yield 'XML-valid C1 character' => ["reader-\u{0085}"];
    }

    #[DataProvider('validCanonicalIds')]
    public function test_canonical_grantee_ids_preserve_valid_identity_text(string $granteeId): void
    {
        self::assertSame($granteeId, AclGrantResolver::validateGrants([[
            'granteeType' => 'CanonicalUser',
            'granteeId' => $granteeId,
            'permission' => 'READ',
        ]])[0]['granteeId']);
    }

    public function test_grant_header_rejects_xml_invalid_canonical_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid UTF-8');

        AclGrantResolver::fromHeaders(
            $this->request(['x-amz-grant-read' => "id=\"reader-\u{FFFF}\""]),
            'owner',
            'bucket',
        );
    }

    public function test_stored_acl_validation_allows_absence_and_maps_corruption_to_internal_error(): void
    {
        self::assertSame([], AclGrantResolver::validateStoredGrants([]));

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Stored ACL grant metadata is invalid.');
        AclGrantResolver::effectiveGrants([[
            'granteeType' => 'CanonicalUser',
            'granteeId' => "reader-\u{FFFF}",
            'permission' => 'READ',
        ]], false);
    }

    public function test_stored_acl_validation_rejects_malformed_records_without_typed_access(): void
    {
        $this->expectException(InternalErrorException::class);

        AclGrantResolver::validateStoredGrants([[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'reader',
        ]]);
    }

    public function test_private_bucket_acl_check_maps_corrupt_stored_grants_to_internal_error(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getAcl')->with('bucket', 'bucket')->willReturn([[
            'granteeType' => 'CanonicalUser',
            'granteeId' => "owner-\u{FFFF}",
            'permission' => 'FULL_CONTROL',
        ]]);

        $this->expectException(InternalErrorException::class);
        BucketOwnership::assertPrivateBucketAcl($metadata, 'bucket', 'owner');
    }

    public function test_invalid_repeated_grant_field_line_is_not_ignored(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid grantee');

        AclGrantResolver::fromHeaders(
            $this->request([
                'x-amz-grant-read' => ['id="reader"', 'invalid'],
            ]),
            'owner',
            'object',
            'bucket-owner',
        );
    }

    /**
     * @param array<non-empty-string, string> $headers
     */
    #[DataProvider('invalidGrantHeaders')]
    public function test_invalid_or_unresolvable_grants_are_rejected(array $headers, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        AclGrantResolver::fromHeaders($this->request($headers), 'owner', 'bucket');
    }

    /**
     * @return iterable<string, array{array<non-empty-string, string>, string}>
     */
    public static function invalidGrantHeaders(): iterable
    {
        yield 'malformed' => [['x-amz-grant-read' => 'reader'], 'Invalid grantee'];
        yield 'email without resolver' => [
            ['x-amz-grant-read' => 'emailAddress="reader@example.test"'],
            'require an account resolver',
        ];
        yield 'unknown group' => [
            ['x-amz-grant-read' => 'uri="https://example.test/group"'],
            'Unsupported S3 ACL group URI',
        ];
    }

    public function test_object_owner_and_log_delivery_canned_acls_expand_completely(): void
    {
        $object = AclGrantResolver::fromHeaders(
            $this->request(['x-amz-acl' => 'bucket-owner-full-control']),
            'writer',
            'object',
            'bucket-owner',
        );
        self::assertNotNull($object);
        self::assertSame(['writer', 'bucket-owner'], array_column($object, 'granteeId'));
        self::assertSame(['FULL_CONTROL', 'FULL_CONTROL'], array_column($object, 'permission'));

        $bucket = AclGrantResolver::fromHeaders(
            $this->request(['x-amz-acl' => 'log-delivery-write']),
            'owner',
            'bucket',
        );
        self::assertNotNull($bucket);
        self::assertSame(['FULL_CONTROL', 'WRITE', 'READ_ACP'], array_column($bucket, 'permission'));
        self::assertSame(AclGrantResolver::LOG_DELIVERY_URI, $bucket[1]['granteeId']);
    }

    public function test_preferred_mode_transfers_only_for_the_exact_canned_acl(): void
    {
        $transferred = BucketOwnership::resolveObjectWrite(
            $this->request(['x-amz-acl' => 'bucket-owner-full-control']),
            BucketOwnership::BUCKET_OWNER_PREFERRED,
            'writer',
            'bucket-owner',
        );
        self::assertSame('bucket-owner', $transferred->ownerId);
        self::assertSame('bucket-owner', $transferred->quotaPrincipalId);
        self::assertSame(AclGrantResolver::privateAcl('bucket-owner'), $transferred->aclGrants);

        $explicitEquivalent = BucketOwnership::resolveObjectWrite(
            $this->request(['x-amz-grant-full-control' => 'id="bucket-owner"']),
            BucketOwnership::BUCKET_OWNER_PREFERRED,
            'writer',
            'bucket-owner',
        );
        self::assertSame('writer', $explicitEquivalent->ownerId);
        self::assertSame('writer', $explicitEquivalent->quotaPrincipalId);
        self::assertSame(['writer', 'bucket-owner'], array_column($explicitEquivalent->aclGrants, 'granteeId'));
    }

    public function test_object_write_decision_covers_existing_modes_without_changing_semantics(): void
    {
        $objectWriter = BucketOwnership::resolveObjectWrite(
            $this->request(['x-amz-acl' => 'bucket-owner-full-control']),
            BucketOwnership::OBJECT_WRITER,
            'writer',
            'bucket-owner',
        );
        self::assertSame('writer', $objectWriter->ownerId);
        self::assertSame(['writer', 'bucket-owner'], array_column($objectWriter->aclGrants, 'granteeId'));

        $enforced = BucketOwnership::resolveObjectWrite(
            $this->request(['x-amz-acl' => 'bucket-owner-full-control']),
            BucketOwnership::BUCKET_OWNER_ENFORCED,
            'writer',
            'bucket-owner',
        );
        self::assertSame('bucket-owner', $enforced->ownerId);
        self::assertSame(AclGrantResolver::privateAcl('bucket-owner'), $enforced->aclGrants);

        $preferredPrivate = BucketOwnership::resolveObjectWrite(
            $this->request(['x-amz-acl' => 'private']),
            BucketOwnership::BUCKET_OWNER_PREFERRED,
            'writer',
            'bucket-owner',
        );
        self::assertSame('writer', $preferredPrivate->ownerId);
        self::assertSame(AclGrantResolver::privateAcl('writer'), $preferredPrivate->aclGrants);
    }

    /** @return iterable<string, array{string}> */
    public static function preferredNonTransferCannedAcls(): iterable
    {
        yield 'private' => ['private'];
        yield 'public read' => ['public-read'];
        yield 'bucket owner read' => ['bucket-owner-read'];
    }

    #[DataProvider('preferredNonTransferCannedAcls')]
    public function test_preferred_mode_preserves_normal_acl_expansion_without_transfer(string $cannedAcl): void
    {
        $decision = BucketOwnership::resolveObjectWrite(
            $this->request(['x-amz-acl' => $cannedAcl]),
            BucketOwnership::BUCKET_OWNER_PREFERRED,
            'writer',
            'bucket-owner',
        );

        self::assertSame('writer', $decision->ownerId);
        self::assertSame('writer', $decision->quotaPrincipalId);
        self::assertSame('writer', $decision->aclGrants[0]['granteeId']);
        self::assertSame('FULL_CONTROL', $decision->aclGrants[0]['permission']);
    }

    public function test_xml_email_grantee_is_rejected_by_validation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require an account resolver');

        AclGrantResolver::validateGrants([[
            'granteeType' => 'AmazonCustomerByEmail',
            'granteeId' => 'reader@example.test',
            'permission' => 'READ',
        ]]);
    }

    /**
     * @param array<non-empty-string, string|list<string>> $headers
     */
    private function request(array $headers): Request
    {
        return new Request(
            new AclGrantResolverTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            $headers,
        );
    }
}

final class AclGrantResolverTestClient implements Client
{
    public function getId(): int
    {
        return 1;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 12345);
    }

    public function getLocalAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 9000);
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return null;
    }

    public function close(): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void {}
}
