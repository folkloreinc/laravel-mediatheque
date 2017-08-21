<?php namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\Models\Picture as PictureContract;

trait HasPictures
{
    /*
     *
     * Relationships
     *
     */
    public function pictures()
    {
        $morphName = 'morphable';
        $key = 'picture_id';
        $model = app(PictureContract::class);
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
    public function syncPictures($items = array())
    {
        $model = get_class(app(PictureContract::class));
        return $this->syncMorph($model, 'morphable', 'pictures', $items);
    }
}
