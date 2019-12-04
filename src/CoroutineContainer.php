<?php
declare(strict_types=1);

namespace Azonmedia\Di;

use Azonmedia\Di\Exceptions\ContainerException;
use Azonmedia\Di\Exceptions\NotFoundException;
use Azonmedia\Di\Interfaces\CoroutineDependencyInterface;
use Guzaba2\Base\Exceptions\RunTimeException;
use Swoole\Coroutine;

/**
 * Class CoroutineContainer
 * Coroutine aware dependency injection container.
 * @package Azonmedia\Di
 */
class CoroutineContainer extends Container
{

    /**
     * CoroutineContainer constructor.
     * Initializes all dependencies that are not CoroutineDependencyInterface.
     * If these are not initialized here then if a lazy initialization dependency that is requested in coroutine mode multiple times will use the parent::get() which will fail the requested_dependencies check due to the high parallelism.
     * Also it is a good idea in Worker/Coroutine mode to have all non-coroutine dependencies to be initialized.
     * @param array $config
     * @param string $container_exception_class
     * @param string $not_found_exception_class
     * @throws ContainerException
     * @throws NotFoundException
     * @throws \ReflectionException
     */
//cant initialize here all dependencies because these may depend on Kernel::get_service()
//    public function __construct(array $config, $container_exception_class = ContainerException::class, $not_found_exception_class = NotFoundException::class)
//    {
//        parent::__construct($config, $container_exception_class, $not_found_exception_class);
//        foreach ($config as $dependency_name=>$dependency_config) {
//            $this->get($dependency_name);
//            if (!is_a($dependency_config['class'], CoroutineDependencyInterface::class, TRUE)) {
//                $this->get($dependency_name);
//            }
//        }
//    }

    public function inititialize() : void
    {
        if ($this->is_initialized()) {
            return;
        }
        foreach ($this->config as $dependency_name=>$dependency_config) {
            $this->get($dependency_name);
            if (!is_a($dependency_config['class'], CoroutineDependencyInterface::class, TRUE)) {
                $this->get($dependency_name);
            }
        }
        $this->is_initialized_flag = TRUE;
    }

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

//    /**
//     * @param string $id
//     * @return bool
//     */
//    public function is_dependency_instantiated(string $id) : bool
//    {
//        $ret = FALSE;
//        $class_name = $this->get_class_by_id($id);
//        if (is_a($class_name, CoroutineDependencyInterface::class, TRUE) && \Swoole\Coroutine::getCid() > 0) {
//            $Context = \Swoole\Coroutine::getContext();
//            if (isset($Context->{$class_name})) {
//                $ret = TRUE;
//            }
//        } else {
//            $ret = parent::is_dependency_instantiated($id);
//        }
//        debug_print_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
//        //print $id.' '.$ret.PHP_EOL;
//        return $ret;
//    }

//    //cleans up the coroutine dependencies from the coroutine context in the right order
//    public function coroutine_services_cleanup() : void
//    {
//        $Context = \Swoole\Coroutine::getContext();
//        $Context->is_in_cleanup = TRUE;
//        $deps_list = [];
//        $context_vars = get_object_vars($Context);
//        $dependency_property_map = [];//a map between the property name and the dependency name
//        foreach ($context_vars as $var_name => $var_value) {
//            if ($var_value instanceof CoroutineDependencyInterface) {
//                //$deps_list[$var_name] = $this->get_depends_on();
//                $dependency_names = $this->get_ids_by_class(get_class($var_value));
//                foreach ($dependency_names as $dependency_name) {
//                    //$deps_list[$dependency_name] = $this->get_depends_on($dependency_name);
//                    //$deps_list[$var_name] = [$dependency_name, $this->get_depends_on($dependency_name) ];
//                    $deps_list[$dependency_name] = $this->get_depends_on($dependency_name);
//                    $dependency_property_map[$dependency_name] = $var_name;
//                }
//            }
//        }
//
//
//        //first the dependencies that have dependencies are to be destroyed
//        $destroy_order = [];//stores the priority in reverse - the first in the array are the last to be destroyed
//        foreach ($deps_list as $dependency_name=>$depends_on) {
//            $destroy_order = [...$destroy_order, ...$depends_on];
//        }
//
//        foreach ($deps_list as $dep_name => $depends_on) {
//            if (!in_array($dep_name, $destroy_order)) {
//                $destroy_order[] = $dep_name;
//            }
//
//        }
//        $destroy_order = array_reverse($destroy_order);
//
//
//        foreach ($destroy_order as $dependency_name) {
//            //netiher of the below actually triggers the destructor immediately
//            //even with gc_collect_cycles();
//            // it is not known in what exact order the destructor will be invoked
//            $Context->{$dependency_property_map[$dependency_name]} = NULL;
//            //unset($Context->{$dependency_property_map[$dependency_name]});
//            //because of this there should be an explicit destroy() method invoked here if the order matters
//            //gc_collect_cycles();
//        }
//
//    }

}