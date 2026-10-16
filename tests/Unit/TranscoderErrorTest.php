<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TranscoderErrorTest extends TestCase
{
    #[Test]
    public function it_reads_a_driver_error(): void
    {
        $error = TranscoderError::fromDriver('limit_exceeded', 'The file is longer than 4 hours.');

        $this->assertSame(TranscoderErrorCode::LimitExceeded, $error->code);
        $this->assertSame('The file is longer than 4 hours.', $error->message);
    }

    #[Test]
    public function an_unknown_code_is_operation_failed(): void
    {
        $this->assertSame(TranscoderErrorCode::OperationFailed, TranscoderError::fromDriver('E_FFMPEG_137', 'Killed')->code);
    }

    #[Test]
    public function it_is_stored_as_code_and_message(): void
    {
        $error = new TranscoderError(TranscoderErrorCode::Timeout, 'Took too long.');

        $this->assertSame(['code' => 'timeout', 'message' => 'Took too long.'], $error->toArray());
        $this->assertEquals($error, TranscoderError::fromArray($error->toArray()));
        $this->assertEquals(new TranscoderError(TranscoderErrorCode::OperationFailed, ''), TranscoderError::fromArray([]));
    }

    #[Test]
    public function a_dependency_failure_names_its_input(): void
    {
        $error = TranscoderError::dependencyFailed('h264');

        $this->assertSame(TranscoderErrorCode::DependencyFailed, $error->code);
        $this->assertStringContainsString('"h264"', $error->message);
    }
}
