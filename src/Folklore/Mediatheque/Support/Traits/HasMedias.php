<?php namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;

trait HasMedias
{
    /*
     *
     * Relationships
     *
     */
    public function medias()
    {
        $morphName = 'morphable';
        $key = 'media_id';
        $model = app(MediaContract::class);
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
    public function syncAudios($items = array())
    {
        $model = get_class(app(MediaContract::class));
        return $this->syncMorph($model, 'morphable', 'medias', $items);
    }
}
