<?php

namespace Folklore\Mediatheque\Tests\Unit\Rector;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * The Rector rules of `rector.php`, on 1.x code (docs/upgrade.md). Each
 * fixture is the code before and after, separated by `-----`.
 */
class RectorRulesTest extends AbstractRectorTestCase
{
    #[Test]
    #[DataProvider('fixtures')]
    public function it_rewrites_1x_code(string $path): void
    {
        $this->doTestFile($path);
    }

    /**
     * @return Iterator<array-key, array<int, string>>
     */
    public static function fixtures(): Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__.'/Fixture');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__.'/../../../rector.php';
    }
}
