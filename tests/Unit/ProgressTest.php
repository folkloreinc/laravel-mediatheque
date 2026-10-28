<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Pipelines\Progress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ProgressTest extends TestCase
{
    /**
     * @return iterable<string, array{float|null, float, float, bool}>
     */
    public static function steps(): iterable
    {
        yield 'a first measure under the step' => [null, 0.04, 0.05, false];
        yield 'a first measure at the step' => [null, 0.05, 0.05, true];
        yield 'within a step' => [0.05, 0.09, 0.05, false];
        yield 'onto the next step, despite floats' => [0.1, 0.15, 0.05, true];
        yield 'several steps at once' => [0.05, 0.4, 0.05, true];
        yield 'the same value' => [0.4, 0.4, 0.05, false];
        yield 'to the end' => [0.99, 1.0, 0.05, true];
        yield 'a larger step' => [0.3, 0.45, 0.25, false];
    }

    #[Test]
    #[DataProvider('steps')]
    public function an_event_is_due_when_the_progress_passes_a_multiple_of_the_step(?float $before, float $after, float $step, bool $passes): void
    {
        $this->assertSame($passes, Progress::passesStep($before, $after, $step));
    }
}
