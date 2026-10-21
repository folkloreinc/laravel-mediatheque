<?php

namespace Folklore\Mediatheque\Console;

use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Monitoring\FailureGroup;
use Folklore\Mediatheque\Monitoring\Monitoring;
use Folklore\Mediatheque\Monitoring\Period;
use Folklore\Mediatheque\Monitoring\RunFilters;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * What the package is doing, in the terminal (docs/decisions/0017): media
 * by status, runs in progress and stuck, what ended in the period, and the
 * latest failures.
 */
#[AsCommand(name: 'mediatheque:status')]
class StatusCommand extends Command
{
    protected $signature = 'mediatheque:status
                            {--hours=24 : The period, in hours up to now}
                            {--failures=10 : How many failure groups to list}';

    protected $description = 'Show the state of media and pipeline runs';

    public function handle(Monitoring $monitoring): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('failures'), FILTER_VALIDATE_INT);

        if (! is_int($hours) || $hours < 1 || ! is_int($limit) || $limit < 0) {
            $this->components->error('--hours and --failures take positive integers.');

            return self::INVALID;
        }

        $period = Period::lastHours($hours);
        $overview = $monitoring->overview($period);

        $this->components->twoColumnDetail('<options=bold>Media</>');
        foreach ($overview->media as $status => $count) {
            $this->components->twoColumnDetail($status, (string) $count);
        }

        $this->newLine();
        $this->components->twoColumnDetail('<options=bold>Runs</>');
        $this->components->twoColumnDetail('In progress', (string) $overview->runsInProgress);
        $this->components->twoColumnDetail('Stuck', $overview->stuckRuns > 0 ? '<fg=red>'.$overview->stuckRuns.'</>' : '0');
        foreach ($overview->runsEnded as $status => $count) {
            $this->components->twoColumnDetail(Str::ucfirst($status).' in the last '.$hours.' h', (string) $count);
        }
        $this->components->twoColumnDetail('Oldest queued rendition', $overview->oldestQueuedAt?->diffForHumans() ?? '—');

        if ($overview->stuckRuns > 0) {
            $this->newLine();
            $this->line('<options=bold>Stuck runs</>');
            $this->table(['Run', 'Pipeline', 'Transcoder', 'Media', 'Status', 'Progress'], array_map(fn (PipelineRun $run) => [
                $run->key(),
                $run->name(),
                $run->transcoder(),
                $run->media()->uid(),
                $run->status()->value,
                round($run->progress() * 100).' %',
            ], $monitoring->runs(new RunFilters(stuck: true), limit: 20)->items));
        }

        $failures = array_slice($monitoring->failures($period), 0, $limit);
        if ($failures !== []) {
            $this->newLine();
            $this->line('<options=bold>Failures in the last '.$hours.' h</>');
            $this->table(['Count', 'Code', 'Pipeline', 'Rendition', 'Transcoder', 'Last message', 'Last'], array_map(fn (FailureGroup $group) => [
                $group->count,
                $group->code->value,
                $group->pipeline,
                $group->rendition,
                $group->transcoder,
                Str::limit($group->lastMessage, 80),
                $group->lastFailedAt->diffForHumans(),
            ], $failures));
        }

        return self::SUCCESS;
    }
}
