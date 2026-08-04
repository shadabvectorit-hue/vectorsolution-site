<?php
/**
 * VectorERP demo — point of sale, in three modes.
 *
 *   retail  general store: barcode, mixed tax, khata
 *   resto   restaurant: tables, kitchen tickets, SRB service tax (not FBR)
 *   pharma  chemist: batch + expiry, first-expiry-first-out picking
 *
 * The till feel is deliberate: the barcode box keeps focus (a USB scanner is
 * just a keyboard that types and presses Enter) and the cart lives in the
 * browser so scanning is instant. Money is always recomputed on the server
 * from the catalogue — the browser is never trusted with a price.
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

if (empty($_SESSION['demo_ok'])) { header('Location: index.php'); exit; }

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

/* ---------- which till are we standing at? ---------- */
$mode = (string)($_GET['mode'] ?? $_SESSION['pos_mode'] ?? 'retail');
if (!in_array($mode, ['retail', 'resto', 'pharma'], true)) $mode = 'retail';
$_SESSION['pos_mode'] = $mode;
$today = date('Y-m-d');

/** Earliest-expiring batch that has not expired — a chemist sells FEFO, not FIFO. */
function fefo(array $batches, string $today): ?array {
    $live = array_filter($batches, static fn($b) => $b['exp'] >= $today && $b['qty'] > 0);
    if (!$live) return null;
    usort($live, static fn($a, $b) => strcmp($a['exp'], $b['exp']));
    return $live[0];
}

$CAT = [];   // normalised catalogue for this mode: code => line template
if ($mode === 'resto') {
    $R = $SHOP['resto'];
    $M = ['name' => $R['name'], 'branch' => $R['branch'], 'cats' => $R['categories'],
          'inclusive' => false, 'taxRate' => $R['tax'], 'taxLabel' => 'SRB service tax ' . $R['tax'] . '%',
          'authority' => 'Sindh Revenue Board', 'docLabel' => 'SRB POS Invoice',
          'idLabel' => 'SRB Reg', 'id' => $R['srb'], 'ntn' => $R['ntn'],
          'staffLabel' => 'Waiter', 'staff' => $R['waiter'], 'accent' => '#B7791F'];
    foreach ($R['menu'] as $i) {
        $CAT[$i['code']] = ['code' => $i['code'], 'name' => $i['name'], 'cat' => $i['cat'],
                            'price' => $i['price'], 'tax' => $R['tax'], 'sch3' => false];
    }
} elseif ($mode === 'pharma') {
    $P = $SHOP['pharma'];
    $M = ['name' => $P['name'], 'branch' => $P['branch'], 'cats' => $P['categories'],
          'inclusive' => true, 'taxRate' => 18, 'taxLabel' => 'Sales tax',
          'authority' => 'FBR', 'docLabel' => 'FBR Digital Invoice',
          'idLabel' => 'STRN', 'id' => $P['strn'], 'ntn' => $P['ntn'],
          'staffLabel' => 'Pharmacist', 'staff' => $P['pharmacist'], 'accent' => '#0E7490',
          'licence' => $P['licence']];
    foreach ($P['items'] as $i) {
        $b = fefo($i['batches'], $today);
        $CAT[$i['code']] = ['code' => $i['code'], 'name' => $i['name'], 'cat' => $i['cat'],
                            'price' => $i['price'], 'tax' => $i['tax'], 'sch3' => false,
                            'batch' => $b['b'] ?? '', 'exp' => $b['exp'] ?? '',
                            'stock' => $b['qty'] ?? 0, 'dead' => $b === null];
    }
} else {
    $S = $SHOP['shop'];
    $M = ['name' => $S['name'], 'branch' => $S['branch'], 'cats' => $SHOP['categories'],
          'inclusive' => true, 'taxRate' => 18, 'taxLabel' => 'Sales tax',
          'authority' => 'FBR', 'docLabel' => 'FBR Digital Invoice',
          'idLabel' => 'STRN', 'id' => $S['strn'], 'ntn' => $S['ntn'],
          'staffLabel' => 'Cashier', 'staff' => $S['cashier'], 'accent' => '#0B8043',
          'till' => $S['till']];
    foreach ($SHOP['items'] as $i) {
        $CAT[$i['code']] = ['code' => $i['code'], 'name' => $i['name'], 'cat' => $i['cat'],
                            'price' => $i['price'], 'tax' => $i['tax'], 'sch3' => !empty($i['sch3']),
                            'stock' => $i['stock']];
    }
}

// Their own name on the shopfront and on every printed document.
if (!empty($_SESSION['biz'])) { $M['name'] = (string)$_SESSION['biz']; }

/** Turn a posted cart into priced lines + totals, using catalogue prices only. */
function priceCart($raw, array $CAT, bool $inclusive): array {
    $lines = []; $net = $tax = $exempt = 0.0;
    if (!is_array($raw)) return [[], 0.0, 0.0, 0.0];
    foreach (array_slice($raw, 0, 60) as $row) {
        $code = (string)($row['code'] ?? '');
        if (!isset($CAT[$code])) continue;
        $qty = (int)($row['qty'] ?? 0);
        if ($qty < 1) continue;
        $qty = min($qty, 999);
        $it = $CAT[$code];
        if (!empty($it['dead'])) continue;                 // expired stock never sells
        $line = ['name' => $it['name'], 'code' => $code, 'qty' => $qty, 'price' => $it['price'],
                 'tax' => $it['tax'], 'sch3' => !empty($it['sch3']),
                 'batch' => $it['batch'] ?? '', 'exp' => $it['exp'] ?? ''];
        if ($it['tax'] > 0) {
            if ($inclusive) {                               // shelf price already carries the tax
                $g = $it['price'] * $qty;
                $n = $g / (1 + $it['tax'] / 100);
                $line['gross'] = $g; $net += $n; $tax += $g - $n;
            } else {                                        // menu price is before tax
                $n = $it['price'] * $qty;
                $t = $n * $it['tax'] / 100;
                $line['gross'] = $n + $t; $net += $n; $tax += $t;
            }
        } else {
            $g = $it['price'] * $qty;
            $line['gross'] = $g; $exempt += $g;
        }
        $lines[] = $line;
    }
    return [$lines, $net, $tax, $exempt];
}

$p = $_GET['p'] ?? 'till';
$tables = $_SESSION['tables'] ?? [];      // resto: table => cart rows
$table  = (string)($_GET['table'] ?? '');
if ($table !== '' && !in_array($table, $SHOP['resto']['tables'], true)) $table = '';

/* ---------- send an order to the kitchen ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['p'] ?? '') === 'kot') {
    $raw = json_decode((string)($_POST['cart'] ?? '[]'), true);
    $t = (string)($_POST['table'] ?? '');
    if (!in_array($t, $SHOP['resto']['tables'], true)) { header('Location: pos.php?mode=resto'); exit; }
    [$lines] = priceCart($raw, $CAT, false);
    if (!$lines) { header('Location: pos.php?mode=resto&table=' . urlencode($t)); exit; }
    $_SESSION['tables'][$t] = array_map(static fn($l) => ['code' => $l['code'], 'qty' => $l['qty']], $lines);
    $_SESSION['kot_seq'] = (int)($_SESSION['kot_seq'] ?? 0) + 1;
    posTrack('demo_pos_kot');
    $kotNo = 'KOT-' . str_pad((string)$_SESSION['kot_seq'], 4, '0', STR_PAD_LEFT);
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex">
    <title><?= $e($kotNo) ?> — Kitchen ticket</title>
    <style>
      *{box-sizing:border-box;margin:0;padding:0}
      body{font-family:"Segoe UI",Arial,sans-serif;background:#eef2f8;padding:20px}
      .bar{max-width:420px;margin:0 auto 14px;display:flex;gap:9px;flex-wrap:wrap}
      .bar a,.bar button{border:0;border-radius:999px;padding:10px 18px;font:600 13px "Segoe UI",sans-serif;cursor:pointer;text-decoration:none}
      .back{background:#fff;color:#0F1B33;border:1px solid #cbd5e1}.print{background:#B7791F;color:#fff}
      .tip{max-width:420px;margin:0 auto 14px;font-size:12.5px;color:#475569;line-height:1.5}
      .paper{width:302px;margin:0 auto;background:#fff;padding:16px 14px 22px;box-shadow:0 10px 30px rgba(15,27,51,.14);
             font-family:"Courier New",monospace;font-size:13px;color:#000;line-height:1.5}
      .c{text-align:center}
      .paper h1{font-size:19px;letter-spacing:2px}
      .tbl{font-size:26px;font-weight:bold;text-align:center;border:2px solid #000;padding:6px;margin:8px 0}
      hr{border:0;border-top:1px dashed #000;margin:8px 0}
      table{width:100%;border-collapse:collapse}td{padding:3px 0;vertical-align:top}
      .q{width:34px;font-weight:bold;font-size:15px}
      @media print{@page{size:80mm auto;margin:0}body{background:#fff;padding:0}.bar,.tip{display:none}
        .paper{width:72mm;box-shadow:none;padding:2mm 2mm 6mm;margin:0}}
    </style></head><body>
    <div class="bar">
      <a class="back" href="pos.php?mode=resto&table=<?= $e($t) ?>">← Back to table</a>
      <button class="print" onclick="window.print()">🖨 Print ticket</button>
    </div>
    <p class="tip">The kitchen copy carries no prices — only what to cook, for which table. It prints on the kitchen roll while the bill stays at the counter.</p>
    <div class="paper">
      <div class="c"><h1>KITCHEN</h1><div><?= $e($kotNo) ?> · <?= date('H:i') ?></div></div>
      <div class="tbl"><?= $e($t) ?></div>
      <div class="c" style="font-size:11px">Waiter: <?= $e($M['staff']) ?></div>
      <hr>
      <table>
        <?php foreach ($lines as $l): ?>
          <tr><td class="q"><?= (int)$l['qty'] ?></td><td><?= $e($l['name']) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <hr>
      <div class="c" style="font-size:11px">Order placed <?= date('d M Y H:i') ?></div>
    </div>
    </body></html><?php
    exit;
}

/* ---------- checkout ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['p'] ?? '') === 'pay') {
    $raw = json_decode((string)($_POST['cart'] ?? '[]'), true);
    $method = (string)($_POST['method'] ?? 'cash');
    if (!in_array($method, ['cash', 'card', 'wallet', 'khata'], true)) $method = 'cash';

    [$lines, $net, $tax, $exempt] = priceCart($raw, $CAT, (bool)$M['inclusive']);
    if (!$lines) { header('Location: pos.php?empty=1'); exit; }

    $total = $net + $tax + $exempt;
    $tendered = max(0.0, (float)($_POST['tendered'] ?? 0));
    $seq = (int)($_SESSION['pos_seq'] ?? 0) + 1;
    $_SESSION['pos_seq'] = $seq;
    $no = ($mode === 'resto' ? 'B-' : 'R-') . str_pad((string)(4180 + $seq), 5, '0', STR_PAD_LEFT);
    $payTable = (string)($_POST['table'] ?? '');
    if (!in_array($payTable, $SHOP['resto']['tables'], true)) $payTable = '';

    $sale = [
        'no' => $no, 'mode' => $mode, 'time' => date('d M Y H:i'), 'lines' => $lines,
        'net' => round($net, 2), 'tax' => round($tax, 2), 'exempt' => round($exempt, 2),
        'total' => round($total, 2), 'method' => $method, 'tendered' => $tendered,
        'change' => $method === 'cash' ? max(0.0, $tendered - $total) : 0.0,
        'khata' => $method === 'khata' ? trim(mb_substr((string)($_POST['khata'] ?? ''), 0, 60)) : '',
        'table' => $payTable,
        'ordertype' => in_array((string)($_POST['ordertype'] ?? ''), ['Dine-in', 'Takeaway', 'Delivery'], true) ? (string)$_POST['ordertype'] : '',
        'fbr' => ($mode === 'resto' ? '3300SRB' : '7000007DI') . (time() * 1000 + $seq),
    ];
    $sales = $_SESSION['pos_sales'] ?? [];
    array_unshift($sales, $sale);
    $_SESSION['pos_sales'] = array_slice($sales, 0, 50);
    if ($payTable !== '') { unset($_SESSION['tables'][$payTable]); }   // table is free again
    posTrack('demo_pos_sale');
    header('Location: pos.php?p=receipt&no=' . urlencode($no)); exit;
}

$sales = $_SESSION['pos_sales'] ?? [];

/* ---------- customer receipt ---------- */
if ($p === 'receipt') {
    $no = (string)($_GET['no'] ?? '');
    $sale = null;
    foreach ($sales as $s) { if ($s['no'] === $no) { $sale = $s; break; } }
    if (!$sale) { header('Location: pos.php'); exit; }
    posTrack('demo_pos_receipt');
    $smode = $sale['mode'] ?? 'retail';
    $isResto = $smode === 'resto'; $isPharma = $smode === 'pharma';
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
      .print{background:<?= $e($M['accent']) ?>;color:#fff}
      .wa{background:#0B8043;color:#fff}
      .tip{max-width:420px;margin:0 auto 14px;font-size:12.5px;color:#475569;line-height:1.5}
      .paper{width:302px;margin:0 auto;background:#fff;padding:16px 14px 22px;box-shadow:0 10px 30px rgba(15,27,51,.14);
             font-family:"Courier New",monospace;font-size:12px;color:#000;line-height:1.45}
      .c{text-align:center}.r{text-align:right}
      .paper h1{font-size:16px;letter-spacing:1px;margin-bottom:2px}
      .sub{font-size:10.5px;line-height:1.4}
      hr{border:0;border-top:1px dashed #000;margin:8px 0}
      table{width:100%;border-collapse:collapse}td{padding:1px 0;vertical-align:top}
      .qtycol{width:26px}.amtcol{width:66px;text-align:right}
      .tot td{font-weight:bold;font-size:13px}
      .qr{width:70px;height:70px;margin:8px auto 4px;
          background:repeating-linear-gradient(0deg,#000 0 3px,transparent 3px 6px),repeating-linear-gradient(90deg,#000 0 3px,#fff 3px 6px)}
      .foot{font-size:10px;line-height:1.45}
      @media print{@page{size:80mm auto;margin:0}body{background:#fff;padding:0}.bar,.tip{display:none}
        .paper{width:72mm;box-shadow:none;padding:2mm 2mm 6mm;margin:0}}
    </style></head><body>
    <?php
      $waText = "Assalam o alaikum. I just rang up a sale on your VectorERP demo —\n"
              . "Receipt {$sale['no']} · Total Rs " . number_format($sale['total']) . "\n"
              . ($isResto ? "Sindh service tax" : "Sales tax") . " Rs " . number_format($sale['tax']) . "\n\n"
              . "I want this for " . ($M['name']) . ". Please tell me the cost and how long setup takes.";
    ?>
    <div class="bar">
      <a class="back" href="pos.php">← Back to till</a>
      <button class="print" onclick="window.print()">🖨 Print receipt</button>
      <a class="wa" href="https://wa.me/<?= WA ?>?text=<?= rawurlencode($waText) ?>" target="_blank" rel="noopener">💬 I want this for my business</a>
      <a class="back" href="pos.php?p=day">Day summary</a>
    </div>
    <p class="tip">A real 80&nbsp;mm thermal layout — on a receipt printer this comes out exactly like this, with no page margins. On a normal printer choose <b>Save as PDF</b>.</p>
    <div class="paper">
      <div class="c">
        <h1><?= $e($M['name']) ?></h1>
        <div class="sub"><?= $e($M['branch']) ?><br>
        NTN <?= $e($M['ntn']) ?> · <?= $e($M['idLabel']) ?> <?= $e($M['id']) ?>
        <?php if ($isPharma): ?><br>Drug sale licence <?= $e($M['licence']) ?><?php endif; ?></div>
      </div>
      <hr>
      <table class="sub">
        <tr><td><?= $isResto ? 'Bill' : 'Receipt' ?></td><td class="r"><?= $e($sale['no']) ?></td></tr>
        <tr><td>Date</td><td class="r"><?= $e($sale['time']) ?></td></tr>
        <?php if ($isResto && $sale['table']): ?>
          <tr><td>Table</td><td class="r"><?= $e($sale['table']) ?><?= $sale['ordertype'] ? ' · ' . $e($sale['ordertype']) : '' ?></td></tr>
        <?php elseif ($isResto && $sale['ordertype']): ?>
          <tr><td>Order</td><td class="r"><?= $e($sale['ordertype']) ?></td></tr>
        <?php endif; ?>
        <tr><td><?= $e($M['staffLabel']) ?></td><td class="r"><?= $e($M['staff']) ?></td></tr>
      </table>
      <hr>
      <table>
        <?php foreach ($sale['lines'] as $l): ?>
          <tr><td colspan="3"><?= $e($l['name']) ?><?= $l['tax'] == 0 ? ' *' : '' ?><?= !empty($l['sch3']) ? ' †' : '' ?></td></tr>
          <?php if (!empty($l['batch'])): ?>
            <tr><td colspan="3" style="font-size:10px">   Batch <?= $e($l['batch']) ?> · Exp <?= $e(date('m/Y', strtotime($l['exp']))) ?></td></tr>
          <?php endif; ?>
          <tr>
            <td class="qtycol"><?= (int)$l['qty'] ?> x</td>
            <td><?= number_format((float)$l['price']) ?></td>
            <td class="amtcol"><?= number_format((float)$l['gross']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <hr>
      <table>
        <tr><td><?= $isResto ? 'Food & service' : 'Taxable value' ?></td><td class="r"><?= number_format($sale['net']) ?></td></tr>
        <tr><td><?= $e($M['taxLabel']) ?></td><td class="r"><?= number_format($sale['tax']) ?></td></tr>
        <?php if (($sale['exempt'] ?? 0) > 0): ?>
          <tr><td>Exempt items *</td><td class="r"><?= number_format($sale['exempt']) ?></td></tr>
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
        <div class="sub"><?= $e($M['docLabel']) ?></div>
        <div class="sub" style="word-break:break-all"><?= $e($sale['fbr']) ?></div>
        <div class="qr" aria-hidden="true"></div>
        <div class="foot"><?= $isResto ? 'Verify with the SRB invoice verification service' : 'Scan with FBR Tax Asaan to verify' ?></div>
      </div>
      <hr>
      <div class="foot">
        <?php if ($isPharma): ?>
          * no sales tax on this item<br>
          Medicines are not returnable. Check the expiry before use.<br>
          Dispensed under licence <?= $e($M['licence']) ?><br><br>
        <?php elseif ($isResto): ?>
          Service charge is not levied.<br>
          Sindh sales tax on services is collected on behalf of the<br>Sindh Revenue Board.<br><br>
        <?php else: ?>
          * exempt from sales tax<br>
          † 3rd Schedule — tax on printed retail price<br>
          Goods once sold are exchangeable within 7 days with this receipt.<br><br>
        <?php endif; ?>
        <div class="c"><b>Shukriya — thank you!</b></div>
      </div>
    </div>
    </body></html><?php
    exit;
}

/* ---------- expiry watch (pharmacy) ---------- */
if ($p === 'expiry') {
    $soon = [];
    foreach ($SHOP['pharma']['items'] as $i) {
        foreach ($i['batches'] as $b) {
            $days = (int)floor((strtotime($b['exp']) - strtotime($today)) / 86400);
            if ($days <= 120) {
                $soon[] = ['name' => $i['name'], 'batch' => $b['b'], 'exp' => $b['exp'],
                           'qty' => $b['qty'], 'days' => $days, 'value' => $b['qty'] * $i['price']];
            }
        }
    }
    usort($soon, static fn($a, $b) => $a['days'] <=> $b['days']);
}

/* ---------- day summary ---------- */
if ($p === 'day') {
    $tot = ['count' => count($sales), 'net' => 0.0, 'tax' => 0.0, 'total' => 0.0,
            'cash' => 0.0, 'card' => 0.0, 'wallet' => 0.0, 'khata' => 0.0];
    foreach ($sales as $s) {
        $tot['net'] += $s['net']; $tot['tax'] += $s['tax']; $tot['total'] += $s['total'];
        $tot[$s['method']] += $s['total'];
    }
}

$MODE_TABS = ['retail' => 'General store', 'resto' => 'Restaurant', 'pharma' => 'Pharmacy'];
$openOrder = ($mode === 'resto' && $table !== '') ? ($tables[$table] ?? []) : [];
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
      --acc:<?= $e($M['accent']) ?>;--acc-t:color-mix(in srgb,<?= $e($M['accent']) ?> 12%,#fff);
      --blue:#2E5BDB;--amber:#B7791F;--red:#B32222;
      --mono:"IBM Plex Mono",monospace;--disp:"Archivo",system-ui,sans-serif}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--disp);background:var(--paper);color:var(--ink);font-size:15px;line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
button,input,select{font:inherit}
.demo-bar{background:linear-gradient(90deg,#12224A,var(--acc));color:#fff;padding:9px 18px;font-size:.84rem;display:flex;gap:14px;align-items:center;justify-content:center;flex-wrap:wrap;text-align:center}
.demo-bar b{font-weight:700}
.demo-bar a{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.3);border-radius:999px;padding:4px 14px;font-weight:600;white-space:nowrap}
.demo-bar a:hover{background:rgba(255,255,255,.3)}

.modes{background:#fff;border-bottom:1px solid var(--line);padding:9px 20px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.modes .lbl{font-family:var(--mono);font-size:.66rem;letter-spacing:.12em;text-transform:uppercase;color:var(--faint);margin-right:4px}
.modes a{border:1px solid var(--line);border-radius:999px;padding:6px 15px;font-size:.85rem;font-weight:600;color:var(--muted)}
.modes a.on{background:var(--acc);border-color:var(--acc);color:#fff}
.modes a:hover{border-color:var(--acc)}

.topbar{background:#fff;border-bottom:1px solid var(--line);padding:12px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.topbar .shop{font-weight:800;font-size:1.05rem}
.topbar .meta{color:var(--muted);font-size:.82rem}
.topbar .spacer{flex:1}
.pill{font-family:var(--mono);font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;background:var(--acc-t);color:var(--acc);border-radius:999px;padding:5px 12px;font-weight:600}
.tbtn{border:1px solid var(--line);background:#fff;border-radius:999px;padding:8px 16px;font-size:.85rem;font-weight:600;cursor:pointer}
.tbtn:hover{border-color:var(--acc);color:var(--acc)}

/* tables strip */
.tables{background:#fff;border-bottom:1px solid var(--line);padding:12px 20px;display:flex;gap:9px;align-items:center;flex-wrap:wrap}
.tables .lbl{font-family:var(--mono);font-size:.66rem;letter-spacing:.12em;text-transform:uppercase;color:var(--faint)}
.tbl{border:1.5px solid var(--line);border-radius:11px;padding:9px 15px;font-weight:700;font-size:.92rem;min-width:64px;text-align:center;color:var(--muted);background:#fff}
.tbl small{display:block;font-weight:600;font-size:.66rem;color:var(--faint)}
.tbl.busy{border-color:var(--amber);background:#FFF8E8;color:#8A5A0B}
.tbl.busy small{color:#B7791F}
.tbl.on{border-color:var(--acc);background:var(--acc);color:#fff;box-shadow:0 6px 16px rgba(183,121,31,.28)}
.tbl.on small{color:rgba(255,255,255,.85)}

.wrap{display:grid;grid-template-columns:1fr 400px;gap:18px;padding:18px 20px 30px;align-items:start;max-width:1500px;margin:0 auto}
/* Grid and flex children default to min-width:auto, so one long barcode or
   product name can push a column wider than a phone screen. */
.wrap>*{min-width:0}
.panel{background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:0 1px 3px rgba(15,27,51,.05)}
.cats{display:flex;gap:8px;padding:14px 16px 0;flex-wrap:wrap}
.cat{border:1px solid var(--line);background:#fff;border-radius:999px;padding:7px 15px;font-size:.85rem;font-weight:600;cursor:pointer;color:var(--muted)}
.cat.on{background:var(--acc);border-color:var(--acc);color:#fff}
.tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:11px;padding:16px}
.tile{border:1px solid var(--line);border-radius:13px;padding:12px;background:#fff;cursor:pointer;text-align:left;transition:transform .07s,border-color .12s,box-shadow .12s}
.tile:hover{border-color:var(--acc);box-shadow:0 6px 18px rgba(15,27,51,.12)}
.tile:active{transform:scale(.97)}
.tile{overflow:hidden}
.tile b{display:block;font-size:.9rem;line-height:1.3;margin-bottom:5px;overflow-wrap:anywhere}
.tile .pr{color:var(--acc);font-weight:800}
.tile .bc{font-family:var(--mono);font-size:.62rem;color:var(--faint);margin-top:5px;overflow-wrap:anywhere}
.tile .tg{font-family:var(--mono);font-size:.6rem;text-transform:uppercase;letter-spacing:.08em;color:var(--amber);margin-top:3px}
.tile .exp{font-family:var(--mono);font-size:.64rem;margin-top:5px;color:var(--muted)}
.tile.warn{border-color:#F0D3A0;background:#FFFCF5}
.tile.warn .exp{color:#B7791F;font-weight:700}
.tile.dead{opacity:.45;cursor:not-allowed}
.tile .st{font-size:.66rem;color:var(--amber);font-weight:700}

.cart{position:sticky;top:14px;display:flex;flex-direction:column;max-height:calc(100vh - 30px)}
.scan{padding:14px 16px;border-bottom:1px solid var(--line)}
.scan label{display:block;font-family:var(--mono);font-size:.66rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
.scan input{width:100%;padding:13px 14px;border:2px solid var(--acc);border-radius:11px;font-family:var(--mono);font-size:1rem;outline:none}
.scan input::placeholder{color:var(--faint);font-family:var(--disp)}
.scan .hint{font-size:.72rem;color:var(--muted);margin-top:7px}
.otype{display:flex;gap:6px;padding:12px 16px 0}
.otype button{flex:1;border:1px solid var(--line);background:#fff;border-radius:9px;padding:8px 6px;font-size:.8rem;font-weight:600;cursor:pointer;color:var(--muted)}
.otype button.on{background:var(--acc);border-color:var(--acc);color:#fff}
.lines{overflow-y:auto;flex:1;min-height:110px}
.empty{padding:38px 20px;text-align:center;color:var(--faint);font-size:.9rem}
.ln{display:grid;grid-template-columns:1fr auto;gap:6px;padding:11px 16px;border-bottom:1px solid rgba(15,27,51,.05)}
.ln>div{min-width:0}
.ln .nm{font-size:.88rem;font-weight:600;line-height:1.3;overflow-wrap:anywhere}
.ln .sub{font-size:.72rem;color:var(--muted);margin-top:2px}
.ln .amt{font-weight:700;text-align:right;white-space:nowrap}
.qty{display:flex;align-items:center;gap:7px;margin-top:6px}
.qty button{width:25px;height:25px;border-radius:7px;border:1px solid var(--line);background:#fff;cursor:pointer;font-weight:700;line-height:1}
.qty span{font-family:var(--mono);font-size:.85rem;min-width:22px;text-align:center}
.rm{border:0;background:none;color:var(--faint);cursor:pointer;font-size:.75rem;text-decoration:underline;padding:0;margin-left:4px}
.rm:hover{color:var(--red)}
/* Nobody should land on a till and wonder what to do. Disappears on first tap. */
.nudge{margin:0 16px 14px;background:var(--acc-t);border:1px dashed var(--acc);border-radius:12px;
  padding:11px 14px;font-size:.83rem;color:var(--ink);line-height:1.5;animation:nudge 2.4s ease-in-out infinite}
.nudge b{color:var(--acc);display:block;margin-bottom:2px}
.nudge code{font-family:var(--mono);font-size:.92em;background:#fff;border-radius:5px;padding:1px 6px}
@keyframes nudge{0%,100%{transform:translateY(0)}50%{transform:translateY(-3px)}}
@media (prefers-reduced-motion:reduce){.nudge{animation:none}}
.totals{padding:13px 16px;border-top:1px solid var(--line);background:#FBFCFE}
.trow{display:flex;justify-content:space-between;font-size:.86rem;color:var(--muted);padding:2px 0}
.trow.big{font-size:1.35rem;font-weight:800;color:var(--ink);padding-top:8px;margin-top:6px;border-top:1px solid var(--line)}
.pay{padding:13px 16px 16px;display:grid;grid-template-columns:1fr 1fr;gap:9px}
.pay button{border:0;border-radius:12px;padding:14px 10px;font-weight:700;font-size:.92rem;cursor:pointer;color:#fff}
.pay .cash{background:var(--acc);grid-column:1/-1;font-size:1.05rem;padding:16px}
.pay .kot{background:#8A5A0B;grid-column:1/-1;font-size:1rem;padding:15px}
.pay .card{background:#33418C}.pay .wallet{background:#7A3E9D}.pay .khata{background:#B7791F}
.pay button:disabled{opacity:.4;cursor:not-allowed}

.mask{position:fixed;inset:0;background:rgba(15,27,51,.5);display:none;align-items:center;justify-content:center;padding:20px;z-index:50}
.mask.on{display:flex}
.modal{background:#fff;border-radius:18px;padding:24px;width:min(400px,100%);box-shadow:0 30px 70px rgba(15,27,51,.3)}
.modal h3{font-size:1.15rem;margin-bottom:4px}
.modal p.m{color:var(--muted);font-size:.85rem;margin-bottom:16px}
.due{background:var(--acc-t);border-radius:12px;padding:14px;text-align:center;margin-bottom:14px}
.due b{display:block;font-size:1.8rem;color:var(--acc)}
.due span{font-size:.75rem;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.1em}
.modal input[type=number],.modal select{width:100%;padding:12px 14px;border:1.5px solid var(--line);border-radius:10px;margin-bottom:10px}
.quick{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px}
.quick button{border:1px solid var(--line);background:#fff;border-radius:9px;padding:8px 13px;font-weight:600;font-size:.85rem;cursor:pointer}
.change{font-size:.95rem;font-weight:700;margin-bottom:12px}
.modal .go{width:100%;background:var(--acc);color:#fff;border:0;border-radius:11px;padding:14px;font-weight:700;font-size:1rem;cursor:pointer}
.modal .cx{width:100%;background:none;border:0;color:var(--muted);padding:10px;cursor:pointer;font-size:.85rem;margin-top:4px}

.day{max-width:820px;margin:22px auto;padding:0 20px}
.day .panel{padding:26px}
.dgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin:18px 0}
.dgrid div b{display:block;font-size:1.5rem;font-weight:800}
.dgrid div span{font-size:.78rem;color:var(--muted)}
table.z{width:100%;border-collapse:collapse;margin-top:10px;font-size:.88rem}
table.z th{text-align:left;font-family:var(--mono);font-size:.66rem;letter-spacing:.1em;text-transform:uppercase;color:var(--faint);padding:8px 6px;border-bottom:1.5px solid var(--line)}
table.z td{padding:9px 6px;border-bottom:1px solid rgba(15,27,51,.05)}
.badge{font-size:.7rem;font-weight:700;border-radius:999px;padding:3px 9px}
.b-red{background:#FDECEC;color:#B32222}.b-amb{background:#FFF4DF;color:#8A5A0B}.b-ok{background:#E7F5EC;color:#0B8043}
.note{background:#FFF8E8;border:1px solid #F0DCAE;border-radius:12px;padding:13px 15px;font-size:.86rem;margin-top:16px}
/* Always-visible total + pay button on a phone: on a small screen the cart
   sits below the item grid, and a cashier must never have to scroll to take
   money. Hidden on desktop, where the cart panel is already beside the tiles. */
.mbar{display:none;position:fixed;left:0;right:0;bottom:0;z-index:45;background:#fff;
  border-top:1px solid var(--line);box-shadow:0 -6px 22px rgba(15,27,51,.13);
  padding:10px 14px calc(10px + env(safe-area-inset-bottom));align-items:center;gap:12px}
.mbar .info{flex:1;min-width:0;line-height:1.25}
.mbar .info b{display:block;font-size:1.15rem}
.mbar .info span{font-size:.74rem;color:var(--muted)}
.mbar button{border:0;border-radius:11px;background:var(--acc);color:#fff;font-weight:700;
  font-size:1rem;padding:13px 22px;cursor:pointer;white-space:nowrap}
.mbar button:disabled{opacity:.4}

@media (max-width:1080px){.wrap{grid-template-columns:1fr}.cart{position:static;max-height:none}}
@media (max-width:700px){
  .wrap{padding:12px 12px 24px;gap:12px}
  .modes,.tables{overflow-x:auto;flex-wrap:nowrap;-webkit-overflow-scrolling:touch}
  .modes a,.tbl{flex:0 0 auto}
  .topbar{padding:11px 13px;gap:10px}
  .topbar .meta{font-size:.76rem}
  .tiles{grid-template-columns:repeat(auto-fill,minmax(128px,1fr));gap:8px;padding:12px}
  .tile{padding:10px}
  .cats{padding:12px 12px 0;gap:6px;overflow-x:auto;flex-wrap:nowrap}
  .cat{flex:0 0 auto}
  .day{padding:0 12px}.day .panel{padding:18px 14px;overflow-x:auto}
  .mbar{display:flex}
  body.has-mbar{padding-bottom:78px}
  .modal{padding:18px}.due b{font-size:1.5rem}
}
</style>
</head>
<body>

<div class="demo-bar">
  <span><b>VectorERP POS</b> — live demo, nothing is saved</span>
  <a href="index.php?p=choose">↔ Switch demo</a>
  <a href="/">← vectorsolution.it</a>
  <a href="https://wa.me/<?= WA ?>?text=<?= rawurlencode('Hello, I saw the VectorERP POS demo and want it for my business.') ?>" target="_blank" rel="noopener">Get this for my business →</a>
</div>

<div class="modes">
  <span class="lbl">Counter type</span>
  <?php foreach ($MODE_TABS as $k => $label): ?>
    <a href="pos.php?mode=<?= $e($k) ?>" class="<?= $mode === $k ? 'on' : '' ?>"><?= $e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($p === 'day'): ?>
  <div class="day">
    <div class="panel">
      <h1 style="font-size:1.4rem">Day summary — <?= date('d M Y') ?></h1>
      <p style="color:var(--muted);font-size:.9rem"><?= $e($M['name']) ?> · <?= $e($M['staffLabel']) ?> <?= $e($M['staff']) ?></p>
      <div class="dgrid">
        <div><b><?= (int)$tot['count'] ?></b><span>Receipts</span></div>
        <div><b><?= $rs($tot['total']) ?></b><span>Gross sales</span></div>
        <div><b><?= $rs($tot['tax']) ?></b><span>Tax collected</span></div>
        <div><b><?= $rs($tot['cash']) ?></b><span>Cash in drawer</span></div>
      </div>
      <table class="z">
        <tr><th>No.</th><th>Time</th><th>Counter</th><th>Items</th><th>Paid by</th><th style="text-align:right">Total</th></tr>
        <?php if (!$sales): ?>
          <tr><td colspan="6" style="color:var(--faint);padding:22px 6px">No sales yet — go back to the till and ring something up.</td></tr>
        <?php endif; ?>
        <?php foreach ($sales as $s): ?>
          <tr>
            <td><a href="pos.php?p=receipt&amp;no=<?= $e($s['no']) ?>" style="color:var(--blue);font-weight:600"><?= $e($s['no']) ?></a></td>
            <td><?= $e($s['time']) ?></td>
            <td><?= $e($MODE_TABS[$s['mode'] ?? 'retail'] ?? 'Retail') ?><?= !empty($s['table']) ? ' · ' . $e($s['table']) : '' ?></td>
            <td><?= count($s['lines']) ?></td>
            <td><?= $e(ucfirst($s['method'])) ?></td>
            <td style="text-align:right;font-weight:700"><?= $rs($s['total']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <div class="note"><b>Why this matters at closing time.</b> Cash, card, wallet and khata are split automatically, and the tax collected is already the figure that goes into your return — nobody sits with a calculator after the shutter comes down.</div>
      <p style="margin-top:18px"><a class="tbtn" href="pos.php">← Back to till</a></p>
    </div>
  </div>

<?php elseif ($p === 'expiry'): ?>
  <div class="day">
    <div class="panel">
      <h1 style="font-size:1.4rem">Expiry watch</h1>
      <p style="color:var(--muted);font-size:.9rem">Batches expiring within 120 days — <?= $e($SHOP['pharma']['name']) ?></p>
      <table class="z">
        <tr><th>Medicine</th><th>Batch</th><th>Expires</th><th>Days</th><th style="text-align:right">On hand</th><th style="text-align:right">Value at risk</th></tr>
        <?php foreach ($soon as $s): ?>
          <tr>
            <td><b><?= $e($s['name']) ?></b></td>
            <td style="font-family:var(--mono);font-size:.82rem"><?= $e($s['batch']) ?></td>
            <td><?= $e(date('d M Y', strtotime($s['exp']))) ?></td>
            <td><span class="badge <?= $s['days'] < 0 ? 'b-red' : ($s['days'] < 60 ? 'b-amb' : 'b-ok') ?>">
              <?= $s['days'] < 0 ? 'EXPIRED' : $s['days'] . ' days' ?></span></td>
            <td style="text-align:right"><?= (int)$s['qty'] ?></td>
            <td style="text-align:right;font-weight:700"><?= $rs($s['value']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <div class="note"><b>This is the money most chemists lose quietly.</b> Stock expires on the shelf and nobody notices until a customer points at the date. The till picks the earliest-expiring batch automatically, expired batches cannot be sold at all, and this list tells you what to return to the distributor while it is still worth something.</div>
      <p style="margin-top:18px"><a class="tbtn" href="pos.php?mode=pharma">← Back to till</a></p>
    </div>
  </div>

<?php else: ?>
  <div class="topbar">
    <div>
      <div class="shop"><?= $e($M['name']) ?></div>
      <div class="meta"><?= $e($M['branch']) ?> · <?= $e($M['idLabel']) ?> <?= $e($M['id']) ?></div>
    </div>
    <span class="pill">● Till open</span>
    <span class="pill"><?= $mode === 'resto' ? 'SRB registered' : 'FBR connected' ?></span>
    <div class="spacer"></div>
    <div class="meta" style="text-align:right"><?= $e($M['staffLabel']) ?> <b><?= $e($M['staff']) ?></b><br><?= date('d M Y') ?></div>
    <?php if ($mode === 'pharma'): ?><a class="tbtn" href="pos.php?p=expiry">Expiry watch</a><?php endif; ?>
    <a class="tbtn" href="pos.php?p=day">Day summary<?= $sales ? ' (' . count($sales) . ')' : '' ?></a>
  </div>

  <?php if ($mode === 'resto'): ?>
    <div class="tables">
      <span class="lbl">Tables</span>
      <?php foreach ($SHOP['resto']['tables'] as $t):
        $busy = !empty($tables[$t]); ?>
        <a class="tbl <?= $table === $t ? 'on' : ($busy ? 'busy' : '') ?>" href="pos.php?mode=resto&amp;table=<?= $e($t) ?>">
          <?= $e($t) ?><small><?= $busy ? count($tables[$t]) . ' items' : 'free' ?></small>
        </a>
      <?php endforeach; ?>
      <a class="tbl <?= $table === '' ? 'on' : '' ?>" href="pos.php?mode=resto" style="min-width:96px">Counter<small>takeaway</small></a>
    </div>
  <?php endif; ?>

  <div class="wrap">
    <div class="panel">
      <div class="cats" id="cats">
        <button class="cat on" data-cat="">All</button>
        <?php foreach ($M['cats'] as $c): ?>
          <button class="cat" data-cat="<?= $e($c) ?>"><?= $e($c) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="tiles" id="tiles">
        <?php foreach ($CAT as $it):
          $days = !empty($it['exp']) ? (int)floor((strtotime($it['exp']) - strtotime($today)) / 86400) : null;
          $warn = $days !== null && $days < 90;
          $dead = !empty($it['dead']); ?>
          <button class="tile<?= $warn ? ' warn' : '' ?><?= $dead ? ' dead' : '' ?>" data-cat="<?= $e($it['cat']) ?>" data-code="<?= $e($it['code']) ?>" <?= $dead ? 'disabled' : '' ?>>
            <b><?= $e($it['name']) ?></b>
            <span class="pr">Rs <?= number_format((float)$it['price']) ?></span>
            <?php if ($it['tax'] == 0 && $mode !== 'resto'): ?><div class="tg">No tax</div>
            <?php elseif (!empty($it['sch3'])): ?><div class="tg">3rd Schedule</div><?php endif; ?>
            <?php if (!empty($it['batch'])): ?>
              <div class="exp">Batch <?= $e($it['batch']) ?><br>Exp <?= $e(date('M Y', strtotime($it['exp']))) ?><?= $warn ? ' · ' . $days . 'd' : '' ?></div>
            <?php elseif ($mode === 'retail'): ?>
              <div class="bc"><?= $e($it['code']) ?></div>
            <?php endif; ?>
            <?php if ($dead): ?><div class="st">Expired — cannot sell</div>
            <?php elseif (isset($it['stock']) && $it['stock'] < 10): ?><div class="st">Only <?= (int)$it['stock'] ?> left</div><?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel cart">
      <div class="scan">
        <label for="scan"><?= $mode === 'retail' ? 'Scan barcode' : 'Quick find' ?></label>
        <input id="scan" autocomplete="off" placeholder="<?= $mode === 'retail' ? 'Scan, or type a barcode and press Enter' : 'Type a code or part of a name, then Enter' ?>" autofocus>
        <p class="hint"><?php if ($mode === 'retail'): ?>A USB scanner just types the number and presses Enter — it works here exactly as it would at your counter. No scanner? Tap any item, or type <code>8964000201457</code>.
          <?php elseif ($mode === 'resto'): ?>Pick a table above, tap the dishes, send the ticket to the kitchen — then bill the table when they are done.
          <?php else: ?>The till always picks the batch expiring first, and will not let an expired batch be sold at all.<?php endif; ?></p>
      </div>
      <?php if ($mode === 'resto'): ?>
        <div class="otype" id="otype">
          <button class="<?= $table !== '' ? 'on' : '' ?>" data-t="Dine-in">Dine-in</button>
          <button class="<?= $table === '' ? 'on' : '' ?>" data-t="Takeaway">Takeaway</button>
          <button data-t="Delivery">Delivery</button>
        </div>
      <?php endif; ?>
      <div class="lines" id="lines"><div class="empty">Nothing added yet.</div></div>
      <div class="nudge" id="nudge">
        <b>Try it →</b>
        <?php if ($mode === 'retail'): ?>Tap any item on the left, or type <code>8964000201457</code> and press Enter.
        <?php elseif ($mode === 'resto'): ?>Pick a table above, tap a few dishes, then send the ticket to the kitchen.
        <?php else: ?>Tap a medicine — the till picks the batch expiring first, on its own.<?php endif; ?>
      </div>
      <div class="totals">
        <div class="trow"><span><?= $mode === 'resto' ? 'Food &amp; service' : 'Taxable value' ?></span><span id="t-net">Rs 0</span></div>
        <div class="trow"><span><?= $e($M['taxLabel']) ?></span><span id="t-tax">Rs 0</span></div>
        <div class="trow" id="t-exrow" style="display:none"><span>No-tax items</span><span id="t-ex">Rs 0</span></div>
        <div class="trow big"><span>Total</span><span id="t-tot">Rs 0</span></div>
      </div>
      <div class="pay">
        <?php if ($mode === 'resto'): ?>
          <button class="kot" id="b-kot" disabled>🍳 Send to kitchen</button>
        <?php endif; ?>
        <button class="cash" id="b-cash" disabled><?= $mode === 'resto' ? 'Bill — cash' : 'Cash' ?></button>
        <button class="card" id="b-card" disabled>Card</button>
        <button class="wallet" id="b-wallet" disabled>JazzCash</button>
        <?php if ($mode !== 'resto'): ?><button class="khata" id="b-khata" disabled style="grid-column:1/-1">Khata (udhaar)</button><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="mbar" id="mbar">
    <div class="info"><b id="mb-total">Rs 0</b><span id="mb-count">Cart empty</span></div>
    <button id="mb-pay" disabled><?= $mode === 'resto' ? 'Bill' : 'Pay' ?></button>
  </div>

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
      <button class="go" id="m-go">Complete &amp; print receipt</button>
      <button class="cx" id="m-cx">Cancel</button>
    </div>
  </div>

  <form id="payform" method="post" action="pos.php?p=pay" style="display:none">
    <input type="hidden" name="cart" id="f-cart"><input type="hidden" name="method" id="f-method">
    <input type="hidden" name="tendered" id="f-tendered"><input type="hidden" name="khata" id="f-khata">
    <input type="hidden" name="table" value="<?= $e($table) ?>"><input type="hidden" name="ordertype" id="f-otype">
  </form>
  <form id="kotform" method="post" action="pos.php?p=kot" style="display:none">
    <input type="hidden" name="cart" id="k-cart"><input type="hidden" name="table" value="<?= $e($table) ?>">
  </form>

  <script>
  (function () {
    var ITEMS = <?= json_encode(array_values(array_map(static fn($i) => [
        'code' => $i['code'], 'name' => $i['name'], 'price' => $i['price'], 'tax' => $i['tax'],
        'batch' => $i['batch'] ?? '', 'exp' => $i['exp'] ?? '', 'dead' => !empty($i['dead']),
    ], $CAT)), JSON_UNESCAPED_UNICODE) ?>;
    var INCLUSIVE = <?= $M['inclusive'] ? 'true' : 'false' ?>;
    var BY = {}; ITEMS.forEach(function (i) { BY[i.code] = i; });

    var cart = <?= json_encode(array_values(array_map(static fn($r) => ['code' => $r['code'], 'qty' => $r['qty']], $openOrder))) ?>;
    var linesEl = document.getElementById('lines'), scan = document.getElementById('scan');
    var otype = document.querySelector('#otype button.on') ? document.querySelector('#otype button.on').getAttribute('data-t') : '';

    function money(n) { return 'Rs ' + Math.round(n).toLocaleString('en-PK'); }

    function totals() {
      var net = 0, tax = 0, ex = 0;
      cart.forEach(function (l) {
        var it = BY[l.code]; if (!it) return;
        if (it.tax > 0) {
          if (INCLUSIVE) { var g = it.price * l.qty, n = g / (1 + it.tax / 100); net += n; tax += g - n; }
          else { var n2 = it.price * l.qty; net += n2; tax += n2 * it.tax / 100; }
        } else { ex += it.price * l.qty; }
      });
      return { net: net, tax: tax, ex: ex, total: net + tax + ex };
    }

    function render() {
      if (!cart.length) {
        linesEl.innerHTML = '<div class="empty">Nothing added yet.</div>';
      } else {
        var html = '';
        cart.forEach(function (l, idx) {
          var it = BY[l.code]; if (!it) return;
          var sub = money(it.price) + (it.tax === 0 ? ' · no tax' : (INCLUSIVE ? ' · incl ' + it.tax + '% tax' : ' + ' + it.tax + '% tax'));
          if (it.batch) sub += '<br>Batch ' + it.batch + ' · exp ' + it.exp.slice(0, 7);
          var amt = INCLUSIVE || it.tax === 0 ? it.price * l.qty : it.price * l.qty * (1 + it.tax / 100);
          html += '<div class="ln"><div><div class="nm">' + it.name + '</div><div class="sub">' + sub + '</div>' +
                  '<div class="qty"><button data-a="-" data-i="' + idx + '">−</button><span>' + l.qty + '</span>' +
                  '<button data-a="+" data-i="' + idx + '">+</button>' +
                  '<button class="rm" data-a="x" data-i="' + idx + '">remove</button></div></div>' +
                  '<div class="amt">' + money(amt) + '</div></div>';
        });
        linesEl.innerHTML = html;
      }
      var t = totals();
      document.getElementById('t-net').textContent = money(t.net);
      document.getElementById('t-tax').textContent = money(t.tax);
      document.getElementById('t-tot').textContent = money(t.total);
      document.getElementById('t-exrow').style.display = t.ex > 0 ? 'flex' : 'none';
      document.getElementById('t-ex').textContent = money(t.ex);
      ['b-cash', 'b-card', 'b-wallet', 'b-khata', 'b-kot'].forEach(function (id) {
        var el = document.getElementById(id); if (el) el.disabled = !cart.length;
      });
      if (cart.length) { var nd = document.getElementById('nudge'); if (nd) nd.remove(); }
      var n = cart.reduce(function (a, l) { return a + l.qty; }, 0);
      document.getElementById('mb-total').textContent = money(t.total);
      document.getElementById('mb-count').textContent = n ? n + (n === 1 ? ' item' : ' items') : 'Cart empty';
      document.getElementById('mb-pay').disabled = !cart.length;
      document.body.classList.toggle('has-mbar', true);
    }

    function flash(msg) {
      scan.style.borderColor = '#B32222'; scan.placeholder = msg;
      setTimeout(function () { scan.style.borderColor = ''; scan.placeholder = 'Type a code or name, then Enter'; }, 1700);
    }

    function add(code) {
      var it = BY[code];
      if (!it) { flash('Not recognised'); return; }
      if (it.dead) { flash('Expired batch — cannot sell'); return; }
      var line = cart.find(function (l) { return l.code === code; });
      if (line) line.qty++; else cart.push({ code: code, qty: 1 });
      var n = document.getElementById('nudge'); if (n) n.remove();
      render();
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
      var t = ev.target.closest('.tile'); if (!t || t.disabled) return;
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
    var ot = document.getElementById('otype');
    if (ot) ot.addEventListener('click', function (ev) {
      var b = ev.target.closest('button'); if (!b) return;
      [].forEach.call(ot.querySelectorAll('button'), function (x) { x.classList.toggle('on', x === b); });
      otype = b.getAttribute('data-t');
    });

    // A scanner is a keyboard: digits then Enter. Typing part of a name works too.
    scan.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter') return;
      ev.preventDefault();
      var q = scan.value.trim(); scan.value = '';
      if (!q) return;
      if (BY[q]) { add(q); return; }
      var hit = ITEMS.filter(function (i) { return i.name.toLowerCase().indexOf(q.toLowerCase()) !== -1; });
      if (hit.length) add(hit[0].code); else flash('Not recognised');
    });
    document.addEventListener('click', function (ev) {
      if (!ev.target.closest('.modal') && !ev.target.closest('input')) scan.focus();
    });

    var kot = document.getElementById('b-kot');
    if (kot) kot.onclick = function () {
      if (!cart.length) return;
      document.getElementById('k-cart').value = JSON.stringify(cart);
      document.getElementById('kotform').submit();
    };

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
    [['b-cash', 'cash'], ['b-card', 'card'], ['b-wallet', 'wallet'], ['b-khata', 'khata']].forEach(function (pair) {
      var el = document.getElementById(pair[0]);
      if (el) el.onclick = function () { openPay(pair[1]); };
    });
    document.getElementById('mb-pay').onclick = function () { if (cart.length) openPay('cash'); };
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
      document.getElementById('f-otype').value = otype;
      document.getElementById('payform').submit();
    };

    render();
  })();
  </script>
<?php endif; ?>

</body>
</html>
