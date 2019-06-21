<?php

namespace Azonmedia\Di;

use Azonmedia\Di\Exceptions\ContainerException;
use Azonmedia\Di\Exceptions\NotFoundException;
use Psr\Container\ContainerInterface;

class Container
    implements ContainerInterface
{


    private $config = [
//        'ConnectionFactory'         => [
//            'class'             => ConnectionFactory::class,
//            'args'              => [],
//            ]


//        'dependencies'          => [
//            ConnectionFactory::class        => [
//                'ConnectionProvider'           => Pool::class,
//            ],
//            Pool::class                     => [
//                'options'                       => [
//                    'max_connections'               => 12,
//                ]
//            ]
//            'ConnectionFactory'             => [
//                'class'                         => ConnectionFactory::class,
//                'args'                          => [ //indexed array
//
//                ],
//            ],
//            'ConnectionProviderInterface'   => [
//                'class'                         => Pool::class,
//                'args'                          => [
//
//                ]
//            ]
//        ]
    ];

    /**
     * @var array Array of objects/services/dependencies
     */
    private $dependencies = [];

    private $container_exception_class = '';

    private $not_found_exception_class = '';

    public function __construct(array $config, $container_exception_class = ContainerException::class, $not_found_exception_class = NotFoundException::class)
    {

        $this->config = $config;

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
        $ret = $this->instantiate_dependency($this->config[$id]);
    }

    //public function has(string $id) : bool
    public function has($id)
    {
        return array_key_exists($id, $this->config);
    }

    private function get_id_from_class(string $class_name) : string
    {
        $ret = NULL;
        foreach ($this->config as $id => $data) {
            if ($data['class'] === $class_name) {
                $ret = $id;
                break;
            }
        }
        if ($ret === NULL) {
            $exception_class = $this->not_found_exception_class;
            throw new $exception_class(sprintf('There is no dependency using the class %s.', $class_name));
        }
        return $ret;
    }

    private function instantiate_dependency(string $id) : object
    {
        $class_name = $this->config[$id];
        $RClass = new ReflectionClass($class_name);
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
                    if (!array_key_exists($position, self::$CONFIG_RUNTIME['dependencies'][$class_name][$RParam->getName()])) {
                        //throw new ContainerException(sprintf('The argument %s on dependency %s is not defined.', $RParam->getName(), $class_name));
                        $exception_class = $this->container_exception_class;
                        throw new $this->container_exception_class(sprintf('The argument %s on dependency %s is not defined.', $RParam->getName(), $class_name));
                    }
                    $param_class_name = self::$CONFIG_RUNTIME['dependencies'][$class_name][$RParam->getName()];

                } else {
                    //throw new ContainerException(sprintf('The argument %s on dependency %s is of type %s which is not found.', $RParam->getName(), $class_name, $param_class_name));
                    $exception_class = $this->container_exception_class;
                    throw new $exception_class(sprintf('The argument %s on dependency %s is of type %s which is not found.', $RParam->getName(), $class_name, $param_class_name));
                }
                $dependency_id = $this->get_id_from_class($param_class_name);
                $arguments[] = $this->instantiate_dependency($dependency_id);
            }
        }
    }

}