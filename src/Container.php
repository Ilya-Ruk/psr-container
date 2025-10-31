<?php

declare(strict_types=1);

namespace Rukavishnikov\Psr\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

final class Container implements ContainerInterface
{
    /**
     * @psalm-var array<string, mixed>
     */
    private array $instances = [];

    private array $building = [];

    /**
     * @psalm-var array<string, string>
     */
    private static array $mapType = [
        'bool' => 'boolean',
        'int' => 'integer',
        'float' => 'double',
    ];

    /**
     * @param array $config
     * @param bool $strictMode
     * @throws ContainerException
     */
    public function __construct(
        private array $config,
        private bool $strictMode = false
    ) {
        foreach ($this->config as $key => $value) {
            if (!is_string($key)) {
                throw new ContainerException("Key must be a string in container config!", 500);
            }
        }
    }

    /**
     * @param string $id
     * @return mixed
     * @throws NotFoundException
     * @throws ContainerException
     */
    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->instances)) {
            $this->instances[$id] = $this->createObject($id);
        }

        return $this->instances[$id];
    }

    /**
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->instances)) {
            return true;
        }

        if (array_key_exists($id, $this->config)) {
            return true;
        }

        if (class_exists($id)) {
            return true;
        }

        return false;
    }

    /**
     * @param string $id
     * @return mixed
     * @throws NotFoundException
     * @throws ContainerException
     */
    private function createObject(string $id): mixed
    {
        $classNameOrClassConfig = $this->config[$id] ?? $id;

        if (is_string($classNameOrClassConfig)) { // Class name
            /** @psalm-var class-string $className */
            $className = $classNameOrClassConfig;

            $config = [];
        } elseif (is_array($classNameOrClassConfig)) { // Class config array
            if (!array_key_exists('class', $classNameOrClassConfig)) {
                throw new ContainerException(sprintf("Class not defined in component '%s'!", $id), 500);
            }

            if (!is_string($classNameOrClassConfig['class'])) {
                throw new ContainerException(sprintf("Class name must be a string in component '%s'!", $id), 500);
            }

            /** @psalm-var class-string $className */
            $className = $classNameOrClassConfig['class'];

            $config = $classNameOrClassConfig;
        } else { // Component define error
            throw new ContainerException(sprintf("Component '%s' define error!", $id), 500);
        }

        if (isset($this->building[$className])) {
            throw new ContainerException(
                sprintf(
                    "Circular reference when instantiating class '%s' ('%s')!",
                    $className,
                    implode("', '", array_keys($this->building))
                ),
                500
            );
        }

        $this->building[$className] = 1;

        try {
            $reflectionClass = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            throw new NotFoundException(sprintf("Class '%s' not found!", $className), 500, $e);
        }

        if (!$reflectionClass->isInstantiable()) {
            throw new ContainerException(sprintf("Class '%s' not instantiable!", $className), 500);
        }

        $resolveConstructParams = [];

        if ($reflectionClass->getConstructor() !== null) {
            $constructorParams = $config['__construct()'] ?? [];

            if (!is_array($constructorParams)) {
                throw new ContainerException(
                    sprintf(
                        "Constructor params must be an array in config of class '%s'!",
                        $className
                    ),
                    500
                );
            }

            $resolveConstructParams = $this->resolveMethodParams(
                $reflectionClass,
                $reflectionClass->getConstructor(),
                $constructorParams
            );
        }

        try {
            $newClass = $reflectionClass->newInstanceArgs($resolveConstructParams);
        } catch (ReflectionException $e) {
            throw new ContainerException(sprintf("Instantiate class '%s' error!", $className), 500, $e);
        } finally {
            unset($this->building[$className]);
        }

        foreach ($config as $name => $value) {
            if (!is_string($name)) {
                throw new ContainerException(
                    sprintf(
                        "Property name or method name must be a string in config of class '%s'!",
                        $className
                    ),
                    500
                );
            }

            if ($name === 'class' || $name == '__construct()') {
                continue;
            }

            if (str_starts_with($name, '$')) {
                $propertyName = substr($name, 1);

                if ($reflectionClass->hasProperty($propertyName) && $reflectionClass->getProperty($propertyName)->isPublic()) {
                    $propertyType = $reflectionClass->getProperty($propertyName)->getType();

                    if (is_null($propertyType)) {
                        throw new ContainerException(
                            sprintf(
                                "The type of property '%s' in class '%s' is not defined!",
                                $propertyName,
                                $className
                            ),
                            500
                        );
                    }

                    if (!($propertyType instanceof ReflectionNamedType)) {
                        throw new ContainerException(
                            sprintf(
                                "Union or intersection type of property '%s' in class '%s' is not supported!",
                                $propertyName,
                                $className
                            ),
                            500
                        );
                    }

                    $propertyTypeName = $propertyType->getName();

                    $resolveValue = $this->resolveValue($value);

                    if ($this->strictMode) {
                        $this->checkPropertyType($reflectionClass, $propertyName, $propertyTypeName, $resolveValue);
                    }

                    try {
                        $newClass->$propertyName = $resolveValue;
                    } catch (Throwable $e) {
                        throw new ContainerException(
                            sprintf(
                                "Set property '%s' in class '%s' error!",
                                $propertyName,
                                $className
                            ),
                            500,
                            $e
                        );
                    }
                } else {
                    throw new ContainerException(
                        sprintf(
                            "Property '%s' not defined in class '%s' or not public!",
                            $propertyName,
                            $className
                        ),
                        500
                    );
                }
            } elseif (str_ends_with($name, '()')) {
                $methodName = substr($name, 0, -2);

                if ($reflectionClass->hasMethod($methodName) && $reflectionClass->getMethod($methodName)->isPublic()) {
                    $methodParams = $value;

                    if (!is_array($methodParams)) {
                        throw new ContainerException(
                            sprintf(
                                "Method params must be an array in config of class '%s'!",
                                $className
                            ),
                            500
                        );
                    }

                    $resolveMethodParams = $this->resolveMethodParams(
                        $reflectionClass,
                        $reflectionClass->getMethod($methodName),
                        $methodParams
                    );

                    try {
                        /** @psalm-suppress MixedMethodCall */
                        $newClass->$methodName(...$resolveMethodParams);
                    } catch (Throwable $e) {
                        throw new ContainerException(
                            sprintf(
                                "Call method '%s' in class '%s' error!",
                                $methodName,
                                $className
                            ),
                            500,
                            $e
                        );
                    }
                } else {
                    throw new ContainerException(
                        sprintf(
                            "Method '%s' not defined in class '%s' or not public!",
                            $methodName,
                            $className
                        ),
                        500
                    );
                }
            } else {
                throw new ContainerException(sprintf("Unknown param '%s' in component '%s'!", $name, $id), 500);
            }
        }

        return $newClass;
    }

    /**
     * @param ReflectionClass $reflectionClass
     * @param ReflectionMethod $reflectionMethod
     * @param array $methodParams
     * @return array
     * @throws NotFoundException
     * @throws ContainerException
     */
    private function resolveMethodParams(
        ReflectionClass $reflectionClass,
        ReflectionMethod $reflectionMethod,
        array $methodParams
    ): array {
        $i = 0;
        $resolveMethodParams = [];

        foreach ($reflectionMethod->getParameters() as $parameter) {
            $parameterName = $parameter->getName();
            $parameterType = $parameter->getType();

            if (is_null($parameterType)) {
                throw new ContainerException(
                    sprintf(
                        "The type of parameter '%s' in method '%s' of class '%s' is not defined!",
                        $parameterName,
                        $reflectionMethod->getName(),
                        $reflectionClass->getName()
                    ),
                    500
                );
            }

            if (!($parameterType instanceof ReflectionNamedType)) {
                throw new ContainerException(
                    sprintf(
                        "Union or intersection type of parameter '%s' in method '%s' of class '%s' is not supported!",
                        $parameterName,
                        $reflectionMethod->getName(),
                        $reflectionClass->getName()
                    ),
                    500
                );
            }

            $parameterTypeName = $parameterType->getName();

            if (isset($methodParams[$i])) {
                $resolveValue = $this->resolveValue($methodParams[$i]);

                if ($this->strictMode) {
                    $this->checkParameterType($reflectionClass, $reflectionMethod, $parameterName, $parameterTypeName, $resolveValue);
                }

                $resolveMethodParams[$parameterName] = $resolveValue;
            } elseif ($this->has($parameterTypeName)) {
                $resolveValue = $this->get($parameterTypeName);

                $resolveMethodParams[$parameterName] = $resolveValue;
            } elseif ($parameter->isOptional() && $parameter->isDefaultValueAvailable()) {
                $resolveValue = $parameter->getDefaultValue();

                $resolveMethodParams[$parameterName] = $resolveValue;
            } else {
                throw new ContainerException(
                    sprintf(
                        "Method '%s' in class '%s' required param '%s' (%s)!",
                        $reflectionMethod->getName(),
                        $reflectionClass->getName(),
                        $parameterName,
                        $parameterTypeName
                    ),
                    500
                );
            }

            $i++;
        }

        return $resolveMethodParams;
    }

    /**
     * @param mixed $value
     * @return mixed
     * @throws NotFoundException
     * @throws ContainerException
     */
    private function resolveValue(mixed $value): mixed
    {
        if (is_string($value) && $this->has($value)) {
            return $this->get($value);
        }

        if ($value instanceof Closure) {
            return $value($this);
        }

        return $value;
    }

    /**
     * @param ReflectionClass $reflectionClass
     * @param string $propertyName
     * @param string $propertyTypeName
     * @param mixed $propertyValue
     * @return void
     * @throws ContainerException
     */
    private function checkPropertyType(
        ReflectionClass $reflectionClass,
        string $propertyName,
        string $propertyTypeName,
        mixed $propertyValue
    ): void {
        if (is_object($propertyValue)) {
            if (!($propertyValue instanceof $propertyTypeName)) {
                throw new ContainerException(
                    sprintf(
                        "Property '%s' in class '%s' type error (required '%s', but given '%s')!",
                        $propertyName,
                        $reflectionClass->getName(),
                        $propertyTypeName,
                        $propertyValue::class
                    ),
                    500
                );
            }
        } else {
            $mapPropertyType = self::$mapType[$propertyTypeName] ?? $propertyTypeName;

            if ($mapPropertyType != gettype($propertyValue)) {
                throw new ContainerException(
                    sprintf(
                        "Property '%s' in class '%s' type error (required '%s', but given '%s')!",
                        $propertyName,
                        $reflectionClass->getName(),
                        $mapPropertyType,
                        gettype($propertyValue)
                    ),
                    500
                );
            }
        }
    }

    /**
     * @param ReflectionClass $reflectionClass
     * @param ReflectionMethod $reflectionMethod
     * @param string $parameterName
     * @param string $parameterTypeName
     * @param mixed $parameterValue
     * @return void
     * @throws ContainerException
     */
    private function checkParameterType(
        ReflectionClass $reflectionClass,
        ReflectionMethod $reflectionMethod,
        string $parameterName,
        string $parameterTypeName,
        mixed $parameterValue
    ): void {
        if (is_object($parameterValue)) {
            if (!($parameterValue instanceof $parameterTypeName)) {
                throw new ContainerException(
                    sprintf(
                        "Parameter '%s' in method '%s' of class '%s' type error (required '%s', but given '%s')!",
                        $parameterName,
                        $reflectionMethod->getName(),
                        $reflectionClass->getName(),
                        $parameterTypeName,
                        $parameterValue::class
                    ),
                    500
                );
            }
        } else {
            $mapParameterType = self::$mapType[$parameterTypeName] ?? $parameterTypeName;

            if ($mapParameterType != gettype($parameterValue)) {
                throw new ContainerException(
                    sprintf(
                        "Parameter '%s' in method '%s' of class '%s' type error (required '%s', but given '%s')!",
                        $parameterName,
                        $reflectionMethod->getName(),
                        $reflectionClass->getName(),
                        $mapParameterType,
                        gettype($parameterValue)
                    ),
                    500
                );
            }
        }
    }
}
