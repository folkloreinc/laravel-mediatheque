<?php namespace Folklore\Mediatheque\Http\Requests;

use Folklore\Mediatheque\Contracts\Model\Document as DocumentContract;

class UploadDocumentRequest extends UploadMediaRequest
{
    protected $modelContract = DocumentContract::class;
    protected $mimeRegex = '/^application\//';
}
