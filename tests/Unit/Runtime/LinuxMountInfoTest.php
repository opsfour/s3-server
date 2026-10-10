<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Runtime\LinuxMountInfo;
use PHPUnit\Framework\TestCase;

final class LinuxMountInfoTest extends TestCase
{
    public function test_longest_matching_mount_and_segment_boundary_win(): void
    {
        $mounts = implode("\n", [
            '1 0 8:1 / / rw - ext4 /dev/root rw',
            '2 1 0:42 / /mnt/shared rw - nfs4 fs.example:/share rw',
            '3 2 8:2 / /mnt/shared/local rw - xfs /dev/local rw',
        ]);

        self::assertSame('ext4', LinuxMountInfo::filesystemTypeForPath('/mnt/sharedness/data', $mounts));
        self::assertSame('nfs4', LinuxMountInfo::filesystemTypeForPath('/mnt/shared', $mounts));
        self::assertSame('nfs4', LinuxMountInfo::filesystemTypeForPath('/mnt/shared/bucket', $mounts));
        self::assertSame('xfs', LinuxMountInfo::filesystemTypeForPath('/mnt/shared/local/bucket', $mounts));
    }

    public function test_octal_escaped_mount_points_match_the_decoded_path(): void
    {
        $mounts = implode("\n", [
            '1 0 8:1 / / rw - ext4 /dev/root rw',
            '2 1 0:42 / /mnt/team\\040storage\\134archive rw,nosuid shared:7 - cifs //server/share rw',
            '3 1 0:43 / /mnt/tab\\011line\\012break rw - fuse.sshfs remote rw',
        ]);

        self::assertSame('cifs', LinuxMountInfo::filesystemTypeForPath('/mnt/team storage\\archive/bucket', $mounts));
        self::assertSame('fuse.sshfs', LinuxMountInfo::filesystemTypeForPath("/mnt/tab\tline\nbreak/bucket", $mounts));
    }

    public function test_stacked_mounts_at_the_same_path_are_ambiguous_in_both_orders(): void
    {
        $mounts = implode("\n", [
            '1 0 8:1 / / rw - ext4 /dev/root rw',
            '2 1 8:2 / /data rw - xfs /dev/local rw',
            '3 1 0:42 / /data rw - nfs4 fs.example:/share rw',
        ]);
        self::assertNull(LinuxMountInfo::filesystemTypeForPath('/data/bucket', $mounts));

        $reverse = implode("\n", [
            '1 0 8:1 / / rw - ext4 /dev/root rw',
            '3 1 0:42 / /data rw - nfs4 fs.example:/share rw',
            '2 1 8:2 / /data rw - xfs /dev/local rw',
        ]);
        self::assertNull(LinuxMountInfo::filesystemTypeForPath('/data/bucket', $reverse));

        $tmpfsThenNfs = implode("\n", [
            '1 0 8:1 / / rw - ext4 /dev/root rw',
            '2 1 0:2 / /data rw - tmpfs tmpfs rw',
            '3 2 0:42 / /data rw - nfs4 fs.example:/share rw',
        ]);
        self::assertNull(LinuxMountInfo::filesystemTypeForPath('/data/identity.json', $tmpfsThenNfs));

        $moreSpecific = $tmpfsThenNfs . "\n" . '4 3 8:2 / /data/private rw - xfs /dev/local rw';
        self::assertSame('xfs', LinuxMountInfo::filesystemTypeForPath('/data/private/file', $moreSpecific));
    }

    public function test_exact_read_only_local_file_bind_rejects_parent_writable_and_stacked_mounts(): void
    {
        $root = "1 0 8:1 / / rw - ext4 /dev/root rw\n";
        $file = '2 1 0:2 /identity.json /run/identity.json ro - tmpfs tmpfs rw';

        self::assertTrue(LinuxMountInfo::isExactReadOnlyLocalFileBind('/run/identity.json', $root . $file));
        self::assertTrue(LinuxMountInfo::isExactReadOnlyLocalFileBind('/run/identity.json', $root . str_replace('tmpfs tmpfs', 'ext4 /dev/root', $file)));
        self::assertFalse(LinuxMountInfo::isExactReadOnlyLocalFileBind('/run/other.json', $root . $file));
        self::assertFalse(LinuxMountInfo::isExactReadOnlyLocalFileBind('/run/identity.json', $root . str_replace(' ro - ', ' rw - ', $file)));
        self::assertFalse(LinuxMountInfo::isExactReadOnlyLocalFileBind('/run/identity.json', $root . str_replace('tmpfs tmpfs', 'nfs4 server:/', $file)));
        self::assertFalse(LinuxMountInfo::isExactReadOnlyLocalFileBind('/run/identity.json', $root . $file . "\n3 2 0:3 /identity.json /run/identity.json ro - nfs4 server:/ rw"));
        self::assertFalse(LinuxMountInfo::isExactReadOnlyLocalFileBind('/run/identity.json', $root . $file . "\ninvalid"));
    }

    public function test_network_fuse_and_unknown_types_are_not_qualified_local_mounts(): void
    {
        foreach (['nfs', 'nfs4', 'cifs', 'smb3', 'fuse', 'fuse.sshfs', 'fuse.s3fs', 'virtiofs', '9p', 'ceph', 'newfs', 'tmpfs', 'ramfs', 'vfat', 'exfat', 'ntfs3'] as $type) {
            self::assertFalse(LinuxMountInfo::isQualifiedLocalFilesystem($type), $type);
        }
        foreach (['ext4', 'xfs', 'btrfs', 'overlay'] as $type) {
            self::assertTrue(LinuxMountInfo::isQualifiedLocalFilesystem($type), $type);
        }
    }

    public function test_missing_or_malformed_mount_information_fails_closed(): void
    {
        self::assertNull(LinuxMountInfo::filesystemTypeForPath('/data/bucket', ''));
        self::assertNull(LinuxMountInfo::filesystemTypeForPath('/data/bucket', '1 0 8:1 / / rw - ext4 /dev/root rw' . "\n" . 'invalid'));
        self::assertNull(LinuxMountInfo::filesystemTypeForPath('/data/bucket', '1 0 8:1 / /data\\q rw - ext4 /dev/root rw'));
    }
}
