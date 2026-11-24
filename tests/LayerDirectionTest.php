<?php
declare(strict_types=1);

namespace Packvium\Tests;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Imports between namespaces only point down the layers of docs/ARCHITECTURE.md (R-01).
 *
 * The Python port holds the same line with an AST test. Here the `use` statements of every file
 * under `src/` are parsed and each one is checked against the tier table below. The upward
 * imports that already exist are listed in `ALLOWED`, grouped by why they exist; the test
 * fails on a new one and also on a listed one that has gone, so the list can only shrink.
 *
 * The tiers follow "Dependency direction": `Units <- Domain <- Constraints/Algorithms/Validation
 * <- Packer <- Serialization/Nested`. That line treats the middle three as one tier; they are
 * ordered here Constraint < Validation < Algorithm because that is the direction their imports
 * actually take, and the stricter order still has no violation of its own. Commerce is a second
 * root: it imports nothing from the other layers and none of them import it.
 *
 * Not placed by the document, so put where their imports show them sitting: `Support` (foundation
 * helpers at the Units tier, except the request-admission files and `ResultValidation`, which
 * import Domain, Serialization and Validation), `Config`, `Explain`, `Execution`, `Sequence`,
 * `Policy`, `Result`, `Artifacts`, `Revisions`. `Extension` and `Objective` have a number in the
 * document but no place in the dependency line; they sit under `Algorithm`, which depends on
 * their abstractions.
 */
final class LayerDirectionTest extends TestCase
{
    /** Longest matching prefix of a class name below `Packvium\` wins; a higher tier may import a lower or equal one. */
    private const TIERS = [
        'Unit' => 0,
        'Support' => 0,
        'Domain' => 1,
        'Constraint' => 2,
        'Config' => 2,
        'Explain' => 2,
        'Execution' => 2,
        'Sequence' => 3,
        'Policy' => 3,
        'Result' => 3,
        'Extension' => 3,
        'Objective' => 3,
        'Artifacts' => 3,
        'Validation' => 4,
        'Revisions' => 4,
        'Algorithm' => 5,
        'Support\\ResultValidation' => 5,
        'Packer' => 6,
        'Serialization' => 7,
        'Nested' => 7,
        'Support\\ExactGeometry' => 1,
        'Support\\UnsupportedPlacements' => 1,
        'Support\\FixedPlacementShape' => 4,
        'Support\\RequestObjects' => 6,
        'Support\\RequestRules' => 6,
        'Support\\Internal' => 6,
    ];

    private const COMMERCE = 'Commerce';

    /**
     * Upward imports that exist today: reason => file below `src/` => imported classes below `Packvium\`.
     *
     * @var array<string,array<string,list<string>>>
     */
    private const ALLOWED = [
        'Commerce is a leaf by design but reuses Support helpers (R-01)' => [
            'Commerce/Rating/AccessorialCharge.php' => ['Support\\Arithmetic'],
            'Commerce/Rating/CarrierRegistry.php' => ['Support\\Arithmetic', 'Support\\CanonicalJson', 'Support\\ExactInteger'],
            'Commerce/Shape.php' => ['Support\\CanonicalJson'],
        ],
        'The refusal types hang off Serialization\\InvalidRequestException, so Support and Validation import up to it (R-01)' => [
            'Support/ObjectRefusal.php' => ['Serialization\\InvalidRequestException'],
            'Support/RequestObjects.php' => ['Serialization\\InvalidRequestException'],
            'Support/RequestRules.php' => ['Serialization\\InvalidRequestException'],
            'Validation/FixedPlacementException.php' => ['Serialization\\InvalidRequestException'],
        ],
        'Config and Revisions call the request-admission rules and Config names its budget in Algorithm (R-01)' => [
            'Config/PackingConfig.php' => ['Algorithm\\EffortBudget', 'Support\\RequestRules'],
            'Revisions/PlanRevision.php' => ['Support\\RequestRules'],
        ],
        'Extension signatures are typed on Algorithm state (R-01)' => [
            'Extension/CandidateScorer.php' => ['Algorithm\\ContainerState'],
            'Extension/ContainerSelector.php' => ['Algorithm\\SingleContainerSolution'],
            'Extension/DefaultCandidateScorer.php' => ['Algorithm\\CandidateFinder', 'Algorithm\\ContainerState'],
            'Extension/DefaultContainerSelector.php' => ['Algorithm\\SingleContainerSolution'],
            'Extension/ExtensionRegistry.php' => ['Algorithm\\SingleContainerSolver'],
        ],
        'Objective scorers take Algorithm\\RawSolution (R-01)' => [
            'Objective/DefaultSolutionScorer.php' => ['Algorithm\\RawSolution'],
            'Objective/LandedCostSolutionScorer.php' => ['Algorithm\\RawSolution'],
            'Objective/LowestCostSolutionScorer.php' => ['Algorithm\\RawSolution'],
            'Objective/MaximumValueSolutionScorer.php' => ['Algorithm\\RawSolution'],
            'Objective/MaximumVolumeSolutionScorer.php' => ['Algorithm\\RawSolution'],
            'Objective/OpenDimensionSolutionScorer.php' => ['Algorithm\\RawSolution'],
            'Objective/ShippingCostSolutionScorer.php' => ['Algorithm\\RawSolution'],
            'Objective/SolutionScorer.php' => ['Algorithm\\RawSolution'],
        ],
        'Facades that delegate to a higher module which imports them back (R-01)' => [
            'Support/MeasureReading.php' => ['Support\\Internal\\MeasureOverflow'],
            'Validation/functions.php' => ['Support\\ResultValidation\\Validator'],
        ],
    ];

    public static function testNoImportPointsUpTheLayersExceptTheListedOnes(): void
    {
        $found = self::violations();
        $allowed = self::allowedPairs();
        self::assertSame([], array_values(array_diff($found, $allowed)), 'new upward imports (file => class); fix the import or list it with a reason');
        self::assertSame([], array_values(array_diff($allowed, $found)), 'allow-listed imports that are gone; remove them from ALLOWED');
    }

    /** Every file's namespace is placed, so a new one cannot slip past the table unnoticed. */
    public static function testEveryNamespaceIsPlaced(): void
    {
        foreach (array_keys(self::imports()) as $file) {
            $class = self::classOf($file);
            self::assertTrue(self::isCommerce($class) || self::tierOf($class) !== null, "{$file} is in no tier of LayerDirectionTest::TIERS");
        }
    }

    /** @return list<string> sorted `file => class` pairs */
    public static function violations(): array
    {
        $found = [];
        foreach (self::imports() as $file => $classes) {
            foreach ($classes as $class) {
                if (self::isUpward(self::classOf($file), $class)) {
                    $found[] = "{$file} => {$class}";
                }
            }
        }
        sort($found);
        return $found;
    }

    /** @return list<string> */
    private static function allowedPairs(): array
    {
        $pairs = [];
        foreach (self::ALLOWED as $files) {
            foreach ($files as $file => $classes) {
                foreach ($classes as $class) {
                    $pairs[] = "{$file} => {$class}";
                }
            }
        }
        sort($pairs);
        return $pairs;
    }

    private static function isUpward(string $from, string $to): bool
    {
        $fromCommerce = self::isCommerce($from);
        if ($fromCommerce || self::isCommerce($to)) {
            return $fromCommerce !== self::isCommerce($to);
        }
        $fromTier = self::tierOf($from);
        $toTier = self::tierOf($to);
        return $fromTier !== null && $toTier !== null && $toTier > $fromTier;
    }

    private static function isCommerce(string $class): bool
    {
        return $class === self::COMMERCE || str_starts_with($class, self::COMMERCE . '\\');
    }

    private static function tierOf(string $class): ?int
    {
        $best = null;
        $bestLength = -1;
        foreach (self::TIERS as $prefix => $tier) {
            $matches = $class === $prefix || str_starts_with($class, $prefix . '\\');
            if ($matches && strlen($prefix) > $bestLength) {
                $best = $tier;
                $bestLength = strlen($prefix);
            }
        }
        return $best;
    }

    /** `Domain/Item.php` => `Domain\Item`. */
    private static function classOf(string $file): string
    {
        return str_replace('/', '\\', substr($file, 0, -strlen('.php')));
    }

    /** @return array<string,list<string>> file below `src/` => Packvium classes it imports, below `Packvium\` */
    private static function imports(): array
    {
        $root = dirname(__DIR__) . '/src';
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $entry) {
            if ($entry->getExtension() === 'php') {
                $files[] = substr($entry->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);
        $imports = [];
        foreach ($files as $file) {
            $imports[$file] = self::usedClasses((string) file_get_contents($root . '/' . $file));
        }
        return $imports;
    }

    /** @return list<string> */
    private static function usedClasses(string $source): array
    {
        preg_match_all('/^use\s+(?!function\s|const\s)([^;]+);/m', $source, $statements);
        $classes = [];
        foreach ($statements[1] as $statement) {
            foreach (self::expand($statement) as $class) {
                if (str_starts_with($class, 'Packvium\\')) {
                    $classes[] = substr($class, strlen('Packvium\\'));
                }
            }
        }
        $classes = array_values(array_unique($classes));
        sort($classes);
        return $classes;
    }

    /** @return list<string> `A\B\{C, D as E}` => `A\B\C`, `A\B\D` */
    private static function expand(string $statement): array
    {
        $names = [];
        if (preg_match('/^([^{]*)\{([^}]*)\}$/s', trim($statement), $group) === 1) {
            foreach (explode(',', $group[2]) as $member) {
                $names[] = trim($group[1]) . self::withoutAlias($member);
            }
            return $names;
        }
        foreach (explode(',', $statement) as $name) {
            $names[] = self::withoutAlias($name);
        }
        return $names;
    }

    private static function withoutAlias(string $name): string
    {
        return trim(preg_split('/\s+as\s+/i', trim($name))[0]);
    }
}
