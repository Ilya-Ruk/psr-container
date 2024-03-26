<?php

declare(strict_types=1);

namespace Rukavishnikov\Psr\Container;

use Exception;
use Psr\Container\ContainerExceptionInterface;

final class ContainerException extends Exception implements ContainerExceptionInterface
{
}
