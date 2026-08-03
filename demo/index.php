<?php
/**
 * VectorERP — public demo sandbox (vectorsolution.it/demo)
 *
 * Self-contained: no database, no configuration. Sample data lives in data.php;
 * anything the visitor changes is held in their own session and disappears on
 * logout, so every visitor gets a clean company.
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
const WA = '923363138686';

$DATA = require __DIR__ . '/data.php';

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

/* ---------- auth ---------- */
if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }
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

$NAV = [
    'dashboard' => ['Dashboard',    'M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z'],
    'invoices'  => ['Invoices',     'M6 3h9l4 4v14H6zM14 3v5h5M9 13h6M9 17h6'],
    'inventory' => ['Inventory',    'M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8'],
    'crm'       => ['Sales / CRM',  'M17 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9.5 11a4 4 0 100-8 4 4 0 000 8z'],
    'ledger'    => ['Accounting',   'M4 4h16v16H4zM4 9h16M9 9v11'],
    'payroll'   => ['HR & Payroll', 'M2 7h20v14H2zM16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>VectorERP — Live Demo</title>
<link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,100..900&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--paper:#F7F9FC;--card:#fff;--ink:#0F1B33;--muted:#55617D;--faint:#93A0BC;--line:rgba(15,27,51,.10);--line-soft:rgba(15,27,51,.06);--blue:#2E5BDB;--blue-deep:#1E3FA6;--blue-tint:#E8EEFC;--signal:#FF5A2D;--mono:"IBM Plex Mono",monospace;--disp:"Archivo",system-ui,sans-serif}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--disp);background:var(--paper);color:var(--ink);font-size:15px;line-height:1.55;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
button,input{font:inherit}
.demo-bar{background:linear-gradient(90deg,#12224A,#2E5BDB);color:#fff;padding:9px 18px;font-size:.84rem;display:flex;gap:14px;align-items:center;justify-content:center;flex-wrap:wrap;text-align:center}
.demo-bar b{font-weight:700}
.demo-bar a{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.3);border-radius:999px;padding:4px 14px;font-weight:600;white-space:nowrap}
.demo-bar a:hover{background:rgba(255,255,255,.28)}
/* login */
.login-wrap{min-height:calc(100vh - 40px);display:flex;align-items:center;justify-content:center;padding:24px;background:radial-gradient(ellipse 70% 60% at 70% 10%,rgba(46,91,219,.10),transparent 60%)}
.login{background:var(--card);border:1px solid var(--line-soft);border-radius:18px;padding:38px 34px;width:min(400px,100%);box-shadow:0 4px 12px rgba(15,27,51,.05),0 30px 70px rgba(30,63,166,.14)}
.login .mark{display:flex;align-items:center;gap:10px;margin-bottom:6px}
.login .mark svg{width:34px;height:34px}
.login .mark b{font-size:1.25rem;font-weight:700}
.login .mark b em{font-style:normal;color:var(--blue)}
.login p.sub{color:var(--muted);font-size:.9rem;margin-bottom:24px}
.login label{display:block;font-family:var(--mono);font-size:.68rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
.login input{width:100%;padding:12px 15px;border:1.5px solid var(--line);border-radius:10px;background:var(--paper);margin-bottom:16px}
.login input:focus{outline:none;border-color:var(--blue);background:#fff;box-shadow:0 0 0 4px rgba(46,91,219,.12)}
.login button{width:100%;background:var(--signal);color:#fff;border:0;border-radius:999px;padding:13px;font-weight:600;cursor:pointer;box-shadow:0 8px 22px rgba(255,90,45,.3)}
.login .hint{margin-top:18px;background:var(--blue-tint);border-radius:10px;padding:12px 14px;font-size:.85rem;color:var(--blue-deep)}
.login .hint code{font-family:var(--mono);font-weight:600}
.err{color:#B32222;font-size:.86rem;margin-bottom:12px}
/* shell */
.shell{display:grid;grid-template-columns:210px 1fr;min-height:calc(100vh - 40px)}
.side{background:#0F1B33;color:#B9C5E4;padding:18px 0;display:flex;flex-direction:column}
.side .logo{display:flex;align-items:center;gap:9px;padding:0 18px 16px;color:#fff;font-weight:700;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:10px}
.side .logo svg{width:22px;height:22px}
.side a{display:flex;align-items:center;gap:11px;padding:10px 18px;font-size:.92rem;transition:background .15s}
.side a svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:1.9;opacity:.85}
.side a:hover{background:rgba(255,255,255,.06);color:#fff}
.side a.on{background:rgba(91,140,255,.18);color:#fff;border-left:3px solid #5B8CFF;padding-left:15px}
.side .foot{margin-top:auto;padding:16px 18px 0;font-size:.76rem;color:#7D8CB0;border-top:1px solid rgba(255,255,255,.08)}
.side .foot a{padding:0;display:inline;color:#9DBAFF}
.main{padding:26px clamp(16px,3vw,34px);overflow-x:auto}
.head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.head h1{font-size:1.5rem;letter-spacing:-.02em}
.head .co{color:var(--muted);font-size:.86rem;font-family:var(--mono)}
.user{display:flex;align-items:center;gap:9px;font-size:.88rem;color:var(--muted)}
.user .av{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#2E5BDB,#5B8CFF);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.kpi{background:var(--card);border:1px solid var(--line-soft);border-radius:13px;padding:16px 18px;box-shadow:0 1px 3px rgba(15,27,51,.05)}
.kpi .l{font-family:var(--mono);font-size:.66rem;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);margin-bottom:6px}
.kpi .v{font-size:1.35rem;font-weight:700;letter-spacing:-.02em}
.kpi .d{font-size:.78rem;margin-top:3px;font-weight:600}
.up{color:#178A50}.down{color:#D93636}
.panel{background:var(--card);border:1px solid var(--line-soft);border-radius:13px;padding:18px 20px;box-shadow:0 1px 3px rgba(15,27,51,.05);margin-bottom:16px}
.panel h2{font-size:1rem;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;gap:10px}
.panel h2 span{font-family:var(--mono);font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);font-weight:400}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-family:var(--mono);font-size:.66rem;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);padding:8px 10px;border-bottom:1.5px solid var(--line)}
td{padding:11px 10px;border-bottom:1px solid var(--line-soft);font-size:.9rem}
tr:last-child td{border-bottom:0}
tbody tr:hover{background:var(--paper)}
.num{text-align:right;font-variant-numeric:tabular-nums}
.pill{display:inline-block;padding:3px 11px;border-radius:999px;font-size:.72rem;font-weight:700}
.paid{background:#DCF5E7;color:#14713F}.due{background:#FFF0D9;color:#925E10}.overdue{background:#FDE3E3;color:#B32222}.low{background:#FDE3E3;color:#B32222}.ok{background:#DCF5E7;color:#14713F}
.btn-sm{background:var(--blue);color:#fff;border:0;border-radius:999px;padding:5px 13px;font-size:.76rem;font-weight:600;cursor:pointer}
.btn-sm:hover{background:var(--blue-deep)}
.fbr{display:inline-flex;align-items:center;gap:6px;background:#DCF5E7;color:#14713F;border-radius:999px;padding:3px 11px;font-size:.68rem;font-weight:700;font-family:var(--mono)}
.fbr::before{content:"";width:6px;height:6px;border-radius:50%;background:#14713F}
.kanban{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.kcol{background:var(--paper);border-radius:11px;padding:12px}
.kcol h3{font-family:var(--mono);font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);display:flex;justify-content:space-between;margin-bottom:10px}
.kcard{background:#fff;border:1px solid var(--line-soft);border-radius:9px;padding:11px 12px;margin-bottom:9px;box-shadow:0 1px 2px rgba(15,27,51,.05)}
.kcard b{display:block;font-size:.88rem;margin-bottom:2px}
.kcard span{font-size:.79rem;color:var(--muted)}
.kcard .amt{display:block;font-weight:700;color:var(--blue);margin-top:5px;font-variant-numeric:tabular-nums}
.toast{background:#DCF5E7;border:1px solid #9ADFBB;color:#14713F;border-radius:11px;padding:12px 16px;margin-bottom:16px;font-weight:600;font-size:.9rem}
.qr{width:74px;height:74px;border-radius:6px;background:repeating-linear-gradient(0deg,#0F1B33 0 4px,transparent 4px 8px),repeating-linear-gradient(90deg,#0F1B33 0 4px,#fff 4px 8px);opacity:.9}
.note{color:var(--muted);font-size:.84rem;margin-top:10px}
/* save-my-demo lead capture */
.save-panel{background:linear-gradient(155deg,#12224A,#0F1B33);border:0;color:#fff}
.save-lede{color:rgba(233,238,249,.82);font-size:.92rem;margin-bottom:16px;max-width:62ch}
.save-lede b{color:#fff}
.save-form{display:flex;gap:10px;flex-wrap:wrap}
.save-form input{flex:1;min-width:170px;padding:11px 15px;border:1px solid rgba(255,255,255,.22);border-radius:999px;background:rgba(255,255,255,.10);color:#fff}
.save-form input::placeholder{color:rgba(233,238,249,.55)}
.save-form input:focus{outline:none;border-color:#5B8CFF;background:rgba(255,255,255,.16)}
.save-form button{background:#25D366;color:#fff;border:0;border-radius:999px;padding:11px 24px;font-weight:700;cursor:pointer;white-space:nowrap}
.save-form button:hover{background:#1FBA59}
.save-form button:disabled{opacity:.65;cursor:default}
.save-done{background:rgba(37,211,102,.16);border:1px solid rgba(37,211,102,.45);border-radius:11px;padding:13px 16px;font-size:.92rem}
.save-warn{background:rgba(255,90,45,.16);border:1px solid rgba(255,90,45,.5);border-radius:11px;padding:12px 15px;font-size:.9rem;margin-top:12px;color:#FFD3C4}
.save-alt{margin-top:14px;font-size:.86rem;color:rgba(233,238,249,.65)}
.save-alt a{color:#7BE49E;font-weight:600}
@media(max-width:900px){.kpis{grid-template-columns:1fr 1fr}.kanban{grid-template-columns:1fr}.save-form input{min-width:100%}.save-form button{width:100%}}
@media(max-width:700px){.shell{grid-template-columns:1fr}.side{flex-direction:row;overflow-x:auto;padding:8px;align-items:center}.side .logo{border:0;margin:0;padding:0 12px}.side .foot{display:none}.side a{white-space:nowrap;padding:8px 12px}.side a.on{border-left:0;border-bottom:2px solid #5B8CFF;padding-left:12px}}
</style>
</head>
<body>

<div class="demo-bar">
  <span><b>VectorERP live demo</b> — sample data, nothing you do here is saved</span>
  <a href="/">← Back to vectorsolution.it</a>
  <?php if ($authed): ?><a href="#save" style="background:#25D366;border-color:#25D366">💾 Save my demo data</a><?php endif; ?>
  <a href="https://wa.me/<?= WA ?>?text=<?= rawurlencode('Hello, I have seen the VectorERP demo and would like to discuss it for my business.') ?>" target="_blank" rel="noopener">Discuss this for my business →</a>
</div>

<?php if (!$authed): ?>
  <div class="login-wrap">
    <form class="login" method="post">
      <div class="mark">
        <svg viewBox="0 0 120 120" fill="none"><circle cx="30" cy="34" r="8" fill="#2E5BDB"/><path d="M30 34 L60 92 L81.5 50.5" stroke="#2E5BDB" stroke-width="12" stroke-linecap="round" stroke-linejoin="round"/><path d="M91 32 L91.6 50.3 L75.6 42.1 Z" fill="#2E5BDB"/></svg>
        <b>Vector<em>ERP</em></b>
      </div>
      <p class="sub">Demo company — Al-Karam Traders (Pvt) Ltd, Karachi</p>
      <?php if (!empty($loginError)): ?><p class="err"><?= $e($loginError) ?></p><?php endif; ?>
      <label for="u">User name</label>
      <input id="u" name="user" value="demo" autocomplete="off">
      <label for="p">Password</label>
      <input id="p" name="pass" type="password" value="demo">
      <button type="submit">Sign in to the demo</button>
      <p class="hint">Login is <code>demo</code> / <code>demo</code> — already filled in. Click through everything; the data resets when you leave.</p>
    </form>
  </div>
<?php else: ?>
  <div class="shell">
    <nav class="side">
      <span class="logo">
        <svg viewBox="0 0 120 120" fill="none"><circle cx="30" cy="34" r="8" fill="#5B8CFF"/><path d="M30 34 L60 92 L81.5 50.5" stroke="#5B8CFF" stroke-width="12" stroke-linecap="round" stroke-linejoin="round"/><path d="M91 32 L91.6 50.3 L75.6 42.1 Z" fill="#5B8CFF"/></svg>
        VectorERP
      </span>
      <?php foreach ($NAV as $key => [$label, $path]): ?>
        <a href="?p=<?= $key ?>" class="<?= $p === $key ? 'on' : '' ?>">
          <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="<?= $path ?>"/></svg><?= $e($label) ?>
        </a>
      <?php endforeach; ?>
      <div class="foot">Demo build · <a href="?logout=1">Sign out &amp; reset</a></div>
    </nav>

    <main class="main">
      <div class="head">
        <div>
          <h1><?= $e($p === 'new_invoice' ? 'New Invoice' : ($NAV[$p][0] ?? 'Dashboard')) ?></h1>
          <p class="co"><?= $e($DATA['company']['name']) ?> · STRN <?= $e($DATA['company']['strn']) ?> · FY <?= $e($DATA['company']['fy']) ?></p>
        </div>
        <div class="user"><span class="av">DM</span> Demo User · Administrator</div>
      </div>

      <?php if (isset($_GET['done'])): ?>
        <div class="toast">✓ <?= $e((string)$_GET['done']) ?> marked as paid — the ledger and receivables updated instantly. (Demo only: this resets when you sign out.)</div>
      <?php endif; ?>
      <?php if (isset($_GET['created'])): ?>
        <div class="toast">✓ Invoice <?= $e((string)$_GET['created']) ?> created with its FBR number — now click <b>PDF</b> on it to see the printed sales tax invoice.
          <a href="#save" style="display:inline-block;margin-left:10px;background:#178A50;color:#fff;border-radius:999px;padding:5px 15px;font-weight:700;font-size:.85rem">Like it? Save my demo data →</a>
        </div>
      <?php endif; ?>
      <?php if (isset($_GET['full'])): ?>
        <div class="toast" style="background:#FFF0D9;border-color:#F2CE8E;color:#925E10">The demo allows up to 10 invoices per session — sign out and back in for a fresh company.</div>
      <?php endif; ?>

      <?php if ($p === 'dashboard'): ?>
        <div class="kpis">
          <?php foreach ($DATA['kpis'] as $k): ?>
            <div class="kpi"><div class="l"><?= $e($k['label']) ?></div><div class="v"><?= $e($k['value']) ?></div><div class="d <?= $e($k['dir']) ?>"><?= $e($k['delta']) ?></div></div>
          <?php endforeach; ?>
        </div>
        <div class="panel">
          <h2>Recent invoices <span>FBR digital invoicing active</span></h2>
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
        <div class="panel">
          <h2>Stock alerts <span>below reorder level</span></h2>
          <table>
            <thead><tr><th>Item</th><th>Location</th><th class="num">On hand</th><th class="num">Reorder at</th></tr></thead>
            <tbody>
            <?php foreach ($DATA['stock'] as $s): if ($s['qty'] >= $s['reorder']) continue; ?>
              <tr><td><?= $e($s['item']) ?></td><td><?= $e($s['loc']) ?></td><td class="num"><?= $s['qty'] ?></td><td class="num"><?= $s['reorder'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      <?php elseif ($p === 'invoices'): ?>
        <div class="panel">
          <h2>Sales tax invoices
            <span style="display:inline-flex;gap:10px;align-items:center">every invoice registered with FBR
              <a class="btn-sm" style="background:var(--signal);padding:7px 16px;font-size:.82rem" href="?p=new_invoice">+ New Invoice</a>
            </span>
          </h2>
          <table>
            <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th class="num">Excl. tax</th><th class="num">Sales tax 18%</th><th class="num">Total</th><th>Status</th><th></th><th></th></tr></thead>
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
                <td><a class="btn-sm" style="background:#0F1B33" target="_blank" href="?p=print_invoice&no=<?= urlencode($inv['no']) ?>">PDF</a></td>
                <td><?php if ($st !== 'paid'): ?><a class="btn-sm" href="?pay=<?= urlencode($inv['no']) ?>">Mark paid</a><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p class="note">Try it: click <b>PDF</b> on any invoice to see the printed sales tax invoice — with FBR number and QR — exactly as your customer receives it. Or click <b>Mark paid</b> and watch the status update.</p>
        </div>
        <div class="panel">
          <h2>INV-2041 — as printed for the customer <span>FBR number + QR on every invoice</span></h2>
          <div style="display:flex;gap:26px;flex-wrap:wrap;align-items:flex-start">
            <div style="flex:1;min-width:280px">
              <p style="margin-bottom:10px"><span class="fbr">FBR INVOICE <?= $e(substr($DATA['invoices'][0]['fbr'], 0, 14)) ?>…</span></p>
              <table>
                <thead><tr><th>Item</th><th>HS code</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
                <tbody>
                <?php $sub = 0; foreach ($DATA['invoice_lines']['INV-2041'] as $l): $amt = $l['qty'] * $l['rate']; $sub += $amt; ?>
                  <tr><td><?= $e($l['item']) ?></td><td style="font-family:var(--mono);font-size:.8rem"><?= $e($l['hs']) ?></td><td class="num"><?= $l['qty'] ?> <?= $e($l['uom']) ?></td><td class="num"><?= number_format($l['rate']) ?></td><td class="num"><?= number_format($amt) ?></td></tr>
                <?php endforeach; ?>
                  <tr><td colspan="4" class="num" style="color:var(--muted)">Subtotal</td><td class="num"><?= $rs($sub) ?></td></tr>
                  <tr><td colspan="4" class="num" style="color:var(--muted)">Sales tax @ 18%</td><td class="num"><?= $rs((int)round($sub * 0.18)) ?></td></tr>
                  <tr><td colspan="4" class="num"><b>Total payable</b></td><td class="num"><b><?= $rs($sub + (int)round($sub * 0.18)) ?></b></td></tr>
                </tbody>
              </table>
            </div>
            <div style="text-align:center">
              <div class="qr" aria-hidden="true"></div>
              <p style="font-family:var(--mono);font-size:.66rem;color:var(--muted);margin-top:7px">FBR QR<br>25×25 · 1″</p>
            </div>
          </div>
        </div>

      <?php elseif ($p === 'new_invoice'): ?>
        <?php if (isset($_GET['err'])): ?>
          <div class="toast" style="background:#FDE3E3;border-color:#F0A9A9;color:#B32222">Please give a customer name and at least one line with description, quantity and rate.</div>
        <?php endif; ?>
        <form class="panel" method="post" action="?p=new_invoice" id="niForm">
          <h2>Create a sales tax invoice <span>FBR number assigned on save</span></h2>
          <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-bottom:16px">
            <div>
              <label style="display:block;font-family:var(--mono);font-size:.66rem;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);margin-bottom:6px">Customer</label>
              <input name="party" list="ni-parties" required placeholder="Type or pick a customer"
                     style="width:100%;padding:10px 14px;border:1.5px solid var(--line);border-radius:9px;background:#fff">
              <datalist id="ni-parties">
                <?php foreach (array_unique(array_column($allInvoices, 'party')) as $pt): ?><option value="<?= $e($pt) ?>"><?php endforeach; ?>
              </datalist>
            </div>
            <div>
              <label style="display:block;font-family:var(--mono);font-size:.66rem;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);margin-bottom:6px">Date</label>
              <input value="<?= $e(date('d M Y')) ?>" disabled style="width:100%;padding:10px 14px;border:1.5px solid var(--line-soft);border-radius:9px;background:var(--paper);color:var(--muted)">
            </div>
            <div>
              <label style="display:block;font-family:var(--mono);font-size:.66rem;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);margin-bottom:6px">Invoice No.</label>
              <input value="INV-<?= 2042 + count($custom) ?> (auto)" disabled style="width:100%;padding:10px 14px;border:1.5px solid var(--line-soft);border-radius:9px;background:var(--paper);color:var(--muted)">
            </div>
          </div>
          <table id="niTable">
            <thead><tr><th style="width:34%">Description</th><th style="width:13%">HS code</th><th style="width:11%">UoM</th><th class="num" style="width:11%">Qty</th><th class="num" style="width:14%">Rate (Rs)</th><th class="num" style="width:17%">Line total</th></tr></thead>
            <tbody>
              <?php for ($r = 0; $r < 3; $r++): ?>
              <tr>
                <td><input name="idesc[]" placeholder="e.g. Cotton fabric — 40s count" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:7px"></td>
                <td><input name="ihs[]" placeholder="5208.1100" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:7px;font-family:var(--mono);font-size:.8rem"></td>
                <td><input name="iuom[]" placeholder="Than" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:7px"></td>
                <td><input name="iqty[]" type="number" min="0" step="any" placeholder="0" class="ni-qty" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:7px;text-align:right"></td>
                <td><input name="irate[]" type="number" min="0" step="any" placeholder="0" class="ni-rate" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:7px;text-align:right"></td>
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
          <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
            <button type="button" class="btn-sm" style="background:var(--paper);color:var(--ink);border:1px solid var(--line)" id="niAdd">+ Add line</button>
            <button type="submit" class="btn-sm" style="background:var(--signal);padding:8px 22px;font-size:.86rem">Save invoice &amp; assign FBR number</button>
            <a class="btn-sm" style="background:var(--paper);color:var(--muted);border:1px solid var(--line)" href="?p=invoices">Cancel</a>
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
        <div class="panel">
          <h2>Stock across all locations <span>3 warehouses · live</span></h2>
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
          <p class="note">Amounts read in lakh and crore — the way you actually say them, not millions.</p>
        </div>

      <?php elseif ($p === 'crm'): ?>
        <div class="panel">
          <h2>Sales pipeline <span>no inquiry lost in a WhatsApp chat</span></h2>
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

      <?php elseif ($p === 'ledger'): ?>
        <div class="panel">
          <h2>Cash &amp; bank ledger
            <span style="display:inline-flex;gap:10px;align-items:center">financial year 1 July – 30 June
              <a class="btn-sm" style="background:#0F1B33" target="_blank" href="?p=print_report">Sales Register PDF</a>
            </span>
          </h2>
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
          <p class="note">Double-entry throughout: every invoice, payment and purchase posts here automatically.</p>
        </div>

      <?php elseif ($p === 'payroll'): ?>
        <div class="panel">
          <h2>Payroll — August 2026 <span>EOBI deducted · salary slips ready</span></h2>
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
          <p class="note">Salman Raza shows 28 days — attendance flows straight into the salary calculation.</p>
        </div>
      <?php endif; ?>

      <div class="panel save-panel" id="save">
        <h2 style="color:#fff">Save my demo data <span style="color:#9DBAFF">— set up with your own business</span></h2>
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
    </main>
  </div>
<?php endif; ?>
</body>
</html>
