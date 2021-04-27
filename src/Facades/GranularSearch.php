<?php

namespace FourelloDevs\GranularSearch\Facades;

use Illuminate\Support\Facades\Facade;

class GranularSearch extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'granular-search';
    }
}
