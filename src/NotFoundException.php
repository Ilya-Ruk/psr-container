<?php

declare(strict_types=1);

namespace Rukavishnikov\Psr\Container;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
