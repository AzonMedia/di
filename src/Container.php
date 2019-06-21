<?php

namespace Azonmedia\Di;

use Azonmedia\Di\Exceptions\ContainerException;
use Azonmedia\Di\Exceptions\NotFoundException;
use Psr\Container\ContainerInterface;

/**
 * Class Container
 * Implements PSR 11
 * @link https://www.php-fig.org/psr/psr-11/
 * Implements autowiring.
 * Does not implement compiling (if executed in swoole this is not needed)
 * @package Azonmedia\Di
 */
class Container
    implements ContainerInterface
{

    /**
     * @example
    'ConnectionFactory'             => [
    'class'                         => ConnectionFactory::class,
    'args'                          => [
    'ConnectionProvider'            => 'ConnectionProviderPool',
    ],
    ],
    'ConnectionProviderPool'       => [
    'class'                         => Pool::class,
    'args'                          => [],
    ],
    'SomeExample'                   => [
    'class'                         => SomeClass::class,
    'args'                          => [
    'arg1'                      => 20,
    'arg2'                      => 'something'
    ],
    ]
     * @var array
     */
    private $config = [];

    /**
     * @var array Array of objects/services/dependencies
     */
    private $dependencies = [];

    /**
     * Contains the name of the class replacing the ContainerException
     * @var string
     */
    private $container_exception_class = '';

    /**
     * Contains the name of the class replacing the NotFoundException
     * @var string
     */
    private $not_found_exception_class = '';

    /**
     * Container constructor.
     * @param array $config
     * @param string $container_exception_class
     * @param string $not_found_exception_class
     * @throws \InvalidArgumentException
     */
    public function __construct(array $config, $container_exception_class = ContainerException::class, $not_found_exception_class = NotFoundException::class)
    {

        $this->config = $config;

        if ($container_exception_class !== ContainerException::class) {
            if (!class_exists($container_exception_class)) {
                throw new \InvalidArgumentException(sprintf('The provided class %s replacing %s does not exist.'), $container_exception_class, ContainerException::class);
            }
            if (!is_subclass_of($container_exception_class, ContainerException::class)) {
                throw new \InvalidArgumentException(sprintf('The provided class %s replacing %s must be extending %s.'), $container_exception_class, ContainerException::class, ContainerException::class);
            }
        }
        if ($not_found_exception_class !== NotFoundException::class) {
            if (!class_exists($not_found_exception_class)) {
                throw new \InvalidArgumentException(sprintf('The provided class %s replacing %s does not exist.'), $not_found_exception_class, NotFoundException::class);
            }
            if (!is_subclass_of($not_found_exception_class, NotFoundException::class)) {
                throw new \InvalidArgumentException(sprintf('The provided class %s replacing %s must be extending %s.'), $not_found_exception_class, NotFoundException::class, NotFoundException::class);
            }
        }
        $this->container_exception_class = $container_exception_class;
        $this->not_found_exception_class = $not_found_exception_class;
    }

    /**
     * @inheritDoc
     * @param string $id
     * @return object
     */
    //public function get(string $id) : object
    public function get($id)
    {
        if (!isset($this->config[$id])) {
            $exception_class = $this->not_found_exception_class;
            throw new $exception_class(sprintf('The requested dependency %s is not defined.', $id));
        }
        return $this->instantiate_dependency($id);
    }

    /**
     * @inheritDoc
     * @param string $id
     * @return bool
     */
    //public function has(string $id) : bool
    public function has($id)
    {
        return array_key_exists($id, $this->config);
    }

    /**
     * Instantiates the object for the provided service $id if the object does not exist already.
     * @param string $id Service name
     * @return object
     * @throws \ReflectionException
     * @throws ContainerException
     * @throws NotFoundException
     */
    private function instantiate_dependency(string $id) : object
    {

        if (empty($this->dependencies[$id])) {
            if (!$this->has($id)) {
                $exception_class = $this->not_found_exception_class;
                throw new $exception_class(sprintf('The requested dependency %s is not defined.', $id));
            }
            $class_name = $this->config[$id]['class'];
            $RClass = new \ReflectionClass($class_name);
            $RConstruct = $RClass->getMethod('__construct');
            $params = $RConstruct->getParameters();
            $arguments = [];
            foreach ($params as $RParam) {
                $RType = $RParam->getType();
                if ($RType->isBuiltin()) {
                    //this is a native PHP type
                    //we need config options what to provide

                } else {
                    //a class ... or no type
                    $param_class_name = (string) $RType;
                    if (class_exists($param_class_name)) {
                        //do nothing
                    } elseif (interface_exists($param_class_name)) {
                        //it is an interface and we need a definition which class should be used
                        //check the arguments
                        if (!array_key_exists($RParam->getName(), $this->config[$id]['args'])) {
                            //throw new ContainerException(sprintf('The argument %s on dependency %s is not defined.', $RParam->getName(), $class_name));
                            $exception_class = $this->container_exception_class;
                            throw new $this->container_exception_class(sprintf('The argument %s on dependency %s is not defined.', $RParam->getName(), $class_name));
                        }
                        //$param_class_name = self::$CONFIG_RUNTIME['dependencies'][$class_name][$RParam->getName()];
                        $dependency_id = $this->config[$id]['args'][$RParam->getName()];//when the parameter is of type interface the config expects the args to provide another service name

                    } else {
                        //throw new ContainerException(sprintf('The argument %s on dependency %s is of type %s which is not found.', $RParam->getName(), $class_name, $param_class_name));
                        $exception_class = $this->container_exception_class;
                        throw new $exception_class(sprintf('The argument %s on dependency %s is of type %s which is not found.', $RParam->getName(), $class_name, $param_class_name));
                    }
                    $arguments[] = $this->instantiate_dependency($dependency_id);
                }
            }
            $this->dependencies[$id] = $RClass->newInstanceArgs($arguments);
        }
        return $this->dependencies[$id];
    }

}