<?php namespace Folklore\Mediatheque\Http\Requests;

class UploadMediaRequest extends Request
{
    protected $modelContract = null;
    protected $mimeRegex = '/^/';

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $types = array_values(config('mediatheque.types', []));
        $mimes = array_reduce($types, function ($mimes, $type) {
            $typeMimes = array_get($type, 'mimes', []);
            $matchingMimes = array_where(array_keys($typeMimes), function ($value, $key) {
                return preg_match($this->mimeRegex, $value);
            }, []);
            return array_merge($mimes, $matchingMimes);
        }, []);

        $model = app($this->modelContract);
        $rules = [
            'id' => 'exists:'.$model->getTable()
        ];
        if (!empty($mimes)) {
            // $rules['file'] = 'mimetypes:'.implode(',', $mimes);
        }
        return $rules;
    }
}
