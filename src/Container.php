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
    public const DEPENDENCY_TYPE_GLOBAL = 'global';
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
    protected array $config = [];

    /**
     * @var array Array of objects/services/dependencies
     */
    private array $dependencies = [];

    /**
     * Contains the name of the class replacing the ContainerException
     * @var string
     */
    private string $container_exception_class = ContainerException::class;

    /**
     * Contains the name of the class replacing the NotFoundException
     * @var string
     */
    private string $not_found_exception_class = NotFoundException::class;

    /**
     * An indexed array of dependency ids.
     * Used to detect recursions when instantiating dependencies.
     * @var array
     */
    private array $requested_dependencies = [];

    /**
     * @var bool
     */
    protected bool $is_initialized_flag = FALSE;

    /**
     * Container constructor.
     * @param array $config
     * @param string $container_exception_class
     * @param string $not_found_exception_class
     * @throws ContainerException
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    public function __construct(array $config, $container_exception_class = ContainerException::class, $not_found_exception_class = NotFoundException::class)
    {

        $this->config = $config;

        if ($container_exception_class !== ContainerException::class) {
            if (!class_exists($container_exception_class)) {
                throw new \InvalidArgumentException(sprintf('The provided class %s replacing %s does not exist.', $container_exception_class, ContainerException::class));
            }
            if (!is_subclass_of($container_exception_class, ContainerException::class)) {
                throw new \InvalidArgumentException(sprintf('The provided class %s replacing %s must be extending %s.', $container_exception_class, ContainerException::class, ContainerException::class));
            }
        }
        if ($not_found_exception_class !== NotFoundException::class) {
            if (!class_exists($not_found_exception_class)) {
                throw new \InvalidArgumentException(sprintf('The provided class %s replacing %s does not exist.', $not_found_exception_class, NotFoundException::class));
            }
            if (!is_subclass_of($not_found_exception_class, NotFoundException::class)) {
                throw new \InvalidArgumentException(sprintf('The provided class %s replacing %s must be extending %s.', $not_found_exception_class, NotFoundException::class, NotFoundException::class));
            }
        }
        $this->container_exception_class = $container_exception_class;
        $this->not_found_exception_class = $not_found_exception_class;


        //certain dependencies may need to be initialized immediately instead of request
//        foreach ($this->config as $dependency_name=>$dependency_config) {
//            if (!empty($dependency_config['initialize_immediately'])) {
//                $this->dependencies[$dependency_name] = $this->instantiate_dependency($dependency_name);
//            }
//        }

    }

    public function is_initialized() : bool
    {
        return $this->is_initialized_flag;
    }

//    public function initialize() : void
//    {
//        if ($this->is_initialized()) {
//            return;
//        }
//        //certain dependencies may need to be initialized immediately instead of request
//        foreach ($this->config as $dependency_name=>$dependency_config) {
//            if (!empty($dependency_config['initialize_immediately'])) {
//                $this->dependencies[$dependency_name] = $this->instantiate_dependency($dependency_name);
//            }
//        }
//        $this->is_initialized_flag = TRUE;
//    }

    public function get_container_exception_class() : string
    {
        return $this->not_found_exception_class;
    }

    public function get_not_found_exception_class() : string
    {
        return $this->not_found_exception_class;
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

        if (in_array($id, $this->requested_dependencies)) {
            throw new $this->container_exception_class(sprintf('A recursion detected while loading dependency %s. The dependency stack so far is [%s].', $id, implode(',', $this->requested_dependencies)));
        }
        array_push($this->requested_dependencies, $id);

        try {


            if (!$this->has($id)) {
                $exception_class = $this->not_found_exception_class;
                throw new $exception_class(sprintf('The requested dependency %s is not defined.', $id));
            }
            if (empty($this->dependencies[$id])) {
                $this->dependencies[$id] = $this->instantiate_dependency($id);
            }

        } finally {
            array_pop($this->requested_dependencies);
        }
        return $this->dependencies[$id];
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
     * The dependency type (this class supported only global).
     * @param string $id
     * @return string
     */
    public function get_dependency_type(string $id) : string
    {
        if (!$this->has($id)) {
            throw new $this->not_found_exception_class(sprintf('The dependency container has no dependency %s.', $id));
        }
        $ret = 'global';
        if (!empty($this->config[$id]['type'])) {
            $ret = $this->config[$id]['type'];
        }
        return $ret;
    }

//    /**
//     * @param string $id
//     * @return bool
//     */
//    public function is_dependency_instantiated(string $id) : bool
//    {
//        return !empty($this->dependencies[$id]);
//    }

    /**
     * Not part of PSR
     * Returns the class name of a dependency
     * @param string $id
     * @return string
     */
    public function get_class_by_id(string $id) : string
    {
        if (!$this->has($id)) {
            $exception_class = $this->not_found_exception_class;
            throw new $exception_class(sprintf('The requested dependency %s is not defined.', $id));
        }
        if (class_exists($id)) {
            $class_name = $id;
        } else {
            if (!$this->has($id)) {
                $exception_class = $this->not_found_exception_class;
                throw new $exception_class(sprintf('The requested dependency %s is not defined.', $id));
            }
            $class_name = $this->config[$id]['class'];
        }
        return $class_name;
    }

    /**
     * Returns dependency IDs by $class
     * @param string $class
     * @return array
     */
    public function get_ids_by_class(string $class) : array
    {
        $ret = [];
        foreach ($this->config as $name => $dependency) {
            if ($dependency['class'] === $class) {
                $ret[] = $name;
            }
        }
        return $ret;
    }

    /**
     * Not part of PSR
     * Returns what the provided $id depends on.
     * @param string $id
     * @return array
     */
    public function get_depends_on(string $id) : array
    {
        return $this->config[$id]['depends_on'] ?? [];
    }

    /**
     * Instantiates the object for the provided service $id if the object does not exist already.
     * @param string $id Service name
     * @return object
     * @throws \ReflectionException
     * @throws ContainerException
     * @throws NotFoundException
     */
    protected function instantiate_dependency(string $id): object
    {

        //this cant work correctly in coroutine context
        //if (in_array($id, $this->requested_dependencies)) {
        //    throw new $this->container_exception_class(sprintf('A recursion detected while loading dependency %s. The dependency stack so far is [%s].', $id, implode(',', $this->requested_dependencies)));
        //}
        //array_push($this->requested_dependencies, $id);

        try {

            $class_name = $this->get_class_by_id($id);

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

            } while ($CurrentRClass && !$RConstruct);


            foreach ($this->get_depends_on($id) as $depends_on_name) {
                if (!$this->has($depends_on_name)) {
                    throw new $this->container_exception_class(sprintf('The dependency %s depends on %s but this is not defined.', $id, $depends_on_name));
                }
                $this->get($depends_on_name);
            }
            //TODO add cycle detection and throw an exception if detected


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

                    $type_name = $RType->getName();
                    if ($RType->isBuiltin() && is_array($arg) && count($arg) == 2 && class_exists($arg[0])) {
                        // Injecting static method
                        if (!method_exists($arg[0], $arg[1])) {
                            throw new $this->container_exception_class(sprintf('Method %s::%s not found', $arg[0], $arg[1]));
                        }

                        $method = $arg[1];
                        $value = $arg[0]::$method();
                        //$is_correct_type = $this->is_value_of_type($value, (string) $RType, $class_name);
                        $is_correct_type = $this->is_value_of_type($value, $type_name, $class_name);
                        if (!$is_correct_type) {
                            //throw new $this->container_exception_class(sprintf('Argument "%s" is not of type "%s" in class "%s".', $arg_name, $RType, $class_name));
                            throw new $this->container_exception_class(sprintf('Argument "%s" is not of type "%s" in class "%s".', $arg_name, $type_name, $class_name));
                        }

                        $arguments[] = $value;

                    } elseif ($RType->isBuiltin() && is_string($arg) && function_exists($arg)) {
                        // Injecting built in functions
                        $function = $arg;
                        $value = $function();
                        //$is_correct_type = $this->is_value_of_type($value, (string) $RType, $class_name);
                        $is_correct_type = $this->is_value_of_type($value, $type_name, $class_name);
                        if (!$is_correct_type) {
                            //throw new $this->container_exception_class(sprintf('Argument "%s" is not of type "%s" in class "%s".', $arg_name, $RType, $class_name));
                            throw new $this->container_exception_class(sprintf('Argument "%s" is not of type "%s" in class "%s".', $arg_name, $type_name, $class_name));
                        }

                        $arguments[] = $value;
                    } elseif ($RType->isBuiltin()) {
                        // Check if expected argument type is provided
                        //$is_correct_type = $this->is_value_of_type($arg, (string) $RType, $class_name);
                        $is_correct_type = $this->is_value_of_type($arg, $type_name, $class_name);
                        if (!$is_correct_type) {
                            //throw new $this->container_exception_class(sprintf('Argument "%s" is not of type "%s" in class "%s".', $arg_name, $RType, $class_name));
                            throw new $this->container_exception_class(sprintf('Argument "%s" is not of type "%s" in class "%s".', $arg_name, $type_name, $class_name));
                        }

                        $arguments[] = $arg;
                    } else {
                        //a class ... or no type
                        //$param_class_name = (string) $RType;
                        $param_class_name = $RType->getName();
                        if (class_exists($param_class_name)) {
                            //do nothing
                            $dependency_id = $arg;
                            //try to instantiate the class only if it has no arguments
                            //TODO - add arguments check
                            //$dependency_id = new $param_class_name();
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
                            if ($RParam->isVariadic()) {
                                $arguments = [...$arguments, ...$dependency_id];
                            } else {
                                if (count($dependency_id) !== 2) {
                                    throw new $this->container_exception_class(sprintf('The argument %s on dependency %s is defined as callable array but it is not a valid callable. The array must contain two elements while it contains %s elements.', $RParam->getName(), $class_name, count($dependency_id)));
                                }
                                if (!is_callable($dependency_id)) {
                                    throw new $this->container_exception_class(sprintf('The argument %s on dependency %s is defined as callable array but it is not a valid callable.', $RParam->getName(), $class_name));
                                }
                                $arguments[] = $dependency_id();//it is expected to be a callable
                            }
//                        } elseif (is_object($dependency_id)) {
//                            $arguments[] = $dependency_id;
                        } else {
                            $arguments[] = $this->get($dependency_id);
                        }
                    }
                }
            }

        } finally {
            //no matter what happens pop the dependency
            //array_pop($this->requested_dependencies);
        }



        //$this->dependencies[$id] = $RClass->newInstanceArgs($arguments);
        return $RClass->newInstanceArgs($arguments);

    }

    /**
     * Check is a value is of a selected type
     *
     * @param $value
     * @param $expected_type
     * @param $class_name
     * @return bool
     */
    protected function is_value_of_type($value, $expected_type, $class_name): bool
    {
        switch ($expected_type) {
            case 'int':
                $is_correct_type = is_int($value);
                break;
            case 'string':
                $is_correct_type = is_string($value);
                break;
            case 'float':
                $is_correct_type = is_float($value);
                break;
            case 'bool':
                $is_correct_type = is_bool($value);
                break;
            case 'array':
                $is_correct_type = is_array($value);
                break;
            case 'object':
                $is_correct_type = is_object($value);
                break;
            case 'callable':
                $is_correct_type = is_callable($value);
                break;
            case 'iterable':
                $is_correct_type = is_array($value) || $value instanceof \Traversable;
                break;
            default:
                throw new $this->container_exception_class(sprintf('Unrecognized data type "%s" expected in class "%s".', $expected_type, $class_name));
        }

        return $is_correct_type;
    }

}