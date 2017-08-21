<?php namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\Models\Font as FontContract;

trait HasFonts
{
    /*
     *
     * Relationships
     *
     */
    public function fonts()
    {
        $morphName = 'morphable';
        $key = 'font_id';
        $model = app(FontContract::class);
        $modelClass = get_class($model);
        $table = $model->getTable().'_pivot';
        $query = $this->morphToMany($modelClass, $morphName, $table, null, $key)
                        ->withTimestamps()
                        ->withPivot('handle', 'order')
                        ->orderBy('order', 'asc');
        return $query;
    }

    /*
     *
     * Sync methods
     *
     */
    public function syncFonts($items = array())
    {
        $model = get_class(app(FontContract::class));
        return $this->syncMorph($model, 'morphable', 'fonts', $items);
    }
}
