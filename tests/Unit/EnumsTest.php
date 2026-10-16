<?php

namespace Folklore\Mediatheque\Tests\Unit;

use BackedEnum;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\ProbeKind;
use Folklore\Mediatheque\Enums\ProgressReporting;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Enums\Visibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The stored and exchanged values of the enums are public API: the database,
 * the JSON resource, Eclair's protocol and laravel-eclair rely on them.
 */
class EnumsTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<BackedEnum>, list<string>}>
     */
    public static function enums(): iterable
    {
        yield 'MediaStatus' => [MediaStatus::class, ['pending', 'processing', 'ready', 'failed']];
        yield 'Visibility' => [Visibility::class, ['public', 'private']];
        yield 'PipelineStatus' => [PipelineStatus::class, ['pending', 'running', 'completed', 'failed', 'canceled']];
        yield 'JobStatus' => [JobStatus::class, ['queued', 'running', 'completed', 'failed', 'skipped', 'canceled']];
        yield 'ProbeKind' => [ProbeKind::class, ['image', 'video', 'audio', 'document', 'font', 'other']];
        yield 'ProgressReporting' => [ProgressReporting::class, ['none', 'pushed', 'polled']];
        yield 'TranscoderErrorCode (Eclair\'s task error codes)' => [TranscoderErrorCode::class, [
            'input_unreadable', 'unsupported_input', 'timeout', 'limit_exceeded',
            'operation_failed', 'storage_failed', 'provider_failed', 'dependency_failed',
        ]];
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @param  list<string>  $values
     */
    #[Test]
    #[DataProvider('enums')]
    public function values_are_stable(string $enum, array $values): void
    {
        $this->assertSame($values, array_map(fn (BackedEnum $case) => $case->value, $enum::cases()));
    }

    #[Test]
    public function an_unknown_error_code_is_read_as_operation_failed(): void
    {
        $this->assertSame(TranscoderErrorCode::Timeout, TranscoderErrorCode::fromDriver('timeout'));
        $this->assertSame(TranscoderErrorCode::OperationFailed, TranscoderErrorCode::fromDriver('gpu_on_fire'));
        $this->assertSame(TranscoderErrorCode::OperationFailed, TranscoderErrorCode::fromDriver(null));
    }
}
