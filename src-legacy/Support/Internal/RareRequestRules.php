<?php
declare(strict_types=1);
namespace Packvium\Support\Internal;

use Packvium\Domain\FlatBag;
use Packvium\Domain\MadeToMeasure;
use Packvium\Unit\Length;
use Packvium\Unit\Weight;
use stdClass;
use Packvium\Support\{BigInt,CanonicalJson,JsonValue,MeasureReading,RequestRules};

/**
 * `RequestRules`' checks for fields an ordinary request does not carry: a made-to-measure
 * machine, a flat bag, a rate table and obstacles. Kept in their own file so PHP compiles
 * them only for a request that has them: without opcache every request compiles what it
 * loads (1.9.0 release frontier). `$call` reaches `RequestRules`' own private helpers.
 *
 * @internal
 */
final class RareRequestRules
{
    /** The two axes `pallet_overhang_limit` requires, in the order they are checked. */
    private const PALLET_OVERHANG_FIELDS = ['length', 'width'];
    /** Every key the request schema's `made_to_measure` declares; it sets `additionalProperties: false`. */
    private const MADE_TO_MEASURE_FIELDS = ['cost_per_square_metre_minor', 'increment', 'minimum', 'outer_allowance',
        'tare_per_square_metre'];

    /**
     * The made-to-measure block's shape top to bottom, then each axis's four rules,
     * then the price ceiling. Run after every other container field, so an existing error
     * always wins. O(1).
     *
     * @param stdClass|array<array-key,mixed> $container
     * @param mixed $raw
     */
    public static function madeToMeasure(\Closure $call, $container, $raw, string $where, string $unit): void
    {
        $field = "{$where}/made_to_measure";
        $machine = $call('objectAt', $raw, $field);
        $call('knownFields', $machine, $field, self::MADE_TO_MEASURE_FIELDS);
        foreach (['minimum', 'increment'] as $name) {
            $call('required', $machine, $name, $field);
        }
        foreach (['minimum', 'increment', 'outer_allowance'] as $name) {
            $sides = JsonValue::get($machine, $name);
            if ($sides !== null) {
                $call('dimensions', $sides, "{$field}/{$name}", $unit, false);
            }
        }
        $call('optionalInteger', $machine, 'cost_per_square_metre_minor', $field, 0);
        $call('optionalMeasure', $machine, 'tare_per_square_metre', $field, Weight::class, RequestRules::WEIGHT_UNIT);
        if (JsonValue::get($container, 'outer_dimensions') !== null) {
            throw $call('refuse', 'not_allowed', "{$where}/outer_dimensions", 'cannot be combined with made_to_measure');
        }
        $inner = $call('sideTicks', JsonValue::get($container, 'inner_dimensions'), $unit);
        $minimum = $call('sideTicks', JsonValue::get($machine, 'minimum'), $unit);
        $increment = $call('sideTicks', JsonValue::get($machine, 'increment'), $unit);
        foreach (RequestRules::SIDES as $axis => $side) {
            if ($minimum[$axis] === 0) {
                throw $call('refuse', 'invalid_value', "{$field}/minimum/{$side}", 'must be positive');
            }
            if ($increment[$axis] === 0) {
                throw $call('refuse', 'invalid_value', "{$field}/increment/{$side}", 'must be positive');
            }
            if ($minimum[$axis] > $inner[$axis]) {
                throw $call('refuse', 'invalid_value', "{$field}/minimum/{$side}", 'exceeds inner_dimensions');
            }
            if (($inner[$axis] - $minimum[$axis]) % $increment[$axis] !== 0) {
                throw $call('refuse', 'invalid_value', "{$field}/increment/{$side}", 'does not divide inner_dimensions minus minimum');
            }
        }
        $price = JsonValue::integer(JsonValue::get($machine, 'cost_per_square_metre_minor') ?? 0);
        $fee = JsonValue::integer(JsonValue::get($container, 'cost_minor') ?? 0);
        if (BigInt::compare(MadeToMeasure::boxCostFor((int) $price, (int) $fee, $inner), MadeToMeasure::MAX_EXACT_INTEGER) > 0) {
            throw $call('refuse', 'invalid_value', "{$field}/cost_per_square_metre_minor", 'prices the largest box above ' . CanonicalJson::MAX_EXACT_MAGNITUDE);
        }
    }

    /**
     * Both axes, each a non-negative length, and a solving frame `(L + 2l, W + 2w, H)`
     * inside the admission ceiling every box is held to. Run after every other container field.
     * O(1).
     *
     * @param stdClass|array<array-key,mixed> $container
     * @param mixed $raw
     */
    public static function palletOverhangLimit(\Closure $call, $container, $raw, string $where, string $unit): void
    {
        $field = "{$where}/pallet_overhang_limit";
        $limit = $call('objectAt', $raw, $field);
        $call('knownFields', $limit, $field, self::PALLET_OVERHANG_FIELDS);
        foreach (self::PALLET_OVERHANG_FIELDS as $axis) {
            $call('measure', $call('required', $limit, $axis, $field), "{$field}/{$axis}", Length::class, $unit);
        }
        [$length, $width, $height] = $call('sideTicks', JsonValue::get($container, 'inner_dimensions'), $unit);
        $overhangX = MeasureReading::ticks(JsonValue::get($limit, 'length'), Length::class, $unit);
        $overhangY = MeasureReading::ticks(JsonValue::get($limit, 'width'), Length::class, $unit);
        RequestRules::box([$length + 2 * $overhangX, $width + 2 * $overhangY, $height], $field);
    }

    /**
     * The bag's own shape top to bottom -- kind, unknown keys, a gusseted bag's
     * gussets and orientation (1.10) -- then every container field a bag refuses, in
     * the order of `BAG_EXCLUSIONS`. O(1).
     *
     * @param stdClass|array<array-key,mixed> $container
     * @param mixed $raw
     */
    public static function deformableBoundary(\Closure $call, $container, $raw, string $where, string $unit): void
    {
        $field = "{$where}/deformable_boundary";
        $boundary = $call('objectAt', $raw, $field);
        $kind = $call('required', $boundary, 'kind', $field);
        if (!\in_array($kind, FlatBag::KINDS, true)) {
            throw $call('refuse', 'not_allowed', "{$field}/kind", 'must be one of ' . CanonicalJson::spelling(FlatBag::KINDS));
        }
        $gusseted = $kind === FlatBag::GUSSETED_BAG;
        $call('knownFields', $boundary, $field, $gusseted ? FlatBag::GUSSETED_BAG_FIELDS : ['kind']);
        if ($gusseted) {
            self::gussetedBag($call, $container, $boundary, $field, $unit);
        }
        foreach (RequestRules::BAG_EXCLUSIONS as $name) {
            if ($call('setBesideABag', $name, JsonValue::get($container, $name))) {
                throw $call('refuse', 'not_allowed', "{$where}/{$name}", 'cannot be combined with deformable_boundary');
            }
        }
    }

    /**
     * The side gusset, then the bottom one, each a positive length within its fold; with
     * neither, the side gusset is reported missing as in 1.10. Then the orientation, and a
     * standing bag's bottom gusset. O(1).
     *
     * @param stdClass|array<array-key,mixed> $container
     * @param stdClass|array<array-key,mixed> $boundary
     */
    private static function gussetedBag(\Closure $call, $container, $boundary, string $field, string $unit): void
    {
        $side = JsonValue::get($boundary, 'side_gusset_depth');
        $bottom = JsonValue::get($boundary, 'bottom_gusset_depth');
        if ($side === null && $bottom === null) {
            $call('required', $boundary, 'side_gusset_depth', $field);
        }
        $inner = $call('sideTicks', JsonValue::get($container, 'inner_dimensions'), $unit);
        if ($side !== null) {
            self::gussetDepth($call, $side, "{$field}/side_gusset_depth", $inner[1], 'exceeds inner_dimensions width', $unit);
        }
        if ($bottom !== null) {
            self::gussetDepth($call, $bottom, "{$field}/bottom_gusset_depth", 2 * $inner[0], 'exceeds twice inner_dimensions length', $unit);
        }
        $orientation = JsonValue::get($boundary, 'orientation');
        if ($orientation === null) {
            return;
        }
        $call('oneOf', $orientation, "{$field}/orientation", FlatBag::ORIENTATIONS);
        if ($orientation === FlatBag::STANDING && $bottom === null) {
            throw $call('refuse', 'missing_field', "{$field}/bottom_gusset_depth", 'is required when orientation is standing');
        }
    }

    /**
     * A length, positive once read in ticks, and no deeper than its fold allows.
     *
     * @param mixed $value
     */
    private static function gussetDepth(\Closure $call, $value, string $field, int $deepest, string $tooDeep, string $unit): void
    {
        $call('measure', $value, $field, Length::class, $unit);
        $depth = MeasureReading::ticks($value, Length::class, $unit);
        if ($depth === 0) {
            throw $call('refuse', 'invalid_value', $field, 'must be positive');
        }
        if ($depth > $deepest) {
            throw $call('refuse', 'invalid_value', $field, $tooDeep);
        }
    }

    /**
     * The table's integers, then its currency against the request's (1.10).
     *
     * @param mixed $raw
     */
    public static function rateTable(\Closure $call, $raw, string $where, ?string $currency): void
    {
        $table = $call('objectAt', $raw, $where);
        foreach (['weight_brackets_g' => 1, 'prices_minor' => 0] as $name => $minimum) {
            $values = JsonValue::get($table, $name);
            if ($values === null) {
                continue;
            }
            foreach ($call('listAt', $values, "{$where}/{$name}") as $index => $value) {
                $call('integer', $value, "{$where}/{$name}/{$index}", $minimum);
            }
        }
        $call('optionalInteger', $table, 'minimum_charge_minor', $where, 0);
        $call('optionalInteger', $table, 'fuel_surcharge_permille', $where, 0);
        $code = JsonValue::get($table, 'currency');
        if ($code !== null) {
            RequestRules::rateTableCurrency($code, "{$where}/currency", $currency);
        }
        $prices = JsonValue::get($table, 'prices_minor');
        RequestRules::rateTableCeiling(
            $prices === null ? [] : \array_map([JsonValue::class, 'integer'], \array_values((array) $prices)),
            JsonValue::integer(JsonValue::get($table, 'minimum_charge_minor') ?? 0),
            JsonValue::integer(JsonValue::get($table, 'fuel_surcharge_permille') ?? 0),
            $where
        );
    }

    /** @param mixed $raw @param array{0:int,1:int,2:int} $inner */
    public static function obstacles(\Closure $call, $raw, string $where, string $unit, array $inner): void
    {
        foreach ($call('listAt', $raw, $where) as $index => $entry) {
            $at = "{$where}/{$index}";
            $obstacle = $call('objectAt', $entry, $at);
            self::placedBox($call, $obstacle, $at, $unit, $inner);
            $extra = JsonValue::get($obstacle, 'additional_boxes');
            if ($extra !== null) {
                foreach ($call('listAt', $extra, "{$at}/additional_boxes") as $position => $box) {
                    $part = "{$at}/additional_boxes/{$position}";
                    self::placedBox($call, $call('objectAt', $box, $part), $part, $unit, $inner);
                }
            }
        }
    }

    /**
     * An optional `origin` and required `dimensions`: an obstacle, or one more box of it
     *, whose measures are read in the request's unit like every other length, and
     * which lies inside the container.
     *
     * @param stdClass|array<array-key,mixed> $box @param array{0:int,1:int,2:int} $inner
     */
    public static function placedBox(\Closure $call, $box, string $at, string $unit, array $inner): void
    {
        $origin = JsonValue::get($box, 'origin');
        if ($origin !== null) {
            $point = $call('objectAt', $origin, "{$at}/origin");
            foreach (['x', 'y', 'z'] as $axis) {
                $call('optionalMeasure', $point, $axis, "{$at}/origin", Length::class, $unit);
            }
        }
        $call('dimensions', $call('required', $box, 'dimensions', $at), "{$at}/dimensions", $unit);
        $sides = $call('sideTicks', JsonValue::get($box, 'dimensions'), $unit);
        foreach (['x', 'y', 'z'] as $index => $axis) {
            $start = $origin === null ? null : JsonValue::get($origin, $axis);
            $offset = $start === null ? 0 : MeasureReading::ticks($start, Length::class, $unit);
            if ($offset + $sides[$index] > $inner[$index]) {
                throw $call('refuse', 'invalid_value', $at, 'lies outside inner_dimensions');
            }
        }
    }

    // ------------------------------------------------------------------------- primitives
}
