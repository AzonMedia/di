<?php
declare(strict_types=1);

namespace Azonmedia\Di\Exceptions;


use Psr\Container\NotFoundExceptionInterface;

class NotFoundException extends ContainerException
implements NotFoundExceptionInterface
{

}