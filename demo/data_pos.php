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

    /* ------------------------------------------------------------------
     * RESTAURANT
     * Menu prices are quoted EXCLUSIVE of tax — that is how a Pakistani menu
     * card works, tax is added on the bill. And the tax is Sindh Revenue Board
     * at 15% on the service, not FBR's 18% on goods: a restaurant in Karachi
     * files to SRB, which is a different authority and a different portal.
     * ------------------------------------------------------------------ */
    'resto' => [
        'name'   => 'Karachi Kitchen',
        'branch' => 'Bahadurabad, Karachi',
        'ntn'    => '4417820-6',
        'srb'    => 'S-3311-2049-8',
        'tax'    => 15,
        'waiter' => 'Faisal',
        'tables' => ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9', 'T10'],
        'categories' => ['Karahi & Handi', 'BBQ', 'Fast food', 'Rice', 'Breads', 'Drinks', 'Dessert'],
        'menu' => [
            ['code' => 'M101', 'name' => 'Chicken Karahi — full',   'cat' => 'Karahi & Handi', 'price' => 2400],
            ['code' => 'M102', 'name' => 'Chicken Karahi — half',   'cat' => 'Karahi & Handi', 'price' => 1300],
            ['code' => 'M103', 'name' => 'Mutton Karahi — full',    'cat' => 'Karahi & Handi', 'price' => 4200],
            ['code' => 'M104', 'name' => 'Chicken White Handi',     'cat' => 'Karahi & Handi', 'price' => 1650],
            ['code' => 'M201', 'name' => 'Chicken Tikka (1 pc)',    'cat' => 'BBQ',            'price' => 480],
            ['code' => 'M202', 'name' => 'Seekh Kabab (4 pcs)',     'cat' => 'BBQ',            'price' => 720],
            ['code' => 'M203', 'name' => 'Malai Boti',              'cat' => 'BBQ',            'price' => 890],
            ['code' => 'M204', 'name' => 'Beef Bihari Boti',        'cat' => 'BBQ',            'price' => 950],
            ['code' => 'M301', 'name' => 'Zinger Burger',           'cat' => 'Fast food',      'price' => 690],
            ['code' => 'M302', 'name' => 'Club Sandwich',           'cat' => 'Fast food',      'price' => 620],
            ['code' => 'M303', 'name' => 'Loaded Fries',            'cat' => 'Fast food',      'price' => 450],
            ['code' => 'M401', 'name' => 'Chicken Biryani',         'cat' => 'Rice',           'price' => 550],
            ['code' => 'M402', 'name' => 'Mutton Pulao',            'cat' => 'Rice',           'price' => 780],
            ['code' => 'M403', 'name' => 'Special Fried Rice',      'cat' => 'Rice',           'price' => 520],
            ['code' => 'M501', 'name' => 'Roghni Naan',             'cat' => 'Breads',         'price' => 90],
            ['code' => 'M502', 'name' => 'Garlic Naan',             'cat' => 'Breads',         'price' => 130],
            ['code' => 'M503', 'name' => 'Tandoori Roti',           'cat' => 'Breads',         'price' => 40],
            ['code' => 'M601', 'name' => 'Fresh Lime',              'cat' => 'Drinks',         'price' => 260],
            ['code' => 'M602', 'name' => 'Mint Margarita',          'cat' => 'Drinks',         'price' => 380],
            ['code' => 'M603', 'name' => 'Soft drink (regular)',    'cat' => 'Drinks',         'price' => 150],
            ['code' => 'M604', 'name' => 'Kashmiri Chai',           'cat' => 'Drinks',         'price' => 320],
            ['code' => 'M701', 'name' => 'Gulab Jamun (2 pcs)',     'cat' => 'Dessert',        'price' => 280],
            ['code' => 'M702', 'name' => 'Kheer',                   'cat' => 'Dessert',        'price' => 300],
        ],
    ],

    /* ------------------------------------------------------------------
     * PHARMACY
     * Every line is a batch, because a chemist does not sell "Panadol" — they
     * sell a specific batch with an expiry date, and the till must pick the
     * one expiring first. Most medicines carry no sales tax; OTC and cosmetic
     * lines do.
     * ------------------------------------------------------------------ */
    'pharma' => [
        'name'      => 'Al-Shifa Pharmacy',
        'branch'    => 'Tariq Road, Karachi',
        'ntn'       => '3390142-7',
        'strn'      => '32-77-9014-227-11',
        'licence'   => 'DRAP/RS/SD-4471',
        'pharmacist'=> 'Dr. Sana Iqbal',
        'categories'=> ['Painkillers', 'Antibiotics', 'Chronic', 'Stomach', 'Baby & OTC', 'Devices'],
        'items' => [
            ['code' => 'P1001', 'name' => 'Panadol 500mg (10 tabs)',   'cat' => 'Painkillers', 'price' => 90,   'tax' => 0,
             'batches' => [['b' => 'PN-2411', 'exp' => '2026-09-30', 'qty' => 40], ['b' => 'PN-2503', 'exp' => '2027-06-30', 'qty' => 180]]],
            ['code' => 'P1002', 'name' => 'Brufen 400mg (10 tabs)',    'cat' => 'Painkillers', 'price' => 165,  'tax' => 0,
             'batches' => [['b' => 'BR-2402', 'exp' => '2027-02-28', 'qty' => 95]]],
            ['code' => 'P1003', 'name' => 'Ponstan Forte (10 tabs)',   'cat' => 'Painkillers', 'price' => 285,  'tax' => 0,
             'batches' => [['b' => 'PF-2312', 'exp' => '2026-08-31', 'qty' => 12], ['b' => 'PF-2504', 'exp' => '2027-11-30', 'qty' => 60]]],
            ['code' => 'P2001', 'name' => 'Augmentin 625mg (14 tabs)', 'cat' => 'Antibiotics', 'price' => 1480, 'tax' => 0,
             'batches' => [['b' => 'AG-2409', 'exp' => '2027-04-30', 'qty' => 26]]],
            ['code' => 'P2002', 'name' => 'Azomax 500mg (6 caps)',     'cat' => 'Antibiotics', 'price' => 690,  'tax' => 0,
             'batches' => [['b' => 'AZ-2408', 'exp' => '2026-10-31', 'qty' => 18], ['b' => 'AZ-2502', 'exp' => '2027-08-31', 'qty' => 44]]],
            ['code' => 'P2003', 'name' => 'Flagyl 400mg (20 tabs)',    'cat' => 'Antibiotics', 'price' => 320,  'tax' => 0,
             'batches' => [['b' => 'FG-2501', 'exp' => '2028-01-31', 'qty' => 70]]],
            ['code' => 'P3001', 'name' => 'Glucophage 500mg (30)',     'cat' => 'Chronic',     'price' => 240,  'tax' => 0,
             'batches' => [['b' => 'GP-2405', 'exp' => '2027-05-31', 'qty' => 130]]],
            ['code' => 'P3002', 'name' => 'Concor 5mg (14 tabs)',      'cat' => 'Chronic',     'price' => 560,  'tax' => 0,
             'batches' => [['b' => 'CN-2310', 'exp' => '2026-09-15', 'qty' => 9], ['b' => 'CN-2506', 'exp' => '2028-03-31', 'qty' => 55]]],
            ['code' => 'P3003', 'name' => 'Tenormin 50mg (14 tabs)',   'cat' => 'Chronic',     'price' => 410,  'tax' => 0,
             'batches' => [['b' => 'TN-2411', 'exp' => '2027-10-31', 'qty' => 48]]],
            ['code' => 'P4001', 'name' => 'Risek 20mg (14 caps)',      'cat' => 'Stomach',     'price' => 520,  'tax' => 0,
             'batches' => [['b' => 'RS-2404', 'exp' => '2027-03-31', 'qty' => 62]]],
            ['code' => 'P4002', 'name' => 'ENO sachet',                'cat' => 'Stomach',     'price' => 60,   'tax' => 18,
             'batches' => [['b' => 'EN-2502', 'exp' => '2027-12-31', 'qty' => 210]]],
            ['code' => 'P5001', 'name' => 'Pampers medium (18)',       'cat' => 'Baby & OTC',  'price' => 1350, 'tax' => 18,
             'batches' => [['b' => 'PM-2503', 'exp' => '2029-01-31', 'qty' => 24]]],
            ['code' => 'P5002', 'name' => 'Cerelac wheat 350g',        'cat' => 'Baby & OTC',  'price' => 1180, 'tax' => 0,
             'batches' => [['b' => 'CR-2412', 'exp' => '2026-11-30', 'qty' => 15]]],
            ['code' => 'P5003', 'name' => 'Dettol 500ml',              'cat' => 'Baby & OTC',  'price' => 690,  'tax' => 18,
             'batches' => [['b' => 'DT-2501', 'exp' => '2028-06-30', 'qty' => 33]]],
            ['code' => 'P6001', 'name' => 'BP monitor (digital)',      'cat' => 'Devices',     'price' => 6900, 'tax' => 18,
             'batches' => [['b' => 'BPM-24', 'exp' => '2030-12-31', 'qty' => 6]]],
            ['code' => 'P6002', 'name' => 'Glucometer strips (50)',    'cat' => 'Devices',     'price' => 2450, 'tax' => 0,
             'batches' => [['b' => 'GS-2410', 'exp' => '2026-12-31', 'qty' => 21]]],
        ],
    ],

    /** Regulars who buy on khata (running credit) — the counter reality here. */
    'khata' => [
        ['name' => 'Rashid — Flat 302',   'phone' => '0300 2214477', 'balance' => 8450],
        ['name' => 'Naeem Bhai (tailor)', 'phone' => '0321 3390182', 'balance' => 3120],
        ['name' => 'Zubair — corner shop','phone' => '0345 2098311', 'balance' => 15600],
    ],
];
