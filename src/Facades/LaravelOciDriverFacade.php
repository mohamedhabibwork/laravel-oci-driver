<?php

namespace LaravelOCI\LaravelOciDriver\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelOCI\LaravelOciDriver\LaravelOciDriver;

/**
 * @see LaravelOciDriver
 */
class LaravelOciDriverFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LaravelOciDriver::class;
    }
}
