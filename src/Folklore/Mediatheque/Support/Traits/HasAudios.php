<?php namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\Models\Audio as AudioContract;

trait HasAudios
{
    /*
     *
     * Relationships
     *
     */
    public function audios()
    {
        $morphName = 'morphable';
        $key = 'audio_id';
        $model = app(AudioContract::class);
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
        $model = get_class(app(AudioContract::class));
        return $this->syncMorph($model, 'morphable', 'audios', $items);
    }
}
