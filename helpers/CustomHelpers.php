<?php

/**
 * @author James Carlo Luchavez <carlo.luchavez@fourello.com>
 * @since 2021-05-06
 */


use FourelloDevs\OpenStreetMap\OpenStreetMap;

if (! function_exists('array_filter_recursive')) {
    /**
     * @param array $arr
     * @return array
     */
    function array_filter_recursive(array $arr): array
    {
        $result = [];
        foreach ($arr as $key => $value) {
            if(is_array($value) && empty($value) === FALSE){
                $result[$key] = array_filter_recursive($value);
            }else if(empty($value) === FALSE) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
