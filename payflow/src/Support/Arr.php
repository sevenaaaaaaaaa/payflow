<?php

declare(strict_types=1);

namespace PayFlow\Support;

final class Arr
{
    /**
     * 深合并：$override 覆盖 $base。关联数组递归合并，列表/标量直接替换。
     */
    public static function mergeDeep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = self::mergeDeep($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * 点号取值：Arr::get($arr, 'a.b.c', $default)
     */
    public static function get(array $array, string $path, mixed $default = null): mixed
    {
        $cursor = $array;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public static function only(array $array, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                $out[$key] = $array[$key];
            }
        }

        return $out;
    }

    public static function firstKey(array $array): ?string
    {
        foreach (array_keys($array) as $key) {
            return (string) $key;
        }

        return null;
    }
}
