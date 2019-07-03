<?php namespace Folklore\Mediatheque;

use Folklore\Mediatheque\Contracts\Type\Type as TypeContract;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactoryContract;
use Folklore\Mediatheque\Contracts\Services\Mime as MimeService;

class TypeManager implements TypeFactoryContract
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * The registered custom type creators.
     *
     * @var array
     */
    protected $customTypes = [];

    /**
     * The array of created "types".
     *
     * @var array
     */
    protected $types = [];

    /**
     * Create a new manager instance.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Get a type instance.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function type($name)
    {
        // If the given driver has not been created before, we will create the instances
        // here and cache it so we can return it next time very quickly. If there is
        // already a driver created by this name, we'll just return that instance.
        if (!isset($this->types[$name])) {
            $this->types[$name] = $this->createType($name);
        }

        return $this->types[$name];
    }

    /**
     * Get type of a path
     *
     * @param  string  $path
     * @return string
     */
    public function typeFromPath($path)
    {
        $fileMime = app(MimeService::class)->getMime($path);
        $types = $this->types();
        foreach ($types as $type) {
            if ($type->pathIsType($path, $fileMime)) {
                return $type;
            }
        }
        return null;
    }

    /**
     * Get all types.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function types()
    {
        $types = array_merge(
            array_keys($this->app['config']->get('mediatheque.types')),
            array_keys($this->customTypes)
        );
        return array_reduce(
            $types,
            function ($map, $type) {
                $map[$type] = $this->type($type);
                return $map;
            },
            []
        );
    }

    /**
     * Create a new type.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function createType($name)
    {
        // First, we will determine if a custom driver creator exists for the given driver and
        // if it does not we will check for a creator method for the driver. Custom creator
        // callbacks allow developers to build their own "drivers" easily using Closures.
        $type = null;
        if (isset($this->customTypes[$name])) {
            $type = $this->createCustomType($name);
        } else {
            $config = $this->app['config']->get("mediatheque.types.$name");
            $type = $this->createTypeInstance($name, $config);
        }

        if (!is_null($type)) {
            return $type;
        }

        throw new InvalidArgumentException("Type [$name] doesn't exists.");
    }

    /**
     * Create a new type instance.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function createTypeInstance($name, $config)
    {
        $type = null;
        if (is_string($config)) {
            $type = $this->app->make($config);
        } elseif (is_array($config)) {
            $type = $this->app->make(TypeContract::class);
            $type->setDefinition($config);
        } elseif (is_object($config)) {
            $type = $config;
        }

        if (!is_null($type)) {
            $type->setName($name);
        }

        return $type;
    }

    /**
     * Create a custom type
     *
     * @param  string  $name
     * @return mixed
     */
    protected function createCustomType($name)
    {
        $customType = $this->customTypes[$name];
        return $customType instanceof Closure
            ? $customType($this->app, $name)
            : $this->createTypeInstance($name, $customType);
    }

    /**
     * Register a custom type
     *
     * @param  string    $name
     * @param  string|array|\Closure  $type
     * @return $this
     */
    public function extend($name, $type)
    {
        $this->customTypes[$name] = $type;

        return $this;
    }

    /**
     * Get all of the created "drivers".
     *
     * @return array
     */
    public function getTypes()
    {
        return $this->types;
    }

    /**
     * Check if a reader exists
     *
     * @param string $name
     * @return boolean
     */
    public function hasType($name)
    {
        return !is_null(
            $this->app['config']->get('mediatheque.types.' . $name)
        ) || isset($this->customTypes[$name]);
    }
}
