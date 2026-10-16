<?php

namespace Folklore\Mediatheque\Tests\Unit\Services;

use Folklore\Mediatheque\Contracts\Services\Extension as ExtensionService;
use Folklore\Mediatheque\Contracts\Services\Mime as MimeService;
use Folklore\Mediatheque\Tests\TestCase;

class MetadataExtensionTest extends TestCase
{
    // A 1x1 transparent PNG
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    protected function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mediatheque-test-');
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }

    protected function getExtension(string $path, string $filename): ?string
    {
        return app(ExtensionService::class)->getExtension($path, $filename);
    }

    public function test_extension_comes_from_the_content_and_not_the_client_name()
    {
        $path = $this->temporaryFile(base64_decode(self::PNG)."<?php echo 'test'; ?>");

        $this->assertEquals('png', $this->getExtension($path, 'image.php'));
        $this->assertEquals('png', $this->getExtension($path, 'image.phtml'));
        $this->assertEquals('png', $this->getExtension($path, 'image.html'));
        $this->assertEquals('png', $this->getExtension($path, 'image.svg'));
    }

    public function test_client_extension_is_kept_when_it_matches_the_content()
    {
        $this->assertEquals('png', $this->getExtension($this->temporaryFile(base64_decode(self::PNG)), 'image.PNG'));
        $this->assertEquals('jpg', $this->getExtension(public_path('image.jpg'), 'photo.jpeg'));
        $this->assertEquals('svg', $this->getExtension(public_path('image.svg'), 'icon.svg'));
        $this->assertEquals('mp4', $this->getExtension(public_path('test.mp4'), 'video.mp4'));
    }

    public function test_dangerous_extensions_are_replaced_for_generic_content()
    {
        $binary = $this->temporaryFile(str_repeat("\x00", 8).str_repeat("\xde\xad\xbe\xef", 16)."<?php echo 'test'; ?>");
        $this->assertEquals('application/octet-stream', app(MimeService::class)->getMime($binary));
        $this->assertEquals('bin', $this->getExtension($binary, 'file.php'));
        $this->assertEquals('bin', $this->getExtension($binary, 'file.pHp5'));

        $text = $this->temporaryFile("Some notes\nwith a second line\n");
        $this->assertEquals('text/plain', app(MimeService::class)->getMime($text));
        $this->assertEquals('txt', $this->getExtension($text, 'page.html'));
        $this->assertEquals('txt', $this->getExtension($text, 'image.svg'));
        $this->assertEquals('txt', $this->getExtension($text, 'notes'));
    }

    public function test_safe_extensions_are_kept_for_generic_content()
    {
        $text = $this->temporaryFile("Some notes\nwith a second line\n");
        $this->assertEquals('md', $this->getExtension($text, 'notes.md'));
        $this->assertEquals('csv', $this->getExtension($text, 'data.CSV'));
    }

    public function test_blocked_extensions_can_be_configured()
    {
        config(['mediatheque.uploads.blocked_extensions' => ['md']]);

        $text = $this->temporaryFile("Some notes\nwith a second line\n");
        $this->assertEquals('txt', $this->getExtension($text, 'notes.md'));
        $this->assertEquals('html', $this->getExtension($text, 'page.html'));
    }
}
