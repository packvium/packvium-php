<?php
declare(strict_types=1);
namespace Packvium\Constraint;
use InvalidArgumentException;
use Packvium\Domain\AxisAlignedBox;
use Packvium\Domain\Dimensions;
use Packvium\Domain\Placement;
use Packvium\Domain\SweptRegion;
/**
 * Rejects a placement that walls an earlier-stop item away from every door.
 *
 * `RouteOrderConstraint` enforces the vertical half of route order -- nothing due later may
 * rest *above* something due earlier. This is the horizontal half: nothing due later may
 * stand *between* an earlier item and the way out. Both are necessary and neither implies
 * the other; docs/STOP-ACCESSIBILITY.md derives the rule and the post-validator's
 * whole-scene replay remains the sufficient check.
 *
 * Opt-in twice over, and both are load-bearing. It is inert unless doors are stated, because
 * assuming all six walls open would enforce a rule true of no real vehicle and nearly vacuous
 * besides, since a box is almost always free through *some* face. And it is inert unless two
 * distinct stops are in play, which is what keeps a caller who never populates `stopIndex`
 * paying nothing.
 *
 * The doors come from the container and fall back to the configuration.
 * `container.access_directions` is a request field and has to be a per-container one: two
 * doors on one trailer and none on another is the case that makes the rule worth having, and
 * a solve opening several container types would otherwise pick one answer for all of them.
 * The constructor argument stays as the default so callers who drove this through
 * `PackingConfig(accessDirections: ...)` before the field existed keep working unchanged.
 *
 * The blocker set is `{q : s(q) > s(p)}` -- strictly later. Items due at the *same* stop are
 * excluded because the order within a stop is free: whichever is in the way comes off first.
 * Using `>=` would refuse two same-stop pallets standing one behind the other, an ordinary
 * load.
 */
final class StopAccessibilityConstraint implements PlacementConstraint
{
    /** @var list<string> */
    private array $defaultDirections;
    /** @var list<Placement>|null */
    private ?array $placements=null;
    private ?Dimensions $container=null;
    /** @var list<string> */
    private array $directions=[];
    /** @var list<array<string,true>> */
    private array $clear=[];
    /** @var list<int|float> */
    private array $stops=[];
    /** @var list<AxisAlignedBox> */
    private array $boxes=[];
    /** @var list<array<string,array{0:int,1:int,2:int,3:int,4:int,5:int}>> */
    private array $sweeps=[];
    private bool $stopsVary=false;

    /** @param list<string> $directions */
    public function __construct(array $directions=[])
    {
        foreach($directions as $direction){
            if(!in_array($direction,SweptRegion::ALL_DIRECTIONS,true)){
                // `InvalidDirectionError` belongs to `Packvium\Sequence`, which sits above
                // this namespace and must not be imported downwards. The refusal is the
                // same; only the type differs, and it is programmer error either way.
                throw new InvalidArgumentException("unknown movement direction {$direction}");
            }
        }
        // Deduplicated in the canonical order rather than as given: two callers passing the
        // same doors in different orders must search identically.
        $this->defaultDirections=array_values(array_filter(
            SweptRegion::ALL_DIRECTIONS,
            static fn(string $d):bool=>in_array($d,$directions,true),
        ));
    }

    /**
     * A placement's stop as an ordering value, with the absent case as `INF`.
     *
     * An item with no `stopIndex` rides the whole route, so it is never removed and blocks
     * every stop -- exactly what `INF` gives when the blocker test is `s(q) > s(p)`. A
     * present stop stays an `int`: `stopIndex` is bounded to the exactly representable
     * range precisely so comparisons never go through a float.
     */
    private static function stopOf(Placement $p):int|float
    {
        return $p->instance->item->stopIndex??INF;
    }

    /**
     * Per placed box, the doors still open to it against the already-placed boxes.
     *
     * Cached by identity for the same reason `TopLoadConstraint` does it: search evaluates a
     * run of candidates against one state, so a single entry covers the whole run. Keyed on
     * the container too, because a corridor runs to a *wall*: the same boxes have different
     * exits in a longer container, and reusing one answer for the other would silently
     * accept a placement that walls an item in. And on the doors, since made them a
     * property of the container rather than of the solve: two containers of the same size
     * with different doors have different answers for the same boxes, and nothing else in
     * the key separates them.
     *
     * The same entry holds what only depends on those placements: their envelope boxes, the
     * region each finite-stop box sweeps towards each door, and whether their stops differ.
     * A placement is immutable, so none of it can go stale while the key holds.
     *
     * @param list<Placement> $placements
     * @param list<string> $directions
     * @return array{0:list<array<string,true>>,1:list<int|float>}
     */
    private function baseFor(array $placements,Dimensions $container,array $directions):array
    {
        if($this->placements===$placements&&$this->container==$container&&$this->directions===$directions)return [$this->clear,$this->stops];
        $stops=[];$boxes=[];
        foreach($placements as $p){$stops[]=self::stopOf($p);$boxes[]=$p->envelopeBox();}
        $clear=[];$sweeps=[];
        foreach($boxes as $index=>$box){
            $open=[];$swept=[];
            if($stops[$index]===INF){
                // Never unloaded, so it needs no door of its own -- it only ever blocks.
                foreach($directions as $d)$open[$d]=true;
            }else{
                foreach($directions as $d){
                    $sweep=$swept[$d]=SweptRegion::volume($box,$container,$d);
                    $blocked=false;
                    foreach($boxes as $other=>$otherBox){
                        if($other!==$index&&$stops[$other]>$stops[$index]&&SweptRegion::intersects($sweep,$otherBox)){$blocked=true;break;}
                    }
                    if(!$blocked)$open[$d]=true;
                }
            }
            $clear[]=$open;$sweeps[]=$swept;
        }
        $this->placements=$placements;$this->container=$container;$this->directions=$directions;$this->clear=$clear;$this->stops=$stops;
        $this->boxes=$boxes;$this->sweeps=$sweeps;
        $this->stopsVary=count(array_unique($stops,SORT_REGULAR))>1;
        return [$clear,$stops];
    }

    /**
     * The doors this constraint enforces in `$container`; empty means it is inert there.
     *
     * The container's own doors win; this instance's configured list is what a container
     * that states none inherits. A flat bag has no walls, so it inherits none.
     * Public so the candidate sweep asks the instance, as Python's `directions_for`
     * does, rather than the configuration a custom instance may not have been built from.
     *
     * @return list<string>
     */
    public function directionsFor(\Packvium\Domain\Container $container):array
    {
        if($container->isFlatBag())return [];
        return $container->accessDirections!==[]?$container->accessDirections:$this->defaultDirections;
    }

    public function evaluate(ConstraintContext $c):ConstraintResult
    {
        if(!$c->routeSensitive)return ConstraintResult::allow();
        $directions=$this->directionsFor($c->container);
        if($directions===[])return ConstraintResult::allow();
        $candidateStop=$c->item->item->stopIndex??INF;
        $inner=$c->container->innerDimensions;
        [$clear,$stops]=$this->baseFor($c->placements,$inner,$directions);

        // One distinct stop means nothing can be due before anything else, so no corridor
        // can be blocked by a later item. Checked over the candidate too, or the first
        // placement into an empty container would skip a check it should make.
        if(!$this->stopsVary&&($stops===[]||$candidateStop===$stops[0]))return ConstraintResult::allow();

        $candidate=$c->envelopeBox();
        foreach($c->placements as $index=>$placement){
            if(!($candidateStop>$stops[$index]))continue;
            if($clear[$index]===[])continue;
            $stillOpen=false;
            foreach(array_keys($clear[$index]) as $d){
                if(!SweptRegion::intersects($this->sweeps[$index][$d],$candidate)){$stillOpen=true;break;}
            }
            if(!$stillOpen){
                $stop=$stops[$index];
                return ConstraintResult::reject('stop_accessibility_violation',
                    $placement->instance->id().' due at stop '.$stop.' loses its last exit to '.$c->item->id());
            }
        }

        if($candidateStop===INF)return ConstraintResult::allow();
        foreach($directions as $d){
            $sweep=SweptRegion::volume($candidate,$inner,$d);
            $blocked=false;
            foreach($this->boxes as $other=>$otherBox){
                if($stops[$other]>$candidateStop&&SweptRegion::intersects($sweep,$otherBox)){$blocked=true;break;}
            }
            if(!$blocked)return ConstraintResult::allow();
        }
        return ConstraintResult::reject('stop_accessibility_violation',
            $c->item->id().' due at stop '.$candidateStop.' would have no exit');
    }
}
