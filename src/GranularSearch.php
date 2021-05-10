<?php

namespace FourelloDevs\GranularSearch;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Class GranularSearch
 * @package FourelloDevs\GranularSearch
 *
 * @author James Carlo Luchavez <carlo.luchavez@fourello.com>
 * @since 2021-05-10
 */
class GranularSearch
{
    /**
     * Alias for q searching
     *
     * @var string
     */
    protected $q_alias;

    /**
     * @var string[]
     */
    protected $mentioned_models;

    /**
     * @var Model|null
     */
    protected $initial_model;

    /**
     * GranularSearch constructor.
     */
    public function __construct()
    {
        $this->setQAlias('q');
        $this->mentioned_models = [];
        $this->initial_model = null;
    }

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
    public function search($request, $model, string $table_name, ?array $excluded_keys = [], ?array $like_keys = [], ?string $prepend_key = '', ?bool $ignore_q = false, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
    {
        $this->validateRequest($request);
        $this->validateTableName($table_name);
        $this->validateExcludedKeys($excluded_keys);

        // Always convert $request to Associative Array
        if (is_request_instance($request)) {
            $request = $request->all();
        }

        $data = $this->prepareData($request, $excluded_keys, $prepend_key, $ignore_q);
        $request_keys = array_keys($data);

        if(empty($data)) {
            return $model;
        }

        $accept_q = !$ignore_q && Arr::isFilled($data, $this->getQAlias());

        $table_keys = $this->prepareTableKeys($table_name, $excluded_keys);

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
                    $search = $data[$this->getQAlias()];
                    foreach ($like_keys as $col) {
                        $value = Arr::get($data, $col, $search);
                        if(is_array($value)){
                            $query = $query->orWhere(function (Builder $q) use ($col, $value) {
                                foreach ($value as $s) {
                                    self::setWhereCondition($q, $col, $s, true, 'or');
                                }
                            });
                        }else{
                            self::setWhereCondition($query, $col, $value, true, 'or');
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
                                        self::setWhereCondition($q, $col, $s, true, 'or');
                                    }
                                });
                            } else {
                                self::setWhereCondition($query, $col, $search, true);
                            }
                        }
                    }
                }
            }

            // 'EXACT' SEARCHING
            if($accept_q){
                $search = $data[$this->getQAlias()];
                foreach ($exact_keys as $col) {
                    $value = Arr::get($data, $col, $search);
                    if(is_array($value)) {
                        if($force_like) {
                            $query = $query->orWhere(function (Builder $q) use ($col, $value) {
                                foreach ($value as $s){
                                    self::setWhereCondition($q, $col, $s, true, 'or');
                                }
                            });
                        } else {
                            $query = $query->orWhereIn($col, $value);
                        }
                    } else {
                        self::setWhereCondition($query, $col, $value, $force_like, 'or');
                    }
                }
            }
            else {
                foreach ($exact_keys as $col) {
                    if (Arr::isFilled($data, $col)) {
                        $search = $data[$col];
                        if (is_array($search)) {
                            if($force_like){
                                $condition = function (Builder $q) use ($col, $search) {
                                    foreach ($search as $s){
                                        self::setWhereCondition($q, $col, $s, true, 'or');
                                    }
                                };
                                $query = $query->where($condition, null, null, $force_or ? 'or' : 'and');
                            } else {
                                $query = $query->whereIn($col, $search, $force_or ? 'or' : 'and');
                            }
                        } else {
                            self::setWhereCondition($query, $col, $search, $force_like, $force_or ? 'or' : 'and');
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

    /***** METHODS *****/

    /**
     * Get an associative array from another associative array with the $prepend_key removed from keys.
     *
     * @param array $request
     * @param array|null $excluded_keys
     * @param string $prepend_key
     * @param bool $ignore_q
     * @return array
     */
    public function prepareData(array $request, ?array $excluded_keys = [], string $prepend_key = '', bool $ignore_q = FALSE): array
    {
        if(is_array($request) && (empty($request) || Arr::isAssoc($request)))
        {
            Arr::forget($request, $excluded_keys);
            return $this->extractPrependedKeys($request, $prepend_key, $ignore_q);
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
    public function extractPrependedKeys($data, string $prepend_key = '', bool $ignore_q = FALSE): array
    {
        if(is_request_instance($data)) {
            $data = $data->all();
        }

        if(empty($prepend_key)) {
            return $data;
        }

        if(empty($data) === FALSE && Arr::isNotAssoc($data)) {
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
                if($ignore_q && $key === $this->getQAlias()) {
                    continue;
                }
                $result[$key] = $value;
            }
            else if ($ignore_q === FALSE && $key === $this->getQAlias()) {
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
    public function prepareTableKeys(string $table_name, ?array $excluded_keys = []): array
    {
        return array_values(array_diff(Schema::getColumnListing($table_name), $excluded_keys));
    }

    /**
     * Determine if the $request is either a Request instance/subclass or an associative array.
     *
     * @param Request|array $request
     */
    public function validateRequest($request): void
    {
        if((is_array($request) && empty($request) === FALSE && Arr::isNotAssoc($request)) && is_request_instance($request) === FALSE) {
            throw new RuntimeException('$request must be an array or an instance/subclass of Illuminate/Http/Request.');
        }
    }

    /**
     * Validate the $table_name if it is an actual database table.
     *
     * @param string $table_name
     */
    public function validateTableName(string $table_name): void
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
    public function validateExcludedKeys(array $excluded_keys): void
    {
        if(Arr::isAssoc($excluded_keys)) {
            throw new RuntimeException('$excluded_keys must be a sequential array, not an associative one.');
        }
    }

    /**
     * Convert a string into a regex string for LIKE searching.
     *
     * @param string $str
     * @return string
     */
    public function getLikeString(string $str): string
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
    public function setWhereCondition(Builder $query, string $col, string $str, ?bool $is_like_search = FALSE, ?string $boolean = 'and'): void
    {
        $operator = $is_like_search ? 'LIKE' : '=';
        $str = $is_like_search ? $this->getLikeString($str) : $str;
        if (empty($str) === FALSE) {
            $query->whereRaw(implode(' ', [$col, $operator, '?']), [$str], $boolean);
        }
    }

    /**
     * Check if a q-aliased key exists and is not empty on a Request or associative array.
     *
     * @param Request|array $request
     * @return bool
     */
    public function hasQ($request): bool
    {
        return is_request_or_array_filled($request, $this->getQAlias(), true);
    }

    /***** SETTERS & GETTERS *****/

    /**
     * Get Q Alias
     *
     * @return string
     */
    public function getQAlias(): string
    {
        return $this->q_alias;
    }

    /**
     * Set Q Alias
     *
     * @param string $q_alias
     */
    public function setQAlias(string $q_alias): void
    {
        $this->q_alias = $q_alias;
    }

    /**
     * Get mentioned models array.
     *
     * @return string[]
     */
    public function getMentionedModels(): array
    {
        return $this->mentioned_models;
    }

    /**
     * Set mentioned models array.
     *
     * @param string[] $mentioned_models
     */
    public function setMentionedModels(array $mentioned_models): void
    {
        $this->mentioned_models = $mentioned_models;
    }

    /**
     * Clear mentioned models array.
     */
    public function clearMentions(): void
    {
        $this->setMentionedModels([]);
    }

    /**
     * Add model to mentions.
     *
     * @param mixed $object_or_class
     */
    public function addToMentionedModels($object_or_class): void
    {
        $this->mentioned_models[] = get_class_name_from_object($object_or_class);
    }

    /**
     * Check if a model is mentioned already.
     *
     * @param mixed $object_or_class
     * @return bool
     */
    public function isModelMentioned($object_or_class): bool
    {
        $needle = get_class_name_from_object($object_or_class);
        return in_array($needle, $this->getMentionedModels(), TRUE);
    }

    /**
     * Set initial model.
     *
     * @param $model
     */
    public function setInitialModel($model): void
    {
        if (is_model_instance($model)) {
            $this->initial_model = $this->initial_model ?? $model;
        }
    }

    /**
     * Get initial model.
     *
     * @return Model|null
     */
    public function getInitialModel(): ?Model
    {
        return $this->initial_model;
    }

    /**
     * Clear initial model.
     */
    public function clearInitialModel() : void
    {
        $this->initial_model = null;
    }

    /**
     * Check if a model is the initial model.
     *
     * @param Model $initial_model
     * @return bool
     */
    public function isInitialModel(Model $initial_model): bool
    {
        return $this->initial_model === $initial_model;
    }
}
