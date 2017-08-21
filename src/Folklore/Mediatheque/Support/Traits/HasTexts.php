<?php namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\Models\Text as TextContract;

trait HasTexts
{
    use Syncable {
        Syncable::syncMorph as baseSyncTexts;
    }

    /*
     *
     * Relationships
     *
     */
    public function texts()
    {
        $morphName = 'morphable';
        $key = 'text_id';
        $model = app(TextContract::class);
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
    public function syncTexts($items = array())
    {
        $model = get_class(app(TextContract::class));
        return $this->baseSyncTexts($model, 'morphable', 'texts', $items);
    }
}
