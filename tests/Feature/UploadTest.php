<?php

namespace Folklore\Mediatheque\Tests\Feature;

use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

class UploadTest extends TestCase
{
    // A 1x1 transparent PNG
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testbench']);

        // Pipelines are not under test here
        foreach (app('mediatheque.types')->types() as $type) {
            $type->set('pipeline', null);
        }
    }

    protected function tearDown(): void
    {
        foreach (['image', 'document'] as $type) {
            $path = storage_path('app/public/'.$type);
            if (app('files')->exists($path)) {
                app('files')->deleteDirectory($path);
            }
        }

        parent::tearDown();
    }

    protected function upload(UploadedFile $file, array $data = [])
    {
        return $this->post(
            route('mediatheque.upload'),
            array_merge($data, ['file' => $file]),
            ['Accept' => 'application/json']
        );
    }

    protected function png(string $name, string $suffix = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG).$suffix);
    }

    protected function lastMedia()
    {
        return app(MediaContract::class)->newQuery()->orderBy('id', 'desc')->first();
    }

    public function test_stored_extension_comes_from_the_content()
    {
        $this->upload($this->png('shell.php', "<?php echo 'test'; ?>"))->assertSuccessful();

        $file = $this->lastMedia()->getOriginalFile();
        $this->assertEquals('image', $this->lastMedia()->type);
        $this->assertStringEndsWith('.png', $file->path);
        $this->assertFileExists(storage_path('app/public/'.$file->path));
        $this->assertEquals('shell.php', $file->name);
    }

    public function test_stored_extension_ignores_the_client_mime_type()
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mediatheque-test-');
        file_put_contents($tmp, "Some notes\n");
        $file = new UploadedFile($tmp, 'notes', 'text/html', null, true);

        $this->upload($file)->assertSuccessful();

        $this->assertStringEndsWith('.txt', $this->lastMedia()->getOriginalFile()->path);
    }

    public function test_unsupported_files_are_rejected()
    {
        $file = UploadedFile::fake()->createWithContent('script.php', "<?php\necho 'test';\n");

        $this->upload($file)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertNull($this->lastMedia());
    }

    public function test_files_larger_than_the_max_size_are_rejected()
    {
        config(['mediatheque.uploads.max_size' => 1]);

        $this->upload($this->png('image.png', str_repeat(' ', 2048)))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->upload($this->png('image.png'))->assertSuccessful();
    }

    public function test_media_cannot_be_replaced_by_default()
    {
        $this->upload($this->png('first.png'))->assertSuccessful();
        $media = $this->lastMedia();
        $path = $media->getOriginalFile()->path;

        $this->upload($this->png('second.png'), ['id' => $media->id])->assertForbidden();

        $media->refresh()->load('files');
        $this->assertEquals('first.png', $media->getOriginalFile()->name);
        $this->assertEquals($path, $media->getOriginalFile()->path);
    }

    public function test_media_can_be_replaced_when_enabled()
    {
        config(['mediatheque.uploads.allow_replace' => true]);

        $this->upload($this->png('first.png'))->assertSuccessful();
        $media = $this->lastMedia();

        $this->upload($this->png('second.png'), ['id' => $media->id])->assertSuccessful();

        $media->refresh()->load('files');
        $this->assertEquals('second.png', $media->getOriginalFile()->name);
    }

    public function test_media_replacement_is_authorized_by_the_gate()
    {
        $this->upload($this->png('first.png'))->assertSuccessful();
        $allowed = $this->lastMedia();
        $this->upload($this->png('first.png'))->assertSuccessful();
        $denied = $this->lastMedia();

        Gate::define('mediatheque.replace', function ($user, $media) use ($allowed) {
            return $media->id === $allowed->id;
        });
        $this->actingAs(new User);

        $this->upload($this->png('second.png'), ['id' => $denied->id])->assertForbidden();
        $this->upload($this->png('second.png'), ['id' => $allowed->id])->assertSuccessful();

        $this->assertEquals('first.png', $denied->refresh()->load('files')->getOriginalFile()->name);
        $this->assertEquals('second.png', $allowed->refresh()->load('files')->getOriginalFile()->name);
    }

    public function test_uploads_are_authorized_by_the_gate_when_defined()
    {
        Gate::define('mediatheque.upload', function ($user = null) {
            return false;
        });

        $this->upload($this->png('image.png'))->assertForbidden();
        $this->assertNull($this->lastMedia());
    }

    public function test_svg_files_are_sanitized()
    {
        $svg = '<?xml version="1.0"?>'
            .'<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" onload="alert(1)">'
            .'<script>alert(2)</script>'
            .'<a href="javascript:alert(3)"><rect width="10" height="10" fill="red"/></a>'
            .'</svg>';
        $file = UploadedFile::fake()->createWithContent('image.svg', $svg);

        $this->upload($file)->assertSuccessful();

        $media = $this->lastMedia();
        $original = $media->getOriginalFile();
        $this->assertStringEndsWith('.svg', $original->path);
        $contents = file_get_contents(storage_path('app/public/'.$original->path));
        $this->assertStringNotContainsString('alert', $contents);
        $this->assertStringContainsString('<rect', $contents);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $contents);
        $this->assertEquals(strlen($contents), $original->size);
    }

    public function test_invalid_svg_files_are_rejected()
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</svg>';
        $file = UploadedFile::fake()->createWithContent('image.svg', $svg);

        $this->upload($file)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertNull($this->lastMedia());
    }

    public function test_type_routes_cannot_replace_media_by_default()
    {
        $this->upload($this->png('first.png'))->assertSuccessful();
        $media = $this->lastMedia();

        $this->post(
            route('mediatheque.upload.image'),
            ['file' => $this->png('second.png'), 'id' => $media->id],
            ['Accept' => 'application/json']
        )->assertForbidden();

        $this->assertEquals('first.png', $media->refresh()->load('files')->getOriginalFile()->name);
    }
}
