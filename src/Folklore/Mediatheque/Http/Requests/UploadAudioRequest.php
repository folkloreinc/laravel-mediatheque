<?php namespace Folklore\Mediatheque\Http\Requests;

use Folklore\Mediatheque\Contracts\Models\Audio as AudioContract;

class UploadAudioRequest extends UploadMediaRequest
{
    protected $modelContract = AudioContract::class;
    protected $mimeRegex = '/^audio\//';
}
