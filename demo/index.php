<?php
/**
 * VectorERP — public demo sandbox (vectorsolution.it/demo)
 *
 * Self-contained: no database, no configuration. Sample data lives in data.php;
 * anything the visitor changes is held in their own session and disappears on
 * logout, so every visitor gets a clean company.
 */
declare(strict_types=1);
session_start();

const DEMO_USER = 'demo';
const DEMO_PASS = 'demo';
const WA = '923363138686';

$DATA = require __DIR__ . '/data.php';

/* ---------- auth ---------- */
if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['user'])) {
    if ($_POST['user'] === DEMO_USER && ($_POST['pass'] ?? '') === DEMO_PASS) {
        $_SESSION['demo_ok'] = true;
        header('Location: index.php'); exit;
    }
    $loginError = 'Use demo / demo — they are printed on the form for you.';
}
$authed = !empty($_SESSION['demo_ok']);

/* ---------- interactive bits (session only) ---------- */
if ($authed && isset($_GET['pay'])) {
    $_SESSION['paid'][(string)$_GET['pay']] = true;
    header('Location: index.php?p=invoices&done=' . urlencode((string)$_GET['pay'])); exit;
}
$paid = $_SESSION['paid'] ?? [];

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
  <a href="../index.html">← Back to vectorsolution.it</a>
  <?php if ($authed): ?><a href="#save" style="background:#25D366;border-color:#25D366">💾 Save my demo data</a><?php endif; ?>
  <a href="https://wa.me/<?= WA ?>?text=<?= rawurlencode('Assalam o Alaikum, maine VectorERP demo dekha hai — mujhe apne business ke liye baat karni hai') ?>" target="_blank" rel="noopener">Ye mere business ke liye chahiye →</a>
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
          <h1><?= $e($NAV[$p][0] ?? 'Dashboard') ?></h1>
          <p class="co"><?= $e($DATA['company']['name']) ?> · STRN <?= $e($DATA['company']['strn']) ?> · FY <?= $e($DATA['company']['fy']) ?></p>
        </div>
        <div class="user"><span class="av">DM</span> Demo User · Administrator</div>
      </div>

      <?php if (isset($_GET['done'])): ?>
        <div class="toast">✓ <?= $e((string)$_GET['done']) ?> marked as paid — the ledger and receivables updated instantly. (Demo only: this resets when you sign out.)</div>
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
            <?php foreach (array_slice($DATA['invoices'], 0, 4) as $inv):
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
          <h2>Sales tax invoices <span>every invoice registered with FBR</span></h2>
          <table>
            <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th class="num">Excl. tax</th><th class="num">Sales tax 18%</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($DATA['invoices'] as $inv):
              $st = isset($paid[$inv['no']]) ? 'paid' : $inv['status']; ?>
              <tr>
                <td><b><?= $e($inv['no']) ?></b></td>
                <td><?= $e($inv['date']) ?></td>
                <td><?= $e($inv['party']) ?></td>
                <td class="num"><?= $rs($inv['excl']) ?></td>
                <td class="num"><?= $rs($inv['tax']) ?></td>
                <td class="num"><b><?= $rs($inv['excl'] + $inv['tax']) ?></b></td>
                <td><span class="pill <?= $e($st) ?>"><?= $st === 'due' ? 'Due' : ucfirst($st) ?></span></td>
                <td><?php if ($st !== 'paid'): ?><a class="btn-sm" href="?pay=<?= urlencode($inv['no']) ?>">Mark paid</a><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p class="note">Try it: click <b>Mark paid</b> on any overdue invoice and watch the status update.</p>
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
          <h2>Cash &amp; bank ledger <span>financial year 1 July – 30 June</span></h2>
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
        <h2 style="color:#fff">Save my demo data <span style="color:#9DBAFF">apne business ke liye</span></h2>
        <p class="save-lede">Leave your name and WhatsApp number — we'll set VectorERP up with <b>your own</b> products, parties and opening balances, and send you a private login. Free, no obligation.</p>
        <form class="save-form" id="save-form" action="../contact-submit.php" method="post" autocomplete="on">
          <input type="text" name="website" tabindex="-1" aria-hidden="true" autocomplete="off" style="position:absolute;left:-9999px">
          <input type="hidden" name="source" value="demo-save">
          <input type="hidden" name="service" value="VectorERP — set up with my own data">
          <input type="hidden" name="message" value="Requested &quot;Save my demo data&quot; from the live demo at /demo">
          <input name="name" placeholder="Aap ka naam / Your name" required aria-label="Your name">
          <input name="whatsapp" placeholder="WhatsApp — 03xx xxxxxxx" required aria-label="WhatsApp number">
          <input name="company" placeholder="Business ka naam (optional)" aria-label="Business name">
          <button type="submit">Save my demo →</button>
        </form>
        <p class="save-done" id="save-done" hidden>✓ <b>Shukriya!</b> Your details are saved. We'll WhatsApp you a private demo set up with your own data — usually the same day.</p>
        <p class="save-warn" id="save-warn" hidden>Couldn't save just now — please add your name and full WhatsApp number, or message us directly on WhatsApp below.</p>
        <p class="save-alt">Prefer to talk right now?
          <a href="https://wa.me/<?= WA ?>?text=<?= rawurlencode('Assalam o Alaikum, maine VectorERP ka demo dekha hai. Mujhe apne business ke liye setup karwana hai.') ?>" target="_blank" rel="noopener">WhatsApp par baat karein →</a>
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
