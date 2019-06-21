<?php


namespace Azonmedia\Di\Exceptions;


use Psr\Container\NotFoundExceptionInterface;

class NotFoundException extends ContainerException
implements NotFoundExceptionInterface
{

}