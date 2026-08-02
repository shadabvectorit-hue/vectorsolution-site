<?php
// VectorIT — private inquiries inbox. Password lives in /home/<user>/_private/admin_pass.txt
// (outside the webroot, never in this public repository).
declare(strict_types=1);
// Reject session IDs the server never issued, and keep the cookie away from
// JavaScript and cross-site requests.
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => true]);
session_start();
header('X-Robots-Tag: noindex, nofollow');

$passFile = dirname(__DIR__) . '/_private/admin_pass.txt';
$storedPass = is_file($passFile) ? trim((string)file_get_contents($passFile)) : '';

if (isset($_GET['logout'])) {
    unset($_SESSION['inq_ok']);
    header('Location: inquiries.php');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['pass'])) {
    // One password is the only gate on every lead's contact details, so cap the
    // guesses: 10 per IP per 15 minutes. Failures are recorded *before* the
    // comparison, otherwise parallel requests would never be counted.
    $lockDir = dirname(__DIR__) . '/_private/ratelimit';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0700, true);
    }
    $lockFile = $lockDir . '/login_' . substr(sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|vectorit-login'), 0, 20) . '.json';
    $tries = is_file($lockFile) ? (json_decode((string)@file_get_contents($lockFile), true) ?: []) : [];
    $tries = array_values(array_filter($tries, static fn($ts) => is_int($ts) && $ts > time() - 900));

    if (count($tries) >= 10) {
        $err = 'Too many attempts. Try again in 15 minutes.';
    } else {
        $tries[] = time();
        @file_put_contents($lockFile, json_encode($tries), LOCK_EX);
        if ($storedPass !== '' && hash_equals($storedPass, (string)$_POST['pass'])) {
            // New ID on login: any session the browser was carrying beforehand
            // (possibly planted by someone else) cannot become an admin session.
            session_regenerate_id(true);
            $_SESSION['inq_ok'] = true;
            @unlink($lockFile);
        } else {
            $err = 'Wrong password.';
            sleep(1);
        }
    }
}
$authed = !empty($_SESSION['inq_ok']);

$stats = null;
if ($authed) {
    // ---- traffic stats from the first-party analytics log ----
    $af = dirname(__DIR__) . '/_private/analytics.jsonl';
    $stats = ['pv7' => 0, 'pv30' => 0, 'uniq7' => [], 'wa7' => 0, 'demo7' => 0, 'inv7' => 0, 'pdf7' => 0, 'pages' => [], 'mobile' => 0, 'total7' => 0];
    if (is_file($af)) {
        $rows = file($af, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (count($rows) > 50000) $rows = array_slice($rows, -50000);
        $d7  = date('Y-m-d', strtotime('-7 days'));
        $d30 = date('Y-m-d', strtotime('-30 days'));
        foreach ($rows as $line) {
            $r = json_decode($line, true);
            if (!is_array($r)) continue;
            $day = substr((string)($r['t'] ?? ''), 0, 10);
            $ev = $r['e'] ?? '';
            if ($day >= $d30 && $ev === 'pv') $stats['pv30']++;
            if ($day < $d7) continue;
            $stats['total7']++;
            if (!empty($r['m'])) $stats['mobile']++;
            if ($ev === 'pv') {
                $stats['pv7']++;
                $stats['uniq7'][$r['v'] ?? ''] = true;
                $pg = $r['p'] ?: '/';
                $stats['pages'][$pg] = ($stats['pages'][$pg] ?? 0) + 1;
            }
            elseif ($ev === 'wa') $stats['wa7']++;
            elseif ($ev === 'demo_login') $stats['demo7']++;
            elseif ($ev === 'demo_invoice_created') $stats['inv7']++;
            elseif ($ev === 'demo_pdf_invoice' || $ev === 'demo_pdf_report') $stats['pdf7']++;
        }
        arsort($stats['pages']);
        $stats['pages'] = array_slice($stats['pages'], 0, 8, true);
    }
}

$leads = [];
if ($authed) {
    $file = dirname(__DIR__) . '/_private/inquiries.jsonl';
    if (is_file($file)) {
        $seen = [];
        // Newest first; a completed chat supersedes its own earlier partial save.
        foreach (array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) continue;
            $id = (string)($row['leadId'] ?? '');
            if ($id !== '') {
                if (isset($seen[$id])) continue;
                $seen[$id] = true;
            }
            $leads[] = $row;
        }
    }
}
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Inquiries — VectorIT</title>
<style>
  body { font-family: system-ui, sans-serif; background: #F7F9FC; color: #0F1B33; margin: 0; padding: 24px; }
  .wrap { max-width: 1100px; margin: 0 auto; }
  h1 { font-size: 1.4rem; } h1 small { color: #55617D; font-weight: 400; font-size: .85rem; }
  .login { max-width: 340px; margin: 12vh auto; background: #fff; border: 1px solid #E3E9F5; border-radius: 14px; padding: 28px; box-shadow: 0 12px 32px rgba(15,27,51,.08); }
  input[type=password] { width: 100%; box-sizing: border-box; padding: 11px 14px; border: 1.5px solid #C9D4EA; border-radius: 9px; font-size: 1rem; margin: 10px 0 14px; }
  button { background: #2E5BDB; color: #fff; border: 0; border-radius: 999px; padding: 11px 22px; font-size: .95rem; font-weight: 600; cursor: pointer; }
  .err { color: #B32222; font-size: .9rem; }
  .card { background: #fff; border: 1px solid #E3E9F5; border-radius: 12px; padding: 16px 18px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(15,27,51,.05); }
  .card b { font-size: 1.05rem; }
  .meta { color: #55617D; font-size: .8rem; margin: 4px 0 8px; }
  .tag { display: inline-block; background: #E8EEFC; color: #1E3FA6; border-radius: 999px; padding: 2px 10px; font-size: .75rem; margin-right: 6px; }
  .msg { white-space: pre-wrap; font-size: .92rem; }
  a { color: #2E5BDB; }
  .top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
  .empty { color: #55617D; padding: 40px; text-align: center; }
</style>
</head>
<body>
<?php if (!$authed): ?>
  <form class="login" method="post" autocomplete="off">
    <h1>Inquiries inbox</h1>
    <?php if ($storedPass === ''): ?>
      <p class="err">Setup needed: create the file <code>_private/admin_pass.txt</code> (one line — your password) in your home directory via cPanel File Manager.</p>
    <?php else: ?>
      <?php if (!empty($err)): ?><p class="err"><?= $e($err) ?></p><?php endif; ?>
      <input type="password" name="pass" placeholder="Password" autofocus>
      <button type="submit">Open inbox</button>
    <?php endif; ?>
  </form>
<?php else: ?>
  <div class="wrap">
    <div class="top">
      <h1>Inquiries <small><?= count($leads) ?> total</small></h1>
      <a href="?logout=1">Log out</a>
    </div>

    <?php if ($stats): ?>
    <div class="card" style="margin-bottom:18px">
      <b style="font-size:1.05rem">Traffic — last 7 days</b>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;margin-top:12px">
        <div><div style="font-size:1.5rem;font-weight:800"><?= $stats['pv7'] ?></div><div class="meta">Page views (30d: <?= $stats['pv30'] ?>)</div></div>
        <div><div style="font-size:1.5rem;font-weight:800"><?= count($stats['uniq7']) ?></div><div class="meta">Unique visitors</div></div>
        <div><div style="font-size:1.5rem;font-weight:800;color:#178A50"><?= $stats['wa7'] ?></div><div class="meta">WhatsApp clicks</div></div>
        <div><div style="font-size:1.5rem;font-weight:800;color:#2E5BDB"><?= $stats['demo7'] ?></div><div class="meta">Demo logins</div></div>
        <div><div style="font-size:1.5rem;font-weight:800;color:#2E5BDB"><?= $stats['inv7'] ?></div><div class="meta">Invoices created</div></div>
        <div><div style="font-size:1.5rem;font-weight:800;color:#2E5BDB"><?= $stats['pdf7'] ?></div><div class="meta">PDFs printed</div></div>
        <div><div style="font-size:1.5rem;font-weight:800"><?= $stats['total7'] ? round($stats['mobile'] * 100 / $stats['total7']) : 0 ?>%</div><div class="meta">On mobile</div></div>
      </div>
      <?php if ($stats['pages']): ?>
      <div class="meta" style="margin-top:12px">Top pages:
        <?php foreach ($stats['pages'] as $pg => $n): ?><span class="tag"><?= $e($pg) ?> · <?= $n ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!$leads): ?>
      <div class="card empty">No inquiries yet. They will appear here the moment someone submits the contact form or finishes the chat bot.</div>
    <?php endif; ?>
    <?php foreach ($leads as $l): ?>
      <div class="card">
        <b><?= $e($l['name'] ?? '') ?></b>
        <span class="tag"><?= $e($l['source'] ?? '') ?></span>
        <?php if (!empty($l['lang'])): ?><span class="tag"><?= $e($l['lang']) ?></span><?php endif; ?>
        <?php if (($l['stage'] ?? '') === 'partial'): ?><span class="tag" style="background:#FFF0D9;color:#925E10">chat not finished — follow up</span><?php endif; ?>
        <?php if (!empty($l['service'])): ?><span class="tag"><?= $e($l['service']) ?></span><?php endif; ?>
        <?php if (!empty($l['budget'])): ?><span class="tag"><?= $e($l['budget']) ?></span><?php endif; ?>
        <div class="meta">
          <?= $e($l['time'] ?? '') ?>
          <?php if (!empty($l['company'])): ?> · <?= $e($l['company']) ?><?php endif; ?>
        </div>
        <div class="meta">
          <?php if (!empty($l['whatsapp'])): ?>📱 <a href="https://wa.me/<?= $e(preg_replace('/\D/', '', $l['whatsapp'])) ?>" target="_blank" rel="noopener"><?= $e($l['whatsapp']) ?></a> &nbsp;<?php endif; ?>
          <?php if (!empty($l['email'])): ?>✉️ <a href="mailto:<?= $e($l['email']) ?>"><?= $e($l['email']) ?></a><?php endif; ?>
        </div>
        <?php if (!empty($l['message'])): ?><div class="msg"><?= $e($l['message']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</body>
</html>
