<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Domain\{Container, Dimensions, FlatBag, Girth, PalletOverhang};
use Packvium\Serialization\{ArrayCodec, InvalidRequestException};
use Packvium\Unit\Length;
use Packvium\Validation\FixedPlacementException;

use function Packvium\Validation\validate;

/**
 * 1.11: bottom gussets, lying flat or standing, admitted by the two loops of film.
 * Mirrors `packvium-python/tests/test_bottom_gusset.py`; its oracle comparison runs against the
 * Python-only `scripts/deformable_boundary_model.py`, so here the loops are checked against the
 * rule's own algebra from docs/DEFORMABLE-CONTAINERS.md instead.
 */
final class BottomGussetTest extends TestCase
{
    private const ROD = ['id' => 'rod', 'allowed_rotations' => ['LWH'], 'dimensions' => ['length' => '300', 'width' => '100', 'height' => '20']];
    private const BOTTLE = ['id' => 'bottle', 'allowed_rotations' => ['LWH'], 'dimensions' => ['length' => '50', 'width' => '50', 'height' => '150']];

    public static function testTheLoopsAreTheRuleOfEitherFrame(): void
    {
        // A fixed linear walk, not a random one: determinism is the suite's own rule too.
        for ($seed = 0; $seed < 60; ++$seed) {
            $length = 2 + ($seed * 7) % 39;
            $width = 2 + ($seed * 11) % 39;
            $side = ($seed * 5) % ($width + 1);
            $bottom = 1 + ($seed * 13) % (2 * $length);
            $standing = $seed % 2 === 1;
            $girth = FlatBag::girthFor($length, $width, $side, $bottom, $standing);
            for ($step = 0; $step < 80; ++$step) {
                $extent = [($seed + $step * 3) % 41, ($seed * 2 + $step * 5) % 41, ($seed * 3 + $step * 7) % 41];
                [$a, $b, $c] = $extent;
                $expected = $standing
                    ? $a + $b <= $width + $side && 2 * ($b + $c) <= 2 * $length + $bottom
                    : 2 * ($a + $c) <= 2 * $length + $bottom && $b + $c <= $width + $side;
                self::assertSame($expected, $girth->admits($extent), "seed {$seed}, extent " . implode(',', $extent));
            }
        }
    }

    public static function testABottomGussetAdmitsARodTheFlatLengthRefuses(): void
    {
        // A 300 mm rod 20 mm thick fills the flat length, so a + c = 320 > 300 lying flat; a
        // 40 mm bottom gusset makes the loop 2L + K = 640 = 2(a + c), and equality fits.
        $flat = ArrayCodec::pack(self::request(['kind' => 'gusseted_bag', 'side_gusset_depth' => '10'], self::ROD, 1));
        self::assertSame('no_compatible_container_dimensions', $flat['unpacked_items'][0]['reason']);
        $block = ArrayCodec::pack(self::request(['kind' => 'gusseted_bag', 'bottom_gusset_depth' => '40'], self::ROD, 1));
        self::assertSame([], $block['unpacked_items']);
        $past = ArrayCodec::pack(self::request(['kind' => 'gusseted_bag', 'bottom_gusset_depth' => ['value' => (string) (self::ticks(40) - 1), 'unit' => 'ticks']], self::ROD, 1));
        self::assertSame('no_compatible_container_dimensions', $past['unpacked_items'][0]['reason']);
    }

    public static function testAStandingPouchPacksInItsTurnedFrameAndTheValidatorAgrees(): void
    {
        // Standing, the 300 x 150 x 100 pouch is a 150 x 100 x 300 frame. Two 50 x 50 x 150
        // bottles stand side by side, here front to back (a + b = 150 = W + G, b <= H); a third
        // beside them breaks the loop round the section and one on top breaks the loop down the
        // bag, 2(b + c) = 700 > 660.
        $standing = self::request(['kind' => 'gusseted_bag', 'bottom_gusset_depth' => '60', 'orientation' => 'standing'], self::BOTTLE, 3);
        $result = ArrayCodec::pack($standing);
        $packed = $result['containers'][0];
        self::assertCount(2, $packed['placements']);
        self::assertCount(1, $result['unpacked_items']);
        self::assertSame(['50', '100', '150'], self::values($packed['achieved_dimensions']));
        self::assertSame(['300', '150', '100'], self::values($packed['inner_dimensions']));
        self::assertSame([], validate($standing, $result));
    }

    public static function testTheValidatorRefusesAStandingPackagePastItsLoop(): void
    {
        // The same two bottles against a pouch 1 mm narrower: a + b = 150 > W = 149.
        $standing = self::request(['kind' => 'gusseted_bag', 'bottom_gusset_depth' => '60', 'orientation' => 'standing'], self::BOTTLE, 2);
        $result = ArrayCodec::pack($standing);
        $narrower = $standing;
        $narrower['containers'][0]['inner_dimensions']['width'] = '149';
        $girth = array_values(array_filter(validate($narrower, $result), static fn($issue): bool => $issue->code === 'bag_girth_exceeded'));
        self::assertCount(1, $girth);
        self::assertSame('pouch#1: b + c = 4000000, a + b = 2400000, standing, length 4800000, width 2384000, bottom gusset 960000', $girth[0]->detail);
    }

    public static function testAStandingFixedSetPastItsLoopCannotHold(): void
    {
        $narrower = self::request(['kind' => 'gusseted_bag', 'bottom_gusset_depth' => '60', 'orientation' => 'standing'], self::BOTTLE, 2);
        $narrower['containers'][0]['inner_dimensions']['width'] = '149';
        $narrower['fixed_placements'] = [];
        foreach (['0', '50'] as $y) {
            $narrower['fixed_placements'][] = ['item_type' => 'bottle', 'container_type' => 'pouch', 'orientation' => 'LWH', 'position' => ['x' => '0', 'y' => $y, 'z' => '0']];
        }
        try {
            ArrayCodec::pack($narrower);
            throw new \RuntimeException('the fixed set was admitted');
        } catch (FixedPlacementException $error) {
            self::assertSame('invalid_fixed_placement: bag_girth_exceeded: pouch#1', $error->getMessage());
        }
    }

    public static function testTheStandingFrameTurnsOnceAndDeclaresTheBagAsGiven(): void
    {
        $declared = Dimensions::mm(300, 150, 100);
        $bag = new Container('pouch', $declared, deformableBoundary: 'gusseted_bag', bottomGussetDepth: Length::mm(60), bagOrientation: 'standing');
        $frame = PalletOverhang::solvingFrame($bag);
        self::assertEquals(Dimensions::mm(150, 100, 300), $frame->innerDimensions);
        self::assertEquals($declared, $frame->declaredInner());
        self::assertSame($frame, PalletOverhang::solvingFrame($frame));
        self::assertEquals(FlatBag::girthFor(self::ticks(300), self::ticks(150), 0, self::ticks(60), true), $frame->girth());
        self::assertEquals(new Girth([0, 2], 2 * self::ticks(300) + self::ticks(60), [1, 2], self::ticks(150)), $bag->girth());
    }

    public static function testTheObjectApiRefusesWhatTheJsonPathRefuses(): void
    {
        $cases = [
            [[], 'missing_field', 'a gusseted_bag requires side_gusset_depth'],
            [['bottomGussetDepth' => new Length(self::ticks(600) + 1)], 'invalid_value', 'bottom_gusset_depth exceeds twice inner_dimensions length'],
            [['bottomGussetDepth' => new Length(0)], 'invalid_value', 'bottom_gusset_depth must be positive'],
            [['sideGussetDepth' => Length::mm(10), 'bagOrientation' => 'standing'], 'missing_field', 'bottom_gusset_depth is required when orientation is standing'],
            [['bottomGussetDepth' => Length::mm(10), 'bagOrientation' => 'upright'], 'not_allowed', "orientation must be one of ['flat', 'standing']"],
        ];
        foreach ($cases as [$fields, $reason, $detail]) {
            $error = self::caught(static fn() => new Container('pouch', Dimensions::mm(300, 150, 100), ...(['deformableBoundary' => 'gusseted_bag'] + $fields)));
            self::assertSame([$reason, $detail], [$error->reason(), $error->detail()]);
        }
    }

    public static function testARigidBoxOrFlatBagRefusesTheGussetedFields(): void
    {
        foreach ([['bottomGussetDepth' => Length::mm(10)], ['bagOrientation' => 'standing']] as $fields) {
            foreach ([null, 'flat_bag'] as $boundary) {
                $error = self::caught(static fn() => new Container('box', Dimensions::mm(300, 150, 100), ...(['deformableBoundary' => $boundary] + $fields)));
                self::assertSame('not_allowed', $error->reason());
            }
        }
    }

    public static function testTheJsonPathRefusesAStandingBagWithoutABottomGusset(): void
    {
        $error = self::caught(static fn() => ArrayCodec::pack(self::request(['kind' => 'gusseted_bag', 'side_gusset_depth' => '10', 'orientation' => 'standing'], self::BOTTLE, 1)));
        self::assertSame(['missing_field', '/containers/0/deformable_boundary/bottom_gusset_depth'], [$error->reason(), $error->field()]);
    }

    private static function ticks(int $millimetres): int
    {
        return Length::mm($millimetres)->ticks;
    }

    /** @param array<string,mixed> $dimensions @return list<string> */
    private static function values(array $dimensions): array
    {
        return array_map(static fn(string $side): string => $dimensions[$side]['value'], ['length', 'width', 'height']);
    }

    /** @param array<string,mixed> $boundary @param array<string,mixed> $item @return array<string,mixed> */
    private static function request(array $boundary, array $item, int $quantity): array
    {
        return ['items' => [['quantity' => $quantity, 'weight' => '300 g'] + $item],
            'containers' => [['id' => 'pouch', 'quantity' => 1, 'deformable_boundary' => $boundary,
                'inner_dimensions' => ['length' => '300', 'width' => '150', 'height' => '100']]],
            'configuration' => ['solver_profile' => 'fast', 'time_limit_ms' => 60000, 'effort_budget' => ['max_search_nodes' => 100000]]];
    }

    private static function caught(callable $build): InvalidRequestException
    {
        try {
            $build();
        } catch (InvalidRequestException $error) {
            return $error;
        }
        throw new \RuntimeException('was not refused');
    }
}
