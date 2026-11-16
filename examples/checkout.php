<?php
/**
 * A shop's checkout: turn a cart into parcels.
 *
 * Run it:
 *
 *     php examples/checkout.php
 *
 * This is the shape most projects start from. You already have product rows and cart
 * lines in your own format; the library wants a request. So there are two small functions
 * between them: `toRequest` maps your records onto the request, and `parcels` reads the
 * result back into something your checkout page can render. Everything Packvium-specific
 * lives in those two functions, and the rest of the shop never sees a tick or a placement.
 *
 * The request is a plain array, the same JSON every Packvium engine reads, so the mapping
 * below works unchanged in a Laravel job, a Symfony service or a WordPress hook, and on
 * every PHP version the package supports.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use Packvium\Serialization\ArrayCodec;

// Your product table. Sizes in centimetres and weights in grams, as a shop usually has them.
const PRODUCTS = [
    'kettle' => ['size_cm' => [24, 22, 26], 'weight_g' => 1300, 'this_side_up' => true],
    'mug' => ['size_cm' => [12, 9, 10], 'weight_g' => 350, 'this_side_up' => false],
    'cookbook' => ['size_cm' => [28, 22, 3], 'weight_g' => 900, 'this_side_up' => false],
    'floor-lamp' => ['size_cm' => [150, 30, 30], 'weight_g' => 4200, 'this_side_up' => false],
];

// The cartons on your packing bench, with what each one costs you in cents.
const BOXES = [
    'small' => ['inner_cm' => [30, 25, 15], 'max_kg' => 5, 'cost_cents' => 45],
    'medium' => ['inner_cm' => [40, 30, 30], 'max_kg' => 12, 'cost_cents' => 80],
    'large' => ['inner_cm' => [60, 40, 40], 'max_kg' => 20, 'cost_cents' => 140],
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
 * Your cart (`[sku => quantity]`) as a Packvium request.
 *
 * @param array<string,int> $cart
 * @return array<string,mixed>
 */
function toRequest(array $cart): array
{
    $items = [];
    foreach ($cart as $sku => $quantity) {
        $product = PRODUCTS[$sku];
        $items[] = [
            'id' => $sku,
            'quantity' => $quantity,
            'weight' => $product['weight_g'] . ' g',
            'keep_upright' => $product['this_side_up'],
            'dimensions' => measures($product['size_cm']),
        ];
    }
    $containers = [];
    foreach (BOXES as $id => $box) {
        $containers[] = [
            'id' => $id,
            'max_payload' => $box['max_kg'] . ' kg',
            'cost_minor' => $box['cost_cents'],
            'inner_dimensions' => measures($box['inner_cm']),
        ];
    }
    return [
        'units' => ['length' => 'cm'],
        'configuration' => [
            // Pay as little as possible for cartons, after packing everything that fits.
            'objective' => 'lowest_cost',
            // Counted work decides where the search stops, so the same cart always gets
            // the same parcels. The time limit is a fuse against a hang, not a search limit.
            'effort_budget' => ['max_candidates_evaluated' => 200000],
            'time_limit_ms' => 60000,
        ],
        'items' => $items,
        'containers' => $containers,
    ];
}

/**
 * The parcels to ship, and the SKUs that fit no carton you stock.
 *
 * @param array<string,int> $cart
 * @return array{0:list<array{box:string,contents:array<string,int>,weight:string}>,1:list<string>}
 */
function parcels(array $cart): array
{
    $result = ArrayCodec::pack(toRequest($cart));
    $shipped = [];
    foreach ($result['containers'] as $container) {
        $contents = [];
        foreach ($container['placements'] as $placement) {
            $contents[$placement['item_type']] = ($contents[$placement['item_type']] ?? 0) + 1;
        }
        ksort($contents);
        $shipped[] = [
            'box' => $container['container_type'],
            'contents' => $contents,
            // `value` is the exact weight rendered in `unit`; here that is whole grams.
            'weight' => $container['gross_weight']['value'] . ' ' . $container['gross_weight']['unit'],
        ];
    }
    // An item that fits nowhere is not an error. It comes back with a reason, and the rest
    // of the cart is still packed.
    $leftOut = array_values(array_unique(array_column($result['unpacked_items'], 'item_type')));
    sort($leftOut);
    return [$shipped, $leftOut];
}

/** @param array<string,int> $cart */
function show(string $title, array $cart): void
{
    [$shipped, $leftOut] = parcels($cart);
    echo PHP_EOL, $title, PHP_EOL;
    $boxCost = 0;
    foreach ($shipped as $index => $parcel) {
        $contents = [];
        foreach ($parcel['contents'] as $sku => $count) {
            $contents[] = "{$count} x {$sku}";
        }
        printf("  parcel %d: %-6s %7s  %s\n", $index + 1, $parcel['box'], $parcel['weight'], implode(', ', $contents));
        $boxCost += BOXES[$parcel['box']]['cost_cents'];
    }
    echo "  cartons cost {$boxCost} cents", PHP_EOL;
    foreach ($leftOut as $sku) {
        echo "  {$sku} fits none of our cartons: ship it in its own packaging", PHP_EOL;
    }
}

show('a small order', ['mug' => 2, 'cookbook' => 1]);
show('a bigger order', ['kettle' => 4, 'mug' => 6, 'cookbook' => 4]);
show('an order with something oversized', ['floor-lamp' => 1, 'mug' => 4]);
