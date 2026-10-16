<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Statuses only move forward (docs/decisions/0012).
 */
class StatusesTest extends TestCase
{
    #[Test]
    public function a_job_moves_forward_only(): void
    {
        $allowed = [
            'queued' => ['running', 'completed', 'failed', 'skipped', 'canceled'],
            'running' => ['completed', 'failed', 'skipped', 'canceled'],
            'completed' => [],
            'failed' => [],
            'skipped' => [],
            'canceled' => [],
        ];

        foreach (JobStatus::cases() as $from) {
            foreach (JobStatus::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, $allowed[$from->value], true),
                    $from->canMoveTo($to),
                    "{$from->value} → {$to->value}",
                );
            }
        }
    }

    #[Test]
    public function a_run_moves_forward_only(): void
    {
        $allowed = [
            'pending' => ['running', 'completed', 'failed', 'canceled'],
            'running' => ['completed', 'failed', 'canceled'],
            'completed' => [],
            'failed' => [],
            'canceled' => [],
        ];

        foreach (PipelineStatus::cases() as $from) {
            foreach (PipelineStatus::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, $allowed[$from->value], true),
                    $from->canMoveTo($to),
                    "{$from->value} → {$to->value}",
                );
            }
        }
    }

    #[Test]
    public function final_statuses(): void
    {
        $this->assertSame(
            ['completed', 'failed', 'skipped', 'canceled'],
            array_values(array_map(fn (JobStatus $status) => $status->value, array_filter(JobStatus::cases(), fn (JobStatus $status) => $status->isFinal()))),
        );
        $this->assertSame(
            ['completed', 'failed', 'canceled'],
            array_values(array_map(fn (PipelineStatus $status) => $status->value, array_filter(PipelineStatus::cases(), fn (PipelineStatus $status) => $status->isFinal()))),
        );
    }
}
