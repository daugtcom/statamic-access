<?php

namespace Daugt\Access\Support;

final class Boolean
{
    private function __construct()
    {
    }

    public static function from(mixed $value): bool
    {
        if (is_bool($value)) return $value;

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return (bool) $value;
    }
}
