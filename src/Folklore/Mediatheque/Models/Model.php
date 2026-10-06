<?php

namespace Folklore\Mediatheque\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Model extends Eloquent
{
    public function __construct(array $attributes = [])
    {
        $this->table = config('mediatheque.table_prefix').$this->table;
        parent::__construct($attributes);
    }

    /**
     * Convert a failure (exception or message) to text that fits a text column
     */
    protected function failureToString($failure): string
    {
        return mb_strcut((string) $failure, 0, 60000);
    }
}
