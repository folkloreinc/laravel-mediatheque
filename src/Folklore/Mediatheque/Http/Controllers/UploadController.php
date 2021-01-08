<?php

namespace Folklore\Mediatheque\Http\Controllers;

use Illuminate\Http\Request;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactory;
use Folklore\Mediatheque\Http\Requests\UploadMediaRequest;

class UploadController extends Controller
{
    protected $typeFactory;

    public function __construct(TypeFactory $typeFactory)
    {
        $this->typeFactory = $typeFactory;
    }

    public function index(UploadMediaRequest $request, $type = null)
    {
        $file = $request->file('file');

        if (!$file) {
            return abort(502);
        }

        $type = $this->getFileType($file);
        if ($type->canUpload()) {
            $item = $type->newModel();
            return $this->updateItemFromRequest($item, $request);
        }

        return abort(404);
    }

    public function pull(Request $request)
    {
        $url = $request->get('url');

        if (empty($url)) {
            return abort(502);
        }

        return abort(500);
    }

    protected function getFileType($file)
    {
        return $this->typeFactory->typeFromPath($file->getRealPath());
    }

    protected function updateItemFromRequest($item, Request $request)
    {
        if ($request->has('id')) {
            $item = $item->newQuery()->findOrFail($request->get('id'));
        }

        $file = $request->file('file');
        $item->setOriginalFile($file);
        $item->save();
        $item->load('files');

        return $item;
    }

    public function __call($method, $args)
    {
        if (mediatheque()->hasType($method)) {
            $request = app(UploadMediaRequest::class);
            return $this->index($request, $method);
        }
        return abort(404);
    }
}
