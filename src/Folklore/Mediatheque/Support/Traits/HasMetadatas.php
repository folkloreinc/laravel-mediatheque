<?php
namespace Folklore\Mediatheque\Support\Traits;

use Illuminate\Support\Collection;
use Folklore\Mediatheque\Contracts\Models\Metadata as MetadataContract;
use Folklore\Mediatheque\Contracts\Metadata\Value as MetadataValue;

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

    public function getMetadatas(): Collection
    {
        return $this->metadatas->mapWithKeys(function ($metadata) {
            return [
                $metadata->getName() => $metadata,
            ];
        });
    }

    public function getMetadata(string $name): MetadataContract
    {
        return $this->getMetadatas()->get($name);
    }

    public function setMetadata(MetadataValue $value)
    {
        if (!$this->exists) {
            $this->save();
        }

        $metadatas = $this->getMetadatas();
        $name = $value->getName();
        $metadata = $metadatas->get($name, app(MetadataContract::class));
        $metadata->setValue($value);
        $this->metadatas()->save($metadata);
    }

    public function setMetadatas(Collection $values)
    {
        if (!$this->exists) {
            $this->save();
        }
        $metadatas = $this->getMetadatas();
        $metadatasValues = $values
            ->map(function ($value) use ($metadatas) {
                $name = $value->getName();
                $metadata = $metadatas->get($name, app(MetadataContract::class));
                $metadata->setValue($value);
                return $metadata;
            })
            ->values();
        $this->metadatas()->saveMany($metadatasValues);
        return $this;
    }
}
