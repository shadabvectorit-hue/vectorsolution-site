<?php
/**
 * VectorERP demo — retail POS terminal.
 *
 * Built to feel like a real till: the barcode box is always focused (a USB
 * scanner is just a keyboard that types and presses Enter), the cart is held in
 * the browser so scanning is instant, and the receipt prints to 80 mm thermal
 * paper. Totals are recomputed on the server from the catalogue — the browser
 * is never trusted with a price.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/guard.php';

@ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
session_name('VDEMOSESS');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => true]);
session_start();

const WA = '923363138686';
$SHOP = require __DIR__ . '/data_pos.php';

if (empty($_SESSION['demo_ok'])) {
    header('Location: index.php'); exit;
}

function posTrack(string $event): void {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (preg_match('/bot|crawl|spider|preview|whatsapp|curl|python|headless/i', $ua)) return;
    if (!vit_rate_allow('beacon', 120, 600)) return;
    $dir = VIT_PRIVATE;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return;
    $file = $dir . '/analytics.jsonl';
    if (is_file($file) && (int)@filesize($file) > 40 * 1024 * 1024) { @rename($file, $file . '.1'); }
    $row = ['t' => date('Y-m-d H:i:s'), 'e' => $event, 'p' => '/demo/pos.php',
            'v' => vit_visitor_hash(), 'r' => substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 160),
            'm' => preg_match('/Mobile|Android|iPhone/i', $ua) ? 1 : 0];
    @file_put_contents($file, (string)json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
}

$e  = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$rs = static fn($n): string => 'Rs ' . number_format((float)$n);
$byCode = [];
foreach ($SHOP['items'] as $it) { $byCode[$it['code']] = $it; }

/* ---------- checkout ---------- */
$p = $_GET['p'] ?? 'till';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['p'] ?? '') === 'pay') {
    $raw = json_decode((string)($_POST['cart'] ?? '[]'), true);
    $method = (string)($_POST['method'] ?? 'cash');
    if (!in_array($method, ['cash', 'card', 'wallet', 'khata'], true)) $method = 'cash';

    $lines = [];
    $net = $tax = $exempt = 0.0;   // exempt is kept apart: it is not taxable value
    if (is_array($raw)) {
        foreach (array_slice($raw, 0, 40) as $row) {
            $code = (string)($row['code'] ?? '');
            if (!isset($byCode[$code])) continue;                   // price comes from the catalogue, never the browser
            $qty = (int)($row['qty'] ?? 0);
            if ($qty < 1) continue;
            $qty = min($qty, 999);
            $it = $byCode[$code];
            $gross = $it['price'] * $qty;                            // shelf price includes sales tax
            $lines[] = ['name' => $it['name'], 'code' => $code, 'qty' => $qty,
                        'price' => $it['price'], 'gross' => $gross, 'tax' => $it['tax'],
                        'sch3' => !empty($it['sch3'])];
            if ($it['tax'] > 0) {
                $lineNet = $gross / (1 + $it['tax'] / 100);
                $net += $lineNet;
                $tax += $gross - $lineNet;
            } else {
                $exempt += $gross;
            }
        }
    }
    if (!$lines) { header('Location: pos.php?empty=1'); exit; }

    $total = $net + $tax + $exempt;
    $tendered = max(0.0, (float)($_POST['tendered'] ?? 0));
    $seq = (int)($_SESSION['pos_seq'] ?? 0) + 1;
    $_SESSION['pos_seq'] = $seq;
    $no = 'R-' . str_pad((string)(4180 + $seq), 5, '0', STR_PAD_LEFT);

    $sale = [
        'no' => $no, 'time' => date('d M Y H:i'), 'lines' => $lines,
        'net' => round($net, 2), 'tax' => round($tax, 2), 'exempt' => round($exempt, 2), 'total' => round($total, 2),
        'method' => $method, 'tendered' => $tendered,
        'change' => $method === 'cash' ? max(0.0, $tendered - $total) : 0.0,
        'khata' => $method === 'khata' ? trim(mb_substr((string)($_POST['khata'] ?? ''), 0, 60)) : '',
        // Shaped like a real FBR invoice reference: 7-digit STRN prefix + DI + timestamp.
        'fbr' => '7000007DI' . (time() * 1000 + $seq),
    ];
    $sales = $_SESSION['pos_sales'] ?? [];
    array_unshift($sales, $sale);
    $_SESSION['pos_sales'] = array_slice($sales, 0, 50);
    posTrack('demo_pos_sale');
    header('Location: pos.php?p=receipt&no=' . urlencode($no)); exit;
}

$sales = $_SESSION['pos_sales'] ?? [];

/* ---------- 80 mm thermal receipt ---------- */
if ($p === 'receipt') {
    $no = (string)($_GET['no'] ?? '');
    $sale = null;
    foreach ($sales as $s) { if ($s['no'] === $no) { $sale = $s; break; } }
    if (!$sale) { header('Location: pos.php'); exit; }
    posTrack('demo_pos_receipt');
    $methodLabel = ['cash' => 'CASH', 'card' => 'CARD', 'wallet' => 'JAZZCASH / EASYPAISA', 'khata' => 'KHATA (CREDIT)'][$sale['method']];
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex">
    <title><?= $e($no) ?> — Receipt</title>
    <style>
      *{box-sizing:border-box;margin:0;padding:0}
      body{font-family:"Segoe UI",Arial,sans-serif;background:#eef2f8;padding:20px}
      .bar{max-width:420px;margin:0 auto 16px;display:flex;gap:9px;flex-wrap:wrap}
      .bar a,.bar button{border:0;border-radius:999px;padding:10px 18px;font:600 13px "Segoe UI",sans-serif;cursor:pointer;text-decoration:none}
      .back{background:#fff;color:#0F1B33;border:1px solid #cbd5e1}
      .print{background:#0B8043;color:#fff}
      .tip{max-width:420px;margin:0 auto 14px;font-size:12.5px;color:#475569;line-height:1.5}
      .paper{width:302px;margin:0 auto;background:#fff;padding:16px 14px 22px;box-shadow:0 10px 30px rgba(15,27,51,.14);
             font-family:"Courier New",monospace;font-size:12px;color:#000;line-height:1.45}
      .c{text-align:center}.r{text-align:right}
      .paper h1{font-size:16px;letter-spacing:1px;margin-bottom:2px}
      .sub{font-size:10.5px;line-height:1.4}
      hr{border:0;border-top:1px dashed #000;margin:8px 0}
      table{width:100%;border-collapse:collapse}
      td{padding:1px 0;vertical-align:top}
      .qtycol{width:26px}.amtcol{width:66px;text-align:right}
      .tot td{font-weight:bold;font-size:13px}
      .qr{width:70px;height:70px;margin:8px auto 4px;
          background:repeating-linear-gradient(0deg,#000 0 3px,transparent 3px 6px),repeating-linear-gradient(90deg,#000 0 3px,#fff 3px 6px)}
      .foot{font-size:10px;line-height:1.45}
      @media print{
        /* Real 80 mm roll: no margins, no browser furniture, cut after the last line. */
        @page{size:80mm auto;margin:0}
        body{background:#fff;padding:0}
        .bar,.tip{display:none}
        .paper{width:72mm;box-shadow:none;padding:2mm 2mm 6mm;margin:0}
      }
    </style></head><body>
    <div class="bar">
      <a class="back" href="pos.php">← Back to till</a>
      <button class="print" onclick="window.print()">🖨 Print receipt</button>
      <a class="back" href="pos.php?p=day">Day summary</a>
    </div>
    <p class="tip">This is a real 80&nbsp;mm thermal layout — printing it on a receipt printer produces exactly this, with no page margins. On a normal printer, choose <b>Save as PDF</b>.</p>
    <div class="paper">
      <div class="c">
        <h1><?= $e($SHOP['shop']['name']) ?></h1>
        <div class="sub"><?= $e($SHOP['shop']['branch']) ?><br>
        Ph <?= $e($SHOP['shop']['phone']) ?><br>
        NTN <?= $e($SHOP['shop']['ntn']) ?> · STRN <?= $e($SHOP['shop']['strn']) ?></div>
      </div>
      <hr>
      <table class="sub">
        <tr><td>Receipt</td><td class="r"><?= $e($sale['no']) ?></td></tr>
        <tr><td>Date</td><td class="r"><?= $e($sale['time']) ?></td></tr>
        <tr><td>Cashier</td><td class="r"><?= $e($SHOP['shop']['cashier']) ?> · <?= $e($SHOP['shop']['till']) ?></td></tr>
      </table>
      <hr>
      <table>
        <?php foreach ($sale['lines'] as $l): ?>
          <tr><td colspan="3"><?= $e($l['name']) ?><?= $l['tax'] == 0 ? ' *' : '' ?><?= $l['sch3'] ? ' †' : '' ?></td></tr>
          <tr>
            <td class="qtycol"><?= (int)$l['qty'] ?> x</td>
            <td><?= number_format((float)$l['price']) ?></td>
            <td class="amtcol"><?= number_format((float)$l['gross']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <hr>
      <table>
        <tr><td>Taxable value</td><td class="r"><?= number_format($sale['net']) ?></td></tr>
        <tr><td>Sales tax</td><td class="r"><?= number_format($sale['tax']) ?></td></tr>
        <?php if (($sale['exempt'] ?? 0) > 0): ?>
          <tr><td>Exempt goods *</td><td class="r"><?= number_format($sale['exempt']) ?></td></tr>
        <?php endif; ?>
        <tr class="tot"><td>TOTAL</td><td class="r">Rs <?= number_format($sale['total']) ?></td></tr>
      </table>
      <hr>
      <table>
        <tr><td>Paid by</td><td class="r"><?= $e($methodLabel) ?></td></tr>
        <?php if ($sale['method'] === 'cash'): ?>
          <tr><td>Cash received</td><td class="r"><?= number_format($sale['tendered']) ?></td></tr>
          <tr><td>Change</td><td class="r"><?= number_format($sale['change']) ?></td></tr>
        <?php elseif ($sale['method'] === 'khata' && $sale['khata'] !== ''): ?>
          <tr><td colspan="2">Khata: <?= $e($sale['khata']) ?></td></tr>
        <?php endif; ?>
      </table>
      <hr>
      <div class="c">
        <div class="sub">FBR Digital Invoice</div>
        <div class="sub" style="word-break:break-all"><?= $e($sale['fbr']) ?></div>
        <div class="qr" aria-hidden="true"></div>
        <div class="foot">Scan with FBR Tax Asaan to verify</div>
      </div>
      <hr>
      <div class="foot">
        * exempt from sales tax<br>
        † 3rd Schedule — tax on printed retail price<br><br>
        <div class="c">Goods once sold are exchangeable within 7 days with this receipt.<br><br>
        <b>Shukriya — thank you!</b></div>
      </div>
    </div>
    </body></html><?php
    exit;
}

/* ---------- day summary (Z read) ---------- */
if ($p === 'day') {
    $tot = ['count' => count($sales), 'net' => 0.0, 'tax' => 0.0, 'total' => 0.0,
            'cash' => 0.0, 'card' => 0.0, 'wallet' => 0.0, 'khata' => 0.0];
    foreach ($sales as $s) {
        $tot['net'] += $s['net']; $tot['tax'] += $s['tax']; $tot['total'] += $s['total'];
        $tot[$s['method']] += $s['total'];
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>VectorERP POS — Live Demo</title>
<link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,100..900&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--paper:#F4F7FB;--card:#fff;--ink:#0F1B33;--muted:#55617D;--faint:#93A0BC;--line:rgba(15,27,51,.10);
      --green:#0B8043;--green-d:#075E31;--green-t:#E7F5EC;--blue:#2E5BDB;--amber:#B7791F;--red:#B32222;
      --mono:"IBM Plex Mono",monospace;--disp:"Archivo",system-ui,sans-serif}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--disp);background:var(--paper);color:var(--ink);font-size:15px;line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
button,input,select{font:inherit}
.demo-bar{background:linear-gradient(90deg,#083B21,#0B8043);color:#fff;padding:9px 18px;font-size:.84rem;display:flex;gap:14px;align-items:center;justify-content:center;flex-wrap:wrap;text-align:center}
.demo-bar b{font-weight:700}
.demo-bar a{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.3);border-radius:999px;padding:4px 14px;font-weight:600;white-space:nowrap}
.demo-bar a:hover{background:rgba(255,255,255,.3)}

.topbar{background:#fff;border-bottom:1px solid var(--line);padding:12px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.topbar .shop{font-weight:800;font-size:1.05rem}
.topbar .meta{color:var(--muted);font-size:.82rem}
.topbar .spacer{flex:1}
.pill{font-family:var(--mono);font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;background:var(--green-t);color:var(--green-d);border-radius:999px;padding:5px 12px;font-weight:600}
.tbtn{border:1px solid var(--line);background:#fff;border-radius:999px;padding:8px 16px;font-size:.85rem;font-weight:600;cursor:pointer}
.tbtn:hover{border-color:var(--green);color:var(--green-d)}

.wrap{display:grid;grid-template-columns:1fr 400px;gap:18px;padding:18px 20px 30px;align-items:start;max-width:1500px;margin:0 auto}
.panel{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:0 1px 3px rgba(15,27,51,.05)}

/* catalogue */
.cats{display:flex;gap:8px;padding:14px 16px 0;flex-wrap:wrap}
.cat{border:1px solid var(--line);background:#fff;border-radius:999px;padding:7px 15px;font-size:.85rem;font-weight:600;cursor:pointer;color:var(--muted)}
.cat.on{background:var(--green);border-color:var(--green);color:#fff}
.tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:11px;padding:16px}
.tile{border:1px solid var(--line);border-radius:13px;padding:12px 12px 11px;background:#fff;cursor:pointer;text-align:left;transition:transform .07s,border-color .12s,box-shadow .12s}
.tile:hover{border-color:var(--green);box-shadow:0 6px 18px rgba(11,128,67,.13)}
.tile:active{transform:scale(.97)}
.tile b{display:block;font-size:.9rem;line-height:1.3;margin-bottom:5px}
.tile .pr{color:var(--green-d);font-weight:800}
.tile .bc{font-family:var(--mono);font-size:.62rem;color:var(--faint);margin-top:5px;letter-spacing:.02em}
.tile .tg{font-family:var(--mono);font-size:.6rem;text-transform:uppercase;letter-spacing:.08em;color:var(--amber);margin-top:3px}
.tile.low{border-color:#F0D3A0}
.tile.low .st{font-size:.66rem;color:var(--amber);font-weight:700}

/* cart */
.cart{position:sticky;top:14px;display:flex;flex-direction:column;max-height:calc(100vh - 30px)}
.scan{padding:14px 16px;border-bottom:1px solid var(--line)}
.scan label{display:block;font-family:var(--mono);font-size:.66rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
.scan input{width:100%;padding:13px 14px;border:2px solid var(--green);border-radius:11px;font-family:var(--mono);font-size:1rem;outline:none}
.scan input::placeholder{color:var(--faint);font-family:var(--disp)}
.scan .hint{font-size:.72rem;color:var(--muted);margin-top:7px}
.lines{overflow-y:auto;flex:1;min-height:120px}
.empty{padding:40px 20px;text-align:center;color:var(--faint);font-size:.9rem}
.ln{display:grid;grid-template-columns:1fr auto;gap:6px;padding:11px 16px;border-bottom:1px solid rgba(15,27,51,.05)}
.ln .nm{font-size:.88rem;font-weight:600;line-height:1.3}
.ln .sub{font-size:.72rem;color:var(--muted);margin-top:2px}
.ln .amt{font-weight:700;text-align:right;white-space:nowrap}
.qty{display:flex;align-items:center;gap:7px;margin-top:6px}
.qty button{width:25px;height:25px;border-radius:7px;border:1px solid var(--line);background:#fff;cursor:pointer;font-weight:700;line-height:1}
.qty button:hover{border-color:var(--green);color:var(--green-d)}
.qty span{font-family:var(--mono);font-size:.85rem;min-width:22px;text-align:center}
.rm{border:0;background:none;color:var(--faint);cursor:pointer;font-size:.75rem;text-decoration:underline;padding:0;margin-left:4px}
.rm:hover{color:var(--red)}
.totals{padding:13px 16px;border-top:1px solid var(--line);background:#FBFCFE}
.trow{display:flex;justify-content:space-between;font-size:.86rem;color:var(--muted);padding:2px 0}
.trow.big{font-size:1.35rem;font-weight:800;color:var(--ink);padding-top:8px;margin-top:6px;border-top:1px solid var(--line)}
.pay{padding:13px 16px 16px;display:grid;grid-template-columns:1fr 1fr;gap:9px}
.pay button{border:0;border-radius:12px;padding:14px 10px;font-weight:700;font-size:.92rem;cursor:pointer;color:#fff}
.pay .cash{background:var(--green);grid-column:1/-1;font-size:1.05rem;padding:16px}
.pay .card{background:#33418C}.pay .wallet{background:#7A3E9D}.pay .khata{background:#B7791F}
.pay button:disabled{opacity:.4;cursor:not-allowed}

/* modal */
.mask{position:fixed;inset:0;background:rgba(15,27,51,.5);display:none;align-items:center;justify-content:center;padding:20px;z-index:50}
.mask.on{display:flex}
.modal{background:#fff;border-radius:18px;padding:24px;width:min(400px,100%);box-shadow:0 30px 70px rgba(15,27,51,.3)}
.modal h3{font-size:1.15rem;margin-bottom:4px}
.modal p.m{color:var(--muted);font-size:.85rem;margin-bottom:16px}
.due{background:var(--green-t);border-radius:12px;padding:14px;text-align:center;margin-bottom:14px}
.due b{display:block;font-size:1.8rem;color:var(--green-d)}
.due span{font-size:.75rem;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.1em}
.modal input[type=number],.modal select{width:100%;padding:12px 14px;border:1.5px solid var(--line);border-radius:10px;margin-bottom:10px}
.quick{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px}
.quick button{border:1px solid var(--line);background:#fff;border-radius:9px;padding:8px 13px;font-weight:600;font-size:.85rem;cursor:pointer}
.quick button:hover{border-color:var(--green)}
.change{font-size:.95rem;font-weight:700;margin-bottom:12px}
.modal .go{width:100%;background:var(--green);color:#fff;border:0;border-radius:11px;padding:14px;font-weight:700;font-size:1rem;cursor:pointer}
.modal .cx{width:100%;background:none;border:0;color:var(--muted);padding:10px;cursor:pointer;font-size:.85rem;margin-top:4px}

/* day summary */
.day{max-width:760px;margin:22px auto;padding:0 20px}
.day .panel{padding:26px}
.dgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin:18px 0}
.dgrid div b{display:block;font-size:1.5rem;font-weight:800}
.dgrid div span{font-size:.78rem;color:var(--muted)}
table.z{width:100%;border-collapse:collapse;margin-top:10px;font-size:.88rem}
table.z th{text-align:left;font-family:var(--mono);font-size:.66rem;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);padding:8px 6px;border-bottom:1.5px solid var(--line)}
table.z td{padding:9px 6px;border-bottom:1px solid rgba(15,27,51,.05)}
.note{background:#FFF8E8;border:1px solid #F0DCAE;border-radius:12px;padding:13px 15px;font-size:.86rem;margin-top:16px}
@media (max-width:1080px){.wrap{grid-template-columns:1fr}.cart{position:static;max-height:none}}
</style>
</head>
<body>

<div class="demo-bar">
  <span><b>VectorERP POS</b> — live demo, nothing is saved</span>
  <a href="index.php?p=choose">↔ Switch demo</a>
  <a href="/">← vectorsolution.it</a>
  <a href="https://wa.me/<?= WA ?>?text=<?= rawurlencode('Hello, I saw the VectorERP POS demo and want it for my shop.') ?>" target="_blank" rel="noopener">Get this for my shop →</a>
</div>

<?php if ($p === 'day'): ?>
  <div class="day">
    <div class="panel">
      <h1 style="font-size:1.4rem">Day summary — <?= date('d M Y') ?></h1>
      <p style="color:var(--muted);font-size:.9rem"><?= $e($SHOP['shop']['name']) ?> · <?= $e($SHOP['shop']['till']) ?> · Cashier <?= $e($SHOP['shop']['cashier']) ?></p>
      <div class="dgrid">
        <div><b><?= (int)$tot['count'] ?></b><span>Receipts</span></div>
        <div><b><?= $rs($tot['total']) ?></b><span>Gross sales</span></div>
        <div><b><?= $rs($tot['tax']) ?></b><span>Sales tax collected</span></div>
        <div><b><?= $rs($tot['cash']) ?></b><span>Cash in drawer</span></div>
      </div>
      <table class="z">
        <tr><th>Receipt</th><th>Time</th><th>Items</th><th>Paid by</th><th style="text-align:right">Total</th></tr>
        <?php if (!$sales): ?>
          <tr><td colspan="5" style="color:var(--faint);padding:22px 6px">No sales yet — go back to the till and scan something.</td></tr>
        <?php endif; ?>
        <?php foreach ($sales as $s): ?>
          <tr>
            <td><a href="pos.php?p=receipt&amp;no=<?= $e($s['no']) ?>" style="color:var(--blue);font-weight:600"><?= $e($s['no']) ?></a></td>
            <td><?= $e($s['time']) ?></td>
            <td><?= count($s['lines']) ?></td>
            <td><?= $e(ucfirst($s['method'])) ?></td>
            <td style="text-align:right;font-weight:700"><?= $rs($s['total']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <div class="note"><b>Why this matters at closing time.</b> Cash in drawer, card, wallet and khata are split automatically, and the sales tax collected is already the figure that goes into your monthly return — no one sits with a calculator after the shutter comes down.</div>
      <p style="margin-top:18px"><a class="tbtn" href="pos.php">← Back to till</a></p>
    </div>
  </div>

<?php else: ?>
  <div class="topbar">
    <div>
      <div class="shop"><?= $e($SHOP['shop']['name']) ?></div>
      <div class="meta"><?= $e($SHOP['shop']['branch']) ?> · STRN <?= $e($SHOP['shop']['strn']) ?></div>
    </div>
    <span class="pill">● Till open</span>
    <span class="pill" style="background:#E8EEFC;color:#1E3FA6">FBR connected</span>
    <div class="spacer"></div>
    <div class="meta" style="text-align:right">Cashier <b><?= $e($SHOP['shop']['cashier']) ?></b><br><?= $e($SHOP['shop']['till']) ?> · <?= date('d M Y') ?></div>
    <a class="tbtn" href="pos.php?p=day">Day summary<?= $sales ? ' (' . count($sales) . ')' : '' ?></a>
  </div>

  <div class="wrap">
    <div class="panel">
      <div class="cats" id="cats">
        <button class="cat on" data-cat="">All items</button>
        <?php foreach ($SHOP['categories'] as $c): ?>
          <button class="cat" data-cat="<?= $e($c) ?>"><?= $e($c) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="tiles" id="tiles">
        <?php foreach ($SHOP['items'] as $it): ?>
          <button class="tile<?= $it['stock'] < 10 ? ' low' : '' ?>" data-cat="<?= $e($it['cat']) ?>" data-code="<?= $e($it['code']) ?>">
            <b><?= $e($it['name']) ?></b>
            <span class="pr">Rs <?= number_format((float)$it['price']) ?></span>
            <?php if ($it['tax'] == 0): ?><div class="tg">Exempt</div>
            <?php elseif (!empty($it['sch3'])): ?><div class="tg">3rd Schedule</div><?php endif; ?>
            <div class="bc"><?= $e($it['code']) ?></div>
            <?php if ($it['stock'] < 10): ?><div class="st">Only <?= (int)$it['stock'] ?> left</div><?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel cart">
      <div class="scan">
        <label for="scan">Scan barcode</label>
        <input id="scan" autocomplete="off" placeholder="Scan, or type a barcode and press Enter" autofocus>
        <p class="hint">A USB scanner just types the number and presses Enter — it works here exactly as it would at your counter. No scanner? Tap any item on the left, or type <code>8964000201457</code>.</p>
      </div>
      <div class="lines" id="lines"><div class="empty">Cart is empty.<br>Scan or tap an item to begin.</div></div>
      <div class="totals">
        <div class="trow"><span>Taxable value</span><span id="t-net">Rs 0</span></div>
        <div class="trow"><span>Sales tax</span><span id="t-tax">Rs 0</span></div>
        <div class="trow" id="t-exrow" style="display:none"><span>Exempt items</span><span id="t-ex">Rs 0</span></div>
        <div class="trow big"><span>Total</span><span id="t-tot">Rs 0</span></div>
      </div>
      <div class="pay">
        <button class="cash" id="b-cash" disabled>Cash</button>
        <button class="card" id="b-card" disabled>Card</button>
        <button class="wallet" id="b-wallet" disabled>JazzCash</button>
        <button class="khata" id="b-khata" disabled style="grid-column:1/-1">Khata (udhaar)</button>
      </div>
    </div>
  </div>

  <!-- cash / khata dialog -->
  <div class="mask" id="mask">
    <div class="modal">
      <h3 id="m-title">Cash payment</h3>
      <p class="m" id="m-sub">Enter the amount handed over — change is worked out for you.</p>
      <div class="due"><span>Amount due</span><b id="m-due">Rs 0</b></div>
      <div id="cash-fields">
        <div class="quick" id="quick"></div>
        <input type="number" id="tendered" min="0" step="1" placeholder="Cash received">
        <div class="change" id="change"></div>
      </div>
      <div id="khata-fields" style="display:none">
        <select id="khata">
          <?php foreach ($SHOP['khata'] as $k): ?>
            <option value="<?= $e($k['name']) ?>"><?= $e($k['name']) ?> — outstanding <?= $rs($k['balance']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="m">The amount is added to their running balance and they get a receipt marked <b>credit</b>.</p>
      </div>
      <button class="go" id="m-go">Complete sale &amp; print receipt</button>
      <button class="cx" id="m-cx">Cancel</button>
    </div>
  </div>

  <form id="payform" method="post" action="pos.php?p=pay" style="display:none">
    <input type="hidden" name="cart" id="f-cart">
    <input type="hidden" name="method" id="f-method">
    <input type="hidden" name="tendered" id="f-tendered">
    <input type="hidden" name="khata" id="f-khata">
  </form>

  <script>
  (function () {
    var ITEMS = <?= json_encode(array_map(static fn($i) => [
        'code' => $i['code'], 'name' => $i['name'], 'price' => $i['price'],
        'tax' => $i['tax'], 'cat' => $i['cat'],
    ], $SHOP['items']), JSON_UNESCAPED_UNICODE) ?>;
    var BY = {}; ITEMS.forEach(function (i) { BY[i.code] = i; });

    var cart = [];                                  // [{code, qty}]
    var linesEl = document.getElementById('lines');
    var scan = document.getElementById('scan');

    function money(n) { return 'Rs ' + Math.round(n).toLocaleString('en-PK'); }

    function totals() {
      var net = 0, tax = 0, ex = 0;
      cart.forEach(function (l) {
        var it = BY[l.code], gross = it.price * l.qty;
        if (it.tax > 0) { var n = gross / (1 + it.tax / 100); net += n; tax += gross - n; }
        else { ex += gross; }
      });
      return { net: net, tax: tax, ex: ex, total: net + tax + ex };
    }

    function render() {
      if (!cart.length) {
        linesEl.innerHTML = '<div class="empty">Cart is empty.<br>Scan or tap an item to begin.</div>';
      } else {
        var html = '';
        cart.forEach(function (l, idx) {
          var it = BY[l.code];
          html += '<div class="ln"><div><div class="nm">' + it.name + '</div>' +
                  '<div class="sub">' + money(it.price) + (it.tax === 0 ? ' · exempt' : ' · incl ' + it.tax + '% tax') + '</div>' +
                  '<div class="qty"><button data-a="-" data-i="' + idx + '">−</button><span>' + l.qty + '</span>' +
                  '<button data-a="+" data-i="' + idx + '">+</button>' +
                  '<button class="rm" data-a="x" data-i="' + idx + '">remove</button></div></div>' +
                  '<div class="amt">' + money(it.price * l.qty) + '</div></div>';
        });
        linesEl.innerHTML = html;
      }
      var t = totals();
      document.getElementById('t-net').textContent = money(t.net);
      document.getElementById('t-tax').textContent = money(t.tax);
      document.getElementById('t-tot').textContent = money(t.total);
      document.getElementById('t-exrow').style.display = t.ex > 0 ? 'flex' : 'none';
      document.getElementById('t-ex').textContent = money(t.ex);
      ['b-cash', 'b-card', 'b-wallet', 'b-khata'].forEach(function (id) {
        document.getElementById(id).disabled = !cart.length;
      });
    }

    function add(code) {
      var it = BY[code];
      if (!it) { flash('Barcode not recognised'); return; }
      var line = cart.find(function (l) { return l.code === code; });
      if (line) { line.qty++; } else { cart.push({ code: code, qty: 1 }); }
      render();
    }

    function flash(msg) {
      scan.style.borderColor = '#B32222';
      scan.placeholder = msg;
      setTimeout(function () { scan.style.borderColor = ''; scan.placeholder = 'Scan, or type a barcode and press Enter'; }, 1600);
    }

    linesEl.addEventListener('click', function (ev) {
      var b = ev.target.closest('button'); if (!b) return;
      var i = +b.getAttribute('data-i'), a = b.getAttribute('data-a');
      if (a === '+') cart[i].qty++;
      else if (a === '-') { cart[i].qty--; if (cart[i].qty < 1) cart.splice(i, 1); }
      else if (a === 'x') cart.splice(i, 1);
      render(); scan.focus();
    });

    document.getElementById('tiles').addEventListener('click', function (ev) {
      var t = ev.target.closest('.tile'); if (!t) return;
      add(t.getAttribute('data-code')); scan.focus();
    });

    document.getElementById('cats').addEventListener('click', function (ev) {
      var c = ev.target.closest('.cat'); if (!c) return;
      var want = c.getAttribute('data-cat');
      [].forEach.call(document.querySelectorAll('.cat'), function (x) { x.classList.toggle('on', x === c); });
      [].forEach.call(document.querySelectorAll('.tile'), function (t) {
        t.style.display = (!want || t.getAttribute('data-cat') === want) ? '' : 'none';
      });
    });

    // A barcode scanner is a keyboard: it types the digits then sends Enter.
    scan.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter') return;
      ev.preventDefault();
      var code = scan.value.trim();
      scan.value = '';
      if (code) add(code);
    });
    document.addEventListener('click', function (ev) {
      if (!ev.target.closest('.modal') && !ev.target.closest('input')) scan.focus();
    });

    /* ---- payment ---- */
    var mask = document.getElementById('mask'), method = 'cash';
    function openPay(m) {
      method = m;
      var t = totals();
      document.getElementById('m-due').textContent = money(t.total);
      var isCash = m === 'cash', isKhata = m === 'khata';
      document.getElementById('cash-fields').style.display = isCash ? '' : 'none';
      document.getElementById('khata-fields').style.display = isKhata ? '' : 'none';
      document.getElementById('m-title').textContent =
        isCash ? 'Cash payment' : isKhata ? 'Put on khata' : (m === 'card' ? 'Card payment' : 'JazzCash / Easypaisa');
      document.getElementById('m-sub').textContent =
        isCash ? 'Enter the amount handed over — change is worked out for you.'
               : isKhata ? 'Choose the customer whose running balance this goes on.'
               : 'Tap complete once the terminal approves the payment.';
      if (isCash) {
        var q = document.getElementById('quick'); q.innerHTML = '';
        var due = Math.ceil(t.total);
        [due, Math.ceil(due / 500) * 500, Math.ceil(due / 1000) * 1000, Math.ceil(due / 5000) * 5000]
          .filter(function (v, i, a) { return a.indexOf(v) === i; })
          .forEach(function (v) {
            var b = document.createElement('button');
            b.textContent = v === due ? 'Exact ' + money(v) : money(v);
            b.onclick = function () { document.getElementById('tendered').value = v; showChange(); };
            q.appendChild(b);
          });
        document.getElementById('tendered').value = '';
        document.getElementById('change').textContent = '';
      }
      mask.classList.add('on');
      setTimeout(function () { if (isCash) document.getElementById('tendered').focus(); }, 60);
    }
    function showChange() {
      var t = totals(), got = parseFloat(document.getElementById('tendered').value || '0');
      var el = document.getElementById('change');
      if (!got) { el.textContent = ''; return; }
      if (got < t.total) { el.style.color = '#B32222'; el.textContent = 'Short by ' + money(t.total - got); }
      else { el.style.color = '#0B8043'; el.textContent = 'Change to return: ' + money(got - t.total); }
    }
    document.getElementById('tendered').addEventListener('input', showChange);
    document.getElementById('b-cash').onclick = function () { openPay('cash'); };
    document.getElementById('b-card').onclick = function () { openPay('card'); };
    document.getElementById('b-wallet').onclick = function () { openPay('wallet'); };
    document.getElementById('b-khata').onclick = function () { openPay('khata'); };
    document.getElementById('m-cx').onclick = function () { mask.classList.remove('on'); scan.focus(); };
    mask.addEventListener('click', function (ev) { if (ev.target === mask) { mask.classList.remove('on'); scan.focus(); } });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { mask.classList.remove('on'); scan.focus(); } });

    document.getElementById('m-go').onclick = function () {
      if (!cart.length) return;
      var t = totals();
      if (method === 'cash') {
        var got = parseFloat(document.getElementById('tendered').value || '0');
        if (!got) { document.getElementById('tendered').value = Math.ceil(t.total); got = Math.ceil(t.total); }
        if (got < t.total) { showChange(); return; }
        document.getElementById('f-tendered').value = got;
      }
      document.getElementById('f-cart').value = JSON.stringify(cart);
      document.getElementById('f-method').value = method;
      document.getElementById('f-khata').value = method === 'khata' ? document.getElementById('khata').value : '';
      document.getElementById('payform').submit();
    };

    render();
  })();
  </script>
<?php endif; ?>

</body>
</html>
