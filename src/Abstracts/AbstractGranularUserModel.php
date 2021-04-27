<?php


namespace FourelloDevs\GranularSearch\Abstracts;

use FourelloDevs\GranularSearch\Traits\GranularSearchableTrait;
use Illuminate\Foundation\Auth\User;

/**
 * Class AbstractGranularUserModel
 * @package FourelloDevs\GranularSearch\Abstracts
 *
 * @note Extend existing Eloquent models that are related to authentication with this Abstract Class to utilize Granular Search algorithm.
 *
 * @since April 27, 2021
 * @author James Carlo S. Luchavez (carlo.luchavez@fourello.com)
 */
abstract class AbstractGranularUserModel extends User
{
    use GranularSearchableTrait;
}
