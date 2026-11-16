<?php
declare(strict_types=1);
namespace Packvium\Algorithm;

use RuntimeException;

/**
 * A billed objective's inventory cannot carry, at any price it lists, a packing that attains
 * `L0`.
 *
 * No answer is compared against a bound then, so none is stated. Separate from
 * `BoundOverflowException` because nothing here is too large: the request simply has no
 * priceable packing for the bound to speak about.
 */
final class BoundWithheldException extends RuntimeException
{
}
