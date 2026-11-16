<?php
declare(strict_types=1);
namespace Packvium\Domain;
use Packvium\Unit\Length;
/**
 * The flat-bag girth rule (docs/DEFORMABLE-CONTAINERS.md "1.6 readiness and contract").
 *
 * A `flat_bag` container is two sheets of usable flat size L x W filled to at most thickness
 * H, its inner dimensions. With a, b and c the largest envelope x2, y2 and z2 over its
 * placements, measured from the container origin, a packing is admitted only when the ordinary
 * boundary holds and a + c <= L and b + c <= W: a loop of film round the package's
 * cross-section is at least as long as that section's perimeter. Integer additions and
 * comparisons, so every check here is O(1) and needs no wider type than a coordinate.
 *
 * The extents only grow as boxes are added, so a verdict reached for a candidate never flips
 * when more items follow, and any subset of an admitted set is admitted.
 *
 * A `gusseted_bag` (1.10) has side gussets of depth G folded into each long edge: the tube
 * round the package's y-z section is 2(W + G) long instead of 2W, so the width sum becomes
 * b + c <= W + G, and G = 0 is the flat bag. Every method here takes the gusset depths in
 * ticks, 0 where none is declared.
 *
 * A bottom gusset (1.11) is one panel of depth K folded into the closed end: the loop
 * along the bag runs front, bottom, back, 2L + K, so the length sum becomes 2(a + c) <= 2L + K,
 * doubled to stay in integers. A gusseted bag with a bottom gusset may stand on it
 * (`orientation: standing`): the solving frame is then W x H x L, the floor is the opened
 * bottom, and the same two loops go round other axes -- a + b <= W + G across, 2(b + c) <= 2L + K
 * along. `Girth` holds the two loops for either frame.
 */
final class FlatBag
{
    public const GUSSETED_BAG='gusseted_bag';
    public const KINDS=['flat_bag',self::GUSSETED_BAG];
    public const FLAT='flat';
    public const STANDING='standing';
    public const ORIENTATIONS=[self::FLAT,self::STANDING];
    /** The keys a gusseted bag's boundary may carry (1.10 side gusset; 1.11 bottom gusset and orientation). */
    public const GUSSETED_BAG_FIELDS=['bottom_gusset_depth','kind','orientation','side_gusset_depth'];

    /**
     * Whether a package of extents (a, b, c) passes both girth sums of a bag lying flat in
     * `$inner`; the frame is the caller's.
     */
    public static function girthAdmits(Dimensions $inner,int $a,int $b,int $c,int $gusset=0,int $bottomGusset=0):bool
    {
        return self::girthFor($inner->length->ticks,$inner->width->ticks,$gusset,$bottomGusset,false)->admits([$a,$b,$c]);
    }

    /**
     * The loops of a bag declared `$length x $width` with the two gusset depths, 0 where none is
     * declared, in its flat or standing frame. O(1).
     */
    public static function girthFor(int $length,int $width,int $sideGusset,int $bottomGusset,bool $standing):Girth
    {
        if($standing)return new Girth([1,2],2*$length+$bottomGusset,[0,1],$width+$sideGusset);
        return new Girth([0,2],2*$length+$bottomGusset,[1,2],$width+$sideGusset);
    }

    /** The W x H x L frame a bag declared L x W x H stands in. */
    public static function standingDimensions(Dimensions $declared):Dimensions
    {
        return new Dimensions($declared->width,$declared->height,$declared->length);
    }

    /**
     * Whether one box of these dimensions, alone at the origin of the frame `$inner`, fits a bag:
     * against its `Girth`, or lying flat with a side gusset of `$gusset` ticks. The parameter keeps
     * its 1.10 name, which a named argument may spell.
     */
    public static function fitsAlone(Dimensions $inner,Dimensions $box,int|Girth $gusset=0):bool
    {
        $girth=$gusset instanceof Girth?$gusset:self::girthFor($inner->length->ticks,$inner->width->ticks,$gusset,0,false);
        return $box->fitsInside($inner)&&$girth->admits([$box->length->ticks,$box->width->ticks,$box->height->ticks]);
    }

    /**
     * `[max x2, max y2, max z2]` over the envelopes, `[0, 0, 0]` for none. O(p).
     *
     * @param list<Placement> $placements @return array{0:int,1:int,2:int}
     */
    public static function extentOf(array $placements):array
    {
        $a=$b=$c=0;
        foreach($placements as $p){$e=$p->envelopeBox();$a=max($a,$e->x2());$b=max($b,$e->y2());$c=max($c,$e->z2());}
        return [$a,$b,$c];
    }

    /** The achieved extent as dimensions; a packed bag always holds at least one box. @param array{0:int,1:int,2:int} $extent */
    public static function asDimensions(array $extent):Dimensions
    {
        return new Dimensions(new Length($extent[0]),new Length($extent[1]),new Length($extent[2]));
    }
}
