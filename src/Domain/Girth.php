<?php
declare(strict_types=1);
namespace Packvium\Domain;
/**
 * The two loops of film round a bag's package, as axis pairs of the solving frame.
 *
 * `along` is the loop down the length (2L + K, compared against the doubled sum of its two
 * extents), `across` the loop round the width (W + G, against their plain sum). Lying flat
 * they are (x, z) and (y, z); standing, (y, z) and (x, y). Built by `FlatBag::girthFor`.
 */
final readonly class Girth
{
    /** @param array{0:int,1:int} $along @param array{0:int,1:int} $across */
    public function __construct(public array $along,public int $alongLimit,public array $across,public int $acrossLimit){}

    /**
     * Whether a package of extents (a, b, c) passes both loops. The rigid boundary is the
     * caller's own check and stays where it already is; only the two sums are new. O(1).
     *
     * @param array{0:int,1:int,2:int} $extent
     */
    public function admits(array $extent):bool
    {
        return 2*($extent[$this->along[0]]+$extent[$this->along[1]])<=$this->alongLimit
            &&$extent[$this->across[0]]+$extent[$this->across[1]]<=$this->acrossLimit;
    }
}
