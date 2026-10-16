<?php

namespace Folklore\Mediatheque\Contracts;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Probe\ProbeOutcome;

/**
 * Reads a file's technical metadata (docs/decisions/0012). The probe runs in
 * a queued job, and can take longer than it: a prober then returns a
 * pending outcome, recorded as a run of the `probe` pipeline that its driver
 * completes through the recorder.
 *
 * The metadata uses the canonical keys and always has a `kind`. The media
 * stays `pending` until the probe completes; its pipeline starts on
 * `MediaProbed`. A failed probe fails the media.
 */
interface Prober
{
    public function probe(File $file): ProbeOutcome;
}
