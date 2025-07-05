<?php

namespace LaravelOCI\LaravelOciDriver\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \LaravelOCI\LaravelOciDriver\LaravelOciDriver
 */
class LaravelOciDriverFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LaravelOCI\LaravelOciDriver\LaravelOciDriver::class;
    }
}
