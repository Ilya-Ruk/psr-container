# Dependency Injection (DI) container compatible with PSR-11

## Features

- [PSR-11](https://www.php-fig.org/psr/psr-11/) compatible
- Accepts array definitions
- Supports constructor injection, property injection and method injection
- Supports autoload of classes not specified in the container configuration
- Supports closure
- Auto-wiring
- Detects circular references
- Property type checking (default disable)
- Method parameter type checking (default disable)

## Requirements

- PHP 8.0 or higher
- PSR container 2.x

## Installation

```
composer require rukavishnikov/psr-container
```

## Using the container

### index.php

```php
$config = require 'config.php'; // Load config for container (see below)

$container = new Container($config); // Default used
//$container = new Container($config, true); // Strict mode used (see below)

if ($container->has(InterfaceOrClass::class)) {
    $instance = $container->get(InterfaceOrClass::class);
}
```

### config.php

```php
return [
    ServerRequestInterface::class => ServerRequest::class, // Simple definition
    RouterInterface::class => [ // Full definition
        'class' => Router::class, // Required

        // Constructor injection
        '__construct()' => [
            [
                '/test' => TestController::class,
            ],
        ],
    ],
    TestController::class => [ // Full definition
        'class' => TestController::class, // Required

        // Constructor injection
        '__construct()' => [
            true, // Bool
            123, // Int
            5.0, // Float
            'qwerty', // String
            [1, 2, 3], // Array
            StdClass::class, // Class
            new DateTime(), // Instance
            fn () => function (Container $container) {
                return $container->get(StdClass::class);
            }, // Closure
        ],

        // Property injection
        '$public_a' => true, // Bool
        '$public_b' => 123, // Int
        '$public_c' => 5.0, // Float
        '$public_d' => 'qwerty', // String
        '$public_e' => [1, 2, 3], // Array
        '$public_f' => StdClass::class, // Class
        '$public_g' => new DateTime(), // Instance
        '$public_h' => fn () => function (Container $container) {
            return $container->get(StdClass::class);
        }, // Closure

        // Method injection
        'setA()' => [true], // Bool
        'setB()' => [123], // Int
        'setC()' => [5.0], // Float
        'setD()' => ['qwerty'], // String
        'setE()' => [[1, 2, 3]], // Array
        'setF()' => [StdClass::class], // Class
        'setG()' => [new DateTime()], // Instance
        'setH()' => [
            fn () => function (Container $container) {
                return $container->get(StdClass::class);
            }
        ], // Closure
    ],
    ResponseInterface::class => Response::class, // Simple definition
    EmitterInterface::class => Emitter::class, // Simple definition
];
```

## Strict mode

For use strict mode to property type checking and method parameter type checking you can enable it on container create (second param). Default value is false.
