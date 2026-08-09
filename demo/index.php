<?php
/**
 * VectorERP — public demo sandbox (vectorsolution.it/demo)
 *
 * Self-contained: no database, no configuration. Sample data lives in data.php;
 * anything the visitor changes is held in their own session and disappears on
 * logout, so every visitor gets a clean company.
 *
 * The layout is deliberately NOT the dark-sidebar admin template every other
 * ERP demo uses — a prospect who has just been shown a competitor's screen
 * must not see the same screen here with different colours. The visual system
 * is the site's own drafting-paper language brought inside the product:
 * numbered sheet tabs instead of a sidebar, documents as paper sheets with
 * corner ticks, and a live FBR compliance rail no generic panel carries.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/guard.php';

@ini_set('display_errors', '0');
// Own cookie name: the public sandbox must not share a session with the private
// inbox, so logging out here can never log the owner out of inquiries.php.
ini_set('session.use_strict_mode', '1');
session_name('VDEMOSESS');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => true]);
session_start();

const DEMO_USER = 'demo';
const DEMO_PASS = 'demo';
const WA = '923022219093';

$DATA = require __DIR__ . '/data.php';
if (!empty($_SESSION['biz'])) { $DATA['company']['name'] = (string)$_SESSION['biz']; }

/** Server-side analytics: same file the site beacon writes to. Never blocks the page. */
function demoTrack(string $event, string $page = ''): void {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (preg_match('/bot|crawl|spider|preview|whatsapp|curl|python|headless/i', $ua)) { return; }
    // Same throttle as the site beacon: this writes to the same shared log, so
    // leaving it open would simply move the flooding problem here.
    if (!vit_rate_allow('beacon', 120, 600)) { return; }
    $dir = VIT_PRIVATE;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { return; }
    $file = $dir . '/analytics.jsonl';
    if (is_file($file) && (int)@filesize($file) > 40 * 1024 * 1024) { @rename($file, $file . '.1'); }
    $row = [
        't' => date('Y-m-d H:i:s'), 'e' => $event, 'p' => $page ?: '/demo/',
        'v' => vit_visitor_hash(),
        'r' => substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 160),
        'm' => preg_match('/Mobile|Android|iPhone/i', $ua) ? 1 : 0,
    ];
    @file_put_contents($file, (string)json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
}

/* ---------- which demo is this visitor here for? ---------- */
$TRACKS = [
    'pos' => [
        'Shop / general store', 'Kiryana, mart, hardware, garments', '#0B8043',
        'Scan a barcode, take cash or JazzCash, print an 80 mm receipt with the FBR number on it.',
        ['Barcode scanning', 'Thermal receipt', 'Khata / udhaar', 'Day close'],
        'M3 3h2l.4 2M7 13h10l3-8H5.4M7 13L5.4 5M7 13l-2 5h13M10 21a1 1 0 100-2 1 1 0 000 2zM17 21a1 1 0 100-2 1 1 0 000 2z',
    ],
    'resto' => [
        'Restaurant / café', 'Dine-in, takeaway, delivery', '#B7791F',
        'Open a table, send the order to the kitchen printer, then bill it — with Sindh service tax, not FBR.',
        ['Table plan', 'Kitchen tickets', 'SRB 15% service tax', 'Split by table'],
        'M4 3v8a3 3 0 003 3v7M7 3v6M10 3v6M17 3c-1.5 2-2 4-2 6s.5 3 2 3v9',
    ],
    'pharma' => [
        'Pharmacy / chemist', 'Batch and expiry matter here', '#0E7490',
        'Every sale picks the batch expiring first, expired stock cannot be sold, and the receipt carries batch and expiry.',
        ['Batch tracking', 'Expiry watch', 'FEFO picking', 'DRAP licence on receipt'],
        'M10.5 20.5a4.95 4.95 0 01-7-7l7-7a4.95 4.95 0 017 7zM8.5 8.5l7 7',
    ],
    'fbr' => [
        'FBR digital invoicing', 'Sales-tax registered business', '#2E5BDB',
        'Raise a sales tax invoice, file it with FBR, get the invoice number and QR back, print the statutory document.',
        ['Live FBR filing', 'Invoice number + QR', 'Sales tax register', '72-hour corrections'],
        'M6 3h9l4 4v14H6zM14 3v5h5M9 13h6M9 17h4',
    ],
    'erp' => [
        'Full business ERP', 'Trading, distribution, manufacturing', '#1E3FA6',
        'Accounts, stock across godowns, receivables, CRM pipeline and payroll — one system instead of six files.',
        ['Accounting & ledger', 'Inventory', 'CRM', 'Payroll & EOBI'],
        'M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8',
    ],
    'custom' => [
        'Something built for you', 'No off-the-shelf system fits', '#FF5A2D',
        'Field apps, approval workflows, integrations with machines or portals — software shaped around how you actually work.',
        ['Web & mobile apps', 'Workflow automation', 'System integration', 'Legacy rebuilds'],
        'M12 2l2.4 6.9L21 9.6l-5 4.6 1.4 7-5.4-3.4L6.6 21 8 14.2l-5-4.6 6.6-.7z',
    ],
];

if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }

/* Choosing a track is the way in — no login wall in front of a sales demo. */
$wanted = (string)($_GET['track'] ?? $_POST['track'] ?? '');
if ($wanted !== '' && isset($TRACKS[$wanted])) {
    // Their own shop name, printed on every document from here on. Seeing your
    // own name on a real sales tax invoice is the moment a demo stops being a
    // demo, so it is worth the one field.
    if (isset($_POST['biz'])) {
        $biz = trim(preg_replace('/[\r\n\t\0]+/', ' ', (string)$_POST['biz']));
        $biz = mb_substr($biz, 0, 42);
        if ($biz !== '') { $_SESSION['biz'] = $biz; } else { unset($_SESSION['biz']); }
    }
    if (empty($_SESSION['demo_ok'])) { session_regenerate_id(true); }
    $_SESSION['demo_ok'] = true;
    $_SESSION['track'] = $wanted;
    // Generic event keeps the inbox "demo opens" figure continuous; the second
    // one says which demo they picked, which is the useful sales signal.
    demoTrack('demo_login');
    demoTrack('demo_start_' . $wanted);
    $to = ['pos' => 'pos.php?mode=retail', 'resto' => 'pos.php?mode=resto', 'pharma' => 'pos.php?mode=pharma',
           'fbr' => 'index.php?p=invoices', 'custom' => 'index.php?p=custom',
           'erp' => 'index.php?p=dashboard'][$wanted] ?? 'index.php?p=dashboard';
    header('Location: ' . $to); exit;
}
$track = (string)($_SESSION['track'] ?? 'erp');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['user'])) {
    if ($_POST['user'] === DEMO_USER && ($_POST['pass'] ?? '') === DEMO_PASS) {
        session_regenerate_id(true);
        $_SESSION['demo_ok'] = true;
        demoTrack('demo_login');
        header('Location: index.php'); exit;
    }
    $loginError = 'Use demo / demo — they are printed on the form for you.';
}
$authed = !empty($_SESSION['demo_ok']);

/* ---------- interactive bits (session only) ---------- */
if ($authed && isset($_GET['pay'])) {
    // Only a real invoice number may be marked paid — otherwise every made-up
    // ?pay= value would add another entry to the session file, without limit.
    $payNo = (string)$_GET['pay'];
    $known = array_column(array_merge($_SESSION['custom_invoices'] ?? [], $DATA['invoices']), 'no');
    if (in_array($payNo, $known, true)) {
        $_SESSION['paid'][$payNo] = true;
    }
    header('Location: index.php?p=invoices&done=' . urlencode($payNo)); exit;
}
$paid = $_SESSION['paid'] ?? [];

/* Visitor-created invoices live in the session only — every visitor gets a clean company. */
$custom      = $_SESSION['custom_invoices'] ?? [];
$customLines = $_SESSION['custom_lines'] ?? [];
$allInvoices = array_merge($custom, $DATA['invoices']);

if ($authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['p'] ?? '') === 'new_invoice') {
    $party = trim(mb_substr((string)($_POST['party'] ?? ''), 0, 80));
    $items = [];
    $descs = (array)($_POST['idesc'] ?? []);
    $hss   = (array)($_POST['ihs'] ?? []);
    $uoms  = (array)($_POST['iuom'] ?? []);
    $qtys  = (array)($_POST['iqty'] ?? []);
    $rates = (array)($_POST['irate'] ?? []);
    // The form offers a handful of rows; the cap stops a crafted post from
    // pushing thousands of lines into the session file on disk.
    $descs = array_slice($descs, 0, 25, true);
    foreach ($descs as $k => $d) {
        $d = trim(mb_substr((string)$d, 0, 90));
        $qty = min(max((float)($qtys[$k] ?? 0), 0), 9_999_999);
        $rate = min(max((float)($rates[$k] ?? 0), 0), 99_999_999);
        if ($d !== '' && $qty > 0 && $rate > 0) {
            $items[] = [
                'item' => $d,
                'hs'   => trim(mb_substr((string)($hss[$k] ?? ''), 0, 12)) ?: '0000.0000',
                'uom'  => trim(mb_substr((string)($uoms[$k] ?? ''), 0, 16)) ?: 'Unit',
                'qty'  => $qty,
                'rate' => $rate,
            ];
        }
    }
    if ($party === '' || !$items) {
        header('Location: index.php?p=new_invoice&err=1'); exit;
    }
    if (count($custom) >= 10) {
        header('Location: index.php?p=invoices&full=1'); exit;
    }
    $excl = 0; foreach ($items as $it) { $excl += $it['qty'] * $it['rate']; }
    $excl = (int)round($excl);
    $no = 'INV-' . (2042 + count($custom));
    $inv = [
        'no' => $no, 'date' => date('d M Y'), 'party' => $party,
        'excl' => $excl, 'tax' => (int)round($excl * 0.18), 'status' => 'due',
        'fbr' => '7000007DI' . substr((string)(int)(microtime(true) * 1000), -13),
    ];
    array_unshift($custom, $inv);
    $_SESSION['custom_invoices'] = $custom;
    $_SESSION['custom_lines'][$no] = $items;
    demoTrack('demo_invoice_created');
    header('Location: index.php?p=invoices&created=' . urlencode($no)); exit;
}

// Landing on /demo/ with nothing in the query always means "show me the menu",
// even for someone who picked a demo earlier — otherwise a returning visitor
// clicking "Live demo" is dropped into whichever one they saw last.
$bare = !isset($_GET['p']);
$p = $_GET['p'] ?? 'dashboard';
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$rs = static fn($n): string => 'Rs ' . number_format((float)$n);
/** Pakistani lakh/crore formatting — the way the numbers are actually said here. */
$pk = static function ($n): string {
    $n = (float)$n;
    if ($n >= 10000000) return 'Rs ' . rtrim(rtrim(number_format($n / 10000000, 2), '0'), '.') . ' Cr';
    if ($n >= 100000)   return 'Rs ' . rtrim(rtrim(number_format($n / 100000, 2), '0'), '.') . ' Lac';
    return 'Rs ' . number_format($n);
};

/** Amount → words in Pakistani notation (lakh / crore), e.g. 454300 → "Four Lakh Fifty Four Thousand Three Hundred".
    Accepts int or float — visitor-entered quantities arrive as floats, and strict_types
    would otherwise fatal mid-page exactly where the words print. */
function pkWords(int|float $n): string {
    $n = (int)round($n);
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $two = static function (int $x) use ($ones, $tens): string {
        if ($x < 20) return $ones[$x];
        return trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]);
    };
    if ($n === 0) return 'Zero';
    $parts = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$div, $label]) {
        if ($n >= $div) {
            $q = intdiv($n, $div);
            $parts[] = ($div === 100 ? $ones[$q] : ($q < 100 ? $two($q) : pkWords($q))) . ' ' . $label;
            $n %= $div;
        }
    }
    if ($n > 0) $parts[] = $two($n);
    return implode(' ', $parts);
}

/* ---------- print-ready documents (open in a new tab, save as PDF from the print dialog) ---------- */
if ($authed && ($p === 'print_invoice' || $p === 'print_report')) {
    demoTrack($p === 'print_invoice' ? 'demo_pdf_invoice' : 'demo_pdf_report', '/demo/?p=' . $p);
    $printCss = '
      *{box-sizing:border-box;margin:0;padding:0}
      body{font-family:"Segoe UI",Arial,sans-serif;background:#eef2f8;color:#1f2937;padding:22px}
      .toolbar{max-width:820px;margin:0 auto 14px;display:flex;gap:10px;align-items:center}
      .toolbar a,.toolbar button{border:0;border-radius:999px;padding:10px 20px;font:600 13px "Segoe UI",sans-serif;cursor:pointer;text-decoration:none}
      .tb-back{background:#fff;color:#0F1B33;border:1px solid #cbd5e1}
      .tb-print{background:#2E5BDB;color:#fff}
      .toolbar span{font-size:12px;color:#55617D}
      .sheet{max-width:820px;margin:0 auto;background:#fff;border-radius:6px;box-shadow:0 16px 40px rgba(15,33,63,.15);overflow:hidden}
      .stripe{height:10px;background:linear-gradient(90deg,#0F1B33,#2E5BDB 55%,#5B8CFF)}
      .pad{padding:26px 30px 28px}
      .lh{text-align:center;border-bottom:2px solid #0F1B33;padding-bottom:11px;margin-bottom:14px}
      .lh h1{font-size:22px;letter-spacing:.05em;color:#0F1B33}
      .lh .sub{font-size:10.5px;color:#475569;margin-top:3px;line-height:1.6}
      .lh .reg{font-size:10px;color:#0F1B33;margin-top:5px;font-weight:700}
      .title{text-align:center;margin:12px 0 14px}
      .title span{display:inline-block;background:#0F1B33;color:#fff;font-size:12px;font-weight:800;letter-spacing:.15em;padding:6px 24px;border-radius:4px}
      .band-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
      .band{border:1px solid #cbd5e1;border-radius:6px;overflow:hidden}
      .band h4{background:#f1f5f9;font-size:9px;letter-spacing:.1em;text-transform:uppercase;color:#0F1B33;padding:5px 10px;border-bottom:1px solid #cbd5e1;font-weight:800}
      .band .bd{padding:8px 10px;font-size:10.5px;line-height:1.65}
      .band .kv{display:flex;justify-content:space-between;gap:8px}
      .band .kv span:first-child{color:#64748b}
      table{width:100%;border-collapse:collapse;margin-bottom:10px}
      th{background:#0F1B33;color:#fff;font-size:9px;letter-spacing:.05em;padding:7px 8px;text-align:left;font-weight:700}
      th.num,td.num{text-align:right;font-variant-numeric:tabular-nums}
      td{font-size:10.5px;padding:7px 8px;border-bottom:1px solid #e2e8f0;vertical-align:top}
      tfoot td{font-weight:700;color:#0F1B33;border-bottom:0}
      tfoot tr.grand td{border-top:2px solid #0F1B33;font-size:12px;padding-top:9px}
      .words{border-top:1px solid #0F1B33;border-bottom:1px solid #0F1B33;padding:7px 3px;margin:8px 0 12px;font-size:10.5px;color:#0F1B33}
      .fbr{display:inline-flex;align-items:center;gap:5px;background:#DCF5E7;color:#14713F;border-radius:999px;padding:3px 10px;font-size:9px;font-weight:800;font-family:Consolas,monospace}
      .fbr::before{content:"";width:6px;height:6px;border-radius:50%;background:#14713F}
      .qr{width:64px;height:64px;border-radius:5px;background:repeating-linear-gradient(0deg,#0F1B33 0 3px,transparent 3px 6px),repeating-linear-gradient(90deg,#0F1B33 0 3px,#fff 3px 6px);opacity:.9}
      .bottom{display:flex;justify-content:space-between;align-items:flex-end;gap:16px}
      .bottom .note{font-size:9.5px;color:#64748b;line-height:1.6;max-width:420px}
      .sign{margin-top:26px;display:flex;justify-content:flex-end}
      .sign div{width:210px;border-top:1px solid #0F1B33;padding-top:5px;text-align:center;font-size:10px;font-weight:700;color:#0F1B33}
      .foot{margin-top:14px;border-top:1px solid #e2e8f0;padding-top:8px;text-align:center;font-size:8.5px;color:#94a3b8;line-height:1.7}
      @media print{
        body{background:#fff;padding:0}
        .toolbar{display:none}
        .sheet{max-width:none;box-shadow:none;border-radius:0}
        @page{size:A4;margin:10mm}
      }';
    $company = $DATA['company'];

    if ($p === 'print_invoice') {
        $no = (string)($_GET['no'] ?? 'INV-2041');
        $inv = null;
        foreach ($allInvoices as $i) { if ($i['no'] === $no) { $inv = $i; break; } }
        if (!$inv) { $inv = $DATA['invoices'][0]; $no = $inv['no']; }
        $lines = $customLines[$no] ?? $DATA['invoice_lines'][$no] ?? [
            ['item' => 'Goods as per delivery challan ' . str_replace('INV', 'DC', $no), 'hs' => '5208.1100', 'uom' => 'Lot', 'qty' => 1, 'rate' => $inv['excl']],
        ];
        $subtotal = 0; foreach ($lines as $l) { $subtotal += $l['qty'] * $l['rate']; }
        $subtotal = (int)round($subtotal);
        $tax = (int)round($subtotal * 0.18);
        $total = $subtotal + $tax;
        ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="robots" content="noindex">
        <title><?= $e($no) ?> — Sales Tax Invoice</title><style><?= $printCss ?></style></head><body>
        <div class="toolbar">
          <a class="tb-back" href="index.php?p=invoices">← Back to demo</a>
          <button class="tb-print" onclick="window.print()">🖨 Print / Save as PDF</button>
          <span>In the print window choose <b>“Save as PDF”</b> — that file is what your customer receives.</span>
        </div>
        <div class="sheet"><div class="stripe"></div><div class="pad">
          <div class="lh">
            <h1><?= $e(strtoupper($company['name'])) ?></h1>
            <div class="sub">General Order Suppliers &amp; Textile Traders · <?= $e($company['city']) ?>, Pakistan · Tel: +92 21 XXXX XXXX</div>
            <div class="reg">NTN <?= $e($company['ntn']) ?> &nbsp;|&nbsp; STRN <?= $e($company['strn']) ?></div>
          </div>
          <div class="title"><span>SALES TAX INVOICE</span></div>
          <div class="band-row">
            <div class="band"><h4>Billed To</h4><div class="bd">
              <b><?= $e($inv['party']) ?></b><br>Karachi, Sindh<br>NTN: 0000000-0 · STRN: 00-00-0000-000-00
            </div></div>
            <div class="band"><h4>Document Info</h4><div class="bd">
              <div class="kv"><span>Invoice No.</span><b><?= $e($no) ?></b></div>
              <div class="kv"><span>Date</span><b><?= $e($inv['date']) ?></b></div>
              <div class="kv"><span>FY</span><b><?= $e($company['fy']) ?></b></div>
              <div class="kv"><span>FBR Invoice No.</span><b style="font-family:Consolas,monospace;font-size:9.5px"><?= $e($inv['fbr']) ?></b></div>
            </div></div>
          </div>
          <table>
            <thead><tr><th style="width:4%">#</th><th>Description</th><th style="width:11%">HS Code</th><th class="num" style="width:8%">Qty</th><th style="width:8%">UoM</th><th class="num" style="width:12%">Rate</th><th class="num" style="width:14%">Amount (PKR)</th></tr></thead>
            <tbody>
              <?php $i = 0; foreach ($lines as $l): $i++; ?>
              <tr><td><?= $i ?></td><td><?= $e($l['item']) ?></td><td><?= $e($l['hs']) ?></td>
                  <td class="num"><?= number_format($l['qty']) ?></td><td><?= $e($l['uom']) ?></td>
                  <td class="num"><?= number_format($l['rate']) ?></td><td class="num"><?= number_format($l['qty'] * $l['rate']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr><td colspan="6" class="num">Value excluding sales tax</td><td class="num"><?= number_format($subtotal) ?></td></tr>
              <tr><td colspan="6" class="num">Sales tax @ 18%</td><td class="num"><?= number_format($tax) ?></td></tr>
              <tr class="grand"><td colspan="6" class="num">TOTAL PAYABLE (PKR)</td><td class="num"><?= number_format($total) ?></td></tr>
            </tfoot>
          </table>
          <div class="words">Amount in words: <b>Rupees <?= $e(pkWords($total)) ?> Only</b></div>
          <div class="bottom">
            <div>
              <span class="fbr">FBR DIGITAL INVOICE — REGISTERED</span>
              <p class="note" style="margin-top:8px">This invoice has been electronically reported to the Federal Board of Revenue. Verify by scanning the QR code with the FBR Tax Asaan app.</p>
            </div>
            <div class="qr" aria-hidden="true"></div>
          </div>
          <div class="sign"><div>For <?= $e($company['name']) ?></div></div>
          <div class="foot">Computer-generated document produced by VectorERP · vectorsolution.it · Sample data for demonstration</div>
        </div></div>
        </body></html><?php
        exit;
    }

    /* print_report — monthly sales register */
    $tExcl = 0; $tTax = 0;
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="robots" content="noindex">
    <title>Sales Register — August 2026</title><style><?= $printCss ?></style></head><body>
    <div class="toolbar">
      <a class="tb-back" href="index.php?p=ledger">← Back to demo</a>
      <button class="tb-print" onclick="window.print()">🖨 Print / Save as PDF</button>
      <span>The same report your tax consultant needs at filing time.</span>
    </div>
    <div class="sheet"><div class="stripe"></div><div class="pad">
      <div class="lh">
        <h1><?= $e(strtoupper($company['name'])) ?></h1>
        <div class="sub">NTN <?= $e($company['ntn']) ?> · STRN <?= $e($company['strn']) ?> · FY <?= $e($company['fy']) ?></div>
      </div>
      <div class="title"><span>SALES REGISTER — AUGUST 2026</span></div>
      <table>
        <thead><tr><th style="width:12%">Date</th><th style="width:13%">Invoice</th><th>Customer</th><th class="num" style="width:14%">Excl. Tax</th><th class="num" style="width:13%">Tax 18%</th><th class="num" style="width:15%">Total (PKR)</th></tr></thead>
        <tbody>
          <?php foreach ($allInvoices as $iv): $tExcl += $iv['excl']; $tTax += $iv['tax']; ?>
          <tr><td><?= $e($iv['date']) ?></td><td><b><?= $e($iv['no']) ?></b></td><td><?= $e($iv['party']) ?></td>
              <td class="num"><?= number_format($iv['excl']) ?></td><td class="num"><?= number_format($iv['tax']) ?></td>
              <td class="num"><?= number_format($iv['excl'] + $iv['tax']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="grand"><td colspan="3">TOTAL — <?= count($allInvoices) ?> INVOICES</td>
              <td class="num"><?= number_format($tExcl) ?></td><td class="num"><?= number_format($tTax) ?></td>
              <td class="num"><?= number_format($tExcl + $tTax) ?></td></tr>
        </tfoot>
      </table>
      <div class="words">Total sales in words: <b>Rupees <?= $e(pkWords($tExcl + $tTax)) ?> Only</b></div>
      <div class="sign"><div>Prepared by VectorERP</div></div>
      <div class="foot">Computer-generated report produced by VectorERP · vectorsolution.it · Sample data for demonstration</div>
    </div></div>
    </body></html><?php
    exit;
}

/* Sheets are numbered like drawing sheets in a set — the numbering IS the nav. */
$NAV = [
    'dashboard' => ['Daftar',        'Overview'],
    'invoices'  => ['Invoices',      'Sales tax'],
    'inventory' => ['Stock',         'Godowns'],
    'crm'       => ['Pipeline',      'Sales / CRM'],
    'accounts'  => ['Accounts',      'Trial balance'],
    'ledger'    => ['Ledger',        'Cash & bank'],
    'payroll'   => ['Payroll',       'HR & EOBI'],
    'custom'    => ['Custom',        'Built for you'],
];

/* The compliance rail is computed from the same data the tables show — the
   point of it is that a generic admin panel has nowhere to put this. */
$railFiled = 0; $railTax = 0; $railDue = 0; $railDueN = 0;
foreach ($allInvoices as $iv) {
    if (!empty($iv['fbr'])) $railFiled++;
    $railTax += $iv['tax'];
    $st = isset($paid[$iv['no']]) ? 'paid' : $iv['status'];
    if ($st !== 'paid') { $railDue += $iv['excl'] + $iv['tax']; $railDueN++; }
}

/* Accounts sheet: both trial-balance columns are summed here, not copied from
   data.php — if someone edits the accounts out of balance, the mismatch prints
   on screen instead of hiding behind a hardcoded total. */
$accDr = 0; $accCr = 0; $accInc = 0; $accExp = 0; $accCogs = 0;
foreach ($DATA['accounts'] as $a) {
    $accDr += $a['dr']; $accCr += $a['cr'];
    if ($a['group'] === 'Income')   { $accInc += $a['cr'] - $a['dr']; }
    if ($a['group'] === 'Expenses') { $accExp += $a['dr'] - $a['cr']; }
    if ($a['name'] === 'Purchases (cost of goods)') { $accCogs = $a['dr'] - $a['cr']; }
}
$accProfit = $accInc - $accExp;
$accRun = $accExp - $accCogs;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>VectorERP — Live Demo</title>
<!-- The demo link is the call to action in every post, email and message we
     send, so a share of it must not render as a bare grey card. noindex keeps
     it out of search; these only control how the link looks when shared. -->
<meta property="og:title" content="Try VectorERP — free live demo, no sign-up">
<meta property="og:description" content="Pick the counter closest to your business, put your own business name on it, and print a real sales tax invoice with an FBR number. Nothing to install.">
<meta property="og:url" content="https://vectorsolution.it/demo/">
<meta property="og:type" content="website">
<meta property="og:image" content="https://vectorsolution.it/assets/og-image.jpg?v=3">
<meta property="og:image:width" content="2400">
<meta property="og:image:height" content="1260">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="https://vectorsolution.it/assets/og-image.jpg?v=3">
<link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,100..900&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--paper:#F7F9FC;--card:#fff;--ink:#0F1B33;--muted:#55617D;--faint:#93A0BC;--line:rgba(15,27,51,.12);--line-soft:rgba(15,27,51,.07);--grid:rgba(46,91,219,.07);--blue:#2E5BDB;--blue-deep:#1E3FA6;--blue-tint:#E8EEFC;--signal:#FF5A2D;--mono:"IBM Plex Mono",monospace;--disp:"Archivo",system-ui,sans-serif}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--disp);background:var(--paper);color:var(--ink);font-size:15px;line-height:1.55;-webkit-font-smoothing:antialiased}
/* The drafting paper the whole product sits on — the same surface as the
   website, so the demo is recognisably the same brand and recognisably NOT
   the admin template every competitor demo uses. */
body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px);
  background-size:56px 56px;
  mask-image:radial-gradient(ellipse 130% 100% at 50% 0%,#000 30%,transparent 100%)}
body>*{position:relative;z-index:1}
a{color:inherit;text-decoration:none}
button,input{font:inherit}
/* ---------- demo strip ---------- */
.demo-bar{background:#0F1B33;color:#C7D3EC;padding:8px 18px;font-size:.8rem;display:flex;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap;text-align:center}
.demo-bar b{color:#fff;font-weight:700}
.demo-bar a{border:1px solid rgba(255,255,255,.25);border-radius:999px;padding:3px 13px;font-weight:600;white-space:nowrap;color:#fff}
.demo-bar a:hover{background:rgba(255,255,255,.12)}
/* ---------- workspace bar (replaces the sidebar) ---------- */
.wbar{background:var(--card);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:40}
.wbar-in{max-width:1280px;margin:0 auto;padding:0 clamp(14px,2.5vw,28px);display:flex;align-items:stretch;gap:clamp(10px,2vw,26px)}
.wbrand{display:flex;align-items:center;gap:8px;font-weight:800;font-size:1rem;padding:12px 0;white-space:nowrap}
.wbrand svg{width:22px;height:22px}
.wbrand em{font-style:normal;color:var(--blue)}
.wtabs{display:flex;align-items:stretch;gap:2px;overflow-x:auto;scrollbar-width:none;flex:1}
.wtabs::-webkit-scrollbar{display:none}
.wtab{display:flex;flex-direction:column;justify-content:center;gap:1px;padding:9px 13px;border-bottom:2px solid transparent;white-space:nowrap}
.wtab i{font-style:normal;font-family:var(--mono);font-size:.58rem;letter-spacing:.14em;color:var(--faint)}
.wtab b{font-weight:600;font-size:.88rem;color:var(--muted)}
.wtab:hover b{color:var(--ink)}
.wtab.on{border-bottom-color:var(--blue)}
.wtab.on b{color:var(--ink);font-weight:700}
.wtab.on i{color:var(--blue)}
.wtab.till i{color:#0B8043}
.wplate{display:flex;flex-direction:column;justify-content:center;text-align:right;padding:9px 0;white-space:nowrap}
.wplate b{font-size:.86rem;font-weight:700;max-width:230px;overflow:hidden;text-overflow:ellipsis}
.wplate span{font-family:var(--mono);font-size:.62rem;letter-spacing:.06em;color:var(--faint)}
/* ---------- stage: sheets + compliance rail ---------- */
.stage{max-width:1280px;margin:0 auto;padding:30px clamp(14px,2.5vw,28px) 60px;display:grid;grid-template-columns:minmax(0,1fr) 248px;gap:clamp(18px,2.5vw,30px);align-items:start}
.stage.noRail{grid-template-columns:minmax(0,1fr)}
/* a sheet is a piece of paper on the board: file tab on top, corner ticks */
.sheet{background:var(--card);border:1px solid var(--line);border-radius:3px;padding:20px 22px 22px;position:relative;margin-top:30px;box-shadow:0 1px 2px rgba(15,27,51,.04),0 10px 28px rgba(30,63,166,.06)}
.sheet::after{content:"";position:absolute;right:7px;bottom:7px;width:14px;height:14px;border-right:2px solid var(--grid);border-bottom:2px solid var(--grid)}
.sheet-tab{position:absolute;top:-25px;left:12px;background:var(--card);border:1px solid var(--line);border-bottom:1px solid var(--card);border-radius:6px 6px 0 0;padding:4px 14px 3px;font-family:var(--mono);font-size:.6rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
.sheet-tab b{color:var(--blue);font-weight:500}
.sheet-act{position:absolute;top:-33px;right:0;display:flex;gap:8px}
.sheet h2{font-size:1.05rem;letter-spacing:-.01em;margin-bottom:4px}
.sheet .sub{color:var(--muted);font-size:.84rem;margin-bottom:14px}
/* meter plates */
.meters{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.meter{background:var(--card);border:1px solid var(--line);border-radius:3px;padding:13px 15px;position:relative}
.meter::before{content:"";position:absolute;left:-1px;top:10px;bottom:10px;width:3px;background:var(--blue);border-radius:0 2px 2px 0}
.meter .l{font-family:var(--mono);font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;color:var(--faint);margin-bottom:5px}
.meter .v{font-size:1.3rem;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.meter .d{font-size:.75rem;margin-top:2px;font-weight:600}
.up{color:#178A50}.down{color:#D93636}
/* register tables */
table{width:100%;border-collapse:collapse}
th{text-align:left;font-family:var(--mono);font-size:.62rem;letter-spacing:.12em;text-transform:uppercase;color:var(--faint);padding:7px 10px;border-bottom:1.5px solid var(--ink)}
td{padding:10px;border-bottom:1px solid var(--line-soft);font-size:.9rem}
tr:last-child td{border-bottom:0}
tbody tr:hover{background:var(--paper)}
.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.reg-nowrap td{white-space:nowrap;padding-left:8px;padding-right:8px}
.reg-nowrap th{padding-left:8px;padding-right:8px}
.mono{font-family:var(--mono);font-size:.78rem}
.pill{display:inline-block;padding:2px 10px;border-radius:4px;font-family:var(--mono);font-size:.66rem;font-weight:500;letter-spacing:.06em;text-transform:uppercase}
.paid{background:#DCF5E7;color:#14713F}.due{background:#FFF0D9;color:#925E10}.overdue{background:#FDE3E3;color:#B32222}.low{background:#FDE3E3;color:#B32222}.ok{background:#DCF5E7;color:#14713F}
/* One button system: same height, same radius, no mid-word wrapping — a row of
   mixed variants must sit on a single shared baseline. */
.btn-sm{background:var(--blue);color:#fff;border:0;border-radius:6px;padding:6px 14px;font-size:.78rem;font-weight:600;cursor:pointer;display:inline-block;white-space:nowrap;text-align:center;line-height:1.4;vertical-align:middle}
.btn-sm:hover{background:var(--blue-deep)}
.btn-dark{background:var(--ink)}
.btn-act{background:var(--signal)}
.btn-ghost{background:var(--card);color:var(--ink);border:1px solid var(--line);padding:5px 14px}
.btn-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px}
.btn-row .btn-sm{padding:9px 18px;font-size:.82rem}
.btn-row .btn-ghost{padding:8px 18px}
.sheet-act{align-items:center}
.sheet-act .btn-sm{padding:7px 16px;font-size:.8rem}
td.act{text-align:right;white-space:nowrap}
td.act .btn-sm{margin-left:6px}
/* Registers keep their columns on one line and scroll inside the sheet when
   the screen is narrower than the paper — buttons must never wrap mid-word. */
.tscroll{overflow-x:auto}
.fbr{display:inline-flex;align-items:center;gap:6px;background:#DCF5E7;color:#14713F;border-radius:4px;padding:3px 10px;font-size:.66rem;font-weight:600;font-family:var(--mono)}
.fbr::before{content:"";width:6px;height:6px;border-radius:50%;background:#14713F}
.note{color:var(--muted);font-size:.84rem;margin-top:12px}
.in{width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:6px;background:#fff}
.in:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(46,91,219,.12)}
.in[disabled]{background:var(--paper);color:var(--muted);border-color:var(--line-soft)}
.lab{display:block;font-family:var(--mono);font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;color:var(--faint);margin-bottom:5px}
/* ---------- the FBR compliance rail ---------- */
.rail{position:sticky;top:76px;border-left:1px solid var(--line);padding-left:18px;display:grid;gap:16px}
.rail .rt{font-family:var(--mono);font-size:.6rem;letter-spacing:.16em;text-transform:uppercase;color:var(--faint)}
.rbox{display:grid;gap:3px}
.rbox .rv{font-size:1.25rem;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.rbox .rl{font-size:.78rem;color:var(--muted)}
.rok{display:inline-flex;align-items:center;gap:7px;font-size:.8rem;font-weight:600;color:#14713F}
.rok::before{content:"";width:8px;height:8px;border-radius:50%;background:#178A50;box-shadow:0 0 0 3px rgba(23,138,80,.18)}
.rnote{font-size:.76rem;color:var(--muted);line-height:1.55;border-top:1px dashed var(--line);padding-top:12px}
.rnote b{color:var(--ink)}
.rwa{display:inline-flex;align-items:center;gap:7px;background:#0B8043;color:#fff;border-radius:6px;padding:8px 13px;font-size:.8rem;font-weight:600}
.rwa:hover{background:#096B37}
/* toasts */
.toast{background:#DCF5E7;border:1px solid #9ADFBB;color:#14713F;border-radius:6px;padding:11px 15px;margin:26px 0 -8px;font-weight:600;font-size:.88rem;grid-column:1/-1}
.toast.warn{background:#FFF0D9;border-color:#F2CE8E;color:#925E10}
.toast.err{background:#FDE3E3;border-color:#F0A9A9;color:#B32222}
/* accounts: the P&L told as one plain-words equation, then the trial balance */
.pnl{display:flex;align-items:stretch;gap:10px;flex-wrap:wrap}
.pbox{flex:1;min-width:140px;border:1px solid var(--line);border-radius:3px;padding:12px 14px;background:var(--paper)}
.pbox .l{font-family:var(--mono);font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;color:var(--faint)}
.pbox b{display:block;font-size:1.18rem;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums;margin-top:4px}
.pbox span.s{display:block;font-size:.75rem;color:var(--muted);margin-top:2px}
.pbox.profit{background:#DCF5E7;border-color:#9ADFBB}
.pbox.profit b{color:#14713F}
.pop{align-self:center;font-size:1.35rem;font-weight:800;color:var(--faint)}
.gcell{padding-top:16px;font-family:var(--mono);font-size:.62rem;letter-spacing:.12em;text-transform:uppercase;color:var(--blue);font-weight:500}
.gcell span{color:var(--faint);text-transform:none;letter-spacing:.02em}
.tb-total td{border-top:2px solid var(--ink);border-bottom:0;font-weight:800;font-variant-numeric:tabular-nums;padding-top:11px}
.tb-ok{display:flex;align-items:center;gap:8px;margin-top:14px;font-size:.88rem;font-weight:600;color:#14713F}
.tb-ok::before{content:"";width:9px;height:9px;border-radius:50%;background:#178A50;box-shadow:0 0 0 3px rgba(23,138,80,.18)}
/* kanban as index cards on the board */
.kanban{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.kcol h3{font-family:var(--mono);font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);display:flex;justify-content:space-between;margin-bottom:10px;border-bottom:1.5px solid var(--ink);padding-bottom:6px}
.kcard{background:#fff;border:1px solid var(--line);border-radius:3px;padding:11px 12px;margin-bottom:9px;box-shadow:0 1px 2px rgba(15,27,51,.05);position:relative}
.kcard::before{content:"";position:absolute;left:-1px;top:8px;bottom:8px;width:3px;background:var(--blue-tint)}
.kcard b{display:block;font-size:.88rem;margin-bottom:2px}
.kcard span{font-size:.79rem;color:var(--muted)}
.kcard .amt{display:block;font-weight:700;color:var(--blue);margin-top:5px;font-variant-numeric:tabular-nums}
.qr{width:74px;height:74px;border-radius:4px;background:repeating-linear-gradient(0deg,#0F1B33 0 4px,transparent 4px 8px),repeating-linear-gradient(90deg,#0F1B33 0 4px,#fff 4px 8px);opacity:.9}
/* custom-build page */
.cust-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}
.cust{border:1px solid var(--line);border-radius:3px;padding:16px 18px;background:#FBFCFE;position:relative}
.cust::before{content:"";position:absolute;left:-1px;top:10px;bottom:10px;width:3px;background:var(--signal);opacity:.6}
.cust h3{font-size:1rem;margin-bottom:7px}
.cust p{color:var(--muted);font-size:.87rem;line-height:1.55;margin-bottom:10px}
.cust-tag{font-family:var(--mono);font-size:.62rem;letter-spacing:.1em;text-transform:uppercase;color:var(--signal)}
.cust-steps{margin:12px 0 0 18px;display:grid;gap:9px}
.cust-steps li{font-size:.92rem;color:var(--muted)}
.cust-steps b{color:var(--ink)}
/* ---------- chooser ---------- */
.choose-wrap{max-width:1220px;margin:0 auto;padding:44px 20px 70px}
.choose-head{text-align:center;margin-bottom:34px}
.choose-head .mark{display:inline-flex;align-items:center;gap:9px;font-size:1.15rem;font-weight:800;margin-bottom:18px}
.choose-head .mark svg{width:30px;height:30px}
.choose-head .mark em{font-style:normal;color:var(--blue)}
.choose-head h1{font-size:clamp(1.7rem,4vw,2.5rem);letter-spacing:-.02em;margin-bottom:12px}
.choose-head h1 .hl{color:var(--blue);position:relative;white-space:nowrap}
.choose-head h1 .hl::after{content:"";position:absolute;left:0;right:0;bottom:.06em;height:.28em;background:rgba(255,90,45,.22);border-radius:3px;z-index:-1}
.choose-head p{color:var(--muted);max-width:640px;margin:0 auto;font-size:1rem}
.bizbox{background:var(--card);border:1px solid var(--line);border-radius:3px;padding:20px 22px;max-width:560px;margin:0 auto 40px;box-shadow:0 8px 26px rgba(30,63,166,.09);text-align:center;position:relative}
.bizbox::after{content:"";position:absolute;right:6px;bottom:6px;width:12px;height:12px;border-right:2px solid var(--grid);border-bottom:2px solid var(--grid)}
.bizbox label{display:block;font-family:var(--mono);font-size:.62rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:9px}
.bizbox input{width:100%;padding:13px 16px;border:0;border-bottom:2.5px solid var(--blue);background:var(--paper);font-size:1.05rem;font-weight:600;text-align:center;outline:none;font-family:var(--disp);border-radius:3px 3px 0 0}
.bizbox input::placeholder{color:var(--faint);font-weight:400}
.bizhint{display:block;font-size:.8rem;color:var(--muted);margin-top:10px}
.choose-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:26px 18px}
.tcard{background:var(--card);border:1px solid var(--line);border-radius:3px;padding:20px 20px 18px;display:flex;flex-direction:column;box-shadow:0 1px 3px rgba(15,27,51,.05);transition:transform .14s,box-shadow .14s,border-color .14s;position:relative;margin-top:22px;font:inherit;color:inherit;text-align:left;width:100%;cursor:pointer}
.tcard::before{content:attr(data-who);position:absolute;top:-21px;left:10px;background:var(--card);border:1px solid var(--line);border-bottom:1px solid var(--card);border-radius:6px 6px 0 0;padding:3px 12px 2px;font-family:var(--mono);font-size:.58rem;letter-spacing:.12em;text-transform:uppercase;color:var(--acc)}
.tcard::after{content:"";position:absolute;right:6px;bottom:6px;width:12px;height:12px;border-right:2px solid var(--grid);border-bottom:2px solid var(--grid)}
.tcard:hover{transform:translateY(-4px);border-color:var(--acc);box-shadow:0 16px 40px rgba(15,27,51,.13)}
.tico{width:44px;height:44px;border-radius:3px;display:flex;align-items:center;justify-content:center;background:color-mix(in srgb,var(--acc) 11%,#fff);color:var(--acc);margin-bottom:13px;border:1px solid color-mix(in srgb,var(--acc) 25%,#fff)}
.tico svg{width:22px;height:22px}
.tcard .th2{display:block;font-size:1.18rem;font-weight:700;margin:2px 0 8px;letter-spacing:-.01em}
.tcard .tp{display:block;color:var(--muted);font-size:.89rem;line-height:1.55;margin-bottom:14px}
.tfeats{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px}
.tfeats i{font-style:normal;font-family:var(--mono);font-size:.64rem;letter-spacing:.04em;background:var(--paper);border:1px solid var(--line-soft);border-radius:4px;padding:3px 9px;color:var(--muted)}
.tgo{margin-top:auto;display:inline-flex;align-items:center;gap:7px;font-weight:700;font-size:.9rem;color:var(--acc)}
.tgo svg{width:15px;height:15px}
.choose-proof{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-top:38px}
.choose-proof span{font-size:.86rem;color:var(--muted);background:var(--card);border:1px solid var(--line-soft);border-radius:3px;padding:14px 16px;line-height:1.5}
.choose-proof b{color:var(--ink)}
.choose-foot{text-align:center;color:var(--muted);font-size:.9rem;margin-top:30px}
.choose-foot a{color:var(--blue);font-weight:600;text-decoration:underline}
/* save panel */
.save-panel{background:linear-gradient(155deg,#12224A,#0F1B33);border:0;color:#fff}
.save-panel .sheet-tab{background:#12224A;border-color:rgba(255,255,255,.2);border-bottom-color:#12224A;color:#9DBAFF}
.save-panel::after{border-color:rgba(91,140,255,.35)}
.save-lede{color:rgba(233,238,249,.82);font-size:.92rem;margin-bottom:16px;max-width:62ch}
.save-lede b{color:#fff}
.save-form{display:flex;gap:10px;flex-wrap:wrap}
.save-form input{flex:1;min-width:170px;padding:11px 15px;border:1px solid rgba(255,255,255,.22);border-radius:6px;background:rgba(255,255,255,.10);color:#fff}
.save-form input::placeholder{color:rgba(233,238,249,.55)}
.save-form input:focus{outline:none;border-color:#5B8CFF;background:rgba(255,255,255,.16)}
.save-form button{background:#25D366;color:#fff;border:0;border-radius:6px;padding:11px 24px;font-weight:700;cursor:pointer;white-space:nowrap}
.save-form button:hover{background:#1FBA59}
.save-form button:disabled{opacity:.65;cursor:default}
.save-done{background:rgba(37,211,102,.16);border:1px solid rgba(37,211,102,.45);border-radius:6px;padding:13px 16px;font-size:.92rem}
.save-warn{background:rgba(255,90,45,.16);border:1px solid rgba(255,90,45,.5);border-radius:6px;padding:12px 15px;font-size:.9rem;margin-top:12px;color:#FFD3C4}
.save-alt{margin-top:14px;font-size:.86rem;color:rgba(233,238,249,.65)}
.save-alt a{color:#7BE49E;font-weight:600}
/* ---------- responsive ---------- */
/* Below 1500px the sheet-number captions make the tab row overflow; the tabs
   matter more than their captions, so the captions go first. */
@media(max-width:1500px){.wtab i{display:none}.wtab{padding:13px 11px}}
@media(max-width:1080px){
  .stage{grid-template-columns:minmax(0,1fr)}
  .rail{position:static;border-left:0;padding-left:0;border-top:1px solid var(--line);padding-top:16px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));align-items:start;order:9}
  .rail .rnote{border-top:0;padding-top:0;grid-column:1/-1}
}
@media(max-width:900px){.meters{grid-template-columns:1fr 1fr}.kanban{grid-template-columns:1fr}.save-form input{min-width:100%}.save-form button{width:100%}}
@media(max-width:700px){
  .wplate{display:none}
  .sheet{padding:16px 14px 18px}
  .sheet-act{position:static;margin:-6px 0 12px;justify-content:flex-start;flex-wrap:wrap}
  td{padding:9px 7px;font-size:.86rem}
  .pnl{flex-direction:column}
  .pop{align-self:flex-start;padding-left:6px;line-height:1}
}
</style>
</head>
<body>

<div class="demo-bar">
  <span><b>VectorERP live demo</b> — sample data, nothing you do here is saved</span>
  <?php if ($authed && !$bare && $p !== 'choose'): ?><a href="?p=choose">↔ Switch demo</a><?php endif; ?>
  <a href="/">← vectorsolution.it</a>
  <?php if ($authed && !$bare && $p !== 'choose'): ?><a href="#save" style="background:#0B8043;border-color:#0B8043">💾 Save my demo data</a><?php endif; ?>
  <a href="https://wa.me/<?= WA ?>?text=<?= rawurlencode('Hello, I have seen the VectorERP demo and would like to discuss it for my business.') ?>" target="_blank" rel="noopener">Discuss this for my business →</a>
</div>

<?php if (!$authed || $bare || $p === 'choose'): ?>
  <div class="choose-wrap">
    <div class="choose-head">
      <span class="mark">
        <svg viewBox="0 0 120 120" fill="none"><circle cx="30" cy="34" r="8" fill="#2E5BDB"/><path d="M30 34 L60 92 L81.5 50.5" stroke="#2E5BDB" stroke-width="12" stroke-linecap="round" stroke-linejoin="round"/><path d="M91 32 L91.6 50.3 L75.6 42.1 Z" fill="#2E5BDB"/></svg>
        Vector<em>ERP</em>
      </span>
      <h1>See it running with <span class="hl">your own</span> business name on it</h1>
      <p>Type your business name, pick the counter closest to yours, and you land in a working system — with your name printed on the invoice and the FBR number on it. Nothing to install, no sign-up, nothing saved.</p>
    </div>

    <form method="post" action="index.php" class="choose-form">
      <div class="bizbox">
        <label for="biz">Your business name</label>
        <input id="biz" name="biz" maxlength="42" autocomplete="organization"
               placeholder="e.g. Ahmed Kiryana Store" value="<?= $e($_SESSION['biz'] ?? '') ?>">
        <span class="bizhint">Optional — but this is the bit that makes it feel real. It goes on the receipts and invoices you print.</span>
      </div>

      <div class="choose-grid">
        <?php foreach ($TRACKS as $key => [$title, $who, $colour, $blurb, $feats, $icon]): ?>
          <button type="submit" name="track" value="<?= $e($key) ?>" class="tcard" style="--acc:<?= $e($colour) ?>" data-who="<?= $e($who) ?>">
            <span class="tico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $icon ?>"/></svg></span>
            <span class="th2"><?= $e($title) ?></span>
            <span class="tp"><?= $e($blurb) ?></span>
            <span class="tfeats"><?php foreach ($feats as $f): ?><i><?= $e($f) ?></i><?php endforeach; ?></span>
            <span class="tgo">Open this demo <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 8h11M9 3.5 13.5 8 9 12.5"/></svg></span>
          </button>
        <?php endforeach; ?>
      </div>
    </form>

    <div class="choose-proof">
      <span><b>Built for here.</b> Rupees, lakh and crore, FBR digital invoicing, Urdu-speaking support on WhatsApp.</span>
      <span><b>Real documents.</b> Every invoice and receipt prints exactly as your customer receives it.</span>
      <span><b>Runs on your phone.</b> No terminal to buy, no software to install.</span>
    </div>
    <p class="choose-foot">Not sure which fits? <a href="https://wa.me/<?= WA ?>?text=<?= rawurlencode('Hello, I want to see the VectorERP demo but not sure which one fits my business.') ?>" target="_blank" rel="noopener">Ask us on WhatsApp</a> — tell us what you do and we will point you at the right one.</p>
  </div>
<?php else: ?>

  <nav class="wbar">
    <div class="wbar-in">
      <a class="wbrand" href="?p=choose" title="Switch demo">
        <svg viewBox="0 0 120 120" fill="none"><circle cx="30" cy="34" r="8" fill="#2E5BDB"/><path d="M30 34 L60 92 L81.5 50.5" stroke="#2E5BDB" stroke-width="12" stroke-linecap="round" stroke-linejoin="round"/><path d="M91 32 L91.6 50.3 L75.6 42.1 Z" fill="#2E5BDB"/></svg>
        Vector<em>ERP</em>
      </a>
      <div class="wtabs">
        <?php $n = 0; foreach ($NAV as $key => [$label, $hint]): $n++; ?>
          <a href="?p=<?= $key ?>" class="wtab <?= $p === $key || ($p === 'new_invoice' && $key === 'invoices') ? 'on' : '' ?>">
            <i>SHEET <?= str_pad((string)$n, 2, '0', STR_PAD_LEFT) ?> · <?= $e(strtoupper($hint)) ?></i>
            <b><?= $e($label) ?></b>
          </a>
        <?php endforeach; ?>
        <a href="pos.php" class="wtab till">
          <i>COUNTER · TILL</i>
          <b>Open POS</b>
        </a>
      </div>
      <div class="wplate">
        <b><?= $e($DATA['company']['name']) ?></b>
        <span>STRN <?= $e($DATA['company']['strn']) ?> · FY <?= $e($DATA['company']['fy']) ?> · <a href="?logout=1" style="color:var(--blue)">reset</a></span>
      </div>
    </div>
  </nav>

  <div class="stage<?= $p === 'custom' ? ' noRail' : '' ?>">

    <?php if (isset($_GET['done'])): ?>
      <div class="toast">✓ <?= $e((string)$_GET['done']) ?> marked as paid — the ledger and receivables updated instantly. (Demo only: this resets when you sign out.)</div>
    <?php endif; ?>
    <?php if (isset($_GET['created'])): ?>
      <div class="toast">✓ Invoice <?= $e((string)$_GET['created']) ?> created with its FBR number — now click <b>PDF</b> on it to see the printed sales tax invoice.
        <a href="#save" style="display:inline-block;margin-left:10px;background:#178A50;color:#fff;border-radius:6px;padding:5px 15px;font-weight:700;font-size:.85rem">Like it? Save my demo data →</a>
      </div>
    <?php endif; ?>
    <?php if (isset($_GET['full'])): ?>
      <div class="toast warn">The demo allows up to 10 invoices per session — sign out and back in for a fresh company.</div>
    <?php endif; ?>

    <div class="col-main" style="min-width:0">

      <?php if ($p === 'dashboard'): ?>
        <div class="meters">
          <?php foreach ($DATA['kpis'] as $k): ?>
            <div class="meter"><div class="l"><?= $e($k['label']) ?></div><div class="v"><?= $e($k['value']) ?></div><div class="d <?= $e($k['dir']) ?>"><?= $e($k['delta']) ?></div></div>
          <?php endforeach; ?>
        </div>
        <div class="sheet">
          <span class="sheet-tab">Sheet 02 · <b>Recent invoices</b> · FBR active</span>
          <div class="tscroll">
          <table>
            <thead><tr><th>Invoice</th><th>Customer</th><th class="num">Total</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($allInvoices, 0, 4) as $inv):
              $st = isset($paid[$inv['no']]) ? 'paid' : $inv['status']; ?>
              <tr>
                <td><a href="?p=invoices" style="color:var(--blue);font-weight:600"><?= $e($inv['no']) ?></a></td>
                <td><?= $e($inv['party']) ?></td>
                <td class="num"><?= $rs($inv['excl'] + $inv['tax']) ?></td>
                <td><span class="pill <?= $e($st) ?>"><?= $st === 'due' ? 'Due' : ucfirst($st) ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        </div>
        <div class="sheet">
          <span class="sheet-tab">Sheet 03 · <b>Stock alerts</b> · below reorder</span>
          <div class="tscroll">
          <table>
            <thead><tr><th>Item</th><th>Location</th><th class="num">On hand</th><th class="num">Reorder at</th></tr></thead>
            <tbody>
            <?php foreach ($DATA['stock'] as $s): if ($s['qty'] >= $s['reorder']) continue; ?>
              <tr><td><?= $e($s['item']) ?></td><td><?= $e($s['loc']) ?></td><td class="num"><?= $s['qty'] ?></td><td class="num"><?= $s['reorder'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        </div>

      <?php elseif ($p === 'invoices'): ?>
        <div class="sheet">
          <span class="sheet-tab">Sheet 02 · <b>Sales tax invoices</b> · FY <?= $e($DATA['company']['fy']) ?></span>
          <div class="sheet-act">
            <a class="btn-sm btn-act" href="?p=new_invoice">+ New Invoice</a>
          </div>
          <div class="tscroll">
          <table class="reg-nowrap">
            <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th class="num">Excl. tax</th><th class="num">Tax 18%</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($allInvoices as $inv):
              $st = isset($paid[$inv['no']]) ? 'paid' : $inv['status']; ?>
              <tr>
                <td><b><?= $e($inv['no']) ?></b></td>
                <td><?= $e($inv['date']) ?></td>
                <td><?= $e($inv['party']) ?></td>
                <td class="num"><?= $rs($inv['excl']) ?></td>
                <td class="num"><?= $rs($inv['tax']) ?></td>
                <td class="num"><b><?= $rs($inv['excl'] + $inv['tax']) ?></b></td>
                <td><span class="pill <?= $e($st) ?>"><?= $st === 'due' ? 'Due' : ucfirst($st) ?></span></td>
                <td class="act">
                  <?php if ($st !== 'paid'): ?><a class="btn-sm" href="?pay=<?= urlencode($inv['no']) ?>">Mark paid</a><?php endif; ?>
                  <a class="btn-sm btn-dark" target="_blank" href="?p=print_invoice&no=<?= urlencode($inv['no']) ?>">PDF</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <p class="note">Try it: click <b>PDF</b> on any invoice to see the printed sales tax invoice — with FBR number and QR — exactly as your customer receives it. Or click <b>Mark paid</b> and watch the status update.</p>
        </div>
        <div class="sheet">
          <span class="sheet-tab">Document · <b>INV-2041 as printed</b></span>
          <div style="display:flex;gap:26px;flex-wrap:wrap;align-items:flex-start">
            <div style="flex:1;min-width:280px">
              <p style="margin-bottom:10px"><span class="fbr">FBR INVOICE <?= $e(substr($DATA['invoices'][0]['fbr'], 0, 14)) ?>…</span></p>
              <div class="tscroll">
              <table>
                <thead><tr><th>Item</th><th>HS code</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
                <tbody>
                <?php $sub = 0; foreach ($DATA['invoice_lines']['INV-2041'] as $l): $amt = $l['qty'] * $l['rate']; $sub += $amt; ?>
                  <tr><td><?= $e($l['item']) ?></td><td class="mono"><?= $e($l['hs']) ?></td><td class="num"><?= $l['qty'] ?> <?= $e($l['uom']) ?></td><td class="num"><?= number_format($l['rate']) ?></td><td class="num"><?= number_format($amt) ?></td></tr>
                <?php endforeach; ?>
                  <tr><td colspan="4" class="num" style="color:var(--muted)">Subtotal</td><td class="num"><?= $rs($sub) ?></td></tr>
                  <tr><td colspan="4" class="num" style="color:var(--muted)">Sales tax @ 18%</td><td class="num"><?= $rs((int)round($sub * 0.18)) ?></td></tr>
                  <tr><td colspan="4" class="num"><b>Total payable</b></td><td class="num"><b><?= $rs($sub + (int)round($sub * 0.18)) ?></b></td></tr>
                </tbody>
              </table>
              </div>
            </div>
            <div style="text-align:center">
              <div class="qr" aria-hidden="true"></div>
              <p style="font-family:var(--mono);font-size:.66rem;color:var(--muted);margin-top:7px">FBR QR<br>25×25 · 1″</p>
            </div>
          </div>
        </div>

      <?php elseif ($p === 'new_invoice'): ?>
        <?php if (isset($_GET['err'])): ?>
          <div class="toast err" style="margin-top:0">Please give a customer name and at least one line with description, quantity and rate.</div>
        <?php endif; ?>
        <form class="sheet" method="post" action="?p=new_invoice" id="niForm">
          <span class="sheet-tab">Sheet 02 · <b>New sales tax invoice</b> · FBR number on save</span>
          <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-bottom:16px">
            <div>
              <label class="lab">Customer</label>
              <input class="in" name="party" list="ni-parties" required placeholder="Type or pick a customer">
              <datalist id="ni-parties">
                <?php foreach (array_unique(array_column($allInvoices, 'party')) as $pt): ?><option value="<?= $e($pt) ?>"><?php endforeach; ?>
              </datalist>
            </div>
            <div>
              <label class="lab">Date</label>
              <input class="in" value="<?= $e(date('d M Y')) ?>" disabled>
            </div>
            <div>
              <label class="lab">Invoice No.</label>
              <input class="in" value="INV-<?= 2042 + count($custom) ?> (auto)" disabled>
            </div>
          </div>
          <div class="tscroll">
          <table id="niTable" style="min-width:640px">
            <thead><tr><th style="width:34%">Description</th><th style="width:13%">HS code</th><th style="width:11%">UoM</th><th class="num" style="width:11%">Qty</th><th class="num" style="width:14%">Rate (Rs)</th><th class="num" style="width:17%">Line total</th></tr></thead>
            <tbody>
              <?php for ($r = 0; $r < 3; $r++): ?>
              <tr>
                <td><input class="in" name="idesc[]" placeholder="e.g. Cotton fabric — 40s count"></td>
                <td><input class="in mono" name="ihs[]" placeholder="5208.1100"></td>
                <td><input class="in" name="iuom[]" placeholder="Than"></td>
                <td><input class="in ni-qty" name="iqty[]" type="number" min="0" step="any" placeholder="0" style="text-align:right"></td>
                <td><input class="in ni-rate" name="irate[]" type="number" min="0" step="any" placeholder="0" style="text-align:right"></td>
                <td class="num ni-line" style="font-variant-numeric:tabular-nums;color:var(--muted)">—</td>
              </tr>
              <?php endfor; ?>
            </tbody>
            <tfoot>
              <tr><td colspan="5" class="num" style="color:var(--muted);border-bottom:0">Value excluding sales tax</td><td class="num" id="niSub" style="border-bottom:0">Rs 0</td></tr>
              <tr><td colspan="5" class="num" style="color:var(--muted);border-bottom:0">Sales tax @ 18%</td><td class="num" id="niTax" style="border-bottom:0">Rs 0</td></tr>
              <tr><td colspan="5" class="num" style="border-bottom:0"><b>Total payable</b></td><td class="num" id="niTot" style="border-bottom:0"><b>Rs 0</b></td></tr>
            </tfoot>
          </table>
          </div>
          <div class="btn-row">
            <button type="submit" class="btn-sm btn-act">Save invoice &amp; assign FBR number</button>
            <button type="button" class="btn-sm btn-ghost" id="niAdd">+ Add line</button>
            <a class="btn-sm btn-ghost" style="color:var(--muted)" href="?p=invoices">Cancel</a>
          </div>
          <p class="note">Fill any lines you like — totals and tax calculate as you type. On save the invoice appears in your list with its FBR number, ready to print as PDF.</p>
        </form>
        <script>
        (function () {
          var tbody = document.querySelector('#niTable tbody');
          function fmt(n) { return 'Rs ' + Math.round(n).toLocaleString('en-PK'); }
          function recalc() {
            var sub = 0;
            tbody.querySelectorAll('tr').forEach(function (tr) {
              var q = parseFloat(tr.querySelector('.ni-qty').value) || 0;
              var r = parseFloat(tr.querySelector('.ni-rate').value) || 0;
              var line = q * r;
              tr.querySelector('.ni-line').textContent = line > 0 ? fmt(line) : '—';
              sub += line;
            });
            document.getElementById('niSub').textContent = fmt(sub);
            document.getElementById('niTax').textContent = fmt(sub * 0.18);
            document.getElementById('niTot').innerHTML = '<b>' + fmt(sub * 1.18) + '</b>';
          }
          tbody.addEventListener('input', recalc);
          document.getElementById('niAdd').addEventListener('click', function () {
            var row = tbody.querySelector('tr').cloneNode(true);
            row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
            row.querySelector('.ni-line').textContent = '—';
            tbody.appendChild(row);
          });
        })();
        </script>

      <?php elseif ($p === 'inventory'): ?>
        <div class="sheet">
          <span class="sheet-tab">Sheet 03 · <b>Stock register</b> · 3 locations</span>
          <div class="tscroll">
          <table>
            <thead><tr><th>Item</th><th>Location</th><th class="num">On hand</th><th class="num">Reorder at</th><th class="num">Stock value</th><th>Status</th></tr></thead>
            <tbody>
            <?php $tot = 0; foreach ($DATA['stock'] as $s): $tot += $s['value']; $lowQ = $s['qty'] < $s['reorder']; ?>
              <tr>
                <td><?= $e($s['item']) ?></td><td><?= $e($s['loc']) ?></td>
                <td class="num"><?= number_format($s['qty']) ?></td><td class="num"><?= number_format($s['reorder']) ?></td>
                <td class="num"><?= $pk($s['value']) ?></td>
                <td><span class="pill <?= $lowQ ? 'low' : 'ok' ?>"><?= $lowQ ? 'Low' : 'OK' ?></span></td>
              </tr>
            <?php endforeach; ?>
              <tr><td colspan="4" class="num"><b>Total stock value</b></td><td class="num"><b><?= $pk($tot) ?></b></td><td></td></tr>
            </tbody>
          </table>
          </div>
          <p class="note">Amounts read in lakh and crore — the way you actually say them, not millions.</p>
        </div>

      <?php elseif ($p === 'crm'): ?>
        <div class="sheet">
          <span class="sheet-tab">Sheet 04 · <b>Sales pipeline</b> · no inquiry lost</span>
          <div class="kanban">
            <?php foreach ($DATA['pipeline'] as $stage => $cards): ?>
              <div class="kcol">
                <h3><span><?= $e($stage) ?></span><span><?= count($cards) ?></span></h3>
                <?php foreach ($cards as $c): ?>
                  <div class="kcard"><b><?= $e($c['party']) ?></b><span><?= $e($c['note']) ?></span><span class="amt"><?= $pk($c['amount']) ?></span></div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

      <?php elseif ($p === 'accounts'): ?>
        <div class="sheet">
          <span class="sheet-tab">Sheet 05 · <b>Profit &amp; loss</b> · plain words</span>
          <h2>How much did the business make?</h2>
          <p class="sub">The whole financial year so far, in one line — no accounting degree needed.</p>
          <div class="pnl">
            <div class="pbox"><span class="l">Sold</span><b><?= $pk($accInc) ?></b><span class="s">everything billed</span></div>
            <span class="pop">−</span>
            <div class="pbox"><span class="l">Cost of goods</span><b><?= $pk($accCogs) ?></b><span class="s">what the goods cost you</span></div>
            <span class="pop">−</span>
            <div class="pbox"><span class="l">Running costs</span><b><?= $pk($accRun) ?></b><span class="s">salaries, rent, bills</span></div>
            <span class="pop">=</span>
            <div class="pbox profit"><span class="l">Profit kept</span><b><?= $pk($accProfit) ?></b><span class="s">before tax</span></div>
          </div>
          <p class="note">These figures are not typed in — they come straight from the invoices and ledger you have already seen. Sell something, and this page changes by itself.</p>
        </div>
        <div class="sheet">
          <span class="sheet-tab">Sheet 05 · <b>Trial balance</b> · debits = credits</span>
          <div class="tscroll">
          <table>
            <thead><tr><th>Account</th><th class="num" style="width:22%">Debit (Rs)</th><th class="num" style="width:22%">Credit (Rs)</th></tr></thead>
            <tbody>
            <?php
            $gWhy = [
                'Assets'      => 'what the business owns',
                'Liabilities' => 'what it owes',
                'Equity'      => "the owner's money in it",
                'Income'      => 'what it earned',
                'Expenses'    => 'what it spent',
            ];
            $g = '';
            foreach ($DATA['accounts'] as $a):
                if ($a['group'] !== $g): $g = $a['group']; ?>
              <tr><td colspan="3" class="gcell"><?= $e(strtoupper($g)) ?> <span>— <?= $e($gWhy[$g] ?? '') ?></span></td></tr>
              <?php endif; ?>
              <tr>
                <td><?= $e($a['name']) ?></td>
                <td class="num"><?= $a['dr'] ? number_format($a['dr']) : '—' ?></td>
                <td class="num"><?= $a['cr'] ? number_format($a['cr']) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
              <tr class="tb-total"><td>Total</td><td class="num"><?= number_format($accDr) ?></td><td class="num"><?= number_format($accCr) ?></td></tr>
            </tbody>
          </table>
          </div>
          <?php if ($accDr === $accCr): ?>
            <p class="tb-ok">Debits equal credits — the books balance.</p>
          <?php endif; ?>
          <p class="note">Every entry posts twice — once debit, once credit — so the two columns must always agree. That is double-entry, and it is why your tax consultant trusts a trial balance and not an Excel sheet. At filing time this page is one click, not a month-end reconciliation.</p>
        </div>

      <?php elseif ($p === 'ledger'): ?>
        <div class="sheet">
          <span class="sheet-tab">Sheet 06 · <b>Cash &amp; bank ledger</b> · FY 1 Jul – 30 Jun</span>
          <div class="sheet-act">
            <a class="btn-sm btn-dark" target="_blank" href="?p=print_report">Sales Register PDF</a>
          </div>
          <div class="tscroll">
          <table>
            <thead><tr><th>Date</th><th>Narration</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
            <tbody>
            <?php foreach ($DATA['ledger'] as $r): ?>
              <tr>
                <td><?= $e($r['date']) ?></td><td><?= $e($r['narration']) ?></td>
                <td class="num"><?= $r['debit'] ? number_format($r['debit']) : '—' ?></td>
                <td class="num"><?= $r['credit'] ? number_format($r['credit']) : '—' ?></td>
                <td class="num"><b><?= number_format($r['balance']) ?></b></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <p class="note">Double-entry throughout: every invoice, payment and purchase posts here automatically — the totals land on the <a href="?p=accounts" style="color:var(--blue);font-weight:600">Accounts sheet</a>.</p>
        </div>

      <?php elseif ($p === 'payroll'): ?>
        <div class="sheet">
          <span class="sheet-tab">Sheet 07 · <b>Payroll — August 2026</b> · EOBI deducted</span>
          <div class="tscroll">
          <table>
            <thead><tr><th>Employee</th><th>Designation</th><th class="num">Days</th><th class="num">Basic</th><th class="num">Allowances</th><th class="num">EOBI</th><th class="num">Net payable</th></tr></thead>
            <tbody>
            <?php $net = 0; foreach ($DATA['payroll'] as $s):
              $pay = (int)round(($s['basic'] * $s['days'] / 30) + $s['allow'] - $s['eobi']); $net += $pay; ?>
              <tr>
                <td><b><?= $e($s['name']) ?></b></td><td><?= $e($s['role']) ?></td>
                <td class="num"><?= $s['days'] ?></td><td class="num"><?= number_format($s['basic']) ?></td>
                <td class="num"><?= number_format($s['allow']) ?></td><td class="num"><?= number_format($s['eobi']) ?></td>
                <td class="num"><b><?= number_format($pay) ?></b></td>
              </tr>
            <?php endforeach; ?>
              <tr><td colspan="6" class="num"><b>Total payroll</b></td><td class="num"><b><?= $rs($net) ?></b></td></tr>
            </tbody>
          </table>
          </div>
          <p class="note">Salman Raza shows 28 days — attendance flows straight into the salary calculation.</p>
        </div>

      <?php elseif ($p === 'custom'): ?>
        <div class="sheet">
          <span class="sheet-tab">Sheet 08 · <b>Custom builds</b> · shaped around you</span>
          <h2>When nothing off-the-shelf fits</h2>
          <p class="note" style="margin:0 0 18px">There is no sample screen for this one, because custom work has no standard screen — it is shaped around how <em>you</em> work. So here is honestly what we have built for other businesses, and what the process looks like.</p>
          <div class="cust-grid">
            <div class="cust">
              <h3>Field staff app</h3>
              <p>Outdoor staff photograph a receipt on site; it is read automatically, an accountant approves it, and the expense posts to the ledger against that person's cash float. Built for an industrial services company working across Pakistan and Dubai.</p>
              <span class="cust-tag">Mobile · offline-tolerant</span>
            </div>
            <div class="cust">
              <h3>Legacy system rebuild</h3>
              <p>A manufacturer ran twenty years of accounts, GST and excise on a Visual Basic 6 program that only worked on one ageing PC. We reverse-engineered it, migrated the data, and rebuilt it as a web system their whole team uses from any browser.</p>
              <span class="cust-tag">Migration · GST &amp; excise</span>
            </div>
            <div class="cust">
              <h3>Approval workflows</h3>
              <p>Quotation → proforma → commercial invoice → packing list → delivery note, each with its own approvals and its own tax treatment, across two companies in two countries with two different fiscal calendars.</p>
              <span class="cust-tag">Multi-entity · multi-currency</span>
            </div>
            <div class="cust">
              <h3>Booking &amp; public portals</h3>
              <p>A hospital site where patients book appointments online and reception sees them instantly, with WhatsApp confirmations — plus an Android app for the patients who live on their phone rather than a browser.</p>
              <span class="cust-tag">Web · Android · WhatsApp</span>
            </div>
          </div>
          <h2 style="margin-top:26px">How a custom project runs</h2>
          <ol class="cust-steps">
            <li><b>We sit with your process first.</b> Usually half a day. What actually happens, who signs what, where the current system hurts.</li>
            <li><b>Fixed scope, fixed price, in writing.</b> You see the number before anything is built. No hourly meter.</li>
            <li><b>You see it working every couple of weeks</b> — not a status report, the actual software.</li>
            <li><b>You own the code outright</b> on final payment, and we hand over the source.</li>
          </ol>
          <p class="note">Honest note: if an existing system would serve you better than a custom build, we will tell you so — it is cheaper for you and it is a shorter argument than delivering something you did not need.</p>
        </div>
      <?php endif; ?>

      <div class="sheet save-panel" id="save">
        <span class="sheet-tab">Next step · <b>Your own setup</b></span>
        <h2 style="color:#fff">Save my demo data <span style="color:#9DBAFF;font-weight:400;font-size:.85rem">— set up with your own business</span></h2>
        <p class="save-lede">Leave your name and WhatsApp number — we'll set VectorERP up with <b>your own</b> products, parties and opening balances, and send you a private login. Free, no obligation.</p>
        <form class="save-form" id="save-form" action="../contact-submit.php" method="post" autocomplete="on">
          <input type="text" name="website" tabindex="-1" aria-hidden="true" autocomplete="off" style="position:absolute;left:-9999px">
          <input type="hidden" name="source" value="demo-save">
          <input type="hidden" name="service" value="VectorERP — set up with my own data">
          <input type="hidden" name="message" value="Requested &quot;Save my demo data&quot; from the live demo at /demo">
          <input name="name" placeholder="Your name" required aria-label="Your name">
          <input name="whatsapp" placeholder="WhatsApp — 03xx xxxxxxx" required aria-label="WhatsApp number">
          <input name="company" placeholder="Company name (optional)" aria-label="Company name">
          <button type="submit">Save my demo →</button>
        </form>
        <p class="save-done" id="save-done" hidden>✓ <b>Thank you.</b> Your details are saved. We'll WhatsApp you a private demo set up with your own data — usually the same day.</p>
        <p class="save-warn" id="save-warn" hidden>Couldn't save just now — please add your name and full WhatsApp number, or message us directly on WhatsApp below.</p>
        <p class="save-alt">Prefer to talk right now?
          <a href="https://wa.me/<?= WA ?>?text=<?= rawurlencode('Hello, I have seen the VectorERP demo and would like it set up for my business.') ?>" target="_blank" rel="noopener">Message us on WhatsApp →</a>
        </p>
      </div>

      <script>
      (function () {
        var f = document.getElementById('save-form');
        if (!f) return;
        f.addEventListener('submit', function (e) {
          e.preventDefault();
          if (f.website.value) return;                    // honeypot
          var btn = f.querySelector('button');
          btn.disabled = true; btn.textContent = 'Saving…';
          fetch('../contact-submit.php', { method: 'POST', body: new FormData(f), headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return { ok: r.ok }; }); })
            .then(function (res) {
              if (!res.ok) throw new Error('rejected');
              f.hidden = true;
              document.getElementById('save-done').hidden = false;
            })
            .catch(function () {
              btn.disabled = false; btn.textContent = 'Save my demo →';
              var w = document.getElementById('save-warn');
              if (w) w.hidden = false;
            });
        });
      })();
      </script>
    </div>

    <?php if ($p !== 'custom'): ?>
    <aside class="rail" aria-label="FBR compliance">
      <span class="rt">FBR — Return Aug 2026</span>
      <div class="rbox">
        <span class="rv"><?= $rs($railTax) ?></span>
        <span class="rl">sales tax collected this period</span>
      </div>
      <div class="rbox">
        <span class="rok"><?= $railFiled ?> of <?= count($allInvoices) ?> invoices filed</span>
        <span class="rl">every invoice carries its FBR number &amp; QR</span>
      </div>
      <div class="rbox">
        <span class="rv" style="color:<?= $railDueN ? '#925E10' : '#14713F' ?>"><?= $rs($railDue) ?></span>
        <span class="rl"><?= $railDueN ?> invoice<?= $railDueN === 1 ? '' : 's' ?> awaiting payment</span>
      </div>
      <p class="rnote"><b>Corrections:</b> a filed invoice can be amended within FBR's 72-hour window — after that it stands in the return. VectorERP tracks the window for you.</p>
      <a class="rwa" href="https://wa.me/<?= WA ?>?text=<?= rawurlencode('Hello, I am looking at the VectorERP demo — can we talk about FBR integration for my business?') ?>" target="_blank" rel="noopener">Ask about FBR setup →</a>
    </aside>
    <?php endif; ?>

  </div>
<?php endif; ?>
</body>
</html>
