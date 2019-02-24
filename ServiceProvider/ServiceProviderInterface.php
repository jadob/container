<?php

namespace Jadob\Container\ServiceProvider;

use Jadob\Container\Container;
use Jadob\Container\ContainerBuilder;

/**
 * Interface ServiceProviderInterface
 * @package Jadob\Container\ServiceProvider
 * @author pizzaminded <miki@appvende.net>
 * @license MIT
 */
interface ServiceProviderInterface
{
    /**
     * returns Config node name that will be passed as $config in register() method.
     * return null if no config needed.
     * @return string|null
     */
    public function getConfigNode();

    /**
     * Here you can define things that will be registered in Container.
     * @param ContainerBuilder $container
     * @param array|null $config
     */
    public function register(ContainerBuilder $container, $config);

    /**
     * Stuff that's needed to be done after container is built.
     * What can you do using these method?
     * - This one gets container as a first argument, so, you can e.g. get all services implementing SomeCoolInterface,
     * and inject them somewhere
     * (example 1: using Twig, you can register all extensions)
     * (example 2: EventListener registers all Listeners here)
     * - You can add new services of course
     *
     * @param Container $container
     * @param array|null $config the same config node as passed in register()
     * @return void
     */
    public function onContainerBuild(Container $container, $config);

}