<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Support\Type;
use Folklore\Mediatheque\Tests\TestCase;

class TypeManagerTest extends TestCase
{
    public function test_extend_with_a_closure()
    {
        $type = new Type('custom', ['mimes' => ['text/x-custom' => 'custom']]);

        app('mediatheque.types')->extend('custom', function ($app, $name) use ($type) {
            return $type;
        });

        $this->assertSame($type, app('mediatheque.types')->type('custom'));
    }
}
