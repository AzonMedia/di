<?php


namespace Azonmedia\Di;


use Azonmedia\Di\Exceptions\ContainerException;
use Azonmedia\Di\Exceptions\NotFoundException;
use Azonmedia\Di\Interfaces\CoroutineDependencyInterface;
use Azonmedia\Di\Interfaces\WorkerDependencyInterface;

class WorkerContainer extends Container
{
//    public function inititialize() : void
//    {
//        if ($this->is_initialized()) {
//            return;
//        }
//        foreach ($this->config as $dependency_name=>$dependency_config) {
//            $this->get($dependency_name);
//            if (!is_a($dependency_config['class'], WorkerDependencyInterface::class, TRUE)) {
//                $this->get($dependency_name);
//            }
//        }
//        //$this->is_initialized_flag = TRUE;
//        parent::initialize();
//    }

    /**
     * If the requested dependency is a coroutine one (implements CoroutineDependencyInterface) but is invoked outside Coroutine context the dependency will be served the normal way - parent::get().
     * @override
     * @param string $id
     * @return object
     * @throws ContainerException
     * @throws NotFoundException
     * @throws \ReflectionException
     */
    //public function get(string $id) : object
    public function get($id)
    {
        $ret = NULL;
        $class_name = $this->get_class_by_id($id);
        if (is_a($class_name, CoroutineDependencyInterface::class, TRUE) && \Swoole\Coroutine::getCid() > 0) {
            $Context = \Swoole\Coroutine::getContext();

            if (!property_exists($Context, self::class)) {
                $Context->{self::class} = [];
            }
            if (!array_key_exists('requested_dependencies', $Context->{self::class})) {
                $Context->{self::class}['requested_dependencies'] = [];
            }
            if (in_array($id, $Context->{self::class}['requested_dependencies'])) {
                $container_exception_class = $this->get_container_exception_class();
                throw new $container_exception_class(sprintf('A recursion detected while loading dependency %s. The dependency stack so far is [%s].', $id, implode(',', $Context->{self::class}['requested_dependencies'] )));
            }
            array_push($Context->{self::class}['requested_dependencies'], $id);
            try {
                if (!isset($Context->{$class_name})) {
                    if (!empty($Context->is_in_cleanup)) {
                        $container_exception_class = $this->get_container_exception_class();
                        throw new $container_exception_class(sprintf('The coroutine %s context is in dependency cleanup at the end of coroutine execution and a new coroutine dependency %s is requested.', \Swoole\Coroutine::getcid(), $id));
                    }
                    $Context->{$class_name} = $this->instantiate_dependency($id);
                }
                $ret = $Context->{$class_name};
            } finally {
                array_pop($Context->{self::class}['requested_dependencies']);
            }


        } else {
            $ret = parent::get($id);
        }

        return $ret;
    }
}