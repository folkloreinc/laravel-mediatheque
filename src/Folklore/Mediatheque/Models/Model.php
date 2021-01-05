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
}
