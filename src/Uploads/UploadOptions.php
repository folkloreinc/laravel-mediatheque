<?php

namespace Folklore\Mediatheque\Uploads;

use Closure;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Models\Upload;
use Illuminate\Http\Request;

/**
 * The options of the media an upload creates, set by the application with
 * `Mediatheque::uploadsUsing()` from its own context (docs/decisions/0015):
 * a tenant id, a visibility, a pipeline. Never from fields the client sends.
 */
class UploadOptions
{
    /** @var (Closure(Request, Upload): (IngestOptions|null))|null */
    private ?Closure $resolver = null;

    /**
     * @param  Closure(Request, Upload): (IngestOptions|null)  $resolver
     */
    public function using(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function resolve(Request $request, Upload $upload): IngestOptions
    {
        if ($this->resolver === null) {
            return new IngestOptions;
        }

        return ($this->resolver)($request, $upload) ?? new IngestOptions;
    }
}
