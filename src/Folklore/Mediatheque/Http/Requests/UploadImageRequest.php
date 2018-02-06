<?php namespace Folklore\Mediatheque\Http\Requests;

use Folklore\Mediatheque\Contracts\Models\Image as ImageContract;

class UploadImageRequest extends UploadMediaRequest
{
    protected $modelContract = ImageContract::class;
    protected $mimeRegex = '/^image\//';
}
