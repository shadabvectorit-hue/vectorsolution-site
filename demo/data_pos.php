<?php
/**
 * VectorERP demo — retail POS catalogue.
 *
 * Barcodes are real EAN-13 shapes so a visitor can test with an actual USB
 * scanner. Tax is per item on purpose: a Pakistani counter mixes standard-rated
 * goods with exempt staples and 3rd Schedule retail-price items, and a POS that
 * charges 18% on everything is wrong at the till and wrong in the return.
 *
 *   tax = 18   standard rated
 *   tax = 0    exempt / zero-rated staple
 *   sch3 = true  3rd Schedule — tax is computed on the printed retail price
 */
declare(strict_types=1);

return [
    'shop' => [
        'name'    => 'Al-Karam Mart',
        'branch'  => 'Gulshan-e-Iqbal, Karachi',
        'strn'    => '32-77-8842-991-73',
        'ntn'     => '7702451-8',
        'phone'   => '021 3498 2210',
        'cashier' => 'Bilal Ahmed',
        'till'    => 'TILL-02',
    ],

    'categories' => ['Beverages', 'Snacks', 'Grocery', 'Dairy & Bakery', 'Personal care', 'Household'],

    'items' => [
        // Beverages
        ['code' => '8964000201457', 'name' => 'Coca-Cola 1.5 L',            'cat' => 'Beverages',     'price' => 260,  'tax' => 18, 'stock' => 84],
        ['code' => '8964000203451', 'name' => 'Sprite 1.5 L',               'cat' => 'Beverages',     'price' => 260,  'tax' => 18, 'stock' => 61],
        ['code' => '8964001120034', 'name' => 'Nestle Water 1.5 L',         'cat' => 'Beverages',     'price' => 90,   'tax' => 18, 'stock' => 210],
        ['code' => '8964002330117', 'name' => 'Tapal Danedar 950 g',        'cat' => 'Beverages',     'price' => 1650, 'tax' => 18, 'stock' => 23],
        ['code' => '8964002338804', 'name' => 'Nescafe Classic 100 g',      'cat' => 'Beverages',     'price' => 1490, 'tax' => 18, 'stock' => 12],

        // Snacks
        ['code' => '8964000455012', 'name' => 'Lays Masala 68 g',           'cat' => 'Snacks',        'price' => 120,  'tax' => 18, 'stock' => 140],
        ['code' => '8964000455203', 'name' => 'Kurkure Chutney 62 g',       'cat' => 'Snacks',        'price' => 100,  'tax' => 18, 'stock' => 96],
        ['code' => '8964003310049', 'name' => 'Sooper Biscuit family pack',  'cat' => 'Snacks',        'price' => 220,  'tax' => 18, 'stock' => 74],
        ['code' => '8964003318802', 'name' => 'Dairy Milk 65 g',            'cat' => 'Snacks',        'price' => 400,  'tax' => 18, 'stock' => 38],
        ['code' => '8964003311114', 'name' => 'Prince Chocolate 24 pcs',    'cat' => 'Snacks',        'price' => 480,  'tax' => 18, 'stock' => 27],

        // Grocery — staples: exempt or 3rd Schedule
        ['code' => '8964005500128', 'name' => 'Atta chakki 10 kg bag',      'cat' => 'Grocery',       'price' => 1450, 'tax' => 0,  'stock' => 46],
        ['code' => '8964005500821', 'name' => 'Basmati rice 5 kg',          'cat' => 'Grocery',       'price' => 2350, 'tax' => 0,  'stock' => 31],
        ['code' => '8964005502115', 'name' => 'Sugar 1 kg',                 'cat' => 'Grocery',       'price' => 175,  'tax' => 18, 'stock' => 120, 'sch3' => true],
        ['code' => '8964005503334', 'name' => 'Dalda cooking oil 5 L',      'cat' => 'Grocery',       'price' => 3950, 'tax' => 18, 'stock' => 18, 'sch3' => true],
        ['code' => '8964005504118', 'name' => 'Daal chana 1 kg',            'cat' => 'Grocery',       'price' => 340,  'tax' => 0,  'stock' => 58],
        ['code' => '8964005505016', 'name' => 'National Salt 800 g',        'cat' => 'Grocery',       'price' => 90,   'tax' => 18, 'stock' => 88],

        // Dairy & Bakery
        ['code' => '8964006600114', 'name' => 'Olpers Milk 1 L',            'cat' => 'Dairy & Bakery','price' => 330,  'tax' => 0,  'stock' => 64],
        ['code' => '8964006601227', 'name' => 'Nurpur Butter 200 g',        'cat' => 'Dairy & Bakery','price' => 690,  'tax' => 18, 'stock' => 22],
        ['code' => '8964006602118', 'name' => 'Eggs — dozen',               'cat' => 'Dairy & Bakery','price' => 420,  'tax' => 0,  'stock' => 40],
        ['code' => '8964006603115', 'name' => 'Bread large',                'cat' => 'Dairy & Bakery','price' => 180,  'tax' => 0,  'stock' => 35],

        // Personal care
        ['code' => '8964007700112', 'name' => 'Safeguard soap 130 g',       'cat' => 'Personal care', 'price' => 220,  'tax' => 18, 'stock' => 110],
        ['code' => '8964007701119', 'name' => 'Head & Shoulders 360 ml',    'cat' => 'Personal care', 'price' => 1290, 'tax' => 18, 'stock' => 19],
        ['code' => '8964007702116', 'name' => 'Colgate 145 g',              'cat' => 'Personal care', 'price' => 480,  'tax' => 18, 'stock' => 44],

        // Household
        ['code' => '8964008800110', 'name' => 'Surf Excel 1 kg',            'cat' => 'Household',     'price' => 890,  'tax' => 18, 'stock' => 52],
        ['code' => '8964008801117', 'name' => 'Harpic 500 ml',              'cat' => 'Household',     'price' => 520,  'tax' => 18, 'stock' => 26],
        ['code' => '8964008802114', 'name' => 'Rose Petal tissue box',      'cat' => 'Household',     'price' => 260,  'tax' => 18, 'stock' => 7],
    ],

    /** Regulars who buy on khata (running credit) — the counter reality here. */
    'khata' => [
        ['name' => 'Rashid — Flat 302',   'phone' => '0300 2214477', 'balance' => 8450],
        ['name' => 'Naeem Bhai (tailor)', 'phone' => '0321 3390182', 'balance' => 3120],
        ['name' => 'Zubair — corner shop','phone' => '0345 2098311', 'balance' => 15600],
    ],
];
