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
        [$ambiguousContainers, $ambiguousItems] = self::ambiguousPaths($data);
        if ($ambiguousContainers === [] && $ambiguousItems === []) {
            return $data;
        }
        $objects = \json_decode($json, false, self::DEPTH, \JSON_THROW_ON_ERROR);
        foreach ($ambiguousContainers as $index) {
            $limits = $objects->containers[$index]->tag_limits ?? null;
            if ($limits instanceof stdClass) {
                // An empty `{}` already decodes to the empty map `[]`; only keyed-from-0 objects need keeping.
                if ($data['containers'][$index]['tag_limits'] !== []) {
                    $data['containers'][$index]['tag_limits'] = $limits;
                }
            } elseif (\is_array($limits) && $limits === []) {
                // `tag_limits: []` in JSON is a list, not `{}`; mark it so `objectAt` refuses it.
                $data['containers'][$index]['tag_limits'] = [0 => null];
            }
        }
        foreach ($ambiguousItems as $index) {
            $vertices = $objects->items[$index]->hull_vertices ?? null;
            if ($vertices instanceof stdClass) {
                $data['items'][$index]['hull_vertices'] = $vertices;
            }
        }
        return $data;
    }

    /**
     * Containers whose `tag_limits` decoded as an empty or non-empty list, and items whose
     * `hull_vertices` decoded as a list (which could be an object `{"0": ...}`).
     *
     * @param mixed $data
     * @return array{0:list<int>,1:list<int>}
     */
    private static function ambiguousPaths($data): array
    {
        if (!\is_array($data)) {
            return [[], []];
        }
        $containerIndices = [];
        $containers = $data['containers'] ?? null;
        if (\is_array($containers) && JsonValue::isList($containers)) {
            foreach ($containers as $index => $container) {
                $limits = \is_array($container) ? ($container['tag_limits'] ?? null) : null;
                if (\is_array($limits) && JsonValue::isList($limits)) {
                    $containerIndices[] = $index;
                }
            }
        }
        $itemIndices = [];
        $items = $data['items'] ?? null;
        if (\is_array($items) && JsonValue::isList($items)) {
            foreach ($items as $index => $item) {
                $vertices = \is_array($item) ? ($item['hull_vertices'] ?? null) : null;
                if (\is_array($vertices) && JsonValue::isList($vertices)) {
                    $itemIndices[] = $index;
                }
            }
        }
        return [$containerIndices, $itemIndices];
    }
}
