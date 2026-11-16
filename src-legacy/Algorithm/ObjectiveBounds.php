<?php
declare(strict_types=1);
namespace Packvium\Algorithm;

use Packvium\Config\PackingConfig;
use Packvium\Constraint\VolumeReserve;
use Packvium\Domain\Container;
use Packvium\Domain\Item;
use Packvium\Domain\ItemInstance;
use Packvium\Domain\ShapeType;
use Packvium\Support\BigInt;
use Packvium\Support\ExactGeometry;
use Packvium\Support\ExactInteger;
use Packvium\Unit\Weight;

/**
 * Lower bounds on the objective vector, and the gap against an incumbent.
 *
 * The mathematics is fixed by docs/OPTIMALITY-CERTIFICATES.md. This is a port of the Python
 * implementation and is held to byte-identical results on the shared corpus, which
 * is what `conformance/scene/objective-bounds.json` exists to prove: Python writes the
 * vectors, this engine recomputes them from the same fixtures, and any divergence fails.
 *
 * Arithmetic. A container's volume in ticks passes a 64-bit integer well before it reaches a
 * realistic size -- a one-metre cube is 4.1e21 cubic ticks -- so every volume here is a
 * decimal string through `BigInt`, exactly as `VolumeReserve` and `Dimensions::volumeString`
 * already are. Weights, capacities and selected costs are also summed as decimal strings;
 * counts and the two parts-per-million keys return to native integers only after the common
 * portable-result ceiling has been checked. This keeps the unsigned `BigInt` away from the
 * one place a negative intermediate appears without letting PHP promote an overflowing sum
 * to a float.
 *
 * Complexity. `O(n log n + c log c)` for `n` instances and `c` container types, the same as
 * the Python port: one sort of the volumes, one of the weights, one of the container types
 * by cost. The volume sort compares decimal strings through `BigInt::compare` rather than
 * PHP's default string order, where "9" would sort after "10". The two billed keys
 * add `O(n + c b log n + c b log(c b))` for `b` rate-table brackets per type.
 *
 * Which instances, and when. The bound is a property of the request: `Packer` computes it
 * once per pack through `forRequest`, over every instance the request declares -- fixed
 * placements included -- before the first solver runs. Refusal is the
 * one predicate of docs/OPTIMALITY-CERTIFICATES.md, the same in every engine.
 *
 * Namespace. `Packvium\Algorithm` is one of the three namespaces `spec/api/php.json`
 * records as internal, and that is deliberate rather than incidental. A lower bound is
 * solver machinery, and nothing serializes it until the 1.8.0 result fields are emitted
 *. Filed first under the public `Packvium\Objective`, it added two classes to a
 * frozen public API for no caller outside the engine, which is a contract this project would
 * then owe forever. Python and JavaScript reached the same place by construction: neither
 * exports its bound.
 */
final class ObjectiveBounds
{
    /** Parts per million, the scale keys 3 and 4 of the objective are carried at. */
    public const PPM = 1000000;

    /**
     * Every sum in the bound path must stay below this, as a decimal string.
     *
     * A string because the ceiling itself is past a 64-bit integer -- which is the point.
     * Declared rather than inherited: this engine's native integers silently become doubles
     * on overflow, JavaScript's stop being exact past 2^53, Python's are unbounded and Rust's
     * `i128` wraps. If each refused at its own limit the four would disagree about which
     * requests are answerable. Keys 3 and 4 multiply a summed volume by `PPM`, so
     * `10^30 * 10^6` sits about 170-fold inside `i128`.
     *
     * Weights, costs and capacities are summed through `BigInt` here for the same reason: a
     * ceiling this engine cannot represent natively is a ceiling it cannot enforce natively.
     */
    public const MAX_BOUND_SUM = '1000000000000000000000000000000';

    /** Largest result integer every binding can carry without changing its value. */
    public const MAX_BOUND_VALUE = '9007199254740991';

    /**
     * The objectives a bound is stated for, and where each reads its bounds: the position in
     * `compute`'s default-order vector of each of the objective's score keys, or the name of
     * the one key only that objective ranks by. `lowest_cost` scores cost ahead of
     * the container count, and the document shows the permuted vector is still a bound.
     * `shipping_cost` and `lowest_landed_cost` add one each for what a carrier bills,
     * read from the configuration's dimensional-weight divisor.
     */
    public const OBJECTIVE_ORDERS = ObjectiveBoundScope::OBJECTIVE_ORDERS;

    /** The objectives whose own key needs the configuration's divisor and units. */
    public const BILLED_OBJECTIVES = ObjectiveBoundScope::BILLED_OBJECTIVES;

    /**
     * The request's bound in `$objective`'s own score order, or null where none is stated.
     *
     * Null for an objective with no bound, for a billed objective whose inventory cannot carry
     * a packing that attains `L0` at a price, for a request with a `made_to_measure`
     * container (D13: the unused-volume key would read the machine maximum while the
     * score reads the fabricated box), and for a request past a declared ceiling. The last
     * withholds the bound and nothing else -- the pack is answered as it was before any
     * bound existed, rather than failing with `BoundOverflowException` (finding F5). An
     * objective's own key past the portable ceiling withholds that objective's bound alone.
     *
     * @param list<ItemInstance> $instances every instance of the request, fixed ones included
     * @param list<Container> $containers
     * @param ?PackingConfig $config the billed objectives' divisor and units; nothing else reads it
     * @return list<int>|null
     */
    public static function forRequest(array $instances, array $containers, string $objective,
        ?PackingConfig $config = null): ?array
    {
        $order = ObjectiveBoundScope::order($containers, $objective);
        if ($order === null) {
            return null;
        }
        try {
            [$normalisedInstances, $normalisedContainers] = self::normalise($instances, $containers);
            if (in_array($objective, self::BILLED_OBJECTIVES, true)) {
                $normalisedContainers = self::withBilling($containers, $normalisedContainers, $config);
            }
            return self::objectiveVector($normalisedInstances, $normalisedContainers, $order);
        } catch (BoundOverflowException | BoundWithheldException $withheld) {
            return null;
        }
    }

    /**
     * Each type's normalised numbers with what a carrier can bill it, at the least.
     *
     * `tare` and `dimensional` are weight ticks: an opened container is billed
     * `max(tare + payload, dimensional)`, so `max(tare, dimensional)` is its floor whatever it
     * carries. A rigid container is billed on its declared outer size, else its declared inner
     * one; a pallet whose load overhangs is billed larger per side, never smaller, and
     * dimensional weight only grows with each side, so the declared size is a floor. A bag is
     * billed on the package its contents make, which can be smaller than declared, so its
     * floor is 0. `brackets` is the rate table as `[upper bound in weight ticks, charge]`, or
     * empty where the objective prices nothing. `O(c b)` for `b` brackets per type.
     *
     * @param list<Container> $containers
     * @param list<array<string,mixed>> $normalised `normalise`'s containers, in the same order
     * @return list<array<string,mixed>>
     */
    private static function withBilling(array $containers, array $normalised, ?PackingConfig $config): array
    {
        if ($config === null || $config->dimensionalWeightDivisor === null) {
            throw new BoundWithheldException('a billed objective needs configuration.dimensional_weight_divisor');
        }
        $landed = $config->objective === 'lowest_landed_cost';
        foreach ($containers as $index => $container) {
            $normalised[$index]['tare'] = $container->tareWeight->ticks;
            $normalised[$index]['dimensional'] = $container->isFlatBag() ? 0
                : ($container->outerDimensions ?? $container->declaredInner())->dimensionalWeight(
                    $config->dimensionalWeightDivisor,
                    $config->dimensionalWeightLengthUnit,
                    $config->dimensionalWeightWeightUnit,
                )->ticks;
            $normalised[$index]['brackets'] = $landed ? self::brackets($container) : [];
        }
        return $normalised;
    }

    /**
     * The rate table as `[bound in weight ticks, charge]`; the charge at a bracket's bound is
     * that bracket's charge, since the bound is the heaviest weight it prices.
     *
     * @return list<array{0:int|string,1:?int}>
     */
    private static function brackets(Container $container): array
    {
        $table = $container->rateTable;
        if ($table === null) {
            return [];
        }
        return array_map(static function (int $bound) use ($table): array {
            return [
                ExactInteger::mul($bound, Weight::TICKS_PER_G),
                $table->chargeMinorOrNull($bound),
            ];
        }, $table->weightBracketsG);
    }

    /**
     * The bound in one objective's score order, from the normalised numbers `fromNormalised`
     * reads plus each instance's `value` -- and, for a billed objective, each container's
     * `tare`, `dimensional` and `brackets` (`withBilling`) -- throwing `BoundOverflowException`
     * where a ceiling withholds it, the one predicate's or the objective's own key's, and
     * `BoundWithheldException` where a billed objective has no priceable answer to bound.
     *
     * @param list<array{volume:string,weight:int,shrinks:bool,value:int}> $instances
     * @param list<array{usable:string,inner:string,base_area:int|string,height:int,payload:?int,max_items:?int,quantity:?int,cost_minor:int,tare?:int|string,dimensional?:int|string,brackets?:list<array{0:int|string,1:?int}>}> $containers
     * @param list<int|string> $order an `OBJECTIVE_ORDERS` entry
     * @return list<int>
     */
    public static function objectiveVector(array $instances, array $containers, array $order): array
    {
        $evaluated = self::evaluate($instances, $containers);
        $vector = $evaluated['vector'];
        return array_map(static function ($position) use ($vector, $instances, $containers, $evaluated): int {
            return is_int($position)
                ? $vector[$position]
                : self::objectiveTerm($position, $instances, $containers, $evaluated);
        }, $order);
    }

    /**
     * The bound on the one key an objective ranks by and the default does not.
     *
     * Each is conditional on the keys before it, as `fromNormalised`'s are: the value and
     * height terms hold for every packing that leaves exactly `L0` instances behind, and the
     * volume term leads its objective and holds for every packing.
     *
     * No term sorts: `evaluate` already paid for the only volume sort (finding F6 doubled that
     * cost once) and hands its ascending volumes and capacity total over, and the value term
     * selects the `L0` smallest values with a bounded heap in `O(n log L0)`. With `O(c)` over
     * the container types the request bound stays `O(n log n + c log c)`; the billed keys add
     * `O(n + c b log n + c b log(c b))` for `b` brackets per type.
     *
     * @param list<array{volume:string,weight:int,shrinks:bool,value:int}> $instances
     * @param list<array<string,mixed>> $containers
     * @param array{vector:array{int,int,int,int,int},volumes:list<string>,weights:list<int>,shrinks:bool,areas:list<int|string>,capacity:?string} $evaluated
     */
    private static function objectiveTerm(string $name, array $instances, array $containers, array $evaluated): int
    {
        [$unpacked, $opened] = $evaluated['vector'];
        if ($name === 'value_forgone') {
            return self::valueForgoneBound(array_column($instances, 'value'), $unpacked);
        }
        if ($name === 'billed_weight') {
            return self::billedWeightBound($evaluated['weights'], $containers, $unpacked, $opened);
        }
        if ($name === 'landed_charge') {
            return self::landedChargeBound($evaluated['weights'], $evaluated['volumes'],
                $evaluated['shrinks'], $containers, $unpacked, $opened);
        }
        if ($name === 'achieved_height') {
            return self::achievedHeightBound($evaluated['volumes'], $evaluated['shrinks'],
                count($instances) - $unpacked, $evaluated['areas']);
        }
        return self::unpackedVolumeBound($evaluated['volumes'], $evaluated['shrinks'],
            $evaluated['capacity']);
    }

    /**
     * `shipping_cost`'s second key, summed billed weight in ticks.
     *
     * A packing that leaves exactly `$unpacked` behind opens at least `$opened` containers and
     * carries at least the lightest `n - unpacked` instances. Each opened container is billed
     * `max(tare + payload, dimensional)`, at least its floor, and a sum of maxima is at least
     * either sum on its own, so the two can be taken over different types. Every term is
     * non-negative, so opening more only adds. `O(n + c log c)`.
     *
     * @param list<int> $weights ascending
     * @param list<array<string,mixed>> $containers
     */
    private static function billedWeightBound(array $weights, array $containers, int $unpacked, int $opened): int
    {
        if ($opened === 0) {
            return 0;
        }
        $quantities = array_map(static function (array $c): ?int {
            return $c['quantity'];
        }, $containers);
        $floors = self::smallestFromInventory(array_map(\Closure::fromCallable([self::class, 'billedFloor']), $containers), $quantities, $opened);
        $tares = self::smallestFromInventory(
            array_map(static function (array $c) {
                return $c['tare'];
            }, $containers), $quantities, $opened);
        if ($floors === null || $tares === null) {
            throw new BoundWithheldException('the inventory holds fewer containers than must be opened');
        }
        $payload = ExactInteger::normalize(self::sumBig(array_map(\Closure::fromCallable('strval'),
            array_slice($weights, 0, count($weights) - $unpacked))));
        return self::guardOutput((string)ExactInteger::max($floors, ExactInteger::add($tares, $payload)),
            'billed weight');
    }

    /**
     * `lowest_landed_cost`'s second key, the summed carrier charge in minor units.
     *
     * Every opened container is billed in exactly one bracket and pays its charge. A container
     * in bracket `[bound, charge]` weighs at most `bound`, so it is reachable only when its
     * floor is within it, carries at most `bound - tare` of payload (and its `max_payload`),
     * and at most as many instances as the lightest-first count under that payload, its usable
     * volume and `max_items`. Three relaxations of that assignment hold, and the largest is the
     * bound: the cheapest reachable charge per container over `$opened` containers; the least
     * cost covering the placed weight; the least cost covering the placed count. Prices need
     * not rise with weight, which is why a bracket is chosen rather than read off the floor. A
     * type no bracket prices offers nothing: a packing that opens one is refused, never
     * returned. `O(n + c b log n + c b log(c b))` for `b` brackets per type: the two
     * prefix sums, a binary search per bracket and one sort of the offers.
     *
     * @param list<int> $weights ascending
     * @param list<string> $volumes ascending
     * @param list<array<string,mixed>> $containers
     */
    private static function landedChargeBound(array $weights, array $volumes, bool $shrinks,
        array $containers, int $unpacked, int $opened): int
    {
        $placed = count($weights) - $unpacked;
        if ($opened === 0 || $placed === 0) {
            return 0;
        }
        $payloadPrefix = self::prefixSums($weights);
        $volumePrefix = $shrinks ? null : self::prefixSums($volumes);
        $cheapest = [];
        $quantities = [];
        $offers = [];
        foreach ($containers as $container) {
            $floor = self::billedFloor($container);
            $reachable = array_values(array_filter($container['brackets'],
                static function (array $bracket) use ($floor): bool {
                    return $bracket[1] !== null
                        && ExactInteger::compare($floor, $bracket[0]) <= 0;
                }));
            if ($reachable === []) {
                continue;
            }
            $cheapest[] = min(array_column($reachable, 1));
            $quantities[] = $container['quantity'];
            // The volume limit is the type's, not the bracket's: asked once per type.
            $roomy = $volumePrefix === null ? null
                : self::fitPrefix($volumePrefix, ExactInteger::normalize($container['usable']));
            foreach ($reachable as [$bound, $charge]) {
                $carried = ExactInteger::sub($bound, $container['tare']);
                if ($container['payload'] !== null && ExactInteger::compare($container['payload'], $carried) < 0) {
                    $carried = $container['payload'];
                }
                $count = self::fitPrefix($payloadPrefix, $carried);
                if ($roomy !== null) {
                    $count = min($count, $roomy);
                }
                if ($container['max_items'] !== null) {
                    $count = min($count, $container['max_items']);
                }
                $offers[] = [$charge, $carried, $count, $container['quantity']];
            }
        }
        $byCount = self::smallestFromInventory($cheapest, $quantities, $opened);
        $byWeight = self::cover(array_map(static function (array $o): array {
            return [$o[0], $o[1], $o[3]];
        }, $offers),
            $payloadPrefix[$placed - 1]);
        $byItems = self::cover(array_map(static function (array $o): array {
            return [$o[0], $o[2], $o[3]];
        }, $offers), $placed);
        if ($byCount === null || $byWeight === null || $byItems === null) {
            throw new BoundWithheldException('no priceable inventory carries a packing that attains L0');
        }
        return self::guardOutput((string)ExactInteger::max($byCount, ExactInteger::max($byWeight, $byItems)),
            'landed charge');
    }

    /**
     * What one container is billed whatever it carries, `max(tare, dimensional)`.
     *
     * @param array<string,mixed> $container
     * @return int|string
     */
    private static function billedFloor(array $container)
    {
        return ExactInteger::max($container['tare'], ExactInteger::normalize($container['dimensional']));
    }

    /**
     * The sum of the `$count` smallest of `{value repeated quantity}`, or null when the
     * inventory holds fewer. Sorting the types and taking `min(quantity, still needed)` of
     * each is `L2`'s rule, `O(c log c)`, without building the multiset.
     *
     * @param list<int|string> $values
     * @param list<?int> $quantities
     * @return int|string|null
     */
    private static function smallestFromInventory(array $values, array $quantities, int $count)
    {
        $order = array_keys($values);
        usort($order, static function (int $a, int $b) use ($values): int {
            return ExactInteger::compare($values[$a], $values[$b]);
        });
        $total = 0;
        $needed = $count;
        foreach ($order as $index) {
            if ($needed === 0) {
                break;
            }
            $quantity = $quantities[$index];
            $taken = $quantity === null ? $needed : min($quantity, $needed);
            $total = ExactInteger::add($total, ExactInteger::mul($values[$index], $taken));
            $needed -= $taken;
        }
        return $needed > 0 ? null : $total;
    }

    /**
     * Running totals of ascending non-negative values, native for as long as they fit.
     *
     * @param list<int|string> $ascending
     * @return list<int|string>
     */
    private static function prefixSums(array $ascending): array
    {
        $totals = [];
        $running = 0;
        foreach ($ascending as $value) {
            $running = ExactInteger::add($running, ExactInteger::normalize($value));
            $totals[] = $running;
        }
        return $totals;
    }

    /**
     * How many of the smallest values sum to at most `$capacity`, by binary search over their
     * prefix sums: `O(log n)` per question.
     *
     * @param list<int|string> $prefix ascending
     * @param int|string $capacity
     */
    private static function fitPrefix(array $prefix, $capacity): int
    {
        $low = 0;
        $high = count($prefix);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if (ExactInteger::compare($prefix[$middle], $capacity) <= 0) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }
        return $low;
    }

    /**
     * The least cost covering `$need` units from `[cost, capacity, quantity]` offers, taken
     * fractionally and rounded up, or null when the offers cannot cover it.
     *
     * A fractional covering problem with one constraint is solved exactly by taking offers in
     * order of cost per unit, compared by cross-multiplication. Only the last offer is taken
     * in part, so the cost is an integer plus one ceiling and no fraction is carried. Offers
     * of equal cost per unit give the same total in either order, so the order needs no
     * tie-break. Each offer is limited by its type's whole inventory, which only weakens the
     * bound. `O(k log k)` for `k` offers.
     *
     * @param list<array{0:int,1:int|string,2:?int}> $offers
     * @param int|string $need
     * @return int|string|null
     */
    private static function cover(array $offers, $need)
    {
        if (ExactInteger::sign($need) <= 0) {
            return 0;
        }
        $ranked = array_values(array_filter($offers,
            static function (array $offer): bool {
                return ExactInteger::sign($offer[1]) > 0;
            }));
        usort($ranked, static function (array $a, array $b): int {
            return ExactInteger::compare(
                ExactInteger::mul($a[0], $b[1]), ExactInteger::mul($b[0], $a[1]));
        });
        $total = 0;
        foreach ($ranked as [$cost, $capacity, $quantity]) {
            if ($quantity === null || ExactInteger::compare(ExactInteger::mul($quantity, $capacity), $need) >= 0) {
                // ceil(cost * need / capacity), as the negated floor of its negation.
                return ExactInteger::add($total, ExactInteger::negate(ExactInteger::floorDiv(
                    ExactInteger::negate(ExactInteger::mul($cost, $need)), $capacity)));
            }
            $total = ExactInteger::add($total, ExactInteger::mul($cost, $quantity));
            $need = ExactInteger::sub($need, ExactInteger::mul($capacity, $quantity));
        }
        return null;
    }

    /**
     * `maximum_value`'s second key: whichever `$unpacked` instances are left behind, their
     * value is at least that of the `$unpacked` least valuable. Values are non-negative, so
     * the sum passes the portable ceiling exactly when some running sum does.
     *
     * @param list<int> $values
     */
    private static function valueForgoneBound(array $values, int $unpacked): int
    {
        return self::guardOutput(self::sumBig(array_map(\Closure::fromCallable('strval'), self::smallestValues($values, $unpacked))),
            'value forgone');
    }

    /**
     * The `$count` smallest of `$values`, in no particular order, without sorting them all: a
     * max-heap holding the smallest seen so far evicts its largest whenever it overfills.
     * `O(n log count)` time, `O(count)` space.
     *
     * @param list<int> $values
     * @return list<int>
     */
    private static function smallestValues(array $values, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        if ($count >= count($values)) {
            return $values;
        }
        $kept = new \SplMaxHeap();
        foreach ($values as $value) {
            $kept->insert($value);
            if ($kept->count() > $count) {
                $kept->extract();
            }
        }
        return iterator_to_array($kept, false);
    }

    /**
     * `open_dimension_height`'s second key, the summed tops in ticks.
     *
     * A container holding volume `v` on floor `A` stands at least `v / A` tall, because its
     * placements are disjoint boxes inside `A x top`. Summed over the opened containers that
     * is at least the placed volume over the widest floor, and the score is an integer. The
     * placed volume is at least the `$placed` smallest. An instance that occupies less than
     * its box breaks the disjoint-boxes step, so the term is dropped.
     *
     * @param list<string> $volumes ascending
     * @param list<int|string> $areas square ticks, exact past a native int
     */
    private static function achievedHeightBound(array $volumes, bool $shrinks, int $placed,
        array $areas): int
    {
        $widest = array_reduce($areas, \Closure::fromCallable([ExactInteger::class, 'max']), 0);
        if ($shrinks || $placed <= 0 || ExactInteger::sign($widest) <= 0) {
            return 0;
        }
        return self::guardOutput(
            self::ceilDivide(self::sumBig(array_slice($volumes, 0, $placed)), (string)$widest),
            'achieved height',
        );
    }

    /**
     * `maximum_volume`'s first key: the volume no inventory can hold, as the score's own
     * rounded-up share of the requested volume.
     *
     * The packed volume is at most the summed usable capacity, so at least the rest is left
     * behind whatever the geometry does. Unlimited inventory (a null capacity) holds
     * everything, and an instance that occupies less than its box stops nominal volumes
     * summing, so both give 0. The share is at most `PPM`, so it needs no output guard.
     *
     * @param list<string> $volumes
     */
    private static function unpackedVolumeBound(array $volumes, bool $shrinks, ?string $capacity): int
    {
        if ($shrinks || $capacity === null) {
            return 0;
        }
        $requested = self::sumBig($volumes);
        if (BigInt::compare($requested, $capacity) <= 0) {
            return 0;
        }
        return (int)self::ceilDivide(
            BigInt::multiply(BigInt::subtract($requested, $capacity), (string)self::PPM),
            $requested,
        );
    }

    /** `ceil(numerator / denominator)` for a non-negative numerator and positive denominator. */
    private static function ceilDivide(string $numerator, string $denominator): string
    {
        if (BigInt::compare($numerator, '0') === 0) {
            return '0';
        }
        return BigInt::divide(BigInt::add(BigInt::subtract($numerator, '1'), $denominator), $denominator);
    }

    /**
     * The `optimality` fields one result states against its request's bound
     * (OPTIMALITY-GAP.md § 1.1), or null where § 1.5 withholds them.
     *
     * `$bound` is in the result's own score order. The prefix runs through the first position
     * the score misses, or is the whole bound when nothing is missed -- the attained case,
     * which carries no gap at all rather than a zero one. A score below its bound means the
     * bound is wrong, and an engine must not publish a claim it has just refuted, so nothing
     * is stated. At most five comparisons, `O(1)`.
     *
     * @param list<int|float|string> $score
     * @param list<int> $bound
     * @return array<string,mixed>|null
     */
    public static function statement(array $score, array $bound): ?array
    {
        foreach ($bound as $index => $least) {
            $achieved = $score[$index];
            if ($achieved < $least) {
                return null;
            }
            if ($achieved > $least) {
                $difference = BigInt::subtract((string)$achieved, (string)$least);
                if (BigInt::compare($difference, self::MAX_BOUND_VALUE) > 0) {
                    return null;
                }
                $absolute = (int)$difference;
                $fields = [
                    'lower_bound' => array_slice($bound, 0, $index + 1),
                    'gap_key' => $index,
                    'absolute_gap' => $absolute,
                ];
                if ($least > 0) {
                    // Unreduced, so the pair reads as (score - bound, bound) by eye.
                    $fields['relative_gap'] = ['numerator' => $absolute, 'denominator' => $least];
                }
                return $fields;
            }
        }
        return ['lower_bound' => $bound];
    }

    /**
     * Refuse a sum past the declared ceiling instead of carrying it further.
     *
     * A structured refusal rather than a number: the alternative is the failure Baldacci et
     * al. document for floating-point bin-packing solvers, a bound that is quietly wrong and
     * carries no signal that it is.
     */
    private static function guard(string $total, string $quantity): string
    {
        if (BigInt::compare($total, self::MAX_BOUND_SUM) > 0) {
            throw new BoundOverflowException(
                "$quantity sums past the " . self::MAX_BOUND_SUM
                . ' ceiling the bound path declares; refusing rather than returning a number'
                . ' no engine can agree on',
            );
        }
        return $total;
    }

    /** A capacity total is guarded only when it is a number: an unbounded one is not. */
    private static function guardFinite(?string $total, string $quantity): ?string
    {
        return $total === null ? null : self::guard($total, $quantity);
    }

    /** Refuse a result before PHP or JavaScript can round it on a binding boundary. */
    private static function guardOutput(string $value, string $quantity): int
    {
        if (BigInt::compare($value, self::MAX_BOUND_VALUE) > 0) {
            throw new BoundOverflowException(
                "$quantity bound is $value, above the " . self::MAX_BOUND_VALUE
                . ' exact portable result ceiling',
            );
        }
        return (int)$value;
    }

    /**
     * Every bound for one request, in the default objective's key order.
     *
     * @param list<ItemInstance> $instances quantity-expanded, free and fixed alike
     * @param list<Container> $containers the types the request permits
     * @return array{int,int,int,int,int}
     */
    public static function compute(array $instances, array $containers): array
    {
        return self::fromNormalised(...self::normalise($instances, $containers));
    }

    /**
     * The numbers the formulas consume, read once from the engine objects.
     *
     * @param list<ItemInstance> $instances
     * @param list<Container> $containers
     * @return array{0:list<array{volume:string,weight:int,shrinks:bool,value:int}>,1:list<array{usable:string,inner:string,base_area:int|string,height:int,payload:?int,max_items:?int,quantity:?int,cost_minor:int}>}
     */
    private static function normalise(array $instances, array $containers): array
    {
        $normalisedInstances = [];
        foreach ($instances as $instance) {
            $normalisedInstances[] = [
                'volume' => $instance->item->dimensions->volumeString(),
                'weight' => $instance->item->weight->ticks,
                'shrinks' => self::occupiesLessThanItsBox($instance->item),
                'value' => $instance->item->value ?? 0,
            ];
        }
        $normalisedContainers = [];
        foreach ($containers as $container) {
            $normalisedContainers[] = [
                'usable' => VolumeReserve::usable($container),
                'inner' => $container->innerDimensions->volumeString(),
                'base_area' => ExactGeometry::baseArea($container->innerDimensions),
                'height' => $container->innerDimensions->height->ticks,
                'payload' => ($nullsafeVariable1 = $container->maxPayload) ? $nullsafeVariable1->ticks : null,
                'max_items' => $container->maxItems,
                'quantity' => $container->quantity,
                'cost_minor' => $container->costMinor,
            ];
        }
        return [$normalisedInstances, $normalisedContainers];
    }

    /**
     * The same bounds, from the numbers the formulas actually consume.
     *
     * This is the shape `conformance/scene/objective-bounds.json` records, so that a port
     * can be held to Python's vectors without first reimplementing a request parser. It
     * takes `shrinks` as given rather than deriving it: whether this engine decides that
     * flag correctly for a `convex_hull` is asserted separately, in this engine's own tests,
     * so a port cannot pass the arithmetic check by copying a flag it never computes.
     *
     * Each instance's `value` is not read here: only `objectiveVector`'s `maximum_value` key
     * consumes it.
     *
     * @param list<array{volume:string,weight:int,shrinks:bool,value?:int}> $instances
     * @param list<array{usable:string,inner:string,base_area:int|string,height:int,payload:?int,max_items:?int,quantity:?int,cost_minor:int}> $containers
     * @return array{int,int,int,int,int}
     */
    public static function fromNormalised(array $instances, array $containers): array
    {
        return self::evaluate($instances, $containers)['vector'];
    }

    /**
     * `fromNormalised`'s vector, with the intermediates the objective-only keys reuse rather
     * than recompute: the ascending volumes and weights, the shrink flag, the floors and the
     * volume capacity total (null when unbounded).
     *
     * @param list<array{volume:string,weight:int,shrinks:bool,value?:int}> $instances
     * @param list<array{usable:string,inner:string,base_area:int|string,height:int,payload:?int,max_items:?int,quantity:?int,cost_minor:int}> $containers
     * @return array{vector:array{int,int,int,int,int},volumes:list<string>,weights:list<int>,shrinks:bool,areas:list<int|string>,capacity:?string}
     */
    private static function evaluate(array $instances, array $containers): array
    {
        $volumes = [];
        $weights = [];
        $shrinks = false;
        foreach ($instances as $instance) {
            $volumes[] = $instance['volume'];
            $weights[] = $instance['weight'];
            $shrinks = $shrinks || $instance['shrinks'];
        }
        self::sortVolumes($volumes);
        sort($weights);
        $count = count($instances);

        $usable = array_map(static function (array $c): string {
            return $c['usable'];
        }, $containers);
        $inner = array_map(static function (array $c): string {
            return $c['inner'];
        }, $containers);
        $areas = array_map(static function (array $c) {
            return ExactInteger::normalize($c['base_area']);
        }, $containers);
        $heights = array_map(static function (array $c): int {
            return $c['height'];
        }, $containers);
        $payloads = array_map(static function (array $c): ?int {
            return $c['payload'];
        }, $containers);
        $slots = array_map(static function (array $c): ?int {
            return $c['max_items'];
        }, $containers);
        $quantities = array_map(static function (array $c): ?int {
            return $c['quantity'];
        }, $containers);
        $costs = array_map(static function (array $c): int {
            return $c['cost_minor'];
        }, $containers);

        // The one refusal predicate, on the way in: the two instance sums and every
        // *finite* capacity total. Every later product is bounded by these times `PPM`, so
        // guarding them here makes the rest safe by derivation. Nothing the formulas never
        // form is guarded -- no single type's capacity or cost, no total an unlimited type
        // makes unbounded -- and the shapes are never read, so the same request is refused
        // in every engine. The five results are held to the portable ceiling on the way out.
        self::guard(self::sumBig($volumes), 'instance volume');
        self::guard(self::sumBig(array_map(\Closure::fromCallable('strval'), $weights)), 'instance weight');
        $capacities = [
            'volume' => self::guardFinite(self::volumeCapacity($usable, $quantities), 'volume capacity'),
            'payload' => self::guardFinite(self::nativeCapacity($payloads, $quantities), 'payload capacity'),
            'slots' => self::guardFinite(self::nativeCapacity($slots, $quantities), 'slot capacity'),
        ];

        $unpacked = self::unpackedBound($volumes, $weights, $shrinks, $count, $capacities);
        $placed = $count - $unpacked;
        $opened = self::containerBound($volumes, $weights, $shrinks, $placed, $containers,
            $usable, $payloads, $slots);
        $cost = self::costBound($costs, $quantities, $opened);
        $unused = self::unusedVolumeBound($volumes, $shrinks, $placed, $inner, $opened);
        $height = self::stackHeightBound($volumes, $shrinks, $placed, $areas, $heights, $opened);
        $vector = [
            self::guardOutput((string)$unpacked, 'unpacked count'),
            self::guardOutput((string)$opened, 'container count'),
            self::guardOutput((string)$cost, 'opening cost'),
            self::guardOutput((string)$unused, 'unused volume'),
            self::guardOutput((string)$height, 'stack height'),
        ];
        return ['vector' => $vector, 'volumes' => $volumes, 'weights' => $weights, 'shrinks' => $shrinks,
            'areas' => $areas, 'capacity' => $capacities['volume']];
    }

    /**
     * Can this item take up less room than its declared dimensions?
     *
     * Three ways, and each breaks the same argument -- that nominal volumes sum to something
     * a solution must carry. A nested item sinks into the one below it; a `convex_hull`
     * occupies its hull and leaves the rest of its bounding box free; a `compressible` item
     * gives up height under load.
     *
     * The design document named only the first until found the omission with a
     * soundness test. Asking the question once, here, is what stops a future fourth shape
     * from reintroducing the same unsoundness silently.
     */
    private static function occupiesLessThanItsBox(Item $item): bool
    {
        if ($item->nestingHeight !== null) {
            return true;
        }
        return $item->shapeType === ShapeType::CONVEX_HULL
            || $item->shapeType === ShapeType::COMPRESSIBLE;
    }

    /**
     * `L0`: remove the geometry entirely and ask what the declared resources alone forbid.
     *
     * @param list<string> $volumes ascending
     * @param list<int> $weights ascending
     * @param array{volume:?string,payload:?string,slots:?string} $capacities null when unbounded
     */
    private static function unpackedBound(array $volumes, array $weights, bool $shrinks,
        int $count, array $capacities): int
    {
        $placeable = $count;
        if (!$shrinks) {
            $placeable = min($placeable, self::fitBig($volumes, $capacities['volume']));
        }
        $placeable = min($placeable, self::fitNative($weights, $capacities['payload']));
        $slotCapacity = $capacities['slots'];
        if ($slotCapacity !== null) {
            $placeable = min($placeable,
                BigInt::compare($slotCapacity, (string)$count) >= 0 ? $count : (int)$slotCapacity);
        }
        return $count - $placeable;
    }

    /**
     * `L1`: grant every container the largest capacity available, which can only understate
     * how many are needed.
     *
     * @param list<string> $volumes ascending
     * @param list<int> $weights ascending
     * @param list<Container> $containers
     * @param list<string> $usable
     * @param list<?int> $payloads
     * @param list<?int> $slots
     */
    private static function containerBound(array $volumes, array $weights, bool $shrinks,
        int $placed, array $containers, array $usable, array $payloads, array $slots): int
    {
        if ($placed <= 0 || $containers === []) {
            return 0;
        }
        $bound = 1;
        if (!$shrinks) {
            $largest = self::maxBig($usable);
            if (BigInt::compare($largest, '0') > 0) {
                $totalVolume = self::sumBig(array_slice($volumes, 0, $placed));
                $needed = BigInt::compare($totalVolume, '0') === 0 ? '0' : BigInt::divide(
                    BigInt::add(BigInt::subtract($totalVolume, '1'), $largest),
                    $largest,
                );
                $bound = max($bound, (int)$needed);
            }
        }
        $largestPayload = self::finiteMax($payloads);
        if ($largestPayload !== null && $largestPayload > 0) {
            $totalWeight = self::sumBig(array_map(\Closure::fromCallable('strval'), array_slice($weights, 0, $placed)));
            $needed = BigInt::compare($totalWeight, '0') === 0 ? '0' : BigInt::divide(
                BigInt::add(BigInt::subtract($totalWeight, '1'), (string)$largestPayload),
                (string)$largestPayload,
            );
            $bound = max($bound, (int)$needed);
        }
        $largestSlots = self::finiteMax($slots);
        if ($largestSlots !== null && $largestSlots > 0) {
            $bound = max($bound, intdiv($placed + $largestSlots - 1, $largestSlots));
        }
        return $bound;
    }

    /**
     * `L2`: at least `L1` containers open, each costing at least the cheapest the inventory
     * still holds.
     *
     * The types are sorted by cost once and taken greedily, `min(quantity, still needed)` of
     * each: the sum of the `L1` cheapest per-unit entries without building that multiset,
     * `O(c log c)` rather than the `O(c * L1 log(c * L1))` of materialising and sorting up to
     * `L1` copies of every type (finding F6). Ties in cost cannot change the sum, so the
     * order among them does not matter.
     *
     * @param list<int> $costs
     * @param list<?int> $quantities
     */
    private static function costBound(array $costs, array $quantities, int $opened): int
    {
        if ($opened <= 0) {
            return 0;
        }
        $cheapestFirst = array_keys($costs);
        usort($cheapestFirst, static function (int $a, int $b) use ($costs): int {
            return $costs[$a] <=> $costs[$b];
        });
        $needed = $opened;
        $selected = '0';
        foreach ($cheapestFirst as $index) {
            if ($needed <= 0) {
                break;
            }
            $quantity = $quantities[$index];
            $taken = $quantity === null ? $needed : min($quantity, $needed);
            $selected = BigInt::add($selected, BigInt::multiply((string)$costs[$index], (string)$taken));
            $needed -= $taken;
        }
        return self::guardOutput($selected, 'opening cost');
    }

    /**
     * `L3`: the fill is largest when every container is the smallest available and holds the
     * greatest volume that could be placed at all.
     *
     * @param list<string> $volumes ascending
     * @param list<string> $inner
     */
    private static function unusedVolumeBound(array $volumes, bool $shrinks, int $placed,
        array $inner, int $opened): int
    {
        if ($shrinks || $opened <= 0 || $inner === []) {
            return 0;
        }
        $smallest = self::minBig($inner);
        if (BigInt::compare($smallest, '0') <= 0) {
            return 0;
        }
        $largestPlaced = $placed > 0
            ? self::sumBig(array_slice($volumes, count($volumes) - $placed))
            : '0';
        // The ratio is a parts-per-million figure once divided, so it returns to a native
        // integer before the only subtraction -- `BigInt` is unsigned and the result here can
        // legitimately fall below zero on the way to being clamped.
        $filled = BigInt::compare($largestPlaced, '0') === 0 ? '0' : BigInt::divide(
            BigInt::add(
                BigInt::subtract(BigInt::multiply($largestPlaced, (string)self::PPM), '1'),
                $smallest,
            ),
            $smallest,
        );
        $maximum = $opened * self::PPM - ($opened - 1);
        if (BigInt::compare($filled, (string)$maximum) >= 0) {
            return 0;
        }
        return self::guardOutput(
            BigInt::subtract((string)$maximum, $filled),
            'unused volume',
        );
    }

    /**
     * `L4`: the volume that must be placed has to stand at least as tall as itself spread
     * across the widest floor available, in the tallest container available.
     *
     * The `L1 - 1` correction is the exact worst-case difference between a sum of floors and
     * the floor of a sum, not a cautionary fudge: the objective floors each container's ratio
     * before summing.
     *
     * @param list<string> $volumes ascending
     * @param list<int|string> $areas square ticks, exact past a native int
     * @param list<int> $heights
     */
    private static function stackHeightBound(array $volumes, bool $shrinks, int $placed,
        array $areas, array $heights, int $opened): int
    {
        if ($shrinks || $opened <= 0 || $areas === []) {
            return 0;
        }
        $widest = array_reduce($areas, \Closure::fromCallable([ExactInteger::class, 'max']), 0);
        $tallest = max($heights);
        if (ExactInteger::sign($widest) <= 0 || $tallest <= 0) {
            return 0;
        }
        $required = '0';
        if ($placed > 0) {
            $total = self::sumBig(array_slice($volumes, 0, $placed));
            if (BigInt::compare($total, '0') > 0) {
                $required = BigInt::divide(
                    BigInt::add(BigInt::subtract($total, '1'), (string)$widest),
                    (string)$widest,
                );
            }
        }
        $ratio = BigInt::divide(
            BigInt::multiply($required, (string)self::PPM),
            (string)$tallest,
        );
        $correction = (string)($opened - 1);
        if (BigInt::compare($ratio, $correction) <= 0) {
            return 0;
        }
        return self::guardOutput(BigInt::subtract($ratio, $correction), 'stack height');
    }

    /**
     * `Sum of usable * quantity`, or null for an unbounded total.
     *
     * A container with no usable volume adds nothing however many of it there are, which is
     * why an unlimited quantity only makes the total unbounded when the type actually holds
     * something.
     *
     * @param list<string> $usable
     * @param list<?int> $quantities
     */
    private static function volumeCapacity(array $usable, array $quantities): ?string
    {
        $total = '0';
        foreach ($usable as $index => $value) {
            $quantity = $quantities[$index];
            if ($quantity === null) {
                if (BigInt::compare($value, '0') > 0) {
                    return null;
                }
                continue;
            }
            $total = BigInt::add($total, BigInt::multiply($value, (string)$quantity));
        }
        return $total;
    }

    /**
     * `Sum of limit * quantity`, or null when any limit or inventory is undeclared.
     *
     * @param list<?int> $values
     * @param list<?int> $quantities
     */
    private static function nativeCapacity(array $values, array $quantities): ?string
    {
        $total = '0';
        foreach ($values as $index => $value) {
            if ($value === null) {
                return null;
            }
            $quantity = $quantities[$index];
            if ($quantity === null) {
                if ($value > 0) {
                    return null;
                }
                continue;
            }
            $total = BigInt::add($total, BigInt::multiply((string)$value, (string)$quantity));
        }
        return $total;
    }

    /**
     * Ascending decimal strings, in numeric order.
     *
     * PHP's own string order is not an option, where "9" would sort after "10". When every
     * volume fits a native integer -- a 10 mm cube is 4.1e15 cubic ticks -- they are sorted
     * as integers in C and written back as the same decimal strings. Measured on 51,200
     * volumes: 17 ms, against 78 ms for the comparator callback and 182 ms for
     * `SORT_NUMERIC`, which re-parses both strings on every comparison. Only a volume wider
     * than a native integer needs the `BigInt` comparator.
     *
     * @param list<string> $volumes
     */
    private static function sortVolumes(array &$volumes): void
    {
        $widest = $volumes === [] ? 0 : max(array_map(\Closure::fromCallable('strlen'), $volumes));
        if ($widest <= 18) {
            $native = array_map(\Closure::fromCallable('intval'), $volumes);
            sort($native);
            $volumes = array_map(\Closure::fromCallable('strval'), $native);
            return;
        }
        usort($volumes, static function (string $a, string $b): int {
            if (strlen($a) <= 18 && strlen($b) <= 18) {
                return (int)$a <=> (int)$b;
            }
            return BigInt::compare($a, $b);
        });
    }

    /**
     * The largest `n` such that the `n` smallest values sum to at most the capacity.
     *
     * The running total is `$flushed + $native`: native additions until one more would
     * overflow, then one `BigInt` addition that folds the chunk in. The capacity test stays
     * native too, against the room the capacity leaves above `$flushed`, so a long run of
     * small values costs native work rather than a string addition and comparison each.
     *
     * @param list<string> $ascending
     */
    private static function fitBig(array $ascending, ?string $capacity): int
    {
        if ($capacity === null) {
            return count($ascending);
        }
        $flushed = '0';
        $native = 0;
        $room = self::nativeRoom($capacity, $flushed);
        foreach ($ascending as $taken => $value) {
            if (strlen($value) > 18 || $native > PHP_INT_MAX - (int)$value) {
                $flushed = BigInt::add(BigInt::add($flushed, (string)$native), $value);
                $native = 0;
                if (BigInt::compare($flushed, $capacity) > 0) {
                    return $taken;
                }
                $room = self::nativeRoom($capacity, $flushed);
                continue;
            }
            $native += (int)$value;
            if ($room !== null && $native > $room) {
                return $taken;
            }
        }
        return count($ascending);
    }

    /**
     * `capacity - flushed` as a native integer, or null when it exceeds every native total.
     *
     * The caller guarantees `flushed <= capacity`.
     */
    private static function nativeRoom(string $capacity, string $flushed): ?int
    {
        $room = BigInt::subtract($capacity, $flushed);
        return BigInt::compare($room, (string)PHP_INT_MAX) <= 0 ? (int)$room : null;
    }

    /** @param list<int> $ascending */
    private static function fitNative(array $ascending, ?string $capacity): int
    {
        return self::fitBig(array_map(\Closure::fromCallable('strval'), $ascending), $capacity);
    }

    /**
     * The largest declared limit, or null if any type declares none.
     *
     * One unlimited type makes the maximum unbounded and every term conditioned on it
     * vacuous, which is why this collapses to null rather than ignoring the gap.
     *
     * @param list<?int> $values
     */
    private static function finiteMax(array $values): ?int
    {
        $best = null;
        foreach ($values as $value) {
            if ($value === null) {
                return null;
            }
            $best = $best === null ? $value : max($best, $value);
        }
        return $best;
    }

    /**
     * Sum decimal strings, staying on native integers for as long as that is exact.
     *
     * The same trade `HullShape::axisFitsNatively` makes, and for the same reason: string
     * arithmetic is exact and slow, so it should be reached for when it is needed rather
     * than always. A single volume fits a 64-bit integer comfortably -- a 10 mm cube is
     * 4.1e15 cubic ticks -- and only the running total leaves that range, at around two
     * thousand such items. So values are added natively in chunks, and a chunk is folded
     * into the `BigInt` total only when one more addend would overflow it: one string
     * addition per ~2^63 of total rather than one per value past the first overflow, which
     * is what the bound computed on every pack pays at scale.
     *
     * The exactness is unchanged: a chunk is folded before the value that would wrap it is
     * added, and a value too wide for `(int)` goes straight to `BigInt`.
     *
     * @param list<string> $values
     */
    private static function sumBig(array $values): string
    {
        $total = '0';
        $native = 0;
        foreach ($values as $value) {
            if (strlen($value) > 18) {
                $total = BigInt::add($total, $value);
                continue;
            }
            $addend = (int)$value;
            if ($native > PHP_INT_MAX - $addend) {
                $total = BigInt::add($total, (string)$native);
                $native = 0;
            }
            $native += $addend;
        }
        return BigInt::add($total, (string)$native);
    }

    /** @param list<string> $values */
    private static function maxBig(array $values): string
    {
        $best = '0';
        foreach ($values as $value) {
            if (BigInt::compare($value, $best) > 0) {
                $best = $value;
            }
        }
        return $best;
    }

    /** @param list<string> $values */
    private static function minBig(array $values): string
    {
        $best = null;
        foreach ($values as $value) {
            if ($best === null || BigInt::compare($value, $best) < 0) {
                $best = $value;
            }
        }
        return $best ?? '0';
    }
}
