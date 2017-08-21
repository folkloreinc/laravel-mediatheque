<?php namespace Folklore\Mediatheque\Http\Requests;

use Folklore\Mediatheque\Contracts\Models\Picture as PictureContract;

class UploadPictureRequest extends UploadMediaRequest
{
    protected $modelContract = PictureContract::class;
    protected $mimeRegex = '/^image\//';
}
