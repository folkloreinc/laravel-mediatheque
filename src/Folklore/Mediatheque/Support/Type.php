<?php

namespace Folklore\Mediatheque\Support;

use Folklore\Mediatheque\Contracts\Type\Type as TypeContract;

class Type extends Definition implements TypeContract
{
    protected $name;

    protected $model;

    protected $pipeline;

    protected $mimes;

    protected $metadatas;

    protected $canUpload = true;

    protected function model()
    {
        return \Folklore\Mediatheque\Contracts\Models\Media::class;
    }

    protected function pipeline()
    {
        return null;
    }

    protected function mimes()
    {
        return [];
    }

    protected function metadatas()
    {
        return [];
    }

    public function newModel()
    {
        $model = resolve($this->getModel());
        $model->setType($this->getName());
        return $model;
    }

    public function newQuery()
    {
        $model = resolve($this->getModel());
        return $model->newQuery()->where($model->getTypeName(), $this->getName());
    }

    public function getName()
    {
        return $this->get('name');
    }

    public function setName($name)
    {
        return $this->set('name', $name);
    }

    public function getModel()
    {
        return $this->get('model');
    }

    public function setModel($model)
    {
        return $this->set('model', $model);
    }

    public function getMetadatas()
    {
        return $this->get('metadatas');
    }

    public function setMetadatas($metadatas)
    {
        return $this->set('metadatas', $metadatas);
    }

    public function getPipeline()
    {
        return $this->get('pipeline');
    }

    public function setPipeline($pipeline)
    {
        return $this->set('pipeline', $pipeline);
    }

    public function getMimes()
    {
        return $this->get('mimes');
    }

    public function setMimes($mimes)
    {
        return $this->set('mimes', $mimes);
    }

    public function canUpload()
    {
        return $this->get('canUpload');
    }

    public function pathIsType($path, $fileMime = null)
    {
        $mimes = $this->getMimes();
        foreach ($mimes as $mime => $extension) {
            $pattern = str_replace('\*', '[^\/]+', preg_quote($mime, '/'));
            if (preg_match('/^'.$pattern.'$/', $fileMime)) {
                return true;
            }
        }
        return false;
    }

    public function toArray()
    {
        return [
            'name' => $this->getName(),
            'model' => $this->getModel(),
            'pipeline' => $this->getPipeline(),
            'mimes' => $this->getMimes(),
            'metadatas' => $this->getMetadatas(),
            'upload' => $this->canUpload(),
        ];
    }

    public function __toString()
    {
        return $this->getName();
    }
}
