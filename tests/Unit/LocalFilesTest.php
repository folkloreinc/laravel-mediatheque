<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Support\LocalFiles;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LocalFilesTest extends TestCase
{
    #[Test]
    #[RequiresFunction('posix_geteuid')]
    public function its_temporary_directory_belongs_to_the_current_user(): void
    {
        $root = LocalFiles::root();

        // Workers and commands run by another user get their own directory.
        $this->assertSame(rtrim(sys_get_temp_dir(), '/').'/mediatheque-'.posix_geteuid(), $root);
        $this->assertDirectoryExists($root);
        $this->assertDirectoryIsWritable($root);
        $this->assertSame(posix_geteuid(), fileowner($root));
        $this->assertSame(0700, fileperms($root) & 0777);
    }

    #[Test]
    public function its_temporary_files_live_in_its_temporary_directory(): void
    {
        $path = (new LocalFiles($this->createStub(Filesystems::class)))->temporaryPath('mp4');

        $this->assertStringStartsWith(LocalFiles::root().'/', $path);
        $this->assertStringEndsWith('.mp4', $path);
    }
}
