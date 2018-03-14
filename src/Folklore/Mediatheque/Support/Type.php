<?php

namespace Folklore\Mediatheque\Support;

use Folklore\Mediatheque\Contracts\Type as TypeContract;

class Type extends Definition implements TypeContract
{
    protected $name;

    protected $model;

    protected $pipeline;

    protected $mimes;

    protected $getters;

    protected function model()
    {
        return null;
    }

    protected function pipeline()
    {
        return null;
    }

    protected function mimes()
    {
        return [];
    }

    protected function interfaces()
    {
        return [
            \Folklore\Mediatheque\Contracts\Getter\Metadata::class,
        ];
    }

    public function setName($name)
    {
        return $this->set('name', $name);
    }

    public function getName()
    {
        return $this->get('name');
    }

    public function setModel($model)
    {
        return $this->set('name', $name);
    }

    public function getModel()
    {
        return $this->get('name');
    }

    public function setMimes($mimes)
    {
        return $this->set('mimes', $mimes);
    }

    public function getMimes()
    {
        return $this->get('mimes');
    }

    public function isType($path, $mime = null)
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
        ];
    }
}
