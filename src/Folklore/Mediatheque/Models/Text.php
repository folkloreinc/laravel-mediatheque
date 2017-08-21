<?php namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Models\Collections\TextsCollection;

class Text extends Model
{
    protected $table = 'texts';

    protected $fillable = array(
        'content',
        'fields'
    );

    protected $casts = [
        'fields' => 'object'
    ];

    protected $appends = [
        'type'
    ];

    /**
     * Relationships
     */
    public function pictures()
    {
        $morphName = 'writable';
        $model = config('mediatheque.models.Picture', 'Folklore\Mediatheque\Models\Picture');
        $table = config('mediatheque.table_prefix').'writables';
        $query = $this->morphedByMany($model, $morphName, $table)
                        ->withTimestamps()
                        ->withPivot($morphName.'_position', $morphName.'_order');
        return $query;
    }

    public function audios()
    {
        $morphName = 'writable';
        $model = config('mediatheque.models.Audio', 'Folklore\Mediatheque\Models\Audio');
        $table = config('mediatheque.table_prefix').'writables';
        $query = $this->morphedByMany($model, $morphName, $table)
                        ->withTimestamps()
                        ->withPivot($morphName.'_position', $morphName.'_order');
        return $query;
    }

    public function videos()
    {
        $morphName = 'writable';
        $model = config('mediatheque.models.Video', 'Folklore\Mediatheque\Models\Video');
        $table = config('mediatheque.table_prefix').'writables';
        $query = $this->morphedByMany($model, $morphName, $table)
                        ->withTimestamps()
                        ->withPivot($morphName.'_position', $morphName.'_order');
        return $query;
    }

    /**
     * Collections
     */
    public function newCollection(array $models = array())
    {
        return new TextsCollection($models);
    }

    /**
     * Query scopes
     */
    public function scopeSearch($query, $text)
    {
        $query->where(function ($query) use ($text) {
            $query->where('slug', 'LIKE', '%'.$text.'%');
            $query->orWhere('name', 'LIKE', '%'.$text.'%');
            $query->orWhere('content', 'LIKE', '%'.$text.'%');
            $query->orWhere('fields', 'LIKE', '%'.$text.'%');
        });

        return $query;
    }

    protected function getTypeAttribute()
    {
        return 'text';
    }
}
