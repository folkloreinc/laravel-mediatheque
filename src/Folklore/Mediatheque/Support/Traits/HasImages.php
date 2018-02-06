<?php namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\Models\Image as ImageContract;

trait HasImages
{
    /*
     *
     * Relationships
     *
     */
    public function images()
    {
        $morphName = 'morphable';
        $key = 'image_id';
        $model = app(ImageContract::class);
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
    public function syncImages($items = array())
    {
        $model = get_class(app(ImageContract::class));
        return $this->syncMorph($model, 'morphable', 'images', $items);
    }
}
