<?php


namespace FourelloDevs\GranularSearch\Abstracts;


use FourelloDevs\GranularSearch\Traits\GranularSearchableTrait;
use Illuminate\Database\Eloquent\Model;

/**
 * Class AbstractGranularModel
 * @package FourelloDevs\GranularSearch\Abstracts
 *
 * @note Extend existing Eloquent models that are NOT related to authentication with this Abstract Class to utilize Granular Search algorithm.
 *
 * @since April 27, 2021
 * @author James Carlo S. Luchavez (carlo.luchavez@fourello.com)
 */
abstract class AbstractGranularModel extends Model
{
    use GranularSearchableTrait;
}
