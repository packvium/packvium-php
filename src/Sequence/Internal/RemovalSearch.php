<?php
declare(strict_types=1);
namespace Packvium\Sequence\Internal;

use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Dimensions;
use Packvium\Domain\SweptRegion;
use Packvium\Sequence\SequenceGeometry;
use Packvium\Sequence\UnloadingDependencyGraph;

/**
 * The lowest-index-first removal search (A-08), as Rust's `RemovalSearch`.
 *
 * It answers exactly what a rescan from the lowest index after every removal would. A box
 * the early-exit scan finds blocked is only marked; blocked a second time it gets
 * per-direction blocker counts that each removal lowers, so it is never tested against the
 * whole scene again. A box found removable is removed at once, so no state records it. A scene
 * whose first box is usually removable never pays for counts at all.
 *
 * Worst case `O(n^2 * d)`: each box is scanned once with early exit, counted once in
 * `O(n * d)`, then costs `O(d)` per removal -- where rescanning cost `O(n^3 * d)`.
 *
 * @internal
 */
final class RemovalSearch
{
    private const UNTESTED = 0;
    private const BLOCKED = 1;

    /** @var list<AxisAlignedBox> */
    private $boxes;
    /** @var Dimensions */
    private $container;
    /** @var list<string> */
    private $directions;
    /** @var array<int,true> */
    private $present;
    /** @var array<int,int> Present boxes still resting on each box. */
    private $restingOnTop = [];
    /** @var array<int,list<int>> The boxes each box rests on, which lose a rider when it leaves. */
    private $supporters = [];
    /** @var array<int,int|list<array{0:array{0:int,1:int,2:int,3:int,4:int,5:int},1:int}>> */
    private $exits = [];

    /** @param list<AxisAlignedBox> $boxes @param list<string> $directions */
    public function __construct(array $boxes, Dimensions $container, array $directions)
    {
        $this->boxes = $boxes;
        $this->container = $container;
        $this->directions = $directions;
        $this->present = array_fill_keys(array_keys($boxes), true);
        foreach (array_keys($boxes) as $index) {
            $this->supporters[$index] = [];
            $this->exits[$index] = self::UNTESTED;
        }
        foreach (UnloadingDependencyGraph::build($boxes)->dependsOn() as $lower => $riders) {
            $this->restingOnTop[$lower] = count($riders);
            foreach (array_keys($riders) as $rider) {
                $this->supporters[$rider][] = $lower;
            }
        }
    }

    /** @return array<int,true> */
    public function present(): array
    {
        return $this->present;
    }

    /** The first of `$candidates`, in their order, that is present and removable now. @param iterable<int> $candidates */
    public function nextRemovable(iterable $candidates): ?int
    {
        foreach ($candidates as $index) {
            if (isset($this->present[$index]) && $this->removable($index)) {
                return $index;
            }
        }
        return null;
    }

    public function remove(int $removed): void
    {
        unset($this->present[$removed]);
        $box = $this->boxes[$removed];
        foreach ($this->exits as $index => $exits) {
            if (!is_array($exits)) {
                continue;
            }
            foreach ($exits as $direction => $exit) {
                if (SweptRegion::intersects($exit[0], $box)) {
                    $this->exits[$index][$direction][1]--;
                }
            }
        }
        foreach ($this->supporters[$removed] as $lower) {
            $this->restingOnTop[$lower]--;
        }
        $this->supporters[$removed] = [];
    }

    private function removable(int $index): bool
    {
        if (($this->restingOnTop[$index] ?? 0) !== 0) {
            return false;
        }
        $exits = $this->exits[$index];
        if ($exits === self::UNTESTED) {
            $this->exits[$index] = self::BLOCKED;
            return SequenceGeometry::clearDirection(
                $index, $this->boxes[$index], $this->boxes, $this->present, $this->container, $this->directions
            ) !== null;
        }
        if ($exits === self::BLOCKED) {
            $exits = $this->countBlockers($index);
        }
        foreach ($exits as $exit) {
            if ($exit[1] === 0) {
                return true;
            }
        }
        $this->exits[$index] = $exits;
        return false;
    }

    /** @return list<array{0:array{0:int,1:int,2:int,3:int,4:int,5:int},1:int}> */
    private function countBlockers(int $index): array
    {
        $counted = [];
        foreach ($this->directions as $direction) {
            $sweep = SequenceGeometry::sweptVolume($this->boxes[$index], $this->container, $direction);
            $blockers = 0;
            foreach (array_keys($this->present) as $other) {
                if ($other !== $index && SweptRegion::intersects($sweep, $this->boxes[$other])) {
                    $blockers++;
                }
            }
            $counted[] = [$sweep, $blockers];
        }
        return $counted;
    }
}
