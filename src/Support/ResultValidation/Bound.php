<?php
declare(strict_types=1);
namespace Packvium\Support\ResultValidation;

use Packvium\Validation\ResultIssue;

/**
 * The optimality bound and gap of docs/OPTIMALITY-GAP.md § 6.2, recomputed from the request
 *: `optimality.lower_bound` is the recomputed prefix, the gap agrees with it and the
 * score, no score falls below it, and `optimal` / `proven_optimal` are claimed only by attainment.
 *
 * The reference's own copy of docs/OPTIMALITY-CERTIFICATES.md's formulas and its one refusal
 * predicate, shared with nothing in the PHP engine (`Algorithm\ObjectiveBounds` is what it checks).
 * Exact at any size through `Exact`. O(t log t + c log c) for `t` item types and `c` container
 * types, O(t + c) memory, once per result; a billed objective's key adds O(c b (t + log(c b)))
 * for `b` rate-table brackets per type.
 */
final class Bound
{
    /**
     * Where each covered objective reads `(L0, L1, L2, L3, L4)` in its own score order, a name
     * standing for the one key only that objective ranks by.
     */
    private const ORDERS = [
        'default' => [0, 1, 2, 3, 4],
        'lowest_cost' => [0, 2, 1, 3, 4],
        'maximum_value' => [0, 'value_forgone', 1, 2, 3],
        'open_dimension_height' => [0, 'achieved_height', 1, 2, 3],
        'maximum_volume' => ['unpacked_volume_ppm', 0, 1, 2, 3],
        'shipping_cost' => [0, 'billed_weight', 1, 3, 4],
        'lowest_landed_cost' => [0, 'landed_charge', 1, 3, 4],
    ];
    /** The objectives whose own key is what a carrier bills. */
    private const BILLED = ['shipping_cost', 'lowest_landed_cost'];
    private const MAX_BOUND_SUM = '1000000000000000000000000000000';
    private const MAX_BOUND_VALUE = 9007199254740991;
    private const SCORE_SCALE = 1000000;
    private const GAP_FIELDS = ['gap_key', 'absolute_gap', 'relative_gap'];

    /**
     * The reference's `_check_optimality_bound`. Absence of the field is never an issue.
     *
     * @param mixed $request @param mixed $result @param Ordered<ItemSpec> $items
     * @param Ordered<ContainerSpec> $containers @param list<int|string> $score @param list<ResultIssue> $issues
     */
    public static function check($request, $result, Ordered $items, Ordered $containers, array $score, array &$issues): void
    {
        $fact = Json::at($result, 'optimality');
        [$bound, $withheld] = self::stated($request, Json::at($result, 'status'), $items, $containers);
        $departure = $bound === null ? null : self::departure($score, $bound);
        $below = $departure !== null && $departure[1] < 0;
        if ($below) {
            $key = $departure[0];
            $issues[] = new ResultIssue('score_below_bound', "score[{$key}] is {$score[$key]}, below its lower bound {$bound[$key]}");
        }
        $emitted = Json::get($fact, 'lower_bound');
        if ($emitted !== null && $bound !== null && $departure !== null
            && Exact::compare(Exact::sub($score[$departure[0]], $bound[$departure[0]]), self::MAX_BOUND_VALUE) > 0) {
            $issues[] = new ResultIssue('optimality_gap_mismatch', 'the gap exceeds the portable result ceiling');
        }
        $counts = $emitted === null ? null : self::wellFormed($emitted);
        if ($emitted === null) {
            if (self::reportsGap($fact)) {
                $issues[] = new ResultIssue('optimality_gap_mismatch', 'a gap is reported without lower_bound');
            }
        } elseif ($counts === null) {
            $issues[] = new ResultIssue('optimality_bound_mismatch', 'lower_bound must be a list of 1 to 5 non-negative integers');
        } elseif ($bound === null) {
            $issues[] = new ResultIssue('optimality_bound_mismatch', "lower_bound is reported where no bound is stated: {$withheld}");
        } elseif ($below) {
            $issues[] = new ResultIssue('optimality_bound_mismatch', 'lower_bound is reported for a score below the recomputed bound');
        } else {
            self::checkReported($counts, $score, $bound, $departure, $fact, $issues);
        }
        $attained = $bound !== null && $departure === null;
        self::checkClaims($result, $fact, $attained, $counts !== null && self::same($counts, $score), $issues);
    }

    /**
     * `[L0, L1, L2, L3, L4]`, or null where the one refusal predicate withholds the bound. Public so
     * the shared scene can hold it to the oracle.
     *
     * @param list<array{0:int|string,1:int|string}> $volumes value runs sorted ascending
     * @param list<array{0:int|string,1:int|string}> $weights
     * @param list<array<string,int|string|null>> $containers
     * @return list<int|string>|null
     */
    public static function lowerBound(array $volumes, array $weights, bool $shrinks, array $containers): ?array
    {
        $quantities = self::column($containers, 'quantity');
        $guarded = [
            self::sumRuns($volumes),
            self::sumRuns($weights),
            self::capacityTotal(self::column($containers, 'usable'), $quantities),
            self::capacityTotal(self::column($containers, 'payload'), $quantities),
            self::capacityTotal(self::column($containers, 'max_items'), $quantities),
        ];
        foreach ($guarded as $total) {
            if ($total !== null && Exact::compare($total, self::MAX_BOUND_SUM) > 0) {
                return null;
            }
        }
        $count = self::runCount($volumes);
        $placeable = 0;
        if ($containers !== []) {
            $placeable = $count;
            if (!$shrinks) {
                $placeable = self::min($placeable, self::fit($volumes, $guarded[2]));
            }
            $placeable = self::min($placeable, self::fit($weights, $guarded[3]));
            if ($guarded[4] !== null) {
                $placeable = self::min($placeable, $guarded[4]);
            }
        }
        $opened = self::containerBound($volumes, $weights, $shrinks, $placeable, $containers);
        $bound = [
            Exact::sub($count, $placeable),
            $opened,
            self::costBound($containers, $opened),
            self::unusedBound($volumes, $shrinks, $placeable, $containers, $opened),
            self::heightBound($volumes, $shrinks, $placeable, $containers, $opened),
        ];
        foreach ($bound as $value) {
            if (Exact::compare($value, self::MAX_BOUND_VALUE) > 0) {
                return null;
            }
        }
        return $bound;
    }

    /**
     * `[value, count]` pairs sorted by value: one per item type, however large its quantity.
     *
     * @param list<array{0:int|string,1:int|string}> $pairs @return list<array{0:int|string,1:int|string}>
     */
    public static function runs(array $pairs): array
    {
        \usort($pairs, static function (array $left, array $right): int { return Exact::compare($left[0], $right[0]); });
        return $pairs;
    }

    /**
     * The bound in the objective's score order, or null with the reason none is stated (§ 1.5).
     *
     * @param mixed $request @param mixed $status @param Ordered<ItemSpec> $items @param Ordered<ContainerSpec> $containers
     * @return array{0:list<int|string>|null,1:string}
     */
    private static function stated($request, $status, Ordered $items, Ordered $containers): array
    {
        $name = Json::get(Json::get($request, 'configuration', []), 'objective', 'default');
        if (!\is_string($name) || !\array_key_exists($name, self::ORDERS)) {
            return [null, 'objective ' . PyFormat::repr($name) . ' has no bound'];
        }
        foreach ($containers->values() as $spec) {
            if ($spec->madeToMeasure !== null) {
                return [null, 'a container is made to measure'];
            }
        }
        if (Json::isText($status, 'infeasible')) {
            return [null, "status is 'infeasible'"];
        }
        if (Json::isText($status, 'invalid_result')) {
            return [null, "status is 'invalid_result'"];
        }
        [$volumes, $weights, $values, $shrinks, $types] = self::inputs($items, $containers);
        $billing = \in_array($name, self::BILLED, true)
            ? self::billing(Json::get($request, 'configuration', []), $containers) : null;
        return self::objectiveBound($volumes, $weights, $values, $shrinks, $types, $name, $billing);
    }

    /**
     * What a carrier can bill each type at the least, or null without a divisor:
     * `floor` is `max(tare, dimensional)` -- the dimensional weight of the declared outer size,
     * else the declared floor and height, and 0 for a bag, whose billed package can be smaller
     * -- and `brackets` the rate table as `[bound in weight ticks, charge]`, the minimum charge
     * and the surcharge rounded up already applied. O(c b) for `b` brackets per type.
     *
     * @param mixed $configuration @param Ordered<ContainerSpec> $containers
     * @return list<array{floor:int|string,tare:int|string,brackets:list<array{0:int|string,1:int|string|null}>}>|null
     */
    private static function billing($configuration, Ordered $containers): ?array
    {
        $divisor = Json::get($configuration, 'dimensional_weight_divisor');
        if ($divisor === null) {
            return null;
        }
        $lengthUnit = Json::text(Json::get($configuration, 'dimensional_weight_length_unit', 'in'), 'dimensional_weight_length_unit');
        $weightUnit = Json::text(Json::get($configuration, 'dimensional_weight_weight_unit', 'lb'), 'dimensional_weight_weight_unit');
        $billing = [];
        foreach ($containers->values() as $spec) {
            $size = $spec->outer ?? ($spec->deck === null ? $spec->inner : [$spec->deck[2], $spec->deck[3], $spec->inner[2]]);
            $dimensional = $spec->flatBag ? 0 : ObjectiveCheck::dimensionalWeight($size, Json::int($divisor, 'dimensional_weight_divisor'), $lengthUnit, $weightUnit);
            $billing[] = [
                'floor' => Exact::max($spec->tareWeight, $dimensional),
                'tare' => $spec->tareWeight,
                'brackets' => self::brackets($spec->rateTable),
            ];
        }
        return $billing;
    }

    /** @param mixed $table @return list<array{0:int|string,1:int|string}> */
    private static function brackets($table): array
    {
        if ($table === null) {
            return [];
        }
        $read = static function ($value, string $what) { return Json::pyInt($value, $what); };
        $prices = Json::items(Json::at($table, 'prices_minor'), 'prices_minor');
        $minimum = $read(Json::get($table, 'minimum_charge_minor', 0), 'minimum_charge_minor');
        $permille = $read(Json::get($table, 'fuel_surcharge_permille', 0), 'fuel_surcharge_permille');
        $brackets = [];
        foreach (Json::items(Json::at($table, 'weight_brackets_g'), 'weight_brackets_g') as $index => $grams) {
            if (!\array_key_exists($index, $prices)) {
                break;
            }
            $base = Exact::max($read($prices[$index], 'price'), $minimum);
            $brackets[] = [
                Exact::mul($read($grams, 'bracket'), Units::TICKS_PER_GRAM),
                Exact::add($base, Exact::ceilDiv(Exact::mul($base, $permille), 1000)),
            ];
        }
        return $brackets;
    }

    /**
     * The bound in a covered objective's score order, or null with the reason a ceiling withholds
     * it -- the one predicate's, or the objective's own key's, which withholds that objective
     * alone. Public so the shared scene can hold every objective's vector to the oracle.
     *
     * @param list<array{0:int|string,1:int|string}> $volumes value runs sorted ascending
     * @param list<array{0:int|string,1:int|string}> $weights @param list<array{0:int|string,1:int|string}> $values
     * @param list<array<string,int|string|null>> $containers
     * @param list<array{floor:int|string,tare:int|string,brackets:list<array{0:int|string,1:int|string|null}>}>|null $billing
     *   per type, in `$containers`' order; read by the billed objectives only, null without a divisor
     * @return array{0:list<int|string>|null,1:string}
     */
    public static function objectiveBound(array $volumes, array $weights, array $values, bool $shrinks, array $containers, string $objective, ?array $billing = null): array
    {
        $bound = self::lowerBound($volumes, $weights, $shrinks, $containers);
        if ($bound === null) {
            return [null, 'the request is past a declared ceiling'];
        }
        $billed = null;
        if (\in_array($objective, self::BILLED, true)) {
            $billed = $billing === null ? null : self::billedTerm($objective, $billing, $volumes, $weights, $shrinks, $containers, $bound);
            if ($billed === null) {
                return [null, 'no priceable inventory carries a packing that attains the count bound'];
            }
        }
        $stated = \array_map(static function ($position) use ($bound, $billed, $volumes, $values, $shrinks, $containers) {
            if (\is_int($position)) {
                return $bound[$position];
            }
            return $billed ?? self::objectiveTerm($position, $volumes, $values, $shrinks, $containers, $bound[0]);
        }, self::ORDERS[$objective]);
        foreach ($stated as $value) {
            if (Exact::compare($value, self::MAX_BOUND_VALUE) > 0) {
                return [null, "the {$objective} key is past the portable ceiling"];
            }
        }
        return [$stated, ''];
    }

    /**
     * The key only one objective ranks by, each conditional on `L0` like the five: the
     * least value forgone and the least summed top of a packing leaving `unpacked` behind, and
     * the least unpacked share of requested volume any packing scores. O(t log t + c).
     *
     * @param list<array{0:int|string,1:int|string}> $volumes @param list<array{0:int|string,1:int|string}> $values
     * @param list<array<string,int|string|null>> $containers @param int|string $unpacked @return int|string
     */
    private static function objectiveTerm(string $name, array $volumes, array $values, bool $shrinks, array $containers, $unpacked)
    {
        if ($name === 'value_forgone') {
            return self::smallest($values, $unpacked);
        }
        if ($shrinks) {
            return 0;
        }
        if ($name === 'achieved_height') {
            $placed = Exact::sub(self::runCount($volumes), $unpacked);
            $floors = self::positive(self::column($containers, 'base_area'));
            return Exact::sign($placed) > 0 && $floors !== [] ? Exact::ceilDiv(self::smallest($volumes, $placed), self::largest($floors)) : 0;
        }
        $requested = self::sumRuns($volumes);
        $capacity = self::capacityTotal(self::column($containers, 'usable'), self::column($containers, 'quantity'));
        if (Exact::sign($requested) === 0 || $capacity === null) {
            return 0;
        }
        return Exact::ceilDiv(Exact::mul(Exact::max(0, Exact::sub($requested, $capacity)), self::SCORE_SCALE), $requested);
    }

    /**
     * The billed key of `shipping_cost` or `lowest_landed_cost`, or null when no
     * priceable inventory carries a packing that leaves exactly `L0` behind.
     *
     * Each opened container bills at least its floor and at least its tare plus what it
     * carries. A rate-table bracket is reachable from a floor within it; there a container
     * carries at most the bracket's bound less its tare (and its `max_payload`), so at most the
     * lightest-first count under that, its usable volume and `max_items`. The cheapest
     * reachable charge over the `L1` containers, the cheapest cover of the placed weight and of
     * the placed count each bound the charge; the largest is stated. O(t + c b (t + log(c b)))
     * for `t` runs and `b` brackets per type.
     *
     * @param list<array{floor:int|string,tare:int|string,brackets:list<array{0:int|string,1:int|string|null}>}> $billing
     * @param list<array{0:int|string,1:int|string}> $volumes @param list<array{0:int|string,1:int|string}> $weights
     * @param list<array<string,int|string|null>> $containers @param list<int|string> $bound @return int|string|null
     */
    private static function billedTerm(string $objective, array $billing, array $volumes, array $weights, bool $shrinks, array $containers, array $bound)
    {
        [$unpacked, $opened] = $bound;
        $placed = Exact::sub(self::runCount($volumes), $unpacked);
        if ($objective === 'shipping_cost') {
            if (Exact::sign($opened) === 0) {
                return 0;
            }
            $quantities = self::column($containers, 'quantity');
            $byFloor = self::leastOfInventory(\array_column($billing, 'floor'), $quantities, $opened);
            $byTare = self::leastOfInventory(\array_column($billing, 'tare'), $quantities, $opened);
            if ($byFloor === null || $byTare === null) {
                return null;
            }
            return Exact::max($byFloor, Exact::add($byTare, self::smallest($weights, $placed)));
        }
        if (Exact::sign($opened) === 0 || Exact::sign($placed) === 0) {
            return 0;
        }
        $cheapest = $cheapestQuantities = $byWeight = $byItems = [];
        foreach ($billing as $index => $billed) {
            $container = $containers[$index];
            $charges = [];
            foreach ($billed['brackets'] as [$limit, $charge]) {
                if ($charge === null || Exact::compare($billed['floor'], $limit) > 0) {
                    continue;
                }
                $carried = Exact::sub($limit, $billed['tare']);
                if ($container['payload'] !== null) {
                    $carried = self::min($carried, $container['payload']);
                }
                $count = self::fit($weights, $carried);
                if (!$shrinks) {
                    $count = self::min($count, self::fit($volumes, $container['usable']));
                }
                if ($container['max_items'] !== null) {
                    $count = self::min($count, $container['max_items']);
                }
                $charges[] = $charge;
                $byWeight[] = [$charge, $carried, $container['quantity']];
                $byItems[] = [$charge, $count, $container['quantity']];
            }
            if ($charges !== []) {
                $cheapest[] = \array_reduce($charges, static function ($least, $charge) { return self::min($least, $charge); }, $charges[0]);
                $cheapestQuantities[] = $container['quantity'];
            }
        }
        $terms = [
            self::leastOfInventory($cheapest, $cheapestQuantities, $opened),
            self::fractionalCover($byWeight, self::smallest($weights, $placed)),
            self::fractionalCover($byItems, $placed),
        ];
        return \in_array(null, $terms, true) ? null : self::largest($terms);
    }

    /**
     * The `count` smallest of `{value repeated quantity}`, summed; null if fewer exist.
     *
     * @param list<int|string> $values @param list<int|string|null> $quantities @param int|string $count @return int|string|null
     */
    private static function leastOfInventory(array $values, array $quantities, $count)
    {
        $order = \array_keys($values);
        \usort($order, static function (int $left, int $right) use ($values): int {
            return Exact::compare($values[$left], $values[$right]) ?: $left <=> $right;
        });
        $total = 0;
        foreach ($order as $index) {
            if (Exact::sign($count) <= 0) {
                break;
            }
            $taken = $quantities[$index] === null ? $count : self::min($quantities[$index], $count);
            $total = Exact::add($total, Exact::mul($values[$index], $taken));
            $count = Exact::sub($count, $taken);
        }
        return Exact::sign($count) > 0 ? null : $total;
    }

    /**
     * Least cost covering `need` from `[cost, capacity, quantity]` offers taken fractionally,
     * rounded up; greedy by cost per unit is optimal for one covering constraint. Exact: whole
     * offers sum as integers and the one share taken in part is the only fraction, rounded once.
     *
     * @param list<array{0:int|string,1:int|string,2:int|string|null}> $offers @param int|string $need @return int|string|null
     */
    private static function fractionalCover(array $offers, $need)
    {
        if (Exact::sign($need) <= 0) {
            return 0;
        }
        $ranked = \array_values(\array_filter($offers, static function (array $offer): bool { return Exact::sign($offer[1]) > 0; }));
        $order = \array_keys($ranked);
        \usort($order, static function (int $left, int $right) use ($ranked): int {
            return Exact::compare(Exact::mul($ranked[$left][0], $ranked[$right][1]), Exact::mul($ranked[$right][0], $ranked[$left][1])) ?: $left <=> $right;
        });
        $total = 0;
        foreach ($order as $index) {
            [$cost, $capacity, $quantity] = $ranked[$index];
            if ($quantity === null || Exact::compare(Exact::mul($quantity, $capacity), $need) >= 0) {
                return Exact::add($total, Exact::ceilDiv(Exact::mul($cost, $need), $capacity));
            }
            $total = Exact::add($total, Exact::mul($cost, $quantity));
            $need = Exact::sub($need, Exact::mul($capacity, $quantity));
        }
        return null;
    }

    /**
     * The request as the bound reads it: every instance, fixed ones included, and every type.
     *
     * @param Ordered<ItemSpec> $items @param Ordered<ContainerSpec> $containers @return array{0:list<array{0:int|string,1:int|string}>,1:list<array{0:int|string,1:int|string}>,2:list<array{0:int|string,1:int|string}>,3:bool,4:list<array<string,int|string|null>>}
     */
    private static function inputs(Ordered $items, Ordered $containers): array
    {
        $volumes = [];
        $weights = [];
        $values = [];
        $shrinks = false;
        foreach ($items->values() as $spec) {
            $quantity = Json::int($spec->quantity, 'quantity');
            $volumes[] = [Exact::mul(Exact::mul($spec->dimensions[0], $spec->dimensions[1]), $spec->dimensions[2]), $quantity];
            $weights[] = [$spec->weight, $quantity];
            $values[] = [Json::truthy($spec->value) ? Json::int($spec->value, 'value') : 0, $quantity];
            $shrinks = $shrinks || ($quantity > 0 && ($spec->nestingHeight !== null
                || Json::isText($spec->shapeType, 'convex_hull') || Json::isText($spec->shapeType, 'compressible')));
        }
        $types = [];
        foreach ($containers->values() as $spec) {
            $volume = $spec->volume();
            $scaled = ContainerCheck::ratioPpm($spec->voidFillReserveRatio);
            $reserved = Exact::add(
                Exact::mul(Exact::floorDiv($volume, self::SCORE_SCALE), $scaled),
                Exact::floorDiv(Exact::mul(Exact::mod($volume, self::SCORE_SCALE), $scaled), self::SCORE_SCALE)
            );
            $types[] = [
                'inner_volume' => $volume,
                'base_area' => Exact::mul($spec->inner[0], $spec->inner[1]),
                'height' => $spec->inner[2],
                'usable' => Exact::sub($volume, $reserved),
                'cost_minor' => $spec->costMinor,
                'payload' => $spec->maxPayload,
                'max_items' => $spec->maxItems,
                'quantity' => $spec->quantity,
            ];
        }
        return [self::runs($volumes), self::runs($weights), self::runs($values), $shrinks, $types];
    }

    /** @param list<array<string,int|string|null>> $containers @return list<int|string|null> */
    private static function column(array $containers, string $field): array
    {
        return \array_map(static function (array $container) use ($field) { return $container[$field]; }, $containers);
    }

    /** @param list<array{0:int|string,1:int|string}> $runs @return int|string */
    private static function sumRuns(array $runs)
    {
        $total = 0;
        foreach ($runs as [$value, $count]) {
            $total = Exact::add($total, Exact::mul($value, $count));
        }
        return $total;
    }

    /** @param list<array{0:int|string,1:int|string}> $runs @return int|string */
    private static function runCount(array $runs)
    {
        $total = 0;
        foreach ($runs as [, $count]) {
            $total = Exact::add($total, $count);
        }
        return $total;
    }

    /** @param int|string $left @param int|string $right @return int|string */
    private static function min($left, $right)
    {
        return Exact::compare($left, $right) <= 0 ? $left : $right;
    }

    /** @param list<int|string> $values @return int|string */
    private static function largest(array $values)
    {
        $largest = $values[0];
        foreach ($values as $value) {
            $largest = Exact::max($largest, $value);
        }
        return $largest;
    }

    /**
     * How many of the smallest values sum to at most `capacity`; all of them when unlimited.
     *
     * @param list<array{0:int|string,1:int|string}> $runs @param int|string|null $capacity @return int|string
     */
    private static function fit(array $runs, $capacity)
    {
        if ($capacity === null) {
            return self::runCount($runs);
        }
        if (Exact::sign($capacity) < 0) {
            return 0;
        }
        $taken = 0;
        $used = 0;
        foreach ($runs as [$value, $count]) {
            $room = Exact::sign($value) === 0 ? $count : self::min($count, Exact::floorDiv(Exact::sub($capacity, $used), $value));
            $taken = Exact::add($taken, $room);
            $used = Exact::add($used, Exact::mul($value, $room));
            if (Exact::compare($room, $count) < 0) {
                break;
            }
        }
        return $taken;
    }

    /** The sum of the `count` smallest values. @param list<array{0:int|string,1:int|string}> $runs @param int|string $count @return int|string */
    private static function smallest(array $runs, $count)
    {
        $total = 0;
        foreach ($runs as [$value, $available]) {
            if (Exact::sign($count) <= 0) {
                break;
            }
            $taken = self::min($available, $count);
            $total = Exact::add($total, Exact::mul($value, $taken));
            $count = Exact::sub($count, $taken);
        }
        return $total;
    }

    /**
     * `Σ value × quantity`, or null when unbounded; a type that holds nothing adds 0.
     *
     * @param list<int|string|null> $values @param list<int|string|null> $quantities @return int|string|null
     */
    private static function capacityTotal(array $values, array $quantities)
    {
        $total = 0;
        foreach ($values as $index => $value) {
            $quantity = $quantities[$index];
            if ($value === null) {
                return null;
            }
            if ($quantity === null) {
                if (Exact::sign($value) > 0) {
                    return null;
                }
                continue;
            }
            $total = Exact::add($total, Exact::mul($value, $quantity));
        }
        return $total;
    }

    /**
     * `L1`, given `L0`.
     *
     * @param list<array{0:int|string,1:int|string}> $volumes @param list<array{0:int|string,1:int|string}> $weights
     * @param int|string $placed @param list<array<string,int|string|null>> $containers @return int|string
     */
    private static function containerBound(array $volumes, array $weights, bool $shrinks, $placed, array $containers)
    {
        if (Exact::sign($placed) <= 0 || $containers === []) {
            return 0;
        }
        $bound = 1;
        $largestVolume = self::largest(self::column($containers, 'usable'));
        if (!$shrinks && Exact::sign($largestVolume) > 0) {
            $bound = Exact::max($bound, Exact::ceilDiv(self::smallest($volumes, $placed), $largestVolume));
        }
        $payloads = self::column($containers, 'payload');
        if (!\in_array(null, $payloads, true)) {
            $largestPayload = self::largest($payloads);
            if (Exact::sign($largestPayload) > 0) {
                $bound = Exact::max($bound, Exact::ceilDiv(self::smallest($weights, $placed), $largestPayload));
            }
        }
        $slots = self::column($containers, 'max_items');
        if (!\in_array(null, $slots, true)) {
            $largestSlots = self::largest($slots);
            if (Exact::sign($largestSlots) > 0) {
                $bound = Exact::max($bound, Exact::ceilDiv($placed, $largestSlots));
            }
        }
        return $bound;
    }

    /**
     * `L2`: the `opened` cheapest containers the inventory holds, greedily by cost (stable).
     *
     * @param list<array<string,int|string|null>> $containers @param int|string $opened @return int|string
     */
    private static function costBound(array $containers, $opened)
    {
        $order = \array_keys($containers);
        \usort($order, static function (int $left, int $right) use ($containers): int {
            return Exact::compare($containers[$left]['cost_minor'], $containers[$right]['cost_minor']) ?: $left <=> $right;
        });
        $total = 0;
        foreach ($order as $index) {
            if (Exact::sign($opened) <= 0) {
                break;
            }
            $quantity = $containers[$index]['quantity'];
            $taken = $quantity === null ? $opened : self::min($quantity, $opened);
            $total = Exact::add($total, Exact::mul($containers[$index]['cost_minor'], $taken));
            $opened = Exact::sub($opened, $taken);
        }
        return $total;
    }

    /**
     * `L3`.
     *
     * @param list<array{0:int|string,1:int|string}> $volumes @param int|string $placed
     * @param list<array<string,int|string|null>> $containers @param int|string $opened @return int|string
     */
    private static function unusedBound(array $volumes, bool $shrinks, $placed, array $containers, $opened)
    {
        $inner = self::positive(self::column($containers, 'inner_volume'));
        if (Exact::sign($opened) <= 0 || $shrinks || $inner === []) {
            return 0;
        }
        $smallestInner = $inner[0];
        foreach ($inner as $volume) {
            $smallestInner = self::min($smallestInner, $volume);
        }
        $filled = Exact::ceilDiv(Exact::mul(self::smallest(\array_reverse($volumes), $placed), self::SCORE_SCALE), $smallestInner);
        $unused = Exact::sub(Exact::sub(Exact::mul($opened, self::SCORE_SCALE), $filled), Exact::sub($opened, 1));
        return Exact::max(0, $unused);
    }

    /**
     * `L4`.
     *
     * @param list<array{0:int|string,1:int|string}> $volumes @param int|string $placed
     * @param list<array<string,int|string|null>> $containers @param int|string $opened @return int|string
     */
    private static function heightBound(array $volumes, bool $shrinks, $placed, array $containers, $opened)
    {
        $floors = self::positive(self::column($containers, 'base_area'));
        $heights = self::positive(self::column($containers, 'height'));
        if (Exact::sign($opened) <= 0 || $shrinks || $floors === [] || $heights === []) {
            return 0;
        }
        $required = Exact::ceilDiv(self::smallest($volumes, $placed), self::largest($floors));
        $aggregate = Exact::floorDiv(Exact::mul($required, self::SCORE_SCALE), self::largest($heights));
        return Exact::max(0, Exact::sub($aggregate, Exact::sub($opened, 1)));
    }

    /** @param list<int|string|null> $values @return list<int|string> */
    private static function positive(array $values): array
    {
        $kept = [];
        foreach ($values as $value) {
            if ($value !== null && Exact::sign($value) > 0) {
                $kept[] = $value;
            }
        }
        return $kept;
    }

    /**
     * The first position where `score` leaves `bound`, and whether it is above (1) or below (-1).
     *
     * @param list<int|string> $score @param list<int|string> $bound @return array{0:int,1:int}|null
     */
    private static function departure(array $score, array $bound): ?array
    {
        $shared = \min(\count($score), \count($bound));
        for ($index = 0; $index < $shared; ++$index) {
            $order = Exact::compare($score[$index], $bound[$index]);
            if ($order !== 0) {
                return [$index, $order];
            }
        }
        return null;
    }

    /** `lower_bound` as counts when it is a list of 1 to 5 non-negative integers. @param mixed $emitted @return list<int>|null */
    private static function wellFormed($emitted): ?array
    {
        if (!Json::isList($emitted) || \count($emitted) < 1 || \count($emitted) > 5) {
            return null;
        }
        foreach ($emitted as $value) {
            if (!\is_int($value) || $value < 0) {
                return null;
            }
        }
        return $emitted;
    }

    /** @param mixed $fact */
    private static function reportsGap($fact): bool
    {
        foreach (self::GAP_FIELDS as $name) {
            if (Json::get($fact, $name) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @param list<int|string> $left @param list<int|string> $right */
    private static function same(array $left, array $right): bool
    {
        if (\count($left) !== \count($right)) {
            return false;
        }
        foreach ($left as $index => $value) {
            if (Exact::compare($value, $right[$index]) !== 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param list<int> $emitted @param list<int|string> $score @param list<int|string> $bound
     * @param array{0:int,1:int}|null $departure @param mixed $fact @param list<ResultIssue> $issues
     */
    private static function checkReported(array $emitted, array $score, array $bound, ?array $departure, $fact, array &$issues): void
    {
        $prefix = \array_slice($bound, 0, \count($emitted));
        if (!self::same($emitted, $prefix)) {
            $issues[] = new ResultIssue('optimality_bound_mismatch', 'reported ' . PyFormat::intList($emitted) . ', recomputed ' . PyFormat::intList($prefix));
        }
        $length = \count($bound);
        $expected = ['gap_key' => null, 'absolute_gap' => null, 'relative_gap' => null];
        if ($departure !== null) {
            $key = $departure[0];
            $absolute = Exact::sub($score[$key], $bound[$key]);
            $length = $key + 1;
            $expected = [
                'gap_key' => $key,
                'absolute_gap' => $absolute,
                'relative_gap' => Exact::sign($bound[$key]) === 0 ? null : [$absolute, $bound[$key]],
            ];
        }
        if (\count($emitted) !== $length) {
            $issues[] = new ResultIssue('optimality_gap_mismatch', 'lower_bound has ' . \count($emitted) . " entries, expected {$length}");
        }
        foreach ($expected as $name => $value) {
            if (!self::gapFieldMatches($name, Json::get($fact, $name), $value)) {
                $text = $value === null ? 'absent' : ($name === 'relative_gap' ? "{$value[0]}/{$value[1]}" : (string)$value);
                $issues[] = new ResultIssue('optimality_gap_mismatch', "{$name} should be {$text}");
            }
        }
    }

    /** @param mixed $actual @param int|string|array{0:int|string,1:int|string}|null $expected */
    private static function gapFieldMatches(string $name, $actual, $expected): bool
    {
        if ($expected === null) {
            return $actual === null;
        }
        if ($name !== 'relative_gap') {
            return \is_int($actual) && Exact::compare($actual, $expected) === 0;
        }
        return Json::isDict($actual) && \count($actual) === 2
            && \is_int(Json::get($actual, 'numerator')) && Exact::compare($actual['numerator'], $expected[0]) === 0
            && \is_int(Json::get($actual, 'denominator')) && Exact::compare($actual['denominator'], $expected[1]) === 0;
    }

    /** @param mixed $result @param mixed $fact @param list<ResultIssue> $issues */
    private static function checkClaims($result, $fact, bool $attained, bool $claimsAttainment, array &$issues): void
    {
        $status = Json::at($result, 'status');
        $complete = Json::truthy(Json::at($result, 'complete'));
        if (Json::isText($status, 'optimal') && !($complete && $attained)) {
            $issues[] = new ResultIssue('result_fact_mismatch', "status 'optimal' requires a complete result that attains the recomputed lower bound");
        }
        if (Json::isText($status, 'feasible') && $complete && $claimsAttainment) {
            $issues[] = new ResultIssue('result_fact_mismatch', "status is 'feasible', but the result attains its lower_bound; expected 'optimal'");
        }
        if (Json::isText(Json::get($fact, 'code'), 'proven_optimal') && !$claimsAttainment) {
            $issues[] = new ResultIssue('unproven_optimality_claim', "optimality.code is 'proven_optimal' without an attained lower_bound");
        }
    }
}
