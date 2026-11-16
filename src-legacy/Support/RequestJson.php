<?php
declare(strict_types=1);
namespace Packvium\Support;

use stdClass;

/**
 * A request's JSON text, decoded for `ArrayCodec`.
 *
 * `json_decode(..., true)` turns the object `{"0": 1}` into the list `[1]`, so a container's
 * `tag_limits` keyed by consecutive integers from 0 would be refused as "must be an object".
 * Only that field holds caller-chosen keys the rules read, so only it is restored as a
 * `stdClass`, which `JsonValue` already reads as an object. A request without such a map pays
 * for a single decode. An array that is already decoded cannot recover the distinction.
 */
final class RequestJson
{
    private const DEPTH = 512;

    /**
     * @return mixed what `json_decode($json, true)` returns, bar the restored `tag_limits` objects
     * @throws \JsonException
     */
    public static function decode(string $json)
    {
        $data = \json_decode($json, true, self::DEPTH, \JSON_THROW_ON_ERROR);
        $ambiguous = self::ambiguousContainers($data);
        if ($ambiguous === []) {
            return $data;
        }
        $objects = \json_decode($json, false, self::DEPTH, \JSON_THROW_ON_ERROR);
        foreach ($ambiguous as $index) {
            $limits = $objects->containers[$index]->tag_limits ?? null;
            if ($limits instanceof stdClass) {
                $data['containers'][$index]['tag_limits'] = $limits;
            }
        }
        return $data;
    }

    /**
     * Containers whose `tag_limits` decoded as a non-empty list: an array, or an object keyed 0..n-1.
     *
     * @param mixed $data
     * @return list<int>
     */
    private static function ambiguousContainers($data): array
    {
        $containers = \is_array($data) ? ($data['containers'] ?? null) : null;
        if (!\is_array($containers) || !JsonValue::isList($containers)) {
            return [];
        }
        $found = [];
        foreach ($containers as $index => $container) {
            $limits = \is_array($container) ? ($container['tag_limits'] ?? null) : null;
            if (\is_array($limits) && $limits !== [] && JsonValue::isList($limits)) {
                $found[] = $index;
            }
        }
        return $found;
    }
}
