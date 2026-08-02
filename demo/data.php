<?php
// VectorERP demo — seeded sample data for a fictional Karachi trading business.
// No database required: this file is the whole dataset. Session holds any edits
// the visitor makes, so the demo feels live and resets itself on logout.
declare(strict_types=1);

return [
    'company' => [
        'name'  => 'Al-Karam Traders (Pvt) Ltd',
        'city'  => 'Karachi',
        'strn'  => '32-77-8842-991-73',
        'ntn'   => '7702451-8',
        'fy'    => '2026-27',
    ],

    'kpis' => [
        ['label' => 'Sales — August',   'value' => 'Rs 42,60,000', 'delta' => '▲ 12.4% vs July', 'dir' => 'up'],
        ['label' => 'Receivables',      'value' => 'Rs 9,15,200',  'delta' => '14 invoices due',  'dir' => 'down'],
        ['label' => 'Stock value',      'value' => 'Rs 1.86 Cr',   'delta' => '3 warehouses',     'dir' => 'up'],
        ['label' => 'Cash & bank',      'value' => 'Rs 12,84,500', 'delta' => '▲ 8.2% this week', 'dir' => 'up'],
    ],

    'invoices' => [
        ['no' => 'INV-2041', 'date' => '02 Aug 2026', 'party' => 'Karachi Textiles (Pvt) Ltd', 'excl' => 385000, 'tax' => 69300, 'status' => 'paid',    'fbr' => '7000007DI1754120041593'],
        ['no' => 'INV-2040', 'date' => '01 Aug 2026', 'party' => 'Indus Foods (Pvt) Ltd',      'excl' => 142600, 'tax' => 25668, 'status' => 'due',     'fbr' => '7000007DI1754120040118'],
        ['no' => 'INV-2039', 'date' => '31 Jul 2026', 'party' => 'Sialkot Sports Co.',         'excl' => 690150, 'tax' => 124227,'status' => 'paid',    'fbr' => '7000007DI1753980039442'],
        ['no' => 'INV-2038', 'date' => '28 Jul 2026', 'party' => 'Multan Traders',             'excl' => 88400,  'tax' => 15912, 'status' => 'overdue', 'fbr' => '7000007DI1753720038907'],
        ['no' => 'INV-2037', 'date' => '26 Jul 2026', 'party' => 'Hyderabad Mart',             'excl' => 210000, 'tax' => 37800, 'status' => 'paid',    'fbr' => '7000007DI1753550037214'],
        ['no' => 'INV-2036', 'date' => '24 Jul 2026', 'party' => 'Faisalabad Fabrics',         'excl' => 512300, 'tax' => 92214, 'status' => 'due',     'fbr' => '7000007DI1753380036655'],
    ],

    'invoice_lines' => [
        'INV-2041' => [
            ['item' => 'Cotton fabric — 40s count', 'hs' => '5208.1100', 'uom' => 'Than', 'qty' => 120, 'rate' => 2450],
            ['item' => 'Polyester blend — grey',    'hs' => '5407.6100', 'uom' => 'Than', 'qty' => 35,  'rate' => 1860],
            ['item' => 'Stitching service — bulk',  'hs' => '9988.0000', 'uom' => 'Job',  'qty' => 1,   'rate' => 25900],
        ],
    ],

    'stock' => [
        ['item' => 'Cotton 40s (than)',      'loc' => 'Karachi — Godown A', 'qty' => 1240, 'reorder' => 400, 'value' => 3038000],
        ['item' => 'Polyester blend grey',   'loc' => 'Lahore — Warehouse', 'qty' => 312,  'reorder' => 300, 'value' => 580320],
        ['item' => 'Dye — Navy #204',        'loc' => 'Factory store',      'qty' => 18,   'reorder' => 50,  'value' => 46800],
        ['item' => 'Packing cartons (L)',    'loc' => 'Karachi — Shop',     'qty' => 2900, 'reorder' => 500, 'value' => 174000],
        ['item' => 'Thread white 5000m',     'loc' => 'Factory store',      'qty' => 64,   'reorder' => 80,  'value' => 51200],
        ['item' => 'Buttons — assorted',     'loc' => 'Karachi — Godown A', 'qty' => 15400,'reorder' => 5000,'value' => 92400],
    ],

    'pipeline' => [
        'New inquiry' => [
            ['party' => 'Faisalabad Fabrics', 'note' => 'Bulk grey cloth — call received', 'amount' => 850000],
            ['party' => 'Hyderabad Mart',     'note' => 'Retail supply inquiry',           'amount' => 210000],
        ],
        'Quoted' => [
            ['party' => 'Sialkot Sports Co.', 'note' => 'QT-402 sent · follow up Monday',  'amount' => 690000],
            ['party' => 'Indus Foods (Pvt)',  'note' => 'QT-399 — negotiating rate',       'amount' => 340000],
        ],
        'Won' => [
            ['party' => 'Karachi Textiles',   'note' => 'PO received → INV-2041',          'amount' => 454300],
            ['party' => 'Multan Traders',     'note' => 'Advance received 50%',            'amount' => 176800],
        ],
    ],

    'ledger' => [
        ['date' => '01 Jul 2026', 'narration' => 'Opening balance',                  'debit' => 0,      'credit' => 0,      'balance' => 1845000],
        ['date' => '08 Jul 2026', 'narration' => 'Sales — INV-2031',                  'debit' => 312000, 'credit' => 0,      'balance' => 2157000],
        ['date' => '12 Jul 2026', 'narration' => 'Payment received — Indus Foods',    'debit' => 0,      'credit' => 142600, 'balance' => 2014400],
        ['date' => '19 Jul 2026', 'narration' => 'Purchase — raw cotton (GRN-118)',   'debit' => 0,      'credit' => 486000, 'balance' => 1528400],
        ['date' => '26 Jul 2026', 'narration' => 'Sales — INV-2037',                  'debit' => 247800, 'credit' => 0,      'balance' => 1776200],
        ['date' => '02 Aug 2026', 'narration' => 'Sales — INV-2041',                  'debit' => 454300, 'credit' => 0,      'balance' => 2230500],
    ],

    'payroll' => [
        ['name' => 'Muhammad Zahid',  'role' => 'Accounts Manager', 'basic' => 95000, 'allow' => 18000, 'eobi' => 370, 'days' => 30],
        ['name' => 'Imran Khan',      'role' => 'Store Incharge',   'basic' => 62000, 'allow' => 9000,  'eobi' => 370, 'days' => 30],
        ['name' => 'Salman Raza',     'role' => 'Sales Officer',    'basic' => 55000, 'allow' => 12000, 'eobi' => 370, 'days' => 28],
        ['name' => 'Ayesha Siddiqui', 'role' => 'Front Desk',       'basic' => 45000, 'allow' => 6000,  'eobi' => 370, 'days' => 30],
    ],
];
