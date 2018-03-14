<?php

namespace Folklore\Mediatheque\Http\Controllers;

use Illuminate\Http\Request;
use Folklore\Mediatheque\Http\Requests\UploadImageRequest;
use Folklore\Mediatheque\Http\Requests\UploadAudioRequest;
use Folklore\Mediatheque\Http\Requests\UploadVideoRequest;
use Folklore\Mediatheque\Http\Requests\UploadDocumentRequest;
use Folklore\Mediatheque\Http\Requests\UploadFontRequest;
use Folklore\Mediatheque\Contracts\Getter\Mime as MimeGetter;
use Folklore\Mediatheque\Contracts\Model\Image as ImageContract;
use Folklore\Mediatheque\Contracts\Model\Audio as AudioContract;
use Folklore\Mediatheque\Contracts\Model\Video as VideoContract;
use Folklore\Mediatheque\Contracts\Model\Document as DocumentContract;
use Folklore\Mediatheque\Contracts\Model\Font as FontContract;

class UploadController extends Controller
{
    public function index(Request $request)
    {
        $file = $request->file('file');

        if (!$file) {
            return abort(502);
        }

        $type = $this->getFileType($file);
        $typeConfig = config('mediatheque.types.'.$type, null);
        $canUpload = $type !== null && $typeConfig !== null ? array_get($typeConfig, 'upload', true) : false;
        if ($canUpload) {
            return app()->call(static::class.'@'.$type);
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

    public function image(UploadImageRequest $request)
    {
        $item = app(ImageContract::class);
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

    protected function getFileType($file)
    {
        $mime = app(MimeGetter::class)->getMime($file->getRealPath());
        foreach (config('mediatheque.types') as $key => $type) {
            $mimes = array_keys(array_get($type, 'mimes', []));
            $foundMime = array_has($mimes, function ($it) use ($mime) {
                $pattern = str_replace('\*', '[^\]+', preg_quote($it));
                return preg_match('/^'.$pattern.'$/', $mime);
            });
            if ($foundMime) {
                return $key;
            }
        }
        return null;
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
