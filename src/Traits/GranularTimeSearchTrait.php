<?php

namespace FourelloDevs\GranularSearch\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Trait GranularTimeSearchTrait
 * @package FourelloDevs\GranularSearch\Traits
 *
 * @author James Carlo Luchavez <carlo.luchavez@fourello.com>
 * @since 2021-05-29
 *
 * @method static Builder searchTimeFromRequest($request, string $time_column, string $timezone)
 */
trait GranularTimeSearchTrait
{
    /**
     * Time column to consider for searching.
     *
     * @var string
     */
    protected $time_column = 'created_at';

    /**
     * @var string
     */
    protected $time_zone = 'Asia/Manila';

    /**
     * Date keyword. Search within specified date.
     *
     * @var string
     */
    protected $granular_date_string = 'date';

    /**
     * Date From keyword. Partner with Date To.
     * Search between Date From and Date To.
     *
     * @var string
     */
    protected $granular_date_from_string = 'date_from';

    /**
     * Date To keyword. Partner with Date From.
     * Search between Date From and Date To.
     *
     * @var string
     */
    protected $granular_date_to_string = 'date_to';

    /**
     * DateTime From keyword. Partner with DateTime To.
     * Search between DateTime From and DateTime To.
     *
     * @var string
     */
    protected $granular_datetime_from_string = 'datetime_from';

    /**
     * DateTime To keyword. Partner with DateTime From.
     * Search between DateTime From and DateTime To.
     *
     * @var string
     */
    protected $granular_datetime_to_string = 'datetime_to';

    /**
     * @param Builder $query
     * @param Request|array $request
     * @param string $time_column
     * @param string $time_zone
     * @return Builder
     */
    public function scopeSearchTimeFromRequest(Builder $query, $request, string $time_column, string $time_zone): Builder
    {
        $time_column = request_or_array_get($request, 'time_column') ?? $time_column;

        if ($date = request_or_array_get($request, $this->granular_date_string)) {
            $query = $query->whereDate($time_column, (new Carbon($date, $time_zone))->toDateString());
        }

        else if (request_or_array_has($request, $this->granular_date_from_string)) {
            if (is_request_or_array_filled($request, $this->granular_date_to_string)) {
                $to = (new Carbon(request_or_array_get($request, $this->granular_date_to_string), $time_zone))->endOfDay();
            }
            else {
                $to = now();
            }

            $query = $query->whereBetween($time_column, [
                (new Carbon(request_or_array_get($request, $this->granular_date_from_string), $time_zone))->startOfDay(),
                $to,
            ]);
        }

        else if (request_or_array_has($request, $this->granular_datetime_from_string)) {
            if (request_or_array_has($request, $this->granular_datetime_to_string)) {
                $to = (new Carbon(request_or_array_get($request, $this->granular_datetime_to_string), $time_zone));
            }
            else {
                $to = now();
            }

            $query = $query->whereBetween($time_column, [
                (new Carbon(request_or_array_get($request, $this->granular_datetime_from_string), $time_zone)),
                $to,
            ]);
        }

        return $query;
    }
}
