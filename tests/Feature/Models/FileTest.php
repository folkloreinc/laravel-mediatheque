<?php

namespace Folklore\Mediatheque\Tests\Feature\Models;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Tests\TestCase;

class FileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testbench']);
    }

    public function test_search_matches_handle_name_or_path()
    {
        $this->makeFile(['handle' => 'original', 'name' => 'portrait.jpg', 'path' => 'image/a.jpg']);
        $this->makeFile(['handle' => 'thumbnail', 'name' => 'cover.jpg', 'path' => 'image/landscape.jpg']);

        $query = app(FileContract::class)->newQuery();

        $this->assertEquals(['portrait.jpg'], (clone $query)->search('portrait')->pluck('name')->all());
        $this->assertEquals(['cover.jpg'], (clone $query)->search('landscape')->pluck('name')->all());
        $this->assertEquals(['cover.jpg'], (clone $query)->search('thumbnail')->pluck('name')->all());
        $this->assertEquals(0, (clone $query)->search('nothing')->count());
    }

    protected function makeFile(array $attributes): void
    {
        $file = app(FileContract::class);
        $file->fill($attributes);
        $file->save();
    }
}
