<?php namespace Folklore\Mediatheque\Http\Requests;

use Folklore\Mediatheque\Contracts\Models\Font as FontContract;

class UploadFontRequest extends UploadMediaRequest
{
    protected $modelContract = FontContract::class;
    protected $mimeRegex = '/^application\//';
}
