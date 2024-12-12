<?php

namespace PayGate\PayHost\Helper;

class ArrayHelper
{
    /**
     * Flattens a multi-dimensional array into a single level array.
     *
     * @param array $array The array to flatten.
     * @param string $prefix The prefix for array keys.
     *
     * @return array Flattened array.
     */
    public function flattenArray(array $array, $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $new_key = $prefix . (is_string($key) ? $key : '');
            if (is_array($value)) {
                if ($this->isAssoc($value)) {
                    $result = array_merge($result, $this->flattenArray($value, $new_key . '_'));
                } else {
                    foreach ($value as $subArray) {
                        $result = array_merge($result, $this->flattenArray($subArray, $new_key . '_'));
                    }
                }
            } else {
                $result[$new_key] = $value;
            }
        }

        return $result;
    }

    /**
     * Checks if an array is associative.
     *
     * @param array $array The array to check.
     *
     * @return bool True if the array is associative, false otherwise.
     */
    public function isAssoc(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
