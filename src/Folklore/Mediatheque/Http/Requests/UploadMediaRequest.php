<?php namespace Folklore\Mediatheque\Http\Requests;

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
        $type = $this->getFileType($file);
        $model = app($type->getModel());
        return [
            'id' => 'exists:'.$model->getTable(),
            'file' => ['required'],
        ];
    }
}
