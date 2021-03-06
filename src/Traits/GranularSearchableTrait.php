<?php


namespace FourelloDevs\GranularSearch\Traits;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

/**
 * Trait GranularSearchableTrait
 * @package FourelloDevs\GranularSearch\Traits
 *
 * @method static static|Builder search($request, ?bool $q_search_relationships = FALSE, ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
 * @method static static|Builder granularSearch($request, string $prepend_key, ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
 * @method static static|Builder ofRelationsFromRequest($request, ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
 * @method static static|Builder ofRelationFromRequest($request, string $relation, ?string $prepend_key = '', ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
 * @method static static|Builder ofRelation(string $relation, $key, $value, ?bool $force_or = FALSE)
 * @method static static|Builder sortFromRequest($request)
 * @method static static|Builder sort($column_or_array, ?bool $is_descending = FALSE, ?bool $is_nulls_first = FALSE)
 *
 * @author James Carlo S. Luchavez (carlo.luchavez@fourello.com)
 * @since April 27, 2021
 */
trait GranularSearchableTrait
{
    use GranularTimeSearchTrait;

    /**
     * @var string[]
     * @label Array of keys to exclude during filtering
     */
    protected static $granular_excluded_keys = [];

    /**
     * @var string[]
     * @label Array of keys to be filtered using LIKE
     */
    protected static $granular_like_keys = [];

    /**
     * @var string[]
     * @label Array of relation names to consider on filtering
     */
    protected static $granular_allowed_relations = [];

    /***** GETTERS *****/

    /**
     * @return string[]
     */
    public static function getGranularExcludedKeys(): array
    {
        return static::$granular_excluded_keys;
    }

    /**
     * @return string[]
     */
    public static function getGranularLikeKeys(): array
    {
        return static::$granular_like_keys;
    }

    /**
     * @return string[]
     */
    public static function getGranularAllowedRelations(): array
    {
        return static::$granular_allowed_relations;
    }

    /***** QUERY SCOPES *****/

    /**
     * Query scope to filter an Eloquent model and its related models using Request parameters.
     *
     * @param Builder $query
     * @param Request|array|string|null|bool $request
     * @param bool|null $q_search_relationships
     * @param bool|null $ignore_q
     * @param bool|null $force_or
     * @param bool|null $force_like
     * @return mixed
     */
    public function scopeSearch(Builder $query, $request, ?bool $q_search_relationships = FALSE, ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
    {
        // Check if initial model is null, set current otherwise
        if (is_null(granular_search()->getInitialModel())) {
            granular_search()->setInitialModel($this);
        }

        if(is_request_instance($request)) {
            $request = $request->all();
        }

        else if(is_string($request)) {
            $request = [granular_search()->getQAlias() => $request];
        }

        else if(is_array($request) && Arr::isAssoc($request) === FALSE) {
            $request = [granular_search()->getQAlias() => array_values($request)];
        }

        else if(is_bool($request) || is_numeric($request)|| is_null($request)) {
            $request = [granular_search()->getQAlias() => $request];
        }

        granular_search()->addToMentionedModels($this);

        $query = $query
            ->granularSearch($request, NULL, $ignore_q, $force_or, $force_like)
            ->ofRelationsFromRequest($request, $q_search_relationships === FALSE ? TRUE : $ignore_q, $force_or, $force_like);

        if (granular_search()->isInitialModel($this)) {
            granular_search()->clearMentions();
            granular_search()->clearInitialModel();

            // Proceed With Search By Time
            $query = $query->searchTimeFromRequest($request, $this->time_column, $this->time_zone);

            // Proceed With Sorting
            $query = $query->sortFromRequest($request);
        }

        return $query;
    }

    /**
     *
     *
     * @param Builder $query
     * @param Request|array $request
     * @param string|null $prepend_key
     * @param bool|null $ignore_q
     * @param bool|null $force_or
     * @param bool|null $force_like
     * @return Builder|Model
     */
    public function scopeGranularSearch(Builder $query, $request, ?string $prepend_key = '', ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
    {
        return granular_search()->search($request, $query, static::getTableName(), static::getGranularExcludedKeys(), static::getGranularLikeKeys(), $prepend_key ?? '', $ignore_q ?? FALSE, $force_or ?? FALSE, $force_like ?? FALSE);
    }

    /**
     * Query scope for multiple relation filtering using Request parameters.
     *
     * @param Builder $query
     * @param Request|array $request
     * @param bool|null $ignore_q
     * @param bool|null $force_or
     * @param bool|null $force_like
     * @return Builder
     */
    public function scopeOfRelationsFromRequest(Builder $query, $request, ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE): Builder
    {
        foreach (static::getGranularAllowedRelations() as $relation)
        {
            $this->validateRelation($relation);

            $prepend_key = Str::snake(Str::singular($relation));

            $params = granular_search()->extractPrependedKeys($request, $prepend_key, $ignore_q);

            if ($this->$relation()->getRelated()->shouldBeSearched($params, $ignore_q)) {
                $query = $query->ofRelationFromRequest($params, $relation, NULL, $ignore_q, $force_or, $force_like);
            }
        }

        return $query;
    }

    /**
     * Query scope for single relation filtering using Request parameters.
     *
     * @param Builder $query
     * @param Request|array $request
     * @param string $relation
     * @param string|null $prepend_key
     * @param bool|null $ignore_q
     * @param bool|null $force_or
     * @param bool|null $force_like
     * @return Builder
     */
    public function scopeOfRelationFromRequest(Builder $query, $request, string $relation, ?string $prepend_key = '', ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE): Builder
    {
        if (empty(trim($prepend_key)) === FALSE) {
            $request = granular_search()->extractPrependedKeys($request, $prepend_key, $ignore_q);
        }

        if (empty($request)) {
            return $query;
        }

        $callback = static function (Builder $q) use ($ignore_q, $force_like, $force_or, $request) {
            $q->search($request, !$ignore_q, $ignore_q, $force_or, $force_like);
        };

        if (granular_search()->hasQ($request)) {
            return $query->orWhereHas($relation, $callback);
        }

        return $query->whereHas($relation, $callback);
    }

    /**
     * Query scope for basic single relation filtering.
     *
     * @param Builder $query
     * @param string $relation
     * @param array|string $key
     * @param array|string $value
     * @param bool|null $force_or
     * @return Builder
     */
    public function scopeOfRelation(Builder $query, string $relation, $key, $value, ?bool $force_or = FALSE): Builder
    {
        $this->validateRelation($relation);
        $params = [];
        if (is_array($key)) {
            foreach ($key as $k){
                $params[$k] = $value;
            }
        } else {
            $params = [$key => $value];
        }
        return $query->whereHas($relation, function ($q) use ($force_or, $params) {
            $q->granularSearch($params, NULL, FALSE, $force_or);
        });
    }

    /**
     * @param Builder $query
     * @param Request|array $request
     * @return Builder
     */

    public function scopeSortFromRequest(Builder $query, $request): Builder
    {
        if ($column_or_array = request_or_array_get($request, 'sort')) {
            $column_or_array = Arr::wrap($column_or_array);
            foreach ($column_or_array as $key => $value) {
                $value = strtolower($value);
                if (is_null($value) === FALSE) {
                    if (is_numeric($key)) {
                        $query = $query->sort($value);
                    }
                    else if (preg_match('/asc|desc/', $value)) {
                        $query = $query->sort($key, false !== strpos($value, "desc"));
                    }
                }
            }
        }

        else if($column_or_array = request_or_array_get($request, 'sortBy'))
        {
            $query = $query->sort($column_or_array);
        }

        else if($column_or_array = request_or_array_get($request, 'sortByDesc')){
            $query = $query->sort($column_or_array, TRUE);
        }

        return $query;
    }

    /**
     * @param Builder $query
     * @param string[]|string $column_or_array
     * @param bool $is_descending
     * @param bool $is_nulls_first
     * @return Builder
     */
    public function scopeSort(Builder $query, $column_or_array, ?bool $is_descending = FALSE, ?bool $is_nulls_first = FALSE): Builder
    {
        $column_or_array = Arr::wrap($column_or_array);

        $columns = collect($column_or_array)->intersect(optional(granular_search()->getTable(static::getTableName(), granular_search()->getDatabaseDriver($query)))->keys());

        if (is_null($columns)) {
            return $query;
        }

        // Respect Sequence of Ordering
        foreach ($columns as $col) {
            $query = $query->orderByRaw('CASE WHEN ' . $col . ' IS NULL THEN 0 ELSE 1 END ' . ($is_nulls_first ? 'ASC' : 'DESC'))->orderBy($col, $is_descending ? 'desc': 'asc');
        }

        return $query;
    }

    /***** METHODS *****/

    /**
     * Get table name of the model instance.
     *
     * @return mixed
     */
    public static function getTableName()
    {
        return with(new static)->getTable();
    }

    public static function getDatabaseDriverName()
    {
        return with(new static)->getConnection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Get Prepared Table Keys
     *
     * @return array
     */
    public static function getPreparedTableKeys (): array
    {
        return granular_search()->prepareTableKeys(static::getTableName(), static::getGranularExcludedKeys(), static::getDatabaseDriverName());
    }

    /**
     * Validate if the $relation really exists on the Eloquent model.
     *
     * @param string $relation
     */
    public function validateRelation(string $relation): void
    {
        if(in_array($relation, static::getGranularAllowedRelations(), TRUE) === FALSE){
            throw new RuntimeException($relation . ' is not included in the allowed relations array of ' . static::class);
        }

        if(static::hasGranularRelation($relation) === FALSE){
            throw new RuntimeException('The ' . static::class . ' model does not have such relation: ' . $relation);
        }
    }

    /**
     * Check for the existence of a relation to an Eloquent model.
     *
     * @param string $relation
     * @return bool
     */
    public static function hasGranularRelation(string $relation): bool
    {
        try {
            return is_model_instance((new static)->$relation()->getRelated());
        }
        catch (BadMethodCallException $exception) {
            return FALSE;
        }
    }

    /**
     * Check if searching should be done based on request keys.
     *
     * @param array $request
     * @param bool|null $ignore_q
     * @return bool
     */
    public function shouldBeSearched(array $request, ?bool $ignore_q = FALSE): bool
    {
        $count = count($request);

        if ($count === 0) { // Check if request array is empty
            return FALSE;
        }

        if ($count === 1 && granular_search()->hasQ($request)) { // Check if just a q search and already mentioned
            return granular_search()->isModelMentioned($this) === FALSE && $this->compareRequestKeysToTableStructure($request);
        }

        // Check if own keys exist

        $prepared_table_keys = static::getPreparedTableKeys();
        $self_keys = array_intersect(array_keys($request), $prepared_table_keys);

        if (empty($self_keys) === FALSE && $this->compareRequestKeysToTableStructure($request)) {
            return TRUE;
        }

        // Check if related model keys exist

        foreach (static::getGranularAllowedRelations() as $relation) {
            $this->validateRelation($relation);

            $prepend_key = Str::snake(Str::singular($relation));

            $params = granular_search()->extractPrependedKeys($request, $prepend_key, $ignore_q);

            if (optional($this->$relation())->getRelated()->shouldBeSearched($params, $ignore_q)) {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * @param array|null $request
     * @return bool
     */
    public function compareRequestKeysToTableStructure(array $request = null): bool
    {
        if (empty($request)) {
            return FALSE;
        }

        $has_q = granular_search()->hasQ($request);

        $request = granular_search()->prepareData($request, static::getGranularExcludedKeys(), '', $has_q === FALSE);

        $table_structure = granular_search()->getTable(static::getTableName(), static::getDatabaseDriverName());

        if (is_null($table_structure) || $table_structure->isEmpty()) {
            return FALSE;
        }

        if ($has_q === FALSE) {
            $table_structure = $table_structure->only(array_keys($request));
        }

        foreach ($table_structure as $column => $value) {

            if (is_null($search = Arr::get($request,$column))) {
                if ($has_q) {
                    $search = $request[granular_search()->getQAlias()];
                }
                else {
                    continue;
                }
            }

            if ($value['is_string'] === FALSE && is_numeric($search) === FALSE) {
                continue;
            }

            return TRUE;
        }

        return FALSE;
    }
}
