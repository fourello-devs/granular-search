<?php


namespace FourelloDevs\GranularSearch\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Trait GranularSearchableTrait
 * @package FourelloDevs\GranularSearch\Traits
 *
 * @method Builder ofRelation(string $relation, $key, $value, bool $force_or = false)
 * @method Builder ofRelationFromRequest($request, string $relation, ?string $prepend_key, ?array &$mentioned_models = [])
 * @method Builder ofRelationsFromRequest($request, ?array &$mentioned_models = [])
 * @method Builder granularSearch($request, string $prepend_key, bool $ignore_q = false, bool $force_or = false)
 * @method Builder search($request, ?bool $ignore_q = false, ?array &$mentioned_models = [])
 *
 * @since April 27, 2021
 * @author James Carlo S. Luchavez (carlo.luchavez@fourello.com)
 */
trait GranularSearchableTrait
{
    use GranularSearchTrait;

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

    /**
     * @var string[]
     * @label Array of relation names to include on filter by q
     */
    protected static $granular_q_relations = [];

    /**
     * Query scope for basic single relation filtering.
     *
     * @param Builder $query
     * @param string $relation
     * @param array|string $key
     * @param array|string $value
     * @param bool $force_or
     * @return Builder
     */
    public function scopeOfRelation(Builder $query, string $relation, $key, $value, bool $force_or = false): Builder
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
            $q->granularSearch($params, '', false, $force_or);
        });
    }

    /**
     * Query scope for single relation filtering using Request parameters.
     *
     * @param Builder $query
     * @param Request|array $request
     * @param string $relation
     * @param string|null $prepend_key
     * @param array|null $mentioned_models
     * @return Builder
     */
    public function scopeOfRelationFromRequest(Builder $query, $request, string $relation, ?string $prepend_key, ?array &$mentioned_models = []): Builder
    {
        $this->validateRelation($relation);

        $q_relations = static::requestOrArrayGet($request, 'q_relations', static::$granular_q_relations);

        $prepend_key = $prepend_key ?? Str::snake(Str::singular($relation));

        $request = static::extractPrependedKeys($request, $prepend_key);

        if (static::hasQ($request) && in_array($relation, $q_relations, true)) {
            return $query->orWhereHas($relation, function (Builder $q) use ($mentioned_models, $request) {
                $q->search($request, false, $mentioned_models);
            });
        }
        else if (empty($request) === false) {
            return $query->whereHas($relation, function (Builder $q) use ($mentioned_models, $request) {
                $q->search($request, true, $mentioned_models);
            });
        }

        return $query;
    }

    /**
     * Query scope for multiple relation filtering using Request parameters.
     *
     * @param Builder $query
     * @param Request|array $request
     * @param array|null $mentioned_models
     * @return Builder
     */
    public function scopeOfRelationsFromRequest(Builder $query, $request, ?array &$mentioned_models = []): Builder
    {
        $relations = static::$granular_allowed_relations;

        foreach ($relations as $relation)
        {
            $this->validateRelation($relation);
            // TODO: Add option to set prepend key for each relationship
            $prepend_key = Str::snake(Str::singular($relation));
            $params = static::extractPrependedKeys($request, $prepend_key);
            if(static::hasQ($params) && in_array(get_class($this->$relation()->getRelated()), $mentioned_models, true) && count($params) === 1){
                continue;
            }
            else if(empty($params) === false) {
                $query->ofRelationFromRequest($params, $relation, '', $mentioned_models);
            }
        }

        return $query;
    }

    /**
     * Query scope to filter an Eloquent model using Request parameters.
     *
     * @param Builder $query
     * @param Request|array $request
     * @param string $prepend_key
     * @param bool $ignore_q
     * @param bool $force_or
     * @return Builder|Model
     */
    public function scopeGranularSearch(Builder $query, $request, string $prepend_key, bool $ignore_q = false, bool $force_or = false)
    {
        return $this->getGranularSearch($request, $query, static::getTableName(), static::$granular_excluded_keys, static::$granular_like_keys, $prepend_key, $ignore_q, $force_or);
    }

    /**
     * Query scope to filter an Eloquent model and its related models using Request parameters.
     *
     * @param Builder $query
     * @param Request|array|string $request
     * @param bool $ignore_q
     * @param array|null $mentioned_models
     * @return mixed
     */
    public function scopeSearch(Builder $query, $request, ?bool $ignore_q = false, ?array &$mentioned_models = [])
    {
        if(is_subclass_of($request, Request::class)) {
            $request = $request->all();
        }
        else if(is_string($request)) {
            $request = ['q' => $request];
        }

        $mentioned_models[] = static::class;
        return $query->granularSearch($request, '', $ignore_q)->ofRelationsFromRequest($request, $mentioned_models);
    }

    // Methods

    /**
     * Get table name of the model instance.
     *
     * @return mixed
     */
    public static function getTableName()
    {
        return with(new static)->getTable();
    }

    /**
     * Determine if the class using the trait is a subclass of Eloquent Model.
     *
     * @return bool
     */
    public static function isModel(): bool
    {
        return is_subclass_of(static::class, Model::class);
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
            return is_subclass_of(get_class((new static)->$relation()->getRelated()), Model::class);
        }
        catch (BadMethodCallException $exception) {
            return false;
        }
    }

    /**
     * Validate if the $relation really exists on the Eloquent model.
     *
     * @param string $relation
     */
    public function validateRelation(string $relation): void
    {
        if(in_array($relation, static::$granular_allowed_relations, true) === false){
            throw new RuntimeException('The relation is not included in the allowed relation array: ' . $relation);
        }

        if(static::hasGranularRelation($relation) === false){
            throw new RuntimeException('The model does not have such relation: ' . $relation);
        }
    }
}
