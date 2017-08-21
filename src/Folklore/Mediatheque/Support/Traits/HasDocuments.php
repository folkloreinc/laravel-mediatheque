<?php namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\Models\Document as DocumentContract;

trait HasDocuments
{
    /*
     *
     * Relationships
     *
     */
    public function documents()
    {
        $morphName = 'morphable';
        $key = 'document_id';
        $model = app(DocumentContract::class);
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
    public function syncDocuments($items = array())
    {
        $model = get_class(app(DocumentContract::class));
        return $this->syncMorph($model, 'morphable', 'documents', $items);
    }
}
