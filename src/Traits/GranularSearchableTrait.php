<?php


namespace FourelloDevs\GranularSearch\Traits;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Trait GranularSearchableTrait
 * @package FourelloDevs\GranularSearch\Traits
 *
 * @method static Builder granularSearch($request, string $prepend_key, ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
 * @method static Builder search(Builder $query, $request, ?bool $ignore_relationships = FALSE, ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
 * @method static Builder ofRelationsFromRequest($request, ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
 * @method static Builder ofRelationFromRequest($request, string $relation, ?string $prepend_key = '', ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
 * @method static Builder ofRelation(string $relation, $key, $value, bool $force_or = FALSE)
 *
 * @author James Carlo S. Luchavez (carlo.luchavez@fourello.com)
 * @since April 27, 2021
 */
trait GranularSearchableTrait
{
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
     * @param Request|array|string $request
     * @param bool|null $ignore_relationships
     * @param bool|null $ignore_q
     * @param bool|null $force_or
     * @param bool|null $force_like
     * @return mixed
     */
    public function scopeSearch(Builder $query, $request, ?bool $ignore_relationships = FALSE, ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
    {
        Log::info('started');

        granular_search()->setInitialModel($this);

        if(is_request_instance($request)) {
            $request = $request->all();
        }
        else if(is_string($request)) {
            $request = [granular_search()->getQAlias() => $request];
        }
        else if(is_array($request) && Arr::isAssoc($request) === FALSE) {
            $request = [granular_search()->getQAlias() => array_values($request)];
        }

        granular_search()->addToMentionsModels($this);

        Log::info('Relation Tracing @ ' . static::class, granular_search()->getMentionedModels());

        $query = $query->granularSearch($request, '', $ignore_q, $force_or, $force_like);

        if ($ignore_relationships === FALSE) {
            $query = $query->ofRelationsFromRequest($request, $ignore_q, $force_or, $force_like);
        }

        if (granular_search()->isInitialModel($this)) {
            granular_search()->clearMentions();
            granular_search()->clearInitialModel();
            Log::info('ended');
        }

        return $query;
    }

    /**
     * Query scope to filter an Eloquent model using Request parameters.
     *
     * @param Builder $query
     * @param Request|array $request
     * @param string|null $prepend_key
     * @param bool $ignore_q
     * @param bool $force_or
     * @param bool $force_like
     * @return Builder|Model
     */
    public function scopeGranularSearch(Builder $query, $request, ?string $prepend_key = '', ?bool $ignore_q = FALSE, ?bool $force_or = FALSE, ?bool $force_like = FALSE)
    {
        return granular_search()->search($request, $query, static::getTableName(), static::getGranularExcludedKeys(), static::getGranularLikeKeys(), $prepend_key, $ignore_q ?? FALSE, $force_or ?? FALSE, $force_like ?? FALSE);
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

            $related = $this->$relation()->getRelated();

            if (count($params) === 1 && granular_search()->isModelMentioned($related) && granular_search()->hasQ($params)) {
                continue;
            }

            if($related->shoulBeSearched($params, $ignore_q)) {
                $query = $query->ofRelationFromRequest($params, $relation, '', $ignore_q, $force_or, $force_like);
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
        $this->validateRelation($relation);

        $prepend_key = $prepend_key ?? Str::snake(Str::singular($relation));

        $request = granular_search()->extractPrependedKeys($request, $prepend_key, $ignore_q);

        if(empty($request) === FALSE) {
            $callback = static function (Builder $q) use ($ignore_q, $force_like, $force_or, $request) {
                $q->search($request, $ignore_q, $force_or, $force_like, FALSE);
            };

            if (granular_search()->hasQ($request)) {
                return $query->orWhereHas($relation, $callback);
            }

            return $query->whereHas($relation, $callback);
        }

        return $query;
    }

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
    public function scopeOfRelation(Builder $query, string $relation, $key, $value, bool $force_or = FALSE): Builder
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
            $q->granularSearch($params, '', FALSE, $force_or);
        });
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

    /**
     * Get Prepared Table Keys
     *
     * @return array
     */
    public static function getPreparedTableKeys (): array
    {
        return granular_search()->prepareTableKeys(static::getTableName(), static::getGranularExcludedKeys());
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
     * Validate if the $relation really exists on the Eloquent model.
     *
     * @param string $relation
     */
    public function validateRelation(string $relation): void
    {
        if(in_array($relation, static::$granular_allowed_relations, TRUE) === FALSE){
            throw new RuntimeException($relation . ' is not included in the allowed relations array of ' . static::class);
        }

        if(static::hasGranularRelation($relation) === FALSE){
            throw new RuntimeException('The ' . static::class . ' model does not have such relation: ' . $relation);
        }
    }

    /**
     * Check if searching should be done based on request keys.
     *
     * @param array $request
     * @param bool|null $ignore_q
     * @return bool
     */
    public function shoulBeSearched(array $request, ?bool $ignore_q = FALSE): bool
    {
        // Check if own keys exist

        $prepared_table_keys = static::getPreparedTableKeys();
        $self_keys = array_intersect(array_keys($request), $prepared_table_keys);

        if(empty($self_keys) === FALSE){
            return TRUE;
        }

        // Check if related model keys exist

        foreach (static::getGranularAllowedRelations() as $relation) {
            $this->validateRelation($relation);

            $prepend_key = Str::snake(Str::singular($relation));

            $params = granular_search()->extractPrependedKeys($request, $prepend_key, $ignore_q);

            if (count($params) === 1 && granular_search()->hasQ($params) && granular_search()->isModelMentioned($this) === FALSE) {
                return TRUE;
            }

            if (empty($params) === FALSE && $this->$relation()->getRelated()->shoulBeSearched($params, $ignore_q)) {
                return TRUE;
            }
        }

        return FALSE;
    }
}
