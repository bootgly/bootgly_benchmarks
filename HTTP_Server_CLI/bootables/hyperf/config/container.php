<?php
/**
 * DI container bootstrap for Hyperf benchmark.
 */

declare(strict_types=1);

use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;

return new Container((new DefinitionSourceFactory())());
