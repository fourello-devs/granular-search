<?php


namespace FourelloDevs\GranularSearch\Macros;

/**
 * Class ArrMacros
 * @package FourelloDevs\GranularSearch\Macros
 *
 * @mixin Arr
 */
class ArrMacros
{
    public function isFilled(){
        return function (array $haystack, string $needle){
            foreach ($haystack as $key => $value) {
                if($key === $needle) {
                    return empty($value) === false;
                }
            }
            return false;
        };
    }
}
