<?php
/**
 * From a paid order to labelled parcels: eligibility, packing, a second opinion, a price.
 *
 * Run it:
 *
 *     php examples/fulfilment.php
 *
 * checkout.php maps a cart onto a request and reads the parcels back. A warehouse needs
 * more around that call, and this example wires the pieces in the order a fulfilment job
 * runs them:
 *
 * 1. `evaluatePolicy` decides which carrier service the order may use at all.
 * 2. `ArrayCodec::pack` packs it, under the rules the goods come with.
 * 3. `validate` checks the plan against the request with code that shares nothing with
 *    the solver, so a parcel is never labelled on the solver's word alone.
 * 4. `quote` prices every parcel from the tariff version you pinned.
 *
 * Two kinds of trouble are kept apart on purpose. A request that is wrong (a quantity of
 * zero from a bad import) throws `InvalidRequestException`, and the job puts the order on
 * hold. An item that fits no carton is not an error: the rest of the order ships and the
 * item is handed to freight.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Serialization\ArrayCodec;
use Packvium\Serialization\InvalidRequestException;

use function Packvium\Commerce\evaluatePolicy;
use function Packvium\Commerce\quote;
use function Packvium\Validation\validate;

// Results carry exact integers: 16 000 length ticks to the millimetre, 8 000 000 weight
// ticks to the gram. A carrier bills whole millimetres and grams, rounded up.
const TICKS_PER_MM = 16000;
const TICKS_PER_GRAM = 8000000;

// What you publish: one tariff per carrier service, and the rules that say what may ship
// how. You would load this from your own storage; a version's number is its position in
// the list, so `tariff_version => 1` below always means the same prices.
const COMMERCE = [
    'tariffs' => [
        [
            'carrier_id' => 'postway', 'service_id' => 'air',
            'versions' => [[
                'effective_at' => 0,
                'dimensional_weight_divisor' => 5000,
                'cost_per_dimensional_kg_minor' => ['domestic' => 520, 'eu' => 890],
                'minimum_charge_minor' => 950,
                'fuel_surcharge_permille' => 140,
                'accessorials' => [],
            ]],
        ],
        [
            'carrier_id' => 'postway', 'service_id' => 'ground',
            'versions' => [[
                'effective_at' => 0,
                'dimensional_weight_divisor' => 6000,
                'cost_per_dimensional_kg_minor' => ['domestic' => 210, 'eu' => 380],
                'minimum_charge_minor' => 590,
                'fuel_surcharge_permille' => 90,
                'accessorials' => [],
            ]],
        ],
    ],
    'policy_rules' => [
        [
            'rule_id' => 'lithium-stays-on-the-ground',
            'versions' => [[
                'scope' => 'service', 'action' => 'reject', 'priority' => 10, 'effective_at' => 0,
                'reason' => 'lithium batteries do not fly with this carrier',
                'predicates' => [
                    ['scope' => 'service', 'field' => 'service', 'operator' => 'equals', 'value' => 'air'],
                    ['scope' => 'service', 'field' => 'battery', 'operator' => 'equals', 'value' => 'lithium'],
                ],
            ]],
        ],
    ],
    'catalogs' => [],
];

const PRODUCTS = [
    'laptop' => ['mm' => [360, 250, 30], 'g' => 2100, 'battery' => 'lithium'],
    'monitor' => ['mm' => [680, 430, 140], 'g' => 5400, 'keep_upright' => true, 'stackable' => false],
    'bleach' => ['mm' => [90, 90, 280], 'g' => 1100, 'keep_upright' => true,
                 'tags' => ['chemical'], 'incompatible_tags' => ['food']],
    'coffee' => ['mm' => [120, 80, 220], 'g' => 1000, 'tags' => ['food']],
    'desk' => ['mm' => [1600, 800, 60], 'g' => 28000],
];

const BOXES = [
    ['id' => 'S', 'mm' => [300, 200, 300], 'tare_g' => 180, 'max_kg' => 8, 'cost_minor' => 50],
    ['id' => 'M', 'mm' => [400, 300, 300], 'tare_g' => 260, 'max_kg' => 15, 'cost_minor' => 85],
    ['id' => 'L', 'mm' => [700, 450, 450], 'tare_g' => 520, 'max_kg' => 25, 'cost_minor' => 160],
];

/**
 * @param array{0:int,1:int,2:int} $edges
 * @return array{length:string,width:string,height:string}
 */
function measures(array $edges): array
{
    return ['length' => (string) $edges[0], 'width' => (string) $edges[1], 'height' => (string) $edges[2]];
}

/**
 * The service the customer chose, or ground when a rule forbids it, and why.
 *
 * @param array<string,mixed> $order
 * @return array{0:string,1:?string}
 */
function allowedService(array $order): array
{
    foreach (array_keys($order['lines']) as $sku) {
        $decision = evaluatePolicy(COMMERCE, [
            'scope' => 'service',
            'context' => ['service' => $order['service'], 'battery' => PRODUCTS[$sku]['battery'] ?? 'none'],
            'as_of' => 0,
        ])['decision'];
        if (!$decision['allowed']) {
            return ['ground', "{$sku}: {$decision['citation']['reason']}"];
        }
    }
    return [$order['service'], null];
}

/**
 * @param array<string,mixed> $order
 * @return array<string,mixed>
 */
function packingRequest(array $order): array
{
    $items = [];
    foreach ($order['lines'] as $sku => $quantity) {
        $product = PRODUCTS[$sku];
        $item = ['id' => $sku, 'quantity' => $quantity, 'weight' => $product['g'] . ' g',
                 'dimensions' => measures($product['mm'])];
        // Only the rules a product actually has are sent; an absent field means "no rule".
        foreach (['keep_upright', 'stackable', 'tags', 'incompatible_tags'] as $rule) {
            if (array_key_exists($rule, $product)) {
                $item[$rule] = $product[$rule];
            }
        }
        $items[] = $item;
    }
    $containers = [];
    foreach (BOXES as $box) {
        $containers[] = [
            'id' => $box['id'], 'inner_dimensions' => measures($box['mm']), 'cost_minor' => $box['cost_minor'],
            'tare_weight' => $box['tare_g'] . ' g', 'max_payload' => $box['max_kg'] . ' kg',
        ];
    }
    return [
        'units' => ['length' => 'mm'],
        'configuration' => [
            'objective' => 'lowest_cost',
            // Nothing may hang in the air: three quarters of every base must rest on something.
            'minimum_support_ratio' => 0.75,
            'effort_budget' => ['max_candidates_evaluated' => 200000],
            'time_limit_ms' => 60000,
        ],
        'items' => $items,
        'containers' => $containers,
    ];
}

/**
 * One parcel through the pinned tariff. Whole grams and millimetres, rounded up.
 *
 * The answer is either a quote or a rejection with a code: a zone the tariff does not
 * price is never a zero.
 *
 * @param array<string,mixed> $container
 * @return array<string,mixed>
 */
function price(array $container, string $service, string $zone): array
{
    $grams = intdiv($container['gross_weight']['ticks'] + TICKS_PER_GRAM - 1, TICKS_PER_GRAM);
    $volume = 1;
    foreach (['length', 'width', 'height'] as $edge) {
        $volume *= intdiv($container['outer_dimensions'][$edge]['ticks'] + TICKS_PER_MM - 1, TICKS_PER_MM);
    }
    return quote(COMMERCE, [
        'carrier_id' => 'postway', 'service_id' => $service, 'tariff_version' => 1,
        'zone' => $zone, 'actual_weight_g' => $grams, 'volume_mm3' => $volume,
    ]);
}

/** @param array<string,mixed> $order */
function fulfil(array $order): void
{
    echo PHP_EOL, "order {$order['id']} to zone {$order['zone']}, customer chose {$order['service']}", PHP_EOL;
    [$service, $why] = allowedService($order);
    if ($why !== null) {
        echo "  moved to {$service}: {$why}", PHP_EOL;
    }

    $request = packingRequest($order);
    try {
        $result = ArrayCodec::pack($request);
    } catch (InvalidRequestException $error) {
        // `field()` is a JSON Pointer into the request, `reason()` one of a closed set.
        echo "  on hold: {$error->reason()} at {$error->field()} ({$error->detail()})", PHP_EOL;
        return;
    }

    $issues = validate($request, $result);
    if ($issues !== []) {
        $found = array_map(static fn ($issue): string => "{$issue->code}: {$issue->detail}", $issues);
        echo '  on hold: the plan failed its check: ', implode('; ', $found), PHP_EOL;
        return;
    }
    echo '  plan checked: ', count($result['containers']), ' parcel(s), no issues', PHP_EOL;

    $answers = [];
    foreach ($result['containers'] as $container) {
        $answer = price($container, $service, $order['zone']);
        if ($answer['status'] !== 'ok') {
            echo "  on hold: no {$service} price for zone {$order['zone']} ({$answer['error']['code']})", PHP_EOL;
            return;
        }
        $answers[] = $answer;
    }

    $total = 0;
    foreach ($result['containers'] as $index => $container) {
        $contents = [];
        foreach ($container['placements'] as $placement) {
            $contents[$placement['item_type']] = ($contents[$placement['item_type']] ?? 0) + 1;
        }
        ksort($contents);
        $listing = [];
        foreach ($contents as $sku => $count) {
            $listing[] = "{$count} x {$sku}";
        }
        $quoted = $answers[$index]['quote'];
        $total += $quoted['total_minor'];
        printf("  %-4s %s\n", $container['id'], implode(', ', $listing));
        echo "       {$quoted['actual_weight_g']} g on the scale, billed as ",
            "{$quoted['billed_weight_g']} g, {$quoted['total_minor']} minor units", PHP_EOL;
    }
    echo "  shipping by {$service}: {$total} minor units", PHP_EOL;

    foreach ($result['unpacked_items'] as $entry) {
        echo "  {$entry['item_id']} goes to freight: {$entry['reason']}", PHP_EOL;
    }
}

fulfil(['id' => 'A-1001', 'zone' => 'domestic', 'service' => 'air',
        'lines' => ['coffee' => 6, 'bleach' => 2]]);
fulfil(['id' => 'A-1002', 'zone' => 'eu', 'service' => 'air',
        'lines' => ['laptop' => 1, 'monitor' => 1, 'desk' => 1]]);
fulfil(['id' => 'A-1003', 'zone' => 'domestic', 'service' => 'ground',
        'lines' => ['coffee' => 0]]);
fulfil(['id' => 'A-1004', 'zone' => 'overseas', 'service' => 'ground',
        'lines' => ['coffee' => 1]]);
