<?php

namespace Folklore\Mediatheque\Http\Requests;

use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Contracts\Services\Mime as MimeService;
use Folklore\Mediatheque\Contracts\Services\SvgSanitizer;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactory;
use Illuminate\Support\Facades\Gate;

class UploadMediaRequest extends Request
{
    public function authorize()
    {
        // Replacing the file of an existing media needs the "mediatheque.replace"
        // gate, or the "mediatheque.uploads.allow_replace" option
        if ($this->has('id') && ! static::canReplaceMedia()) {
            return false;
        }

        if (Gate::has('mediatheque.upload')) {
            return Gate::allows('mediatheque.upload');
        }

        return true;
    }

    /**
     * Check if the upload routes may replace the file of an existing media.
     * When the "mediatheque.replace" gate is defined, the controller checks it
     * for each media.
     */
    public static function canReplaceMedia(): bool
    {
        return Gate::has('mediatheque.replace') ||
            (bool) config('mediatheque.uploads.allow_replace', false);
    }

    public function rules()
    {
        $file = $this->file('file');
        if (! $file) {
            return [
                'file' => ['required'],
            ];
        }
        $path = $file->getRealPath();
        $type = $path !== false ? app(TypeFactory::class)->typeFromPath($path) : null;
        $model = ! is_null($type) ? $type->newModel() : app(MediaContract::class);
        $maxSize = config('mediatheque.uploads.max_size');

        return [
            'id' => 'exists:'.$model->getTable(),
            'file' => array_merge(
                ['required', 'file'],
                ! empty($maxSize) ? ['max:'.$maxSize] : [],
                [
                    function ($attribute, $value, $fail) use ($type) {
                        if (is_null($type) || ! $type->canUpload()) {
                            $fail('The file type is not supported.');
                        }
                    },
                    function ($attribute, $value, $fail) use ($path) {
                        if ($path !== false && ! $this->isValidSvg($path)) {
                            $fail('The SVG file is invalid.');
                        }
                    },
                ]
            ),
        ];
    }

    protected function isValidSvg(string $path): bool
    {
        if (
            ! config('mediatheque.uploads.sanitize_svg', true) ||
            ! in_array(app(MimeService::class)->getMime($path), SvgSanitizer::MIMES, true)
        ) {
            return true;
        }

        $contents = file_get_contents($path);

        return $contents !== false && ! is_null(app(SvgSanitizer::class)->sanitize($contents));
    }
}
