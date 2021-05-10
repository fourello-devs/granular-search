<?php

namespace FourelloDevs\GranularSearch\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Trait GranularSearchTrait
 * @package FourelloDevs\GranularSearch\Traits
 *
 * Granular Search's goal is to make model filtering/searching easier with just one line code.
 *
 * As observed, most of the keys inside the Request variable are also column names of a certain Eloquent model's associated database table.
 * Therefore, most of the search algorithms that are being created has a repetitive pattern: $model->where($key, $value).
 * To save time and apply DRY principle, this trait was created.
 *
 * @note By design, this trait will ONLY process the Request keys that belongs to the column names of a certain Eloquent model's table.
 * @note Since a $request key can have an array as value, whereIn and whereInLike are also added in this algorithm.
 *
 * @author James Carlo S. Luchavez (carlo.luchavez@fourello.com)
 * @since April 27, 2021
 */
trait GranularSearchTrait
{
    protected static $q_alias = 'q';

    /**
     * Get a filtered collection of model using the contents of a Request or an associative array.
     *
     * @param Request|array $request Contains all the information regarding the HTTP request
     * @param Model|Builder $model Model or query builder that will be subjected to searching/filtering
     * @param string $table_name Database table name associated with the $model
     * @param array|null $excluded_keys Request keys or table column names to be excluded from $request
     * @param array|null $like_keys Request keys or table column names to be search with LIKE
     * @param string|null $prepend_key
     * @param bool $ignore_q
     * @param bool $force_or
     * @param bool $force_like
     * @return Model|Builder
     */
    public static function getGranularSearch($request, $model, string $table_name, ?array $excluded_keys = [], ?array $like_keys = [], ?string $prepend_key = '', ?bool $ignore_q = false, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
    {
        self::validateRequest($request);
        self::validateTableName($table_name);
        self::validateExcludedKeys($excluded_keys);

        // Always convert $request to Associative Array
        if (is_subclass_of($request, Request::class)) {
            $request = $request->all();
        }

        $data = self::prepareData($request, $excluded_keys, $prepend_key, $ignore_q);
        $request_keys = array_keys($data);

        if(empty($data)) {
            return $model;
        }

        $accept_q = !$ignore_q && Arr::isFilled($data, self::$q_alias);

        $table_keys = static::prepareTableKeys($table_name, $excluded_keys);

        $like_keys = array_values(array_intersect($like_keys, $table_keys));

        if($accept_q) {
            $exact_keys = array_values(array_diff($table_keys, $like_keys));
        }
        else {
            $like_keys = array_values(array_intersect($request_keys, $like_keys));
            $exact_keys = array_values(array_intersect($request_keys, $table_keys));
            $exact_keys = array_values(array_diff($exact_keys, $like_keys));
        }

        $model = $model->where(function (Builder $query) use ($force_like, $force_or, $accept_q, $data, $like_keys, $exact_keys) {
            // 'LIKE' SEARCHING
            if (empty($like_keys) === FALSE) {
                // If 'q' is present and is filled, proceed with all-column search
                if($accept_q){
                    $search = $data[self::$q_alias];
                    foreach ($like_keys as $col) {
                        $value = Arr::get($data, $col, $search);
                        if(is_array($value)){
                            $query = $query->orWhere(function (Builder $q) use ($col, $value) {
                                foreach ($value as $s) {
                                    self::setQueryWhereCondition($q, $col, $s, true, 'or');
                                }
                            });
                        }else{
                            self::setQueryWhereCondition($query, $col, $value, true, 'or');
                        }
                    }
                }

                // If 'q' is not present, proceed with column-specific search
                else {
                    foreach ($like_keys as $col) {
                        if (Arr::isFilled($data, $col)) {
                            $search = $data[$col];
                            if (is_array($search)) {
                                $query = $query->where(function (Builder $q) use ($col, $search) {
                                    foreach ($search as $s) {
                                        self::setQueryWhereCondition($q, $col, $s, true, 'or');
                                    }
                                });
                            } else {
                                self::setQueryWhereCondition($query, $col, $search, true);
                            }
                        }
                    }
                }
            }

            // 'EXACT' SEARCHING
            if($accept_q){
                $search = $data[self::$q_alias];
                foreach ($exact_keys as $col) {
                    $value = Arr::get($data, $col, $search);
                    if(is_array($value)) {
                        if($force_like) {
                            $query = $query->orWhere(function (Builder $q) use ($col, $value) {
                                foreach ($value as $s){
                                    self::setQueryWhereCondition($q, $col, $s, true, 'or');
                                }
                            });
                        } else {
                            $query = $query->orWhereIn($col, $value);
                        }
                    } else {
                        self::setQueryWhereCondition($query, $col, $value, $force_like, 'or');
                    }
                }
            }
            else {
                foreach ($exact_keys as $col) {
                    if (Arr::isFilled($data, $col)) {
                        $search = $data[$col];
                        if (is_array($search)) {
                            if($force_like){
                                $condition = static function (Builder $q) use ($col, $search) {
                                    foreach ($search as $s){
                                        self::setQueryWhereCondition($q, $col, $s, true, 'or');
                                    }
                                };
                                $query = $query->where($condition, null, null, $force_or ? 'or' : 'and');
                            } else {
                                $query = $query->whereIn($col, $search, $force_or ? 'or' : 'and');
                            }
                        } else {
                            self::setQueryWhereCondition($query, $col, $search, $force_like, $force_or ? 'or' : 'and');
                        }
                    }
                }
            }
        });

        // SORTING
        if(Arr::isFilled($data, 'sortBy'))
        {
            $asc = $data['sortBy'];
            if(is_array($asc)){
                foreach ($asc as $a) {
                    if(Schema::hasColumn($table_name, $a)){
                        $model = $model->orderBy($a);
                    }
                }
            }
            else if(Schema::hasColumn($table_name, $asc)) {
                $model = $model->orderBy($asc);
            }
        }

        else if(Arr::isFilled($data, 'sortByDesc')){
            $desc = $data['sortByDesc'];
            if(is_array($desc)) {
                foreach ($desc as $d) {
                    if(Schema::hasColumn($table_name, $d)){
                        $model = $model->orderBy($d, 'desc');
                    }
                }
            } else if(Schema::hasColumn($table_name, $desc)) {
                $model = $model->orderBy($desc, 'desc');
            }
        }

        return $model;
    }

    // Methods

    /**
     * Get an associative array from another associative array with the $prepend_key removed from keys.
     *
     * @param array $request
     * @param array|null $excluded_keys
     * @param string $prepend_key
     * @param bool $ignore_q
     * @return array
     */
    private static function prepareData(array $request, ?array $excluded_keys = [], string $prepend_key = '', bool $ignore_q = FALSE): array
    {
        if(is_array($request) && (empty($request) || Arr::isAssoc($request)))
        {
            Arr::forget($request, $excluded_keys);
            return static::extractPrependedKeys($request, $prepend_key, $ignore_q);
        }
        throw new RuntimeException('$request variable must be an associative array.');
    }

    /**
     * Get an associative array from a Request or another associative array with the $prepend_key removed from keys.
     *
     * @param Request|array $data
     * @param string $prepend_key
     * @param bool $ignore_q
     * @return array
     */
    public static function extractPrependedKeys($data, string $prepend_key = '', bool $ignore_q = FALSE): array
    {
        if(is_subclass_of($data, Request::class)) {
            $data = $data->all();
        }

        if(empty($prepend_key)) {
            return $data;
        }

        if(empty($data) === FALSE && Arr::isAssoc($data) === FALSE) {
            throw new RuntimeException('$data must be an associative array.');
        }

        $result = [];
        $prepend = $prepend_key . '_';

        foreach ($data as $key=>$value) {
            if(empty($value)) {
                continue;
            }
            if(Str::startsWith($key, $prepend)) {
                $key = Str::after($key, $prepend);
                if($ignore_q === true && $key === self::$q_alias) {
                    continue;
                }
                $result[$key] = $value;
            }
            else if ($key === self::$q_alias && $ignore_q === FALSE) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get a list of column names of a database table with excluded keys removed.
     *
     * @param string $table_name
     * @param array|null $excluded_keys
     * @return array
     */
    private static function prepareTableKeys(string $table_name, ?array $excluded_keys = []): array
    {
        return array_values(array_diff(Schema::getColumnListing($table_name), $excluded_keys));
    }

    /**
     * Validate the $table_name if it is an actual database table.
     *
     * @param string $table_name
     */
    private static function validateTableName(string $table_name): void
    {
        if(Schema::hasTable($table_name) === FALSE){
            throw new RuntimeException('Table name provided does not exist in database.');
        }
    }

    /**
     * Validate the $excluded_keys if it is an associative array.
     *
     * @param array $excluded_keys
     */
    private static function validateExcludedKeys(array $excluded_keys): void
    {
        if(Arr::isAssoc($excluded_keys)){
            throw new RuntimeException('$excluded_keys must be a sequential array, not an associative one.');
        }
    }

    /**
     * Determine if the $request is either a Request instance/subclass or an associative array.
     *
     * @param Request|array $request
     */
    public static function validateRequest($request): void
    {
        if((is_array($request) && empty($request) === FALSE && Arr::isAssoc($request) === FALSE) && is_subclass_of($request, Request::class) === FALSE){
            throw new RuntimeException('$request must be an array or an instance/subclass of Illuminate/Http/Request.');
        }
    }

    /**
     * Convert a string into a regex string for LIKE searching.
     *
     * @param string $str
     * @return string
     */
    public static function getLikeString(string $str): string
    {
        $result = '';
        foreach (str_split($str) as $s){
            if (ctype_alnum($s)){
                $result .= $s . '%';
            }
        }
        return empty($result) ? '' : '%' . $result;
    }

    /**
     * Set where condition of query.
     *
     * @param Builder $query
     * @param string $col
     * @param string $str
     * @param bool|null $is_like_search
     * @param string|null $boolean
     */
    public static function setQueryWhereCondition(Builder &$query, string $col, string $str, ?bool $is_like_search = FALSE, ?string $boolean = 'and'): void
    {
        $operator = $is_like_search ? 'LIKE' : '=';
        $str = $is_like_search ? self::getLikeString($str) : $str;
        if (empty($str) === FALSE) {
            $query->whereRaw(implode(' ', [$col, $operator, '?']), [$str], $boolean);
        }
    }

    /**
     * Check if the Request or associative array has a specific key.
     *
     * @param Request|array $request
     * @param string $key
     * @param bool|null $is_exact
     * @return bool
     */
    public static function requestOrArrayHas($request, $key = '', ?bool $is_exact = true): bool
    {
        if(is_array($request) && (empty($request) || Arr::isAssoc($request))){
            if($is_exact){
                return Arr::has($request, $key);
            } else{
                return (bool) preg_grep("/$key/", array_keys($request));
            }
        }
        else if(is_subclass_of($request, Request::class)){
            if($is_exact){
                return $request->has($key);
            }else{
                return (bool) preg_grep("/$key/", $request->keys());
            }
        }
        return FALSE;
    }

    /**
     * Get a value from Request or associative array using a string key.
     *
     * @param Request|array $request
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function requestOrArrayGet($request, string $key, $default = null) {
        if(static::requestOrArrayHas($request, $key)) {
            if(is_array($request)) {
                return $request[$key];
            } else {
                return $request->$key;
            }
        }
        return $default;
    }

    /**
     * Check if a key exists and is not empty on a Request or associative array.
     *
     * @param Request|array $request
     * @param string $key
     * @return bool
     */
    public static function isRequestOrArrayFilled($request, string $key): bool
    {
        if(static::requestOrArrayHas($request, $key)){
            if(is_array($request)) {
                return Arr::isFilled($request, $key);
            } else {
                return $request->filled($key);
            }
        }
        return FALSE;
    }

    /**
     * Check if a q-aliased key exists and is not empty on a Request or associative array.
     *
     * @param Request|array $request
     * @return bool
     */
    public static function hasQ($request): bool
    {
        return static::isRequestOrArrayFilled($request, static::$q_alias, true);
    }
}
