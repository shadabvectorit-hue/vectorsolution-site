<?php
// VectorIT — private inquiries inbox. Password lives in /home/<user>/_private/admin_pass.txt
// (outside the webroot, never in this public repository).
declare(strict_types=1);
require_once __DIR__ . '/lib/guard.php';

// Never print a stack trace to the browser: it would disclose the server path.
@ini_set('display_errors', '0');
// Reject session IDs the server never issued, and keep the cookie away from
// JavaScript and cross-site requests.
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => true]);
session_start();
header('X-Robots-Tag: noindex, nofollow');

const INQ_IDLE = 43200;   // 12 h without a request ends the session
const INQ_ABSOLUTE = 604800; // 7 days, however active

$passFile = VIT_PRIVATE . '/admin_pass.txt';
$storedPass = is_file($passFile) ? trim((string)file_get_contents($passFile)) : '';

/** Wipe every trace of the session, not just the flag. */
$endSession = static function (): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
};

if (isset($_GET['logout'])) {
    // Token-checked so a third-party page cannot log the owner out by embedding
    // this URL; falls back to simply clearing the flag if no token is present.
    if (!empty($_SESSION['inq_csrf']) && hash_equals((string)$_SESSION['inq_csrf'], (string)($_GET['t'] ?? ''))) {
        $endSession();
    } else {
        unset($_SESSION['inq_ok']);
    }
    header('Location: inquiries.php');
    exit;
}

// Expire an idle or over-age session before it can be used.
if (!empty($_SESSION['inq_ok'])) {
    $seen = (int)($_SESSION['inq_seen'] ?? 0);
    $born = (int)($_SESSION['inq_born'] ?? 0);
    if (($seen && time() - $seen > INQ_IDLE) || ($born && time() - $born > INQ_ABSOLUTE)) {
        $endSession();
        session_start();
        $err = 'Session expired. Please sign in again.';
    } else {
        $_SESSION['inq_seen'] = time();
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['pass'])) {
    // One password is the only gate on every lead's contact details. The limiter
    // holds its lock across read and write, so parallel guesses cannot each read
    // the same pre-image and multiply the budget.
    $submitted = is_string($_POST['pass']) ? $_POST['pass'] : '';

    if (!vit_rate_allow('login', 10, 900)) {
        $err = 'Too many attempts. Try again in 15 minutes.';
        vit_audit('admin_login_throttled');
    } elseif ($storedPass !== '' && $submitted !== '' && hash_equals($storedPass, $submitted)) {
        // New ID on login: any session the browser was carrying beforehand
        // (possibly planted by someone else) cannot become an admin session.
        session_regenerate_id(true);
        $_SESSION['inq_ok'] = true;
        $_SESSION['inq_born'] = time();
        $_SESSION['inq_seen'] = time();
        $_SESSION['inq_csrf'] = bin2hex(random_bytes(16));
        vit_rate_clear('login');
        vit_audit('admin_login_ok');
        // Redirect-after-post, and specifically to a bare URL. The login form
        // posts back to wherever the expired session left the owner — which can
        // be ?export=csv, and $_GET is populated on a POST too. Without this
        // redirect the very response to the password would be a CSV download,
        // leaving the login form on screen looking as if nothing happened.
        header('Location: inquiries.php');
        exit;
    } else {
        $err = 'Wrong password.';
        vit_audit('admin_login_failed');
        sleep(1);
    }
}
$authed = !empty($_SESSION['inq_ok']);

/* ---------- delete: one or many ----------
   The table posts an array of selections, each "linehash|leadId". Deleting by
   the exact line AND by leadId in the same pass matters: a bot lead is saved
   twice (partial, then complete), and removing only the line the owner can see
   would leave its half-finished twin to reappear on the next reload. */
if ($authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['sel'])) {
    $ok = !empty($_SESSION['inq_csrf']) && hash_equals((string)$_SESSION['inq_csrf'], (string)($_POST['csrf'] ?? ''));
    $hashes = [];
    $leadIds = [];
    if ($ok && is_array($_POST['sel'])) {
        foreach ($_POST['sel'] as $sel) {
            if (!is_string($sel)) continue;
            $parts = explode('|', $sel, 2);
            $h = preg_replace('/[^a-f0-9]/', '', $parts[0]);
            if (strlen($h) === 16) $hashes[$h] = true;
            $id = trim(mb_substr($parts[1] ?? '', 0, 40));
            if ($id !== '') $leadIds[$id] = true;
        }
    }
    $removed = 0;
    if ($ok && ($hashes || $leadIds)) {
        $lf = VIT_PRIVATE . '/inquiries.jsonl';
        $fh = @fopen($lf, 'c+');
        if ($fh && flock($fh, LOCK_EX)) {
            $keep = [];
            $gone = [];   // who was deleted — the audit line is the only record left
            rewind($fh);
            while (($line = fgets($fh)) !== false) {
                $trim = rtrim($line, "\r\n");
                if ($trim === '') continue;
                $isTarget = isset($hashes[substr(sha1($trim), 0, 16)]);
                $row = null;
                if (!$isTarget && $leadIds) {
                    $row = json_decode($trim, true);
                    if (is_array($row) && isset($leadIds[(string)($row['leadId'] ?? '')])) $isTarget = true;
                }
                if ($isTarget) {
                    $removed++;
                    if (count($gone) < 50) {
                        $row = is_array($row) ? $row : json_decode($trim, true);
                        $gone[] = mb_substr((string)(is_array($row) ? ($row['name'] ?? $row['whatsapp'] ?? $row['email'] ?? '?') : '?'), 0, 40);
                    }
                } else {
                    $keep[] = $trim;
                }
            }
            if ($removed > 0) {
                /* The payload is built BEFORE the truncate, so an out-of-memory
                   here dies with the file intact rather than after emptying it.
                   The write is then verified byte-for-byte: this file is the
                   only copy of every lead, and a short write (disk full, worker
                   killed) reported as success would be silent data loss. On
                   failure a recovery copy is dropped beside it and audited. */
                $payload = $keep ? implode("\n", $keep) . "\n" : '';
                ftruncate($fh, 0);
                rewind($fh);
                $written = fwrite($fh, $payload);
                fflush($fh);
                if ($written !== strlen($payload)) {
                    @file_put_contents(VIT_PRIVATE . '/inquiries.recovery-' . time() . '.jsonl', $payload);
                    vit_audit('lead_delete_writefail', ['expected' => strlen($payload), 'written' => (int)$written]);
                }
            }
            flock($fh, LOCK_UN);
        }
        if ($fh) fclose($fh);
        vit_audit('lead_deleted', ['n' => $removed, 'selected' => count($hashes), 'who' => $gone ?? []]);
    }
    header('Location: inquiries.php?deleted=' . $removed);
    exit;
}

/* ---------- the leads, newest first ----------
   Loaded before the export branch so both the page and the CSV see the same
   deduplicated list: a completed chat supersedes its own earlier partial save. */
$leads = [];
if ($authed) {
    $file = VIT_PRIVATE . '/inquiries.jsonl';
    if (is_file($file)) {
        $seen = [];
        foreach (array_reverse(vit_tail_lines($file, 41943040)) as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) continue;
            $id = (string)($row['leadId'] ?? '');
            if ($id !== '') {
                if (isset($seen[$id])) continue;
                $seen[$id] = true;
            }
            $row['_k'] = substr(sha1($line), 0, 16);   // handle for the delete checkbox
            $leads[] = $row;
        }
    }
}

/* ---------- export ----------
   Same session gate as the page; a read, so no CSRF token needed. The BOM is
   for Excel, which otherwise renders Urdu names as mojibake. Cells that start
   with a formula character are prefixed with an apostrophe — a spreadsheet
   that executes '=HYPERLINK(...)' pasted by a visitor into the contact form
   is this page's problem to prevent, not the spreadsheet user's to notice. */
// GET-only: $_GET is populated from the query string even on a POST, and the
// login form posts back to whatever URL the owner was on when the session
// expired — which must never be answered with a file download.
if ($authed && isset($_GET['export']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="vectorit-inquiries-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $cols = ['time', 'source', 'lang', 'stage', 'name', 'whatsapp', 'email', 'company', 'service', 'budget', 'message'];
    $guard = static function ($v): string {
        $v = (string)$v;
        return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
    };
    fputcsv($out, ['Time', 'Source', 'Language', 'Stage', 'Name', 'Mobile / WhatsApp', 'Email', 'Company', 'Need', 'Budget', 'Message']);
    foreach ($leads as $l) {
        $row = [];
        foreach ($cols as $c) $row[] = $guard($l[$c] ?? '');
        fputcsv($out, $row);
    }
    fclose($out);
    vit_audit('leads_exported', ['n' => count($leads)]);
    exit;
}

$stats = null;
if ($authed) {
    // ---- traffic stats from the first-party analytics log ----
    $af = VIT_PRIVATE . '/analytics.jsonl';
    $stats = ['pv7' => 0, 'pv30' => 0, 'uniq7' => [], 'wa7' => 0, 'demo7' => 0, 'inv7' => 0, 'pdf7' => 0, 'pos7' => 0, 'tracks' => [], 'pages' => [], 'mobile' => 0, 'total7' => 0];
    if (is_file($af)) {
        // Bounded read: pulling the whole log into an array would let anyone who
        // can grow it exhaust PHP's memory and lock the owner out of this page.
        $rows = vit_tail_lines($af);
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
            elseif ($ev === 'demo_pos_sale') $stats['pos7']++;
            elseif (str_starts_with($ev, 'demo_start_')) {
                $t = substr($ev, 11);
                $stats['tracks'][$t] = ($stats['tracks'][$t] ?? 0) + 1;
            }
        }
        arsort($stats['pages']);
        $stats['pages'] = array_slice($stats['pages'], 0, 8, true);
    }

    // ---- security: who has been trying to get in ----
    $sec = ['failed' => 0, 'throttled' => 0, 'ok' => 0, 'ips' => [], 'last' => ''];
    $auditFile = VIT_PRIVATE . '/audit.jsonl';
    if (is_file($auditFile)) {
        $cut = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        foreach (vit_tail_lines($auditFile, 4194304) as $line) {
            $r = json_decode($line, true);
            if (!is_array($r) || (string)($r['t'] ?? '') < $cut) continue;
            $ev = (string)($r['e'] ?? '');
            if ($ev === 'admin_login_failed') {
                $sec['failed']++;
                $ip = (string)($r['ip'] ?? '');
                if ($ip !== '') $sec['ips'][$ip] = ($sec['ips'][$ip] ?? 0) + 1;
            } elseif ($ev === 'admin_login_throttled') {
                $sec['throttled']++;
            } elseif ($ev === 'admin_login_ok') {
                $sec['ok']++;
                $sec['last'] = (string)($r['t'] ?? '');
            }
        }
        arsort($sec['ips']);
        $sec['ips'] = array_slice($sec['ips'], 0, 5, true);
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
  .wrap { max-width: 1240px; margin: 0 auto; }
  h1 { font-size: 1.4rem; } h1 small { color: #55617D; font-weight: 400; font-size: .85rem; }
  .login { max-width: 340px; margin: 12vh auto; background: #fff; border: 1px solid #E3E9F5; border-radius: 14px; padding: 28px; box-shadow: 0 12px 32px rgba(15,27,51,.08); }
  input[type=password] { width: 100%; box-sizing: border-box; padding: 11px 14px; border: 1.5px solid #C9D4EA; border-radius: 9px; font-size: 1rem; margin: 10px 0 14px; }
  button { background: #2E5BDB; color: #fff; border: 0; border-radius: 999px; padding: 11px 22px; font-size: .95rem; font-weight: 600; cursor: pointer; }
  .err { color: #B32222; font-size: .9rem; }
  .card { background: #fff; border: 1px solid #E3E9F5; border-radius: 12px; padding: 16px 18px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(15,27,51,.05); }
  .meta { color: #55617D; font-size: .8rem; margin: 4px 0 8px; }
  .tag { display: inline-block; background: #E8EEFC; color: #1E3FA6; border-radius: 999px; padding: 2px 10px; font-size: .75rem; margin-right: 6px; }
  a { color: #2E5BDB; }
  .top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; gap: 12px; flex-wrap: wrap; }
  .empty { color: #55617D; padding: 40px; text-align: center; }
  .done { background: #E7F5EC; border: 1px solid #B7DEC6; color: #0B6B39; border-radius: 10px;
          padding: 11px 15px; margin-bottom: 14px; font-size: .9rem; }

  /* ---- the table ---- */
  .toolbar { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
  .toolbar .grow { flex: 1; }
  .btn-2 { background: #fff; color: #2E5BDB; border: 1.5px solid #C9D4EA; border-radius: 999px;
           padding: 9px 18px; font-size: .88rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
  .btn-2:hover { border-color: #2E5BDB; }
  .btn-danger { background: #fff; color: #B32222; border: 1.5px solid #E4B7B7; border-radius: 999px;
                padding: 9px 18px; font-size: .88rem; font-weight: 600; cursor: pointer; }
  .btn-danger:disabled { color: #B0BACD; border-color: #E3E9F5; cursor: default; }
  .btn-danger:not(:disabled):hover { background: #FDECEC; }
  .tbl-wrap { background: #fff; border: 1px solid #E3E9F5; border-radius: 12px; overflow-x: auto; box-shadow: 0 1px 3px rgba(15,27,51,.05); }
  table { width: 100%; border-collapse: collapse; font-size: .88rem; min-width: 980px; }
  th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #EEF2FA; vertical-align: top; }
  th { font-size: .72rem; letter-spacing: .08em; text-transform: uppercase; color: #55617D; background: #F9FBFE;
       position: sticky; top: 0; white-space: nowrap; }
  tr:last-child td { border-bottom: 0; }
  tr:hover td { background: #F9FBFE; }
  td.nowrap { white-space: nowrap; }
  td .msg { white-space: pre-wrap; max-width: 380px; color: #3A4761; }
  td .name { font-weight: 700; }
  .chk { width: 17px; height: 17px; accent-color: #2E5BDB; cursor: pointer; }
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
      <a href="?logout=1&amp;t=<?= $e($_SESSION['inq_csrf'] ?? '') ?>">Log out</a>
    </div>

    <?php if (isset($_GET['deleted'])): ?>
      <?php $n = (int)$_GET['deleted']; ?>
      <div class="done"><?= $n > 0 ? '✓ Deleted — ' . $n . ' record' . ($n === 1 ? '' : 's') . ' removed.' : 'Nothing was deleted (the entries may already be gone).' ?></div>
    <?php endif; ?>

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
        <div><div style="font-size:1.5rem;font-weight:800;color:#0B8043"><?= $stats['pos7'] ?></div><div class="meta">POS sales rung up</div></div>
        <div><div style="font-size:1.5rem;font-weight:800"><?= $stats['total7'] ? round($stats['mobile'] * 100 / $stats['total7']) : 0 ?>%</div><div class="meta">On mobile</div></div>
      </div>
      <?php if (!empty($stats['tracks'])): ?>
      <div class="meta" style="margin-top:12px">Which demo they picked:
        <?php arsort($stats['tracks']); foreach ($stats['tracks'] as $t => $n): ?><span class="tag"><?= $e($t) ?> · <?= (int)$n ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($stats['pages']): ?>
      <div class="meta" style="margin-top:12px">Top pages:
        <?php foreach ($stats['pages'] as $pg => $n): ?><span class="tag"><?= $e($pg) ?> · <?= $n ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($sec)): ?>
    <div class="card" style="margin-bottom:18px;<?= $sec['failed'] >= 20 ? 'border-color:#E4B7B7;background:#FFF7F7' : '' ?>">
      <b style="font-size:1.05rem">Security — last 7 days</b>
      <div class="meta" style="margin-top:8px">
        <?= (int)$sec['ok'] ?> successful sign-in<?= $sec['ok'] === 1 ? '' : 's' ?><?php if ($sec['last']): ?>
          (most recent <?= $e($sec['last']) ?> UTC)<?php endif; ?> ·
        <strong style="color:<?= $sec['failed'] ? '#B32222' : '#178A50' ?>"><?= (int)$sec['failed'] ?></strong> failed
        <?php if ($sec['throttled']): ?> · <strong style="color:#B32222"><?= (int)$sec['throttled'] ?></strong> blocked by rate limit<?php endif; ?>
      </div>
      <?php if ($sec['ips']): ?>
      <div class="meta">Most failed attempts from:
        <?php foreach ($sec['ips'] as $ip => $n): ?><span class="tag"><?= $e($ip) ?> · <?= (int)$n ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($sec['failed'] >= 20): ?>
      <div class="meta" style="color:#B32222"><strong>Worth a look.</strong> That is a lot of failed attempts — consider changing the password in <code>_private/admin_pass.txt</code> to a long random passphrase.</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!$leads): ?>
      <div class="card empty">No inquiries yet. They will appear here the moment someone submits the contact form, finishes the chat, or messages the WhatsApp number.</div>
    <?php else: ?>

    <form method="post" id="leads-form"
          onsubmit="return confirm('Delete ' + document.querySelectorAll('.row-chk:checked').length + ' selected inquiry(ies) permanently? This cannot be undone.')">
      <input type="hidden" name="csrf" value="<?= $e($_SESSION['inq_csrf'] ?? '') ?>">

      <div class="toolbar">
        <button type="submit" class="btn-danger" id="del-btn" disabled>Delete selected</button>
        <span class="meta" id="sel-count"></span>
        <span class="grow"></span>
        <a class="btn-2" href="?export=csv">⬇ Export CSV</a>
      </div>

      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th><input type="checkbox" class="chk" id="chk-all" title="Select all"></th>
              <th>Time</th>
              <th>Name</th>
              <th>Mobile / WhatsApp</th>
              <th>Email</th>
              <th>Source</th>
              <th>Need</th>
              <th>Budget</th>
              <th>Message</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($leads as $l): ?>
            <tr>
              <td><input type="checkbox" class="chk row-chk" name="sel[]"
                         value="<?= $e(($l['_k'] ?? '') . '|' . ($l['leadId'] ?? '')) ?>"></td>
              <td class="nowrap"><?= $e($l['time'] ?? '') ?></td>
              <td>
                <span class="name"><?= $e($l['name'] ?? '') ?></span>
                <?php if (($l['stage'] ?? '') === 'partial'): ?><br><span class="tag" style="background:#FFF0D9;color:#925E10">not finished</span><?php endif; ?>
                <?php if (!empty($l['company'])): ?><br><span class="meta"><?= $e($l['company']) ?></span><?php endif; ?>
              </td>
              <td class="nowrap">
                <?php if (!empty($l['whatsapp'])): ?>
                  <a href="https://wa.me/<?= $e(preg_replace('/\D/', '', (string)$l['whatsapp'])) ?>" target="_blank" rel="noopener"><?= $e($l['whatsapp']) ?></a>
                <?php endif; ?>
              </td>
              <td class="nowrap">
                <?php if (!empty($l['email'])): ?><a href="mailto:<?= $e($l['email']) ?>"><?= $e($l['email']) ?></a><?php endif; ?>
              </td>
              <td class="nowrap">
                <span class="tag"><?= $e($l['source'] ?? '') ?></span>
                <?php if (!empty($l['lang'])): ?><span class="tag"><?= $e($l['lang']) ?></span><?php endif; ?>
              </td>
              <td><?= $e($l['service'] ?? '') ?></td>
              <td class="nowrap"><?= $e($l['budget'] ?? '') ?></td>
              <td><div class="msg"><?= $e($l['message'] ?? '') ?></div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>

    <script>
    (function () {
      var all = document.getElementById('chk-all');
      var rows = Array.prototype.slice.call(document.querySelectorAll('.row-chk'));
      var btn = document.getElementById('del-btn');
      var count = document.getElementById('sel-count');
      function refresh() {
        var n = rows.filter(function (c) { return c.checked; }).length;
        btn.disabled = n === 0;
        btn.textContent = n ? 'Delete selected (' + n + ')' : 'Delete selected';
        count.textContent = n ? n + ' of ' + rows.length + ' selected' : '';
        all.checked = n === rows.length && n > 0;
        all.indeterminate = n > 0 && n < rows.length;
      }
      all.addEventListener('change', function () {
        rows.forEach(function (c) { c.checked = all.checked; });
        refresh();
      });
      rows.forEach(function (c) { c.addEventListener('change', refresh); });
      refresh();
    })();
    </script>

    <?php endif; ?>
  </div>
<?php endif; ?>
</body>
</html>
