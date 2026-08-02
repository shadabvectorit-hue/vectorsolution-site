<?php
// VectorIT — private inquiries inbox. Password lives in /home/<user>/_private/admin_pass.txt
// (outside the webroot, never in this public repository).
declare(strict_types=1);
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
    if ($storedPass !== '' && hash_equals($storedPass, (string)$_POST['pass'])) {
        $_SESSION['inq_ok'] = true;
    } else {
        $err = true;
        sleep(1); // slow brute force a little
    }
}
$authed = !empty($_SESSION['inq_ok']);

$leads = [];
if ($authed) {
    $file = dirname(__DIR__) . '/_private/inquiries.jsonl';
    if (is_file($file)) {
        foreach (array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) $leads[] = $row;
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
      <?php if (!empty($err)): ?><p class="err">Wrong password.</p><?php endif; ?>
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
    <?php if (!$leads): ?>
      <div class="card empty">No inquiries yet. They will appear here the moment someone submits the contact form or finishes the chat bot.</div>
    <?php endif; ?>
    <?php foreach ($leads as $l): ?>
      <div class="card">
        <b><?= $e($l['name'] ?? '') ?></b>
        <span class="tag"><?= $e($l['source'] ?? '') ?></span>
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
