<?php

namespace LaravelOCI\LaravelOciDriver\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \LaravelOCI\LaravelOciDriver\LaravelOciDriver
 */
class LaravelOciDriver extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LaravelOCI\LaravelOciDriver\LaravelOciDriver::class;
    }
}
