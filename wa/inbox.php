<?php
// VectorIT — WhatsApp inbox for +92 302 2219093. Read every customer thread and
// reply from the business number itself. Same password and session as
// inquiries.php (_private/admin_pass.txt), so signing in to one opens both.
declare(strict_types=1);
require_once __DIR__ . '/../lib/cfg.php';
require_once __DIR__ . '/../lib/wa.php';
require_once __DIR__ . '/../lib/agent.php';
require_once __DIR__ . '/../lib/wainbox.php';

@ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => true]);
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

const INBOX_IDLE = 43200;
const INBOX_ABSOLUTE = 604800;

$passFile = VIT_PRIVATE . '/admin_pass.txt';
$storedPass = is_file($passFile) ? trim((string)file_get_contents($passFile)) : '';
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$self = 'inbox.php';
$err = '';

if (!empty($_SESSION['inq_ok'])) {
    $seen = (int)($_SESSION['inq_seen'] ?? 0);
    $born = (int)($_SESSION['inq_born'] ?? 0);
    if (($seen && time() - $seen > INBOX_IDLE) || ($born && time() - $born > INBOX_ABSOLUTE)) {
        $_SESSION = [];
        session_regenerate_id(true);
        $err = 'Session expired. Please sign in again.';
    } else {
        $_SESSION['inq_seen'] = time();
    }
}

if (isset($_GET['logout']) && !empty($_SESSION['inq_csrf']) && hash_equals((string)$_SESSION['inq_csrf'], (string)($_GET['t'] ?? ''))) {
    $_SESSION = [];
    session_regenerate_id(true);
    header('Location: ' . $self);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['pass'])) {
    $submitted = is_string($_POST['pass']) ? $_POST['pass'] : '';
    if (!vit_rate_allow('login', 10, 900)) {
        $err = 'Too many attempts. Try again in 15 minutes.';
        vit_audit('admin_login_throttled');
    } elseif ($storedPass !== '' && $submitted !== '' && hash_equals($storedPass, $submitted)) {
        session_regenerate_id(true);
        $_SESSION['inq_ok'] = true;
        $_SESSION['inq_born'] = time();
        $_SESSION['inq_seen'] = time();
        $_SESSION['inq_csrf'] = bin2hex(random_bytes(16));
        vit_rate_clear('login');
        vit_audit('admin_login_ok', ['page' => 'wa_inbox']);
        header('Location: ' . $self);
        exit;
    } else {
        $err = 'Wrong password.';
        vit_audit('admin_login_failed', ['page' => 'wa_inbox']);
        sleep(1);
    }
}
$authed = !empty($_SESSION['inq_ok']);
$csrfOk = static fn(): bool => !empty($_SESSION['inq_csrf']) && hash_equals((string)$_SESSION['inq_csrf'], (string)($_POST['csrf'] ?? ''));
$open = preg_replace('/\D+/', '', (string)($_GET['c'] ?? $_POST['c'] ?? ''));

/* ---------- actions ---------- */
if ($authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $open !== '' && isset($_POST['act'])) {
    $flash = '';
    if (!$csrfOk()) {
        $flash = 'expired';
    } else {
        $t = vit_wa_thread_load($open);
        $act = (string)$_POST['act'];
        if ($act === 'send') {
            $msg = trim(str_replace("\r\n", "\n", (string)($_POST['msg'] ?? '')));
            $open24 = (time() - (int)($t['last_in'] ?? 0)) < VIT_WA_WINDOW;
            if ($msg === '') {
                $flash = 'empty';
            } elseif (!$open24) {
                $flash = 'window';
            } elseif (vit_wa_send($open, $msg)) {
                vit_wa_log($open, 'out', $msg);
                $t = vit_wa_thread_load($open);
                $t['human_until'] = time() + VIT_WA_HUMAN_HOLD;
                vit_wa_thread_save($t);
                // Give the assistant the same context the customer now has, so
                // when it resumes it does not contradict what was just said.
                $convo = vit_convo_load($open);
                $convo['turns'][] = ['role' => 'assistant', 'content' => '[Shadab replied personally] ' . $msg];
                vit_convo_save($open, $convo);
                vit_audit('wa_owner_reply', ['len' => mb_strlen($msg)]);
                $flash = 'sent';
            } else {
                $flash = 'failed';
            }
        } elseif ($act === 'resume') {
            $t['human_until'] = 0;
            vit_wa_thread_save($t);
            $flash = 'resumed';
        } elseif ($act === 'pause') {
            $t['human_until'] = time() + VIT_WA_HUMAN_HOLD;
            vit_wa_thread_save($t);
            $flash = 'paused';
        }
    }
    header('Location: ' . $self . '?c=' . rawurlencode($open) . '&f=' . rawurlencode($flash) . '#end');
    exit;
}

$threads = $authed ? vit_wa_threads() : [];
$thread = null;
if ($authed && $open !== '') {
    $thread = vit_wa_thread_load($open);
    if ((int)($thread['unread'] ?? 0) > 0) {
        $thread['unread'] = 0;
        vit_wa_thread_save($thread);
        foreach ($threads as &$tt) { if ($tt['phone'] === $open) { $tt['unread'] = 0; } }
        unset($tt);
    }
}
$fmtPhone = static function (string $p): string {
    return preg_match('/^92(\d{3})(\d{7})$/', $p, $m) ? '+92 ' . $m[1] . ' ' . $m[2] : '+' . $p;
};
$ago = static function (int $ts): string {
    if ($ts <= 0) return '';
    $d = time() - $ts;
    if ($d < 60) return 'now';
    if ($d < 3600) return floor($d / 60) . 'm';
    if ($d < 86400) return floor($d / 3600) . 'h';
    return date('d M', $ts);
};
$flashText = [
    'sent' => ['ok', 'Sent from +92 302 2219093. The assistant is paused on this chat for 6 hours.'],
    'resumed' => ['ok', 'The assistant will answer this customer again.'],
    'paused' => ['ok', 'The assistant is paused on this chat for 6 hours.'],
    'failed' => ['err', 'WhatsApp did not accept the message. Try again in a minute.'],
    'window' => ['err', 'More than 24 hours since the customer last wrote. WhatsApp only allows a free reply within 24 hours — call them, or wait for them to message again.'],
    'empty' => ['err', 'Type a message first.'],
    'expired' => ['err', 'The page was open too long. Please try again.'],
][(string)($_GET['f'] ?? '')] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0F1B33">
<title>WhatsApp inbox · VectorIT</title>
<style>
  :root { --ink:#0F1B33; --muted:#55617D; --line:#E3E9F5; --blue:#2E5BDB; --paper:#F4F6FB; --green:#178A50; --in:#FFFFFF; --out:#DCF8C6; --bot:#E8EEFC; }
  * { box-sizing: border-box; }
  html, body { margin: 0; height: 100%; }
  body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--paper); color: var(--ink); font-size: 15px; }
  a { color: var(--blue); text-decoration: none; }
  .login { max-width: 340px; margin: 14vh auto; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 28px; box-shadow: 0 12px 32px rgba(15,27,51,.08); }
  .login h1 { font-size: 1.25rem; margin: 0 0 6px; }
  input[type=password] { width: 100%; padding: 11px 14px; border: 1.5px solid #C9D4EA; border-radius: 9px; font-size: 1rem; margin: 12px 0 14px; }
  .btn { background: var(--blue); color: #fff; border: 0; border-radius: 999px; padding: 10px 20px; font-size: .95rem; font-weight: 600; cursor: pointer; }
  .btn-2 { background: #fff; color: var(--ink); border: 1.5px solid #C9D4EA; border-radius: 999px; padding: 7px 14px; font-size: .82rem; font-weight: 600; cursor: pointer; }
  .err { color: #B32222; font-size: .9rem; }
  .app { display: grid; grid-template-columns: 340px 1fr; height: 100vh; height: 100dvh; }
  .side { background: #fff; border-right: 1px solid var(--line); display: flex; flex-direction: column; min-height: 0; }
  .side-top { padding: 14px 16px; background: var(--ink); color: #fff; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
  .side-top b { font-size: 1rem; } .side-top small { color: #B9C6E4; display: block; font-size: .75rem; }
  .side-top a { color: #B9C6E4; font-size: .8rem; }
  .list { overflow-y: auto; flex: 1; }
  .row { display: flex; gap: 12px; padding: 12px 16px; border-bottom: 1px solid #EEF2FA; color: inherit; align-items: center; }
  .row:hover { background: #F7F9FD; } .row.on { background: #EAF0FF; }
  .av { width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg,#2E5BDB,#5B8CFF); color: #fff; display: grid; place-items: center; font-weight: 700; flex: 0 0 42px; }
  .row .mid { flex: 1; min-width: 0; display: flex; flex-direction: column; } .row .nm { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .row .pv { color: var(--muted); font-size: .84rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
  .row .rt { flex: 0 0 auto; }
  .row .rt { text-align: right; font-size: .75rem; color: var(--muted); display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
  .badge { background: var(--green); color: #fff; border-radius: 999px; padding: 1px 7px; font-size: .72rem; font-weight: 700; }
  .empty { color: var(--muted); padding: 40px 20px; text-align: center; line-height: 1.6; }
  .chat { display: flex; flex-direction: column; min-height: 0; background: #ECE5DD url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='40'%3E%3Cpath d='M0 20h40M20 0v40' stroke='%23000' stroke-opacity='.025'/%3E%3C/svg%3E"); }
  .chat-top { background: #fff; border-bottom: 1px solid var(--line); padding: 10px 16px; display: flex; align-items: center; gap: 12px; }
  .chat-top .mid { flex: 1; min-width: 0; } .chat-top .nm { font-weight: 700; } .chat-top .sub { font-size: .8rem; color: var(--muted); }
  .back { display: none; font-size: 1.4rem; color: var(--ink); padding-right: 4px; }
  .msgs { flex: 1; overflow-y: auto; padding: 18px 6%; display: flex; flex-direction: column; gap: 6px; }
  .b { max-width: 78%; padding: 7px 11px 5px; border-radius: 10px; box-shadow: 0 1px 1px rgba(0,0,0,.08); word-wrap: break-word; line-height: 1.4; }
  .b .tx { white-space: pre-wrap; }
  .b small { display: block; text-align: right; font-size: .68rem; color: var(--muted); margin-top: 3px; }
  .b.in { background: var(--in); align-self: flex-start; border-top-left-radius: 2px; }
  .b.out { background: var(--out); align-self: flex-end; border-top-right-radius: 2px; }
  .b.bot { background: var(--bot); align-self: flex-end; border-top-right-radius: 2px; }
  .who { font-size: .68rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--blue); margin-bottom: 2px; }
  .b.out .who { color: var(--green); }
  .day { align-self: center; background: #E1F2FB; color: #3A4761; font-size: .74rem; padding: 4px 10px; border-radius: 8px; margin: 8px 0; }
  .note { margin: 8px 16px 0; padding: 9px 13px; border-radius: 9px; font-size: .86rem; }
  .note.ok { background: #E7F5EC; color: #0B6B39; border: 1px solid #B7DEC6; }
  .note.err { background: #FDECEC; color: #8E1C1C; border: 1px solid #F0C4C4; }
  .note.warn { background: #FFF6E5; color: #7A4A00; border: 1px solid #F1D9A6; }
  .compose { display: flex; gap: 10px; padding: 10px 14px calc(10px + env(safe-area-inset-bottom)); background: #F0F2F5; align-items: flex-end; }
  .compose textarea { flex: 1; resize: none; border: 0; border-radius: 22px; padding: 11px 16px; font: inherit; min-height: 44px; max-height: 140px; outline: none; }
  .send { width: 46px; height: 46px; border-radius: 50%; border: 0; background: var(--green); color: #fff; font-size: 1.15rem; cursor: pointer; flex: 0 0 46px; }
  .send:disabled { background: #9DB3A6; cursor: default; }
  .placeholder { flex: 1; display: grid; place-items: center; color: var(--muted); text-align: center; padding: 30px; }
  @media (max-width: 820px) {
    .app { grid-template-columns: 1fr; }
    .app.has-chat .side { display: none; }
    .app:not(.has-chat) .chat { display: none; }
    .back { display: inline-block; }
    .msgs { padding: 14px 10px; } .b { max-width: 88%; }
  }
</style>
</head>
<body>
<?php if (!$authed): ?>
  <form class="login" method="post" autocomplete="off" action="<?= $e($self) ?>">
    <h1>WhatsApp inbox</h1>
    <div style="color:var(--muted);font-size:.88rem">+92 302 2219093 · VectorERP</div>
    <?php if ($storedPass === ''): ?>
      <p class="err">Setup needed: create <code>_private/admin_pass.txt</code> with your password.</p>
    <?php else: ?>
      <?php if ($err !== ''): ?><p class="err"><?= $e($err) ?></p><?php endif; ?>
      <input type="password" name="pass" placeholder="Password" autofocus>
      <button class="btn" type="submit">Open inbox</button>
    <?php endif; ?>
  </form>
<?php else: ?>
<div class="app<?= $thread ? ' has-chat' : '' ?>">
  <aside class="side">
    <div class="side-top">
      <div><b>WhatsApp inbox</b><small>+92 302 2219093 · <?= count($threads) ?> chat<?= count($threads) === 1 ? '' : 's' ?></small></div>
      <div><a href="../inquiries.php">Leads</a> · <a href="?logout=1&amp;t=<?= $e($_SESSION['inq_csrf'] ?? '') ?>">Log out</a></div>
    </div>
    <div class="list">
      <?php if (!$threads): ?>
        <div class="empty">No conversations yet.<br>New WhatsApp messages to 0302 appear here.</div>
      <?php endif; ?>
      <?php foreach ($threads as $t):
        $last = end($t['msgs']) ?: ['x' => '', 'd' => ''];
        $nm = ($t['name'] ?? '') !== '' ? $t['name'] : $fmtPhone((string)$t['phone']);
        $pref = $last['d'] === 'out' ? 'You: ' : ($last['d'] === 'bot' ? 'Assistant: ' : ''); ?>
        <a class="row<?= $t['phone'] === $open ? ' on' : '' ?>" href="?c=<?= $e($t['phone']) ?>#end">
          <span class="av"><?= $e(mb_strtoupper(mb_substr($nm, 0, 1))) ?></span>
          <span class="mid"><span class="nm"><?= $e($nm) ?></span><span class="pv"><?= $e($pref . mb_substr((string)$last['x'], 0, 80)) ?></span></span>
          <span class="rt"><?= $e($ago((int)($t['last_at'] ?? 0))) ?><?php if ((int)($t['unread'] ?? 0) > 0): ?><span class="badge"><?= (int)$t['unread'] ?></span><?php endif; ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </aside>

  <section class="chat">
    <?php if (!$thread): ?>
      <div class="placeholder"><div><b style="color:var(--ink);font-size:1.05rem">Pick a chat</b><br>Replies you send here go out from +92 302 2219093.</div></div>
    <?php else:
      $nm = ($thread['name'] ?? '') !== '' ? $thread['name'] : $fmtPhone((string)$thread['phone']);
      $open24 = (time() - (int)($thread['last_in'] ?? 0)) < VIT_WA_WINDOW;
      $human = (int)($thread['human_until'] ?? 0) > time();
      $leftH = $open24 ? max(0, (int)ceil((VIT_WA_WINDOW - (time() - (int)$thread['last_in'])) / 3600)) : 0; ?>
      <div class="chat-top">
        <a class="back" href="<?= $e($self) ?>" aria-label="Back">←</a>
        <span class="av"><?= $e(mb_strtoupper(mb_substr($nm, 0, 1))) ?></span>
        <div class="mid"><div class="nm"><?= $e($nm) ?></div><div class="sub"><?= $e($fmtPhone((string)$thread['phone'])) ?> · <?= $human ? 'assistant paused' : 'assistant on' ?></div></div>
        <form method="post" action="<?= $e($self) ?>">
          <input type="hidden" name="csrf" value="<?= $e($_SESSION['inq_csrf'] ?? '') ?>"><input type="hidden" name="c" value="<?= $e($thread['phone']) ?>">
          <?php if ($human): ?><button class="btn-2" name="act" value="resume">Resume assistant</button>
          <?php else: ?><button class="btn-2" name="act" value="pause">Pause assistant</button><?php endif; ?>
        </form>
      </div>
      <?php if ($flashText): ?><div class="note <?= $e($flashText[0]) ?>"><?= $e($flashText[1]) ?></div><?php endif; ?>
      <?php if (!$open24): ?><div class="note warn">Last customer message was over 24 hours ago, so WhatsApp won't deliver a free reply. Call <?= $e($fmtPhone((string)$thread['phone'])) ?> or wait until they write again.</div><?php endif; ?>
      <div class="msgs" id="msgs">
        <?php $day = ''; foreach ($thread['msgs'] as $m):
          $d = date('d M Y', (int)$m['t']);
          if ($d !== $day): $day = $d; ?><div class="day"><?= $e($d === date('d M Y') ? 'Today' : $d) ?></div><?php endif; ?>
          <div class="b <?= $e($m['d']) ?>">
            <?php if ($m['d'] === 'bot'): ?><div class="who">Assistant</div><?php elseif ($m['d'] === 'out'): ?><div class="who">You</div><?php endif; ?>
            <span class="tx"><?= $e($m['x']) ?></span><small><?= $e(date('H:i', (int)$m['t'])) ?></small>
          </div>
        <?php endforeach; ?>
        <span id="end"></span>
      </div>
      <form class="compose" method="post" action="<?= $e($self) ?>" id="compose">
        <input type="hidden" name="csrf" value="<?= $e($_SESSION['inq_csrf'] ?? '') ?>">
        <input type="hidden" name="c" value="<?= $e($thread['phone']) ?>">
        <input type="hidden" name="act" value="send">
        <textarea name="msg" id="msg" rows="1" maxlength="4000" placeholder="<?= $open24 ? 'Reply as VectorERP (' . $leftH . 'h left to reply free)' : 'Reply window closed' ?>" <?= $open24 ? '' : 'disabled' ?>></textarea>
        <button class="send" type="submit" aria-label="Send" <?= $open24 ? '' : 'disabled' ?>>➤</button>
      </form>
    <?php endif; ?>
  </section>
</div>
<script>
(function () {
  var box = document.getElementById('msgs'); if (box) box.scrollTop = box.scrollHeight;
  var ta = document.getElementById('msg'), form = document.getElementById('compose');
  if (ta) {
    ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 140) + 'px'; });
    ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && window.innerWidth > 820) { e.preventDefault(); if (ta.value.trim()) form.submit(); } });
    form.addEventListener('submit', function () { form.querySelector('.send').disabled = true; });
  }
  // New messages: reload every 20 s unless a reply is being typed.
  setInterval(function () { if (!ta || ta.value.trim() === '') location.reload(); }, 20000);
})();
</script>
<?php endif; ?>
</body>
</html>
