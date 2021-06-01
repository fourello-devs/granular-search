<?php

/**
 * @author James Carlo Luchavez <carlo.luchavez@fourello.com>
 * @since 2021-05-06
 */


use FourelloDevs\GranularSearch\GranularSearch;

if (! function_exists('granular_search')) {

    /**
     * @return GranularSearch
     */
    function granular_search(): GranularSearch
    {
        return app('granular-search');
    }
}
