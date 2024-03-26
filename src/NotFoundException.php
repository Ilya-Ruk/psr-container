<?php

declare(strict_types=1);

namespace Rukavishnikov\Psr\Container;

use Exception;
use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends Exception implements NotFoundExceptionInterface
{
}
