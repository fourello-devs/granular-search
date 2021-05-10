<?php

namespace FourelloDevs\GranularSearch\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Class GranularSearch
 * @package FourelloDevs\GranularSearch\Facades
 *
 * @author James Carlo Luchavez <carlo.luchavez@fourello.com>
 * @since 2021-05-10
 *
 * @see \FourelloDevs\GranularSearch\GranularSearch
 */
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
