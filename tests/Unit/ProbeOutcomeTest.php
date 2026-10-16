<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Enums\ProbeKind;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Probe\ProbeOutcome;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ProbeOutcomeTest extends TestCase
{
    #[Test]
    public function a_done_probe_has_canonical_metadata_and_a_kind(): void
    {
        $outcome = ProbeOutcome::done(['kind' => 'video', 'frameRate' => 29.97, 'width' => 1920]);

        $this->assertTrue($outcome->isDone());
        $this->assertFalse($outcome->isPending());
        $this->assertFalse($outcome->isFailed());
        $this->assertSame(ProbeKind::Video, $outcome->kind());
        $this->assertSame(['kind' => 'video', 'frame_rate' => 29.97, 'width' => 1920], $outcome->metadata);
    }

    #[Test]
    public function the_kind_can_be_given_as_an_enum(): void
    {
        $this->assertSame(['kind' => 'audio'], ProbeOutcome::done(['kind' => ProbeKind::Audio])->metadata);
    }

    #[Test]
    public function a_probe_must_report_a_kind(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProbeOutcome::done(['width' => 1920]);
    }

    #[Test]
    public function a_probe_kind_is_never_a_mediatheque_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProbeOutcome::done(['kind' => 'captions']);
    }

    #[Test]
    public function a_pending_probe_has_the_driver_job_id(): void
    {
        $outcome = ProbeOutcome::pending('job_123');

        $this->assertTrue($outcome->isPending());
        $this->assertFalse($outcome->isDone());
        $this->assertSame('job_123', $outcome->externalId);
        $this->assertSame([], $outcome->metadata);
        $this->assertNull($outcome->kind());
    }

    #[Test]
    public function a_pending_probe_needs_an_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProbeOutcome::pending('');
    }

    #[Test]
    public function a_failed_probe_has_an_error(): void
    {
        $outcome = ProbeOutcome::failed(new TranscoderError(TranscoderErrorCode::InputUnreadable, 'Not a media file.'));

        $this->assertTrue($outcome->isFailed());
        $this->assertFalse($outcome->isDone());
        $this->assertSame(TranscoderErrorCode::InputUnreadable, $outcome->error?->code);
    }
}
