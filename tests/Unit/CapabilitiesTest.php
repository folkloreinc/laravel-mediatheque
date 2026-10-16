<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Enums\ProgressReporting;
use Folklore\Mediatheque\Transcoders\Capabilities;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CapabilitiesTest extends TestCase
{
    #[Test]
    public function a_transcoder_has_no_optional_ability_by_default(): void
    {
        $capabilities = Capabilities::none();

        $this->assertSame(ProgressReporting::None, $capabilities->progress);
        $this->assertFalse($capabilities->cancel);
        $this->assertFalse($capabilities->refresh);
        $this->assertFalse($capabilities->probe);
        $this->assertFalse($capabilities->remoteRenditions);
        $this->assertFalse($capabilities->needsRefreshSchedule());
    }

    #[Test]
    public function a_driver_with_refresh_needs_the_refresh_command_scheduled(): void
    {
        $eclair = new Capabilities(progress: ProgressReporting::None, cancel: true, refresh: true, probe: true);
        $mediaconvert = new Capabilities(progress: ProgressReporting::Polled, refresh: true);

        $this->assertTrue($eclair->needsRefreshSchedule());
        $this->assertTrue($mediaconvert->needsRefreshSchedule());
    }

    #[Test]
    public function polled_progress_needs_refresh(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Capabilities(progress: ProgressReporting::Polled);
    }
}
