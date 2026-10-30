<?php

namespace Folklore\Mediatheque\Tests\Unit\Upgrade;

use Folklore\Mediatheque\Upgrade\LegacyConfigFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A 1.x config file read as it is written: the defaults of its env() calls,
 * whatever this machine's environment holds (#147).
 */
class LegacyConfigFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/mediatheque-legacy-config-'.bin2hex(random_bytes(4)).'.php';
        putenv('MEDIATHEQUE_TEST_BIN=/opt/homebrew/bin/ffmpeg');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        putenv('MEDIATHEQUE_TEST_BIN');

        parent::tearDown();
    }

    #[Test]
    public function env_calls_give_their_default_and_their_keys_are_listed(): void
    {
        file_put_contents($this->path, <<<'PHP'
            <?php

            $base = ['root' => __DIR__];

            return [
                'process_timeout' => env('MEDIATHEQUE_TEST_TIMEOUT', 600),
                'url' => env('APP_URL').'/storage',
                'root' => $base['root'],
                'file' => __FILE__,
                'services' => array(
                    'ffmpeg' => [
                        'ffmpeg.binaries' => env('MEDIATHEQUE_TEST_BIN', '/usr/local/bin/ffmpeg'),
                        "ffprobe.binaries" => \env("MEDIATHEQUE_TEST_PROBE", "/usr/local/bin/ffprobe"),
                    ],
                ),
                'list' => [env('MEDIATHEQUE_TEST_LIST')],
                'role' => env('MEDIATHEQUE_TEST_ROLE') ? ['job' => 'convert'] : null,
                'lazy' => env('MEDIATHEQUE_TEST_LAZY', fn () => 'computed'),
            ];
            PHP);

        $file = LegacyConfigFile::read($this->path);

        $this->assertSame([
            'process_timeout' => 600,
            'url' => '/storage',
            'root' => dirname($this->path),
            'file' => $this->path,
            'services' => [
                'ffmpeg' => [
                    'ffmpeg.binaries' => '/usr/local/bin/ffmpeg',
                    'ffprobe.binaries' => '/usr/local/bin/ffprobe',
                ],
            ],
            'list' => [null],
            'role' => null,
            'lazy' => 'computed',
        ], $file->config);

        $this->assertSame([
            'process_timeout' => 'MEDIATHEQUE_TEST_TIMEOUT',
            'services.ffmpeg.ffmpeg.binaries' => 'MEDIATHEQUE_TEST_BIN',
            'services.ffmpeg.ffprobe.binaries' => 'MEDIATHEQUE_TEST_PROBE',
            'lazy' => 'MEDIATHEQUE_TEST_LAZY',
        ], $file->env);

        $this->assertSame([
            'MEDIATHEQUE_TEST_TIMEOUT',
            'APP_URL',
            'MEDIATHEQUE_TEST_BIN',
            'MEDIATHEQUE_TEST_PROBE',
            'MEDIATHEQUE_TEST_LIST',
            'MEDIATHEQUE_TEST_ROLE',
            'MEDIATHEQUE_TEST_LAZY',
        ], $file->variables);
        $this->assertSame([], $file->code, 'array(), closures and offsets read nothing of this machine.');
    }

    #[Test]
    public function a_file_that_returns_no_array_is_refused(): void
    {
        file_put_contents($this->path, "<?php\n\nreturn 'config';\n");

        $this->expectException(RuntimeException::class);

        LegacyConfigFile::read($this->path);
    }
}
