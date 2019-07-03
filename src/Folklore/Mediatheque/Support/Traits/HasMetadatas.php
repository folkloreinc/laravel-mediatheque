<?php namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\Models\Metadata as MetadataContract;
use Folklore\Mediatheque\Metadata\ValuesCollection;

trait HasMetadatas
{
    /*
     *
     * Relationships
     *
     */
    public function metadatas()
    {
        $morphName = 'morphable';
        $model = app(MetadataContract::class);
        $modelClass = get_class($model);
        $query = $this->morphMany($modelClass, $morphName);
        return $query;
    }

    public function metadata($name)
    {
        if ($this->relationLoaded('metadatas')) {
            return $this->metadatas->{$name};
        }
        return $this->metadatas()
            ->where('name', $name)
            ->first();
    }

    public function setMetadata(ValuesCollection $values)
    {
        if (!$this->exists) {
            $this->save();
        }
        $this->load('metadatas');
        foreach ($values as $value) {
            $name = $value->getName();
            $metadata = isset($this->metadatas->{$name})
                ? $this->metadatas->{$name}
                : app(MetadataContract::class);
            $metadata->fillFromValue($value);
            $this->metadatas()->save($metadata);
        }
        return $this;
    }

    protected function getMetadataAttribute()
    {
        $this->loadMissing('metadatas');
        return $this->metadatas->toMetadataArray();
    }
}
