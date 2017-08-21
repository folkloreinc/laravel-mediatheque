<?php namespace Folklore\Mediatheque\Http\Requests;

use Folklore\Mediatheque\Contracts\Models\Video as VideoContract;

class UploadVideoRequest extends UploadMediaRequest
{
    protected $modelContract = VideoContract::class;
    protected $mimeRegex = '/^video\//';
}
