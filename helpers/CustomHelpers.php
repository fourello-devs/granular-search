<?php

/**
 * @author James Carlo Luchavez <carlo.luchavez@fourello.com>
 * @since 2021-05-06
 */


use FourelloDevs\GranularSearch\GranularSearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

if (! function_exists('granular_search')) {

    /**
     * @return GranularSearch
     */
    function granular_search()
    {
        return app('granular-search');
    }
}

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

if (! function_exists('is_request_instance')) {

    /**
     * @param $request
     * @return bool
     */
    function is_request_instance($request): bool
    {
        return is_subclass_of($request, Request::class);
    }
}

if (! function_exists('request_or_array_has')) {
    /**
     * Check if the Request or associative array has a specific key.
     *
     * @param Request|array $request
     * @param string $key
     * @param bool|null $is_exact
     * @return bool
     */
    function request_or_array_has($request, string $key = '', ?bool $is_exact = true): bool
    {
        if(is_array($request) && (empty($request) || Arr::isAssoc($request))){
            if($is_exact) {
                return Arr::has($request, $key);
            }
            else {
                return (bool) preg_grep("/$key/", array_keys($request));
            }
        }
        else if(is_subclass_of($request, Request::class)){
            if($is_exact) {
                return $request->has($key);
            }
            else {
                return (bool) preg_grep("/$key/", $request->keys());
            }
        }
        return FALSE;
    }
}

if (! function_exists('request_or_array_get')) {
    /**
     * Get a value from Request or associative array using a string key.
     *
     * @param Request|array $request
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function request_or_array_get($request, string $key, $default = null) {
        if(request_or_array_has($request, $key)) {
            if(is_array($request)) {
                return $request[$key];
            } else {
                return $request->$key;
            }
        }
        return $default;
    }
}

if (! function_exists('is_request_or_array_filled')) {
    /**
     * Check if a key exists and is not empty on a Request or associative array.
     *
     * @param Request|array $request
     * @param string $key
     * @return bool
     */
    function is_request_or_array_filled($request, string $key): bool
    {
        if(request_or_array_has($request, $key)){
            if(is_array($request)) {
                return Arr::isFilled($request, $key);
            } else {
                return $request->filled($key);
            }
        }
        return FALSE;
    }
}

if (! function_exists('is_model_instance')) {
    /**
     * Determine if the class using the trait is a subclass of Eloquent Model.
     *
     * @param mixed $object_or_class
     * @return bool
     */
    function is_model_instance($object_or_class): bool
    {
        return is_subclass_of($object_or_class, Model::class);
    }
}
