<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Contracts\Metadata\Value;
use Folklore\Mediatheque\Metadata\Reader;
use Folklore\Mediatheque\Tests\TestCase;
use InvalidArgumentException;

class MetadataManagerTest extends TestCase
{
    public function test_extend_with_a_closure()
    {
        $reader = new class extends Reader
        {
            public function getValue(string $path): ?Value
            {
                return null;
            }
        };

        app('mediatheque.metadatas')->extend('custom', function ($app, $name) use ($reader) {
            $reader->setName($name);

            return $reader;
        });

        $this->assertSame($reader, app('mediatheque.metadatas')->metadata('custom'));
        $this->assertEquals('custom', $reader->getName());
    }

    public function test_unknown_reader_throws()
    {
        $this->expectException(InvalidArgumentException::class);

        app('mediatheque.metadatas')->metadata('unknown');
    }
}
