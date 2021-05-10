<?php


namespace FourelloDevs\GranularSearch\Macros;

use Closure;
use Illuminate\Support\Arr;

/**
 * Class ArrMacros
 * @package FourelloDevs\GranularSearch\Macros
 *
 * @mixin Arr
 */
class ArrMacros
{
    /**
     * @return Closure
     */
    public function isFilled(): Closure
    {
        return static function (array $haystack, string $needle){
            foreach ($haystack as $key => $value) {
                if($key === $needle) {
                    return empty($value) === FALSE;
                }
            }
            return FALSE;
        };
    }

    /**
     * @return Closure
     */
    public function isNotAssoc(): Closure
    {
        return static function (array $array){
            return Arr::isAssoc($array) === FALSE;
        };
    }
}
