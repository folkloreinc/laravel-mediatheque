<?php

namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Models\Collections\MetadatasCollection;
use Folklore\Mediatheque\Contracts\Metadata\Value;

class Metadata extends Model
{
    protected $table = 'metadatas';

    protected $fillable = [
        'name',
        'type',
        'value_string',
        'value_text',
        'value_integer',
        'value_float',
        'value_boolean',
        'value_json',
    ];

    protected $casts = [
        'name' => 'string',
        'type' => 'string',
        'value_string' => 'string',
        'value_text' => 'string',
        'value_integer' => 'integer',
        'value_float' => 'float',
        'value_boolean' => 'boolean',
        'value_json' => 'array',
    ];

    public static function makeFromValue(Value $value)
    {
        $model = static::create();
        $model->setValue($value);
        return $model;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setValue(Value $value)
    {
        $type = $value->getType();
        $valueKey = sprintf('value_%s', $type);
        return $this->fill([
            'name' => $value->getName(),
            'type' => $type,
            $valueKey => $value->getValue(),
        ]);
    }

    public function getValue()
    {
        $valueKey = sprintf('value_%s', $this->type);
        return $this->{$valueKey};
    }
}
