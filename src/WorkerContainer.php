<?php


namespace Azonmedia\Di;


use Azonmedia\Di\Exceptions\ContainerException;
use Azonmedia\Di\Exceptions\NotFoundException;
use Azonmedia\Di\Interfaces\WorkerDependencyInterface;

class WorkerContainer extends Container
{

    public const DEPENDENCY_TYPE_WORKER = 'worker';

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

    private array $worker_requested_dependencies = [];

    private array $worker_dependencies = [];

    /**
     *
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
        //$ServerInstance = \Swoole\Server::getInstance();//this is no longer available as of Swoole 4.5.0
        //to check is it executed in a worker we can rely on the coroutine - if the code is executed in a coroutine this means it is probably in worker context
        $cid = \Swoole\Coroutine::getCid();
        //if ( (is_a($class_name, WorkerDependencyInterface::class, TRUE) || $this->get_dependency_type($id) === self::DEPENDENCY_TYPE_WORKER) && $ServerInstance) {
        if ( (is_a($class_name, WorkerDependencyInterface::class, TRUE) || $this->get_dependency_type($id) === self::DEPENDENCY_TYPE_WORKER) && $cid) {

            if (in_array($id, $this->worker_requested_dependencies)) {
                $container_exception_class = $this->get_container_exception_class();
                throw new $container_exception_class(sprintf('A recursion detected while loading dependency %s. The dependency stack so far is [%s].', $id, implode(',', $this->worker_requested_dependencies) ));
            }
            array_push($this->worker_requested_dependencies, $id);
            try {
                if (!isset($this->worker_dependencies[$class_name])) {
                    $this->worker_dependencies[$class_name] = $this->instantiate_dependency($id);
                }
                $ret = $this->worker_dependencies[$class_name];
            } finally {
                array_pop($this->worker_requested_dependencies);
            }

        } else {
            $ret = parent::get($id);
        }

        return $ret;
    }
}