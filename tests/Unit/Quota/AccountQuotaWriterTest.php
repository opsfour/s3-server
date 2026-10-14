<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Quota;

use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Quota\AccountQuotaWriter;
use OpsFour\S3Server\Quota\OwnerIdValidator;
use OpsFour\S3Server\Quota\QuotaConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountQuotaWriterTest extends TestCase
{
    #[DataProvider('invalidOwnerIds')]
    public function test_put_rejects_invalid_owner_before_opening_a_transaction(string $ownerId): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('transaction');
        $metadata->expects(self::never())->method('lockOwnerForUpdate');
        $metadata->expects(self::never())->method('putAccountQuota');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(OwnerIdValidator::ERROR_MESSAGE);

        AccountQuotaWriter::put($metadata, $ownerId, new QuotaConfig());
    }

    #[DataProvider('invalidOwnerIds')]
    public function test_delete_rejects_invalid_owner_before_opening_a_transaction(string $ownerId): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('transaction');
        $metadata->expects(self::never())->method('lockOwnerForUpdate');
        $metadata->expects(self::never())->method('deleteAccountQuota');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(OwnerIdValidator::ERROR_MESSAGE);

        AccountQuotaWriter::delete($metadata, $ownerId);
    }

    public function test_slash_is_a_valid_owner_character(): void
    {
        $events = [];
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('transaction')->willReturnCallback(function (callable $callback) use (&$events): mixed {
            $events[] = 'transaction';

            return $callback();
        });
        $metadata->method('lockOwnerForUpdate')->willReturnCallback(function (string $ownerId) use (&$events): void {
            $events[] = 'lock:' . $ownerId;
        });
        $metadata->method('putAccountQuota')->willReturnCallback(function (string $ownerId) use (&$events): void {
            $events[] = 'put:' . $ownerId;
        });

        AccountQuotaWriter::put($metadata, 'tenant/member', new QuotaConfig());

        self::assertSame(['transaction', 'lock:tenant/member', 'put:tenant/member'], $events);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOwnerIds(): iterable
    {
        yield 'empty' => [''];
        yield 'over 255 bytes' => [str_repeat('a', 256)];
        yield 'control character' => ["tenant\0hidden"];
        yield 'Unicode control character' => ["tenant\u{0085}hidden"];
        yield 'XML noncharacter U+FFFE' => ["tenant\u{FFFE}"];
        yield 'XML noncharacter U+FFFF' => ["tenant\u{FFFF}"];
        yield 'invalid UTF-8' => ["tenant-\xFF"];
    }
}
