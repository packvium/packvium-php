<?php
declare(strict_types=1);
namespace Packvium\Support\Internal;

use InvalidArgumentException;
use Packvium\Unit\{Length,Rounding,Weight};
use Packvium\Support\{BigInt,JsonValue,MeasureReading,RationalParser,RequestRules};

/**
 * `MeasureReading`'s exact reading of a measure the native parser overflowed on,
 * kept in its own file so PHP compiles it only for such a measure (1.9.0 release frontier).
 *
 * @internal
 */
final class MeasureOverflow
{
    private const SPACE_CLASS = "[ \\t\\n\\r\\x0B\\x0C]";

    /** The unit suffixes `Length::parse` and `Weight::parse` split off a string measure. */
    private const SUFFIXES = [
        Length::class => '/^(.+?)' . self::SPACE_CLASS . '*(mm|cm|m|in|inch|inches|ft|ticks?)\z/i',
        Weight::class => '/^(.+?)' . self::SPACE_CLASS . '*(mg|kg|g|oz|lbs?|ticks?)\z/i',
    ];

    /**
     * Exact big-digit scaling when `RationalParser::scaled` overflows native integers on
     * intermediate numerators or denominators (e.g. a 30-digit fraction or 20-digit decimal
     * whose rounded tick count still fits in `int`). O(digits^2).
     */
    public static function scaled(string $value, int $multiplier, Rounding $rounding): int
    {
        $text = RationalParser::withoutPadding($value);
        $fraction = self::exactFraction($text);
        if ($fraction === null) {
            throw new InvalidArgumentException('Scaled unit value exceeds integer range');
        }
        [$negative, $numerator, $denominator] = $fraction;
        // Decimal digits, not `abs()`: `abs(PHP_INT_MIN)` is a float.
        $magnitude = \ltrim((string) $multiplier, '-');
        if ($multiplier < 0) {
            $negative = !$negative;
        }
        $scaled = BigInt::multiply($numerator, $magnitude);
        $quotient = BigInt::divide($scaled, $denominator);
        $remainder = BigInt::subtract($scaled, BigInt::multiply($quotient, $denominator));
        if ($remainder === '0') {
            $rounded = $quotient;
        } else {
            $doubledRemainder = BigInt::multiply($remainder, 2);
            $cmp = BigInt::compare($doubledRemainder, $denominator);
            $oddQuotient = ((int) \substr($quotient, -1)) % 2 === 1;
            $increment = match ($rounding) {
                Rounding::Floor => $negative,
                Rounding::Ceil => !$negative,
                Rounding::Nearest => $cmp > 0 || ($cmp === 0 && $oddQuotient),
            };
            $rounded = $increment ? BigInt::add($quotient, '1') : $quotient;
        }
        if ($rounded === '0') {
            return 0;
        }
        if ($negative) {
            if (BigInt::compare($rounded, '9223372036854775808') > 0) {
                throw new InvalidArgumentException('Scaled unit value exceeds integer range');
            }
            return $rounded === '9223372036854775808' ? \PHP_INT_MIN : -(int) $rounded;
        }
        if (BigInt::compare($rounded, (string) \PHP_INT_MAX) > 0) {
            throw new InvalidArgumentException('Scaled unit value exceeds integer range');
        }
        return (int) $rounded;
    }

    /**
     * The verdict on text the native parser overflowed on, read exactly: negative, past the
     * tick ceiling of its kind, or -- a vanishing fraction with a vast denominator -- still
     * unreadable here.
     * Ticks round to nearest, so they pass `MAX` once `n x m / d >= MAX + 1/2`; `MAX` is odd,
     * so the tie itself rounds up to even. O(digits^2).
     *
     * @param int|string|array{value:int|string,unit?:string} $normalised
     * @param class-string<Length>|class-string<Weight> $kind
     */
    public static function beyondNativeInteger($normalised, string $kind, string $unit): string
    {
        $text = \is_array($normalised) ? (string) $normalised['value'] : (string) $normalised;
        $unit = \is_array($normalised) ? ($normalised['unit'] ?? $unit) : $unit;
        if (\is_string($normalised) && \preg_match(self::SUFFIXES[$kind], \trim($normalised, RationalParser::ASCII_SPACE), $m)) {
            [$text, $unit] = [\trim($m[1], RationalParser::ASCII_SPACE), $m[2]];
        }
        $fraction = self::exactFraction(RationalParser::withoutPadding($text));
        if ($fraction === null) {
            return MeasureReading::UNREADABLE;
        }
        [$negative, $numerator, $denominator] = $fraction;
        if ($negative) {
            return MeasureReading::NEGATIVE;
        }
        if ($numerator === '0') {
            return MeasureReading::UNREADABLE;
        }
        $multiplier = $kind::multiplier($unit);
        $doubled = BigInt::multiply(BigInt::multiply($numerator, $multiplier), 2);
        $ceiling = $kind === Weight::class ? RequestRules::MAX_WEIGHT_TICKS : JsonValue::MAX_EXACT_MAGNITUDE;
        $threshold = BigInt::multiply($denominator, BigInt::add(BigInt::multiply($ceiling, 2), '1'));
        return BigInt::compare($doubled, $threshold) >= 0 ? MeasureReading::TOO_LARGE : MeasureReading::UNREADABLE;
    }

    /**
     * `RationalParser::parse` over decimal strings: an integer, decimal, fraction or mixed
     * fraction as its sign, numerator and denominator, or null for any other text.
     *
     * @return array{0:bool,1:string,2:string}|null
     */
    private static function exactFraction(string $text): ?array
    {
        $negative = \str_starts_with($text, '-');
        if (\preg_match('/^[+-]?(\d+)' . self::SPACE_CLASS . '+(\d+)' . self::SPACE_CLASS . '*\/' . self::SPACE_CLASS . '*(\d+)\z/', $text, $m)) {
            return self::signed($negative, BigInt::add(BigInt::multiply(self::digits($m[1]), self::digits($m[3])), self::digits($m[2])), $m[3]);
        }
        if (\preg_match('/^[+-]?(\d+)' . self::SPACE_CLASS . '*\/' . self::SPACE_CLASS . '*(\d+)\z/', $text, $m)) {
            return self::signed($negative, $m[1], $m[2]);
        }
        if (\preg_match('/^[+-]?(\d+)(?:\.(\d+))?\z/', $text, $m)) {
            $fraction = $m[2] ?? '';
            return self::signed($negative, $m[1] . $fraction, '1' . \str_repeat('0', \strlen($fraction)));
        }
        return null;
    }

    /** @return array{0:bool,1:string,2:string}|null */
    private static function signed(bool $negative, string $numerator, string $denominator): ?array
    {
        $numerator = self::digits($numerator);
        $denominator = self::digits($denominator);
        if ($denominator === '0') {
            return null;
        }
        return [$negative && $numerator !== '0', $numerator, $denominator];
    }

    private static function digits(string $digits): string
    {
        return \ltrim($digits, '0') ?: '0';
    }
}
