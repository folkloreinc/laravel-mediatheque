<?php namespace Folklore\Mediatheque;

use Illuminate\Contracts\Container\Container as ContainerContract;
use Folklore\Mediatheque\Contracts\Type as TypeContract;
use Folklore\Mediatheque\Contracts\Getter\Metadata as MetadataGetter;

class TypeManager
{
    protected $container;

    protected $type;

    public function __construct(Container $container, TypeContract $type)
    {
        $this->container = $container;
        $this->type = $type;
    }

    public function model()
    {
        return $this->container->make($this->type->getModel());
    }

    public function metadata($path)
    {
        $interfaces = $this->type->getInterfaces();
        foreach ($interfaces as $interface) {
            $interface = $this->container->make($interface);
            if ($interface instanceof MetadataGetter) {
                return $interface->getMetadata($path);
            }
        }
        return null;
    }

    public function __call($method, $args)
    {
        return call_user_func_array([$this->type, $method], $args);
    }
}
