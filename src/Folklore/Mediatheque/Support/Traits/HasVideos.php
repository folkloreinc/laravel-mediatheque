<?php namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\Models\Video as VideoContract;

trait HasVideos
{
    /*
     *
     * Relationships
     *
     */
    public function videos()
    {
        $morphName = 'morphable';
        $key = 'video_id';
        $model = app(VideoContract::class);
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
    public function syncVideos($items = array())
    {
        $model = get_class(app(VideoContract::class));
        return $this->syncMorph($model, 'morphable', 'videos', $items);
    }
}
