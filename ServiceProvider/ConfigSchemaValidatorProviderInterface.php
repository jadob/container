<?php
declare(strict_types=1);

namespace Jadob\Container\ServiceProvider;

/**
 * It allows to validate configuration passed to providers.
 * If Service Provider class does not implements this one, validation will be skipped.
 *
 * @author  pizzaminded <mikolajczajkowsky@gmail.com>
 * @license MIT
 */
interface ConfigSchemaValidatorProviderInterface
{
    /**
     * Returns path to XSD configuration file.
     * This method does not rely on BootstrapInterface methods, to path to file MUST be ABSOLUTE.
     *
     * @return string
     */
    public function getValidatorSchemaFileLocation(): string;
}