<?php

namespace Folklore\Mediatheque\Http\Controllers;

use Illuminate\Http\Request;
use Folklore\Mediatheque\Http\Requests\UploadPictureRequest;
use Folklore\Mediatheque\Http\Requests\UploadAudioRequest;
use Folklore\Mediatheque\Http\Requests\UploadVideoRequest;
use Folklore\Mediatheque\Http\Requests\UploadDocumentRequest;
use Folklore\Mediatheque\Http\Requests\UploadFontRequest;
use Folklore\Mediatheque\Contracts\MimeGetter;
use Folklore\Mediatheque\Contracts\Models\Picture as PictureContract;
use Folklore\Mediatheque\Contracts\Models\Audio as AudioContract;
use Folklore\Mediatheque\Contracts\Models\Video as VideoContract;
use Folklore\Mediatheque\Contracts\Models\Document as DocumentContract;
use Folklore\Mediatheque\Contracts\Models\Font as FontContract;

class UploadController extends Controller
{
    public function index(Request $request)
    {
        $file = $request->file('file');

        if (!$file) {
            return abort(502);
        }

        $type = $this->getFileType($file);

        if ($type !== null) {
            return app()->call(static::class.'@'.$type);
        }

        return abort(500);
    }

    protected function getFileType($file)
    {
        $mime = app(MimeGetter::class)->getMime($file->getRealPath());
        $type = null;
        foreach (config('mediatheque.mimes') as $key => $mimes) {
            if (isset($mimes[$mime])) {
                $type = $key;
                break;
            }
        }
        return $type;
    }

    public function pull(Request $request)
    {
        $url = $request->get('url');

        if (empty($url)) {
            return abort(502);
        }

        return abort(500);
    }

    public function picture(UploadPictureRequest $request)
    {
        $item = app(PictureContract::class);
        return $this->updateItemFromRequest($item, $request);
    }

    public function audio(UploadAudioRequest $request)
    {
        $item = app(AudioContract::class);
        return $this->updateItemFromRequest($item, $request);
    }

    public function video(UploadVideoRequest $request)
    {
        $item = app(VideoContract::class);
        return $this->updateItemFromRequest($item, $request);
    }

    public function document(UploadDocumentRequest $request)
    {
        $item = app(DocumentContract::class);
        return $this->updateItemFromRequest($item, $request);
    }

    public function font(UploadFontRequest $request)
    {
        $item = app(FontContract::class);
        return $this->updateItemFromRequest($item, $request);
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
}
