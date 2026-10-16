<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Pipelines\RenditionCondition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RenditionConditionTest extends TestCase
{
    #[Test]
    public function an_empty_condition_matches_anything(): void
    {
        $condition = RenditionCondition::fromArray('h264', []);

        $this->assertTrue($condition->isEmpty());
        $this->assertTrue($condition->matches([]));
    }

    #[Test]
    public function it_matches_the_probe_kind(): void
    {
        $condition = RenditionCondition::fromArray('waveform', ['kind' => ['audio', 'video']]);

        $this->assertTrue($condition->matches(['kind' => 'audio']));
        $this->assertTrue($condition->matches(['kind' => 'video']));
        $this->assertFalse($condition->matches(['kind' => 'image']));
        $this->assertFalse($condition->matches([]));
    }

    #[Test]
    public function it_matches_mime_patterns(): void
    {
        $condition = RenditionCondition::fromArray('h264', ['mime' => ['video/*', 'image/gif']]);

        $this->assertTrue($condition->matches(['mime' => 'video/quicktime']));
        $this->assertTrue($condition->matches(['mime' => 'image/gif']));
        $this->assertFalse($condition->matches(['mime' => 'image/png']));
    }

    #[Test]
    public function it_matches_durations_in_seconds(): void
    {
        $condition = RenditionCondition::fromArray('highlights', ['min_duration' => 30, 'max_duration' => 3600]);

        $this->assertTrue($condition->matches(['duration' => 30]));
        $this->assertTrue($condition->matches(['duration' => 2851.2]));
        $this->assertFalse($condition->matches(['duration' => 12.5]));
        $this->assertFalse($condition->matches(['duration' => 3600.5]));
        $this->assertFalse($condition->matches([]), 'A condition on a value the probe did not report does not match.');
    }
}
