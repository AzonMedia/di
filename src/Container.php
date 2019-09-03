<?php
declare(strict_types=1);

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
     * 'ConnectionFactory' => [
     *      'class' => ConnectionFactory::class,
     *      'args' => [
     *          'ConnectionProvider' => 'ConnectionProviderPool',
     *      ],
     * ],
     * 'ConnectionProviderPool' => [
     *      'class' => Pool::class,
     *      'args' => [],
     * ],
     * 'SomeExample' => [
     *      'class' => SomeClass::class,
     *      'args' => [
     *          'arg1' => 20,
     *          'arg2' => 'something'
     *      ],
     * ]
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


        //certain dependencies may need to be initialized immediately instead of request
        foreach ($this->config as $dependency_name=>$dependency_config) {
            if (!empty($dependency_config['initialize_immediately'])) {
                $this->instantiate_dependency($dependency_name);
            }
        }



    }

    /**
     * @inheritDoc
     * @param string $id
     * @return object
     * @throws ContainerException
     * @throws NotFoundException
     * @throws \ReflectionException
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
    private function instantiate_dependency(string $id): object
    {

        if (empty($this->dependencies[$id])) {
            if (class_exists($id)) {
                $class_name = $id;
            } else {
                if (!$this->has($id)) {
                    $exception_class = $this->not_found_exception_class;
                    throw new $exception_class(sprintf('The requested dependency %s is not defined.', $id));
                }
                $class_name = $this->config[$id]['class'];
            }

            $RClass = new \ReflectionClass($class_name);

            //it is possible that the class itself not to define a construct method
            //then a lookup for the parent construct should be done

            $arguments = [];
            $RConstruct = NULL;
            $CurrentRClass = $RClass;


            do {
                if ($CurrentRClass->hasMethod('__construct')) {
                    $RConstruct = $CurrentRClass->getMethod('__construct');
                } else {
                    $CurrentRClass = $CurrentRClass->getParentClass();

                }

            } while($CurrentRClass && !$RConstruct);


            //print $CurrentRClass->hasMethod('__construct') ? 'DA' : 'NE';
            //$RConstruct = $CurrentRClass->getMethod('__construct');

            if ($RConstruct) {
                $params = $RConstruct->getParameters();

                $class_args = $this->config[$id]['args'] ?? [];
                if (count($class_args) > count($params)) {
                    throw new $this->container_exception_class('More arguments provided than available in constructor in class' . $class_name);
                }

                foreach ($params as $key => $RParam) {
                    $RType = $RParam->getType();
                    $arg_name = $RParam->getName();

                    // Throw error if a required param is missing
                    if (!$RParam->isDefaultValueAvailable() && !isset($class_args[$arg_name])) {
                        throw new $this->container_exception_class(sprintf('The argument "%s" on dependency "%s" is not defined.', $arg_name, $class_name));
                    }

                    // Use default value if there isn't one provided in the configuration
                    if (!isset($class_args[$arg_name])) {
                        $arguments[] = $RParam->getDefaultValue();
                        continue;
                    }

                    $arg = $class_args[$arg_name];
                    // Don't validate type of argument if it is not provided in the constructor
                    if (is_null($RType)) {
                        $arguments[] = $arg;
                        continue;
                    }

                    if ($RType->isBuiltin()) {
                        // Check if expected argument type is provided
                        switch ((string) $RType) {
                            case 'int':
                                $is_correct_type = is_int($arg);
                                break;
                            case 'string':
                                $is_correct_type = is_string($arg);
                                break;
                            case 'float':
                                $is_correct_type = is_float($arg);
                                break;
                            case 'bool':
                                $is_correct_type = is_bool($arg);
                                break;
                            case 'array':
                                $is_correct_type = is_array($arg);
                                break;
                            case 'object':
                                $is_correct_type = is_object($arg);
                                break;
                            case 'callable':
                                $is_correct_type = is_callable($arg);
                                break;
                            case 'iterable':
                                $is_correct_type = is_array($arg) || $arg instanceof \Traversable;
                                break;
                            default:
                                throw new $this->container_exception_class(sprintf('Unrecognized data type "%s" expected in class "%s".', $RType, $class_name));
                        }

                        if (!$is_correct_type) {
                            throw new $this->container_exception_class(sprintf('Argument "%s" is not of type %s in class "%s".', $arg_name, $RType, $class_name));
                        }

                        $arguments[] = $arg;
                    } else {
                        //a class ... or no type
                        $param_class_name = (string) $RType;
                        if (class_exists($param_class_name)) {
                            //do nothing
                            $dependency_id = $arg;
                        } elseif (interface_exists($param_class_name)) {
                            //it is an interface and we need a definition which class should be used
                            //check the arguments
                            if (!array_key_exists($RParam->getName(), $class_args)) {
                                //throw new ContainerException(sprintf('The argument %s on dependency %s is not defined.', $RParam->getName(), $class_name));
                                $exception_class = $this->container_exception_class;
                                throw new $this->container_exception_class(sprintf('The argument %s on dependency %s is not defined.', $RParam->getName(), $class_name));
                            }
                            //$param_class_name = self::$CONFIG_RUNTIME['dependencies'][$class_name][$RParam->getName()];
                            $dependency_id = $arg;//when the parameter is of type interface the config expects the args to provide another service name

                        } else {
                            //throw new ContainerException(sprintf('The argument %s on dependency %s is of type %s which is not found.', $RParam->getName(), $class_name, $param_class_name));
                            $exception_class = $this->container_exception_class;
                            throw new $exception_class(sprintf('The argument %s on dependency %s is of type %s which is not found.', $RParam->getName(), $class_name, $param_class_name));
                        }
                        if (is_array($dependency_id)) {
                            if (count($dependency_id) !== 2) {
                                throw new $this->container_exception_class(sprintf('The argument %s on dependency %s is defined as callable array but it is not a valid callable. The array must contain two elements while it contains %s elements.'), $RParam->getName(), $class_name, count($dependency_id) );
                            }
                            if (!is_callable($dependency_id)) {
                                throw new $this->container_exception_class(sprintf('The argument %s on dependency %s is defined as callable array but it is not a valid callable.'), $RParam->getName(), $class_name);
                            }
                            $arguments[] = $dependency_id();//it is expected to be a callable
                        } else {
                            $arguments[] = $this->instantiate_dependency($dependency_id);
                        }

                    }
                }
            }

            $this->dependencies[$id] = $RClass->newInstanceArgs($arguments);
        }
        return $this->dependencies[$id];
    }

}