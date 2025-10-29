<?php

declare(strict_types=1);

namespace Rukavishnikov\Psr\Container;

use Closure;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;

final class Container implements ContainerInterface
{
    private array $instances = [];

    private static array $mapType = [
        'bool' => 'boolean',
        'int' => 'integer',
        'float' => 'double',
    ];

    /**
     * @param array $config
     * @param bool $strictMode
     */
    public function __construct(
        private array $config,
        private bool $strictMode = false
    ) {
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
        return $this->hasInternal($id, $this->strictMode);
    }

    /**
     * @param string $id
     * @param bool $strictMode
     * @return bool
     */
    private function hasInternal(string $id, bool $strictMode): bool
    {
        if (array_key_exists($id, $this->instances)) {
            return true;
        }

        $present = array_key_exists($id, $this->config);

        if ($present && $strictMode) {
            $classNameOrClassConfig = $this->config[$id];

            if (is_string($classNameOrClassConfig)) { // Class name
                $className = $classNameOrClassConfig;
            } elseif (is_array($classNameOrClassConfig)) { // Class config array
                if (!array_key_exists('class', $classNameOrClassConfig)) {
                    return false;
                }

                $className = $classNameOrClassConfig['class'];

                if (!is_string($className)) {
                    return false;
                }
            } else { // Component define error
                return false;
            }

            try {
                $reflectionClass = new ReflectionClass($className);
            } catch (ReflectionException) {
                return false;
            }

            if (!$reflectionClass->isInstantiable()) {
                return false;
            }
        }

        return $present;
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
            $className = $classNameOrClassConfig;

            $config = [];
        } elseif (is_array($classNameOrClassConfig)) { // Class config array
            if (!array_key_exists('class', $classNameOrClassConfig)) {
                throw new NotFoundException(sprintf("Class not defined in component '%s'!", $id), 500);
            }

            $className = $classNameOrClassConfig['class'];

            if (!is_string($className)) {
                throw new NotFoundException(sprintf("Class name must be a string in component '%s'!", $id), 500);
            }

            $config = $classNameOrClassConfig;
        } else { // Component define error
            throw new NotFoundException(sprintf("Component '%s' define error!", $id), 500);
        }

        try {
            $reflectionClass = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            throw new NotFoundException(sprintf("Class '%s' not found!", $className), 500, $e);
        }

        if (!$reflectionClass->isInstantiable()) {
            throw new NotFoundException(sprintf("Class '%s' not instantiable!", $className), 500);
        }

        $resolveConstructParams = [];

        if ($reflectionClass->getConstructor() !== null) {
            $constructorParams = $config['__construct()'] ?? [];

            $resolveConstructParams = $this->resolveMethodParams(
                $reflectionClass,
                $reflectionClass->getConstructor(),
                $constructorParams
            );
        }

        try {
            $newClass = new $className(...$resolveConstructParams);
        } catch (Throwable $e) {
            throw new ContainerException(sprintf("Instantiate class '%s' error!", $className), 500, $e);
        }

        foreach ($config as $name => $value) {
            if ($name === 'class' || $name == '__construct()') {
                continue;
            }

            if (str_starts_with($name, '$')) {
                $propertyName = substr($name, 1);

                if ($reflectionClass->hasProperty($propertyName) && $reflectionClass->getProperty($propertyName)->isPublic()) {
                    $propertyType = $reflectionClass->getProperty($propertyName)->getType()?->getName();

                    $resolveValue = $this->resolveParam($value);

                    if ($this->strictMode && $propertyType !== null) {
                        $this->checkPropertyType($reflectionClass, $propertyName, $propertyType, $resolveValue);
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
                    $resolveMethodParams = $this->resolveMethodParams(
                        $reflectionClass,
                        $reflectionClass->getMethod($methodName),
                        $value
                    );

                    try {
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
            $parameterType = $parameter->getType()?->getName();

            if (isset($methodParams[$i])) {
                $resolveValue = $this->resolveParam($methodParams[$i]);

                if ($this->strictMode && $parameterType !== null) {
                    $this->checkParameterType($reflectionClass, $reflectionMethod, $parameterName, $parameterType, $resolveValue);
                }

                $resolveMethodParams[$parameterName] = $resolveValue;
            } elseif (is_string($parameterType) && $this->hasInternal($parameterType, false)) {
                $resolveMethodParams[$parameterName] = $this->get($parameterType);
            } elseif (is_string($parameterType) && class_exists($parameterType)) {
                $resolveMethodParams[$parameterName] = new $parameterType();
            } elseif ($parameter->isOptional() && $parameter->isDefaultValueAvailable()) {
                $resolveMethodParams[$parameterName] = $parameter->getDefaultValue();
            } else {
                throw new ContainerException(
                    sprintf(
                        "Method '%s' in class '%s' required param '%s' (%s)!",
                        $reflectionMethod->getName(),
                        $reflectionClass->getName(),
                        $parameterName,
                        $parameterType
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
    private function resolveParam(mixed $value): mixed
    {
        if (is_string($value) && $this->hasInternal($value, false)) {
            return $this->get($value);
        }

        if (is_string($value) && class_exists($value)) {
            return new $value();
        }

        if ($value instanceof Closure) {
            return $value($this);
        }

        return $value;
    }

    /**
     * @param ReflectionClass $reflectionClass
     * @param string $propertyName
     * @param string $propertyType
     * @param mixed $propertyValue
     * @return void
     * @throws ContainerException
     */
    private function checkPropertyType(
        ReflectionClass $reflectionClass,
        string $propertyName,
        string $propertyType,
        mixed $propertyValue
    ): void {
        if (is_object($propertyValue)) {
            if (!($propertyValue instanceof $propertyType)) {
                throw new ContainerException(
                    sprintf(
                        "Property '%s' in class '%s' type error (required '%s', but given '%s')!",
                        $propertyName,
                        $reflectionClass->getName(),
                        $propertyType,
                        $propertyValue::class
                    ),
                    500
                );
            }
        } else {
            $mapPropertyType = self::$mapType[$propertyType] ?? $propertyType;

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
     * @param string $parameterType
     * @param mixed $parameterValue
     * @return void
     * @throws ContainerException
     */
    private function checkParameterType(
        ReflectionClass $reflectionClass,
        ReflectionMethod $reflectionMethod,
        string $parameterName,
        string $parameterType,
        mixed $parameterValue
    ): void {
        if (is_object($parameterValue)) {
            if (!($parameterValue instanceof $parameterType)) {
                throw new ContainerException(
                    sprintf(
                        "Parameter '%s' in method '%s' of class '%s' type error (required '%s', but given '%s')!",
                        $parameterName,
                        $reflectionMethod->getName(),
                        $reflectionClass->getName(),
                        $parameterType,
                        $parameterValue::class
                    ),
                    500
                );
            }
        } else {
            $mapParameterType = self::$mapType[$parameterType] ?? $parameterType;

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
