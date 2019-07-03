<?php

namespace Folklore\Mediatheque\Http\Requests;

use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactory;

class UploadMediaRequest extends Request
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $file = $this->file('file');
        if (!$file) {
            return [
                'file' => ['required'],
            ];
        }
        $path = $file->getRealPath();
        $type = app(TypeFactory::class)->typeFromPath($path);
        $model = $type->newModel();
        return [
            'id' => 'exists:'.$model->getTable(),
            'file' => ['required'],
        ];
    }
}
