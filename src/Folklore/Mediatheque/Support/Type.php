<?php

namespace Folklore\Mediatheque\Support;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Folklore\Mediatheque\Contracts\Metadata\Factory as MetadataFactory;
use Folklore\Mediatheque\Contracts\Type\Type as TypeContract;
use Folklore\Mediatheque\Contracts\Pipeline\Factory as PipelineFactory;
use Folklore\Mediatheque\Contracts\Pipeline\Pipeline as PipelineContract;
use Folklore\Mediatheque\Contracts\Models\Media as MediaModelContract;
use Folklore\Mediatheque\Contracts\Services\Mime as MimeService;

class Type extends Definition implements TypeContract
{
    protected static $mimeService;

    protected $name;

    protected $model = \Folklore\Mediatheque\Contracts\Models\Media::class;

    protected $pipeline;

    protected $mimes;

    protected $metadatas;

    protected $canUpload = true;

    public function __construct($name, $definition = [])
    {
        $this->name = $name;

        if (!is_null($definition)) {
            $this->setDefinition($definition);
        }
    }

    public function name(): string
    {
        return $this->get('name');
    }

    public function model(): string
    {
        return $this->get('model');
    }

    public function newModel(): MediaModelContract
    {
        $model = resolve($this->model());
        $model->setType($this->name());
        return $model;
    }

    public function newQuery(): QueryBuilder
    {
        $model = $this->newModel();
        return $model->newQuery()->where($model->getTypeName(), $this->name());
    }

    public function mimes(): array
    {
        $mimes = $this->get('mimes');
        return isset($mimes) ? $mimes : [];
    }

    public function metadatas(): Collection
    {
        $metadatas = $this->get('metadatas');
        $metadataFactory = resolve(MetadataFactory::class);
        return collect(isset($metadatas) ? $metadatas : [])->map(function ($metadata) use (
            $metadataFactory
        ) {
            return $metadataFactory->metadata($metadata);
        });
    }

    public function pipeline(): ?PipelineContract
    {
        $pipeline = $this->get('pipeline');
        return !is_null($pipeline) ? resolve(PipelineFactory::class)->pipeline($pipeline) : null;
    }

    public function canUpload(): bool
    {
        return $this->get('canUpload');
    }

    public function pathIsType(string $path): bool
    {
        $fileMime = self::getMimeFromPath($path);
        $mimes = array_keys($this->mimes());
        foreach ($mimes as $mime) {
            $pattern = str_replace('\*', '[^\/]+', preg_quote($mime, '/'));
            if (preg_match('/^' . $pattern . '$/', $fileMime) === 1) {
                return true;
            }
        }
        return false;
    }

    public function toArray()
    {
        return [
            'name' => $this->name(),
            'model' => $this->get('model'),
            'pipeline' => $this->get('pipeline'),
            'mimes' => $this->mimes(),
            'metadatas' => $this->metadatas()->toArray(),
            'can_upload' => $this->canUpload(),
        ];
    }

    public function __toString()
    {
        return $this->name();
    }

    protected static function getMimeFromPath(string $path)
    {
        return self::getMimeService()->getMime($path);
    }

    protected static function getMimeService()
    {
        if (!isset(static::$mimeService)) {
            static::$mimeService = resolve(MimeService::class);
        }
        return static::$mimeService;
    }
}
