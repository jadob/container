<?php

declare(strict_types=1);

namespace Jadob\Container;

use Jadob\Container\Exception\ContainerLockedException;
use function array_keys;
use function class_exists;
use Closure;
use function in_array;
use Jadob\Container\Exception\AutowiringException;
use Jadob\Container\Exception\ContainerException;
use Jadob\Container\Exception\ServiceNotFoundException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use function sprintf;

/**
 * @TODO:   maybe some arrayaccess? Fixed services?
 * @author  pizzaminded <mikolajczajkowsky@gmail.com>
 * @license MIT
 */
class Container implements ContainerInterface
{

    /**
     * @var array<string|class-string, Definition>
     */
    protected array $definitions = [];

    /**
     * Instantiated objects, ready to be used.
     *
     * @var array<string, object>
     */
    protected array $services = [];

    /**
     * If true, adding new services/aliases will throw an exception.
     *
     * @var bool
     */
    protected bool $locked = false;

    /**
     * @var array<string, string|int|float|array?
     */
    protected array $parameters = [];

    public function __construct(array $services = null)
    {
        if ($services !== null) {
            $this->services = $services;
        }
    }

    /**
     * This methods does not try to autowire services.
     *
     * @param string $serviceName
     * @return mixed
     * @throws ServiceNotFoundException
     * @throws ContainerException
     */
    public function get($serviceName): object
    {
        /**
         * Return service if exists
         */
        if (isset($this->services[$serviceName])) {
            return $this->services[$serviceName];
        }

        /**
         * Check there is a factory for given service
         */
        if (isset($this->factories[$serviceName])) {
            /**
             * instantiateFactory() adds them to $this->services, so we can just return them here
             */
            return $this->instantiateFactory($serviceName);
        }



        /**
         * if reached this moment, the only thing we need to do, is to break
         */
        throw new ServiceNotFoundException('Service ' . $serviceName . ' is not found in container.');
    }

    /**
     * @throws ContainerException
     */
    private function unwrapDefinition(Definition $definition, int $wrapsCount = 0): object
    {

        if ($wrapsCount >= self::MAX_DEFINITION_WRAPS) {
            throw new ContainerException('Could not unwrap a definition as is it wrapped too much.');
        }

        $service = $definition->getService();
        if ($service instanceof Definition) {
            $service = $this->unwrapDefinition($definition, ++$wrapsCount);
        }

        return $service;
    }

    /**
     * @throws ContainerException
     */
    private function createServiceFromDefinition(string $serviceId)
    {
        /**
         * Do not instantiate if exists
         */
        if (isset($this->services[$serviceId])) {
            return $this->services[$serviceId];
        }

        // Pick a present from under the tree
        $definition = $this->definitions[$serviceId];

        // unwrap them
        $service = $this->unwrapDefinition($definition);

        // put some batteries to our gift, if needed
        if ($service instanceof Closure) {
            $service = $this->instantiateFactory($service);
        }

        // make sure our present is running fine
        if (is_object($service) === false) {
            throw new ContainerException(
                sprintf(
                    'Factory for "%s" does not returned an object.',
                    $serviceId
                )
            );
        }



    }


    /**
     * Turns a factory into service.
     * @deprecated
     * @param string $factoryName
     * @return mixed
     */
    protected function instantiateFactory(string $factoryName)
    {
        /**
         * Do not instantiate factories if it has been instantiated
         */
        if (isset($this->services[$factoryName])) {
            return $this->services[$factoryName];
        }


        $service = $this->factories[$factoryName]($this);

        if (!is_object($service)) {
            throw new ContainerException('Factory "' . $factoryName . '" should return an object, ' . gettype($service) . ' returned');
        }

        $this->services[$factoryName] = $service;
        unset($this->factories[$factoryName]);
        return $this->services[$factoryName];
    }

    /**
     * @param string $factoryName
     * @param string $interfaceToCheck
     * @return bool|null
     * @throws ReflectionException
     */
    protected function factoryReturnImplements(string $factoryName, string $interfaceToCheck): ?bool
    {
        if (!isset($this->factories[$factoryName])) {
            return null;
        }

        $factory = $this->factories[$factoryName];
        $reflectionMethod = new ReflectionMethod($factory, '__invoke');

        /**
         * There is no return type defined in factory, return null as at this moment is not possible to resolve
         * return type without service instantiating
         */
        if (!$reflectionMethod->hasReturnType()) {
            return null;
        }

        /** @var ReflectionNamedType $returnRypeReflection */
        $returnRypeReflection = $reflectionMethod->getReturnType();
        $returnType = $returnRypeReflection->getName();

        return $returnType === $interfaceToCheck
        || in_array($interfaceToCheck, class_implements($returnType), true)
        || in_array($interfaceToCheck, class_parents($returnType), true);
    }

    /**
     * @param string $interfaceClassName FQCN of interface that need to be verified
     *
     * @return array
     * @throws ReflectionException
     */
    public function getObjectsImplementing(string $interfaceClassName): array
    {
        $objects = [];

        foreach ($this->services as $service) {
            if ($service instanceof $interfaceClassName) {
                $objects[] = $service;
            }
        }

        foreach (array_keys($this->factories) as $factoryName) {
            /**
             * When given factory has got a return type defined, use it and check that returned class implements
             * requested interface
             *
             * Also, factoryReturnImplements() returns bool|null, so explicitly check for return type
             */
            if ($this->factoryReturnImplements($factoryName, $interfaceClassName) === false) {
                continue;
            }

            /**
             * If given factory does not have return type defined, instantiate them
             */
            $service = $this->instantiateFactory($factoryName);

            if ($service instanceof $interfaceClassName) {
                $objects[] = $service;
            }
        }

        return $objects;
    }

    /**
     * A has() on steroids.
     * Checks the services and factories by it's type, not the name.
     *
     * @param string $className FQCN of class that we need to find
     * @return null|object - null when no object found
     * @throws ReflectionException
     * @throws ContainerException
     */
    public function findObjectByClassName(string $className)
    {
        if (in_array($className, [ContainerInterface::class, self::class], true)) {
            return $this;
        }

        //search in instantiated stuff
        foreach ($this->services as $service) {
            if ($service instanceof $className) {
                return $service;
            }
        }

        /**
         * Probably there is an issue:
         * When factory will request yet another service, it will be created and removed from $this->factories,
         * BUT these ones are still present in current foreach
         */
        foreach (array_keys($this->factories) as $factoryName) {

            /**
             * Use factory return check as this method works similar to getObjectsImplementing()
             * @see self::getObjectsImplementing()
             */
            if ($this->factoryReturnImplements($factoryName, $className) === false) {
                continue;
            }

            $service = $this->instantiateFactory($factoryName);

            if ($service instanceof $className) {
                return $service;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function has($id): bool
    {
        return isset($this->services[$id]) || isset($this->factories[$id]);
    }

    /**
     * @param string $id
     * @param $object
     *
     * @return Definition
     * @throws ContainerLockedException
     */
    public function add(string $id, object $object)
    {
        if($this->locked) {
            throw new ContainerLockedException('Could not add any services as container is locked.');
        }

        $definition = new Definition($object);
        $this->definitions[$id] = $definition;

        return $definition;
    }

    /**
     * @param string $from
     * @param string $to
     * @return Container
     */
    public function alias(string $from, string $to): Container
    {

        //factories will create different stuff each time so we need to instantiate them
        if (isset($this->factories[$from])) {
            $this->instantiateFactory($from);
        }

        if (isset($this->services[$from])) {
            $this->services[$to] = &$this->services[$from];
        }

        return $this;
    }

    /**
     * @return void
     */
    public function addParameter(string $key, string|int|float|array $value): void
    {
        $this->parameters[$key] = $value;
    }

    /**
     * @param string $key
     * @return string|int|float|array
     */
    public function getParameter(string $key): string|int|float|array
    {
        if (!isset($this->parameters[$key])) {
            throw new RuntimeException('Could not find "' . $key . '" parameter');
        }

        return $this->parameters[$key];
    }

    /**
     * Creates new instance of object with dependencies that currently have been stored in container
     * @TODO REFACTOR - method looks ugly af
     * @param string $className
     * @return object
     * @throws AutowiringException
     * @throws ReflectionException
     */
    public function autowire(string $className): object
    {
        if (!class_exists($className)) {
            throw new AutowiringException(
                sprintf(
                    'Unable to autowire class "%s", as it does not exists.',
                    $className
                )
            );
        }

        $classReflection = new ReflectionClass($className);
        $constructor = $classReflection->getConstructor();

        //no dependencies required, we can just instantiate them and return
        if ($constructor === null) {
            $object = new $className();
            $this->add($className, $object);
            return $object;
        }


        $arguments = $constructor->getParameters();
        $argumentsToInject = [];

        #TODO REFACTOR - method looks ugly af
        foreach ($arguments as $argument) {
            $this->checkConstructorArgumentCanBeAutowired($argument, $className);

            $argumentClass = $argument->getType()->getName();
            try {
                $argumentsToInject[] = $this->findObjectByClassName($argumentClass);
            } catch (ServiceNotFoundException $exception) {
                //try to autowire if not found
                try {
                    $argumentsToInject[] = $this->autowire($argumentClass);
                } catch (ContainerException $autowiringException) {
                    //TODO Named constructors
                    throw new AutowiringException('Unable to autowire class "' . $className . '", could not find service ' . $argumentClass . ' in container. See Previous exception for details ', 0, $exception);
                }
            }
        }

        $service = new $className(...$argumentsToInject);
        $this->add($className, $service);
        return $service;
    }

    /**
     * @param ReflectionParameter $parameter
     * @param string $className
     * @throws AutowiringException
     */
    protected function checkConstructorArgumentCanBeAutowired(ReflectionParameter $parameter, string $className)
    {
        //no nulls allowed
        if ($parameter->getType() === null) {
            //TODO Named constructors
            throw new AutowiringException('Unable to autowire class "' . $className . '", one of arguments is null.');
        }

        //only classes allowed so far
        if ($parameter->getType()->isBuiltin()) {
            //TODO Named constructors
            throw new AutowiringException('Unable to autowire class "' . $className . '", as "$' . $parameter->name . '" constructor argument requires a scalar value');
        }
    }

    /**
     * Prevents adding new services to container.
     * @return void
     */
    public function lock(): void
    {
        $this->locked = true;
    }
}