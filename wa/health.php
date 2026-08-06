<?php
/**
 * VectorIT — assistant self-check.
 *
 * Answers the questions you otherwise have to guess at on shared hosting: can
 * this server reach the outside world at all, is every credential present, does
 * the model actually answer. Reachable only with the inbox password, because the
 * output tells an attacker exactly which parts of the system are live.
 *
 * Nothing here is destructive and nothing is written except one metered test
 * call, which you have to ask for explicitly with ?live=1.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/cfg.php';
require_once __DIR__ . '/../lib/ai.php';
require_once __DIR__ . '/../lib/wa.php';

@ini_set('display_errors', '0');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

/* ---- same gate as the inbox ----
   This page shares the inbox session, so it must harden the cookie identically.
   Calling a bare session_start() here would issue a session cookie without
   HttpOnly/Secure/SameSite — and inquiries.php would then trust that weaker
   cookie, quietly undoing its own hardening. It must also honour the same
   expiry, or this page would stay open on a session the inbox has retired. */
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => true]);
session_start();

$passFile = VIT_PRIVATE . '/admin_pass.txt';
$stored   = is_file($passFile) ? trim((string)@file_get_contents($passFile)) : '';

if (!empty($_SESSION['inq_ok'])) {
    $seen = (int)($_SESSION['inq_seen'] ?? 0);
    $born = (int)($_SESSION['inq_born'] ?? 0);
    if (($seen && time() - $seen > 43200) || ($born && time() - $born > 604800)) {
        $_SESSION = [];
        session_destroy();
        session_start();
    } else {
        $_SESSION['inq_seen'] = time();
    }
}
$authed = !empty($_SESSION['inq_ok']);

if (!$authed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['pw'])) {
    if (!vit_rate_allow('healthlogin', 10, 900)) {
        http_response_code(429);
        exit('Too many attempts.');
    }
    if ($stored !== '' && is_string($_POST['pw']) && hash_equals($stored, trim((string)$_POST['pw']))) {
        session_regenerate_id(true);
        $_SESSION['inq_ok']   = true;
        $_SESSION['inq_born'] = time();
        $_SESSION['inq_seen'] = time();
        $authed = true;
    }
}
if (!$authed) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>VectorIT — assistant check</title>'
       . '<style>body{font:16px/1.5 system-ui;margin:0;display:grid;place-items:center;min-height:100vh;background:#F7F9FC;color:#0F1B33}'
       . 'form{background:#fff;padding:28px;border-radius:14px;box-shadow:0 10px 30px rgba(15,27,51,.12);width:min(92vw,340px)}'
       . 'input,button{width:100%;padding:11px;margin-top:10px;border-radius:9px;border:1px solid #C9D4EA;font-size:16px;box-sizing:border-box}'
       . 'button{background:#2E5BDB;color:#fff;border:0;font-weight:700;cursor:pointer}</style>'
       . '<form method="post"><b>Assistant check</b><input type="password" name="pw" placeholder="Password" autofocus>'
       . '<button>Open</button></form>';
    exit;
}

/* ---- checks ---- */
$rows = [];
$add  = static function (string $what, bool $ok, string $detail = '') use (&$rows): void {
    $rows[] = ['what' => $what, 'ok' => $ok, 'detail' => $detail];
};

$add('PHP version', PHP_VERSION_ID >= 80000, PHP_VERSION);
$add('cURL available', function_exists('curl_init'), function_exists('curl_init') ? (string)(curl_version()['version'] ?? '') : 'MISSING — nothing can call an API');
$add('_private writable', is_dir(VIT_PRIVATE) && is_writable(VIT_PRIVATE), VIT_PRIVATE);
$add('config.php found', is_file(VIT_PRIVATE . '/config.php'), is_file(VIT_PRIVATE . '/config.php') ? 'loaded' : 'create it — see SETUP-ASSISTANT.md');

// Outbound HTTPS is the make-or-break question on shared hosting. Some hosts
// block it entirely, which would make every part of this system silently dead.
$reach = static function (string $url): array {
    if (!function_exists('curl_init')) {
        return [false, 'no curl'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code > 0, $code > 0 ? ('reachable, HTTP ' . $code) : ('BLOCKED: ' . $err)];
};
[$okA, $dA] = $reach('https://api.anthropic.com/v1/messages');
$add('Can reach api.anthropic.com', $okA, $dA);
[$okG, $dG] = $reach('https://graph.facebook.com/v21.0/');
$add('Can reach graph.facebook.com', $okG, $dG);

$add('Early response supported', function_exists('litespeed_finish_request') || function_exists('fastcgi_finish_request'),
     function_exists('litespeed_finish_request') ? 'litespeed_finish_request()' : (function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request()' : 'neither — webhook replies after processing (still fine, just slower)'));

$add('Anthropic key set', trim((string)vit_cfg_get('anthropic_key')) !== '', 'model: ' . (string)vit_cfg_get('model'));
$add('AI enabled', vit_ai_enabled(), vit_cfg_get('kill_switch') ? 'kill_switch is ON' : '');
foreach (['wa_token' => 'WhatsApp token', 'wa_phone_id' => 'WhatsApp phone number ID', 'wa_verify_token' => 'Webhook verify token', 'wa_app_secret' => 'App secret'] as $k => $label) {
    $add($label . ' set', trim((string)vit_cfg_get($k)) !== '');
}
$add('WhatsApp enabled', vit_wa_enabled());

// Spend so far this month, read from the meter rather than estimated.
$in = $out = 0;
foreach (vit_tail_lines(VIT_PRIVATE . '/ai_usage.jsonl', 2 * 1024 * 1024) as $line) {
    $d = json_decode($line, true);
    if (is_array($d) && str_starts_with((string)($d['t'] ?? ''), gmdate('Y-m'))) {
        $in  += (int)($d['in'] ?? 0);
        $out += (int)($d['out'] ?? 0);
    }
}
$add('Tokens this month', true, number_format($in) . ' in / ' . number_format($out) . ' out');

$live = '';
if (isset($_GET['live']) && vit_ai_enabled()) {
    $r = vit_ai_complete('Reply with exactly: OK', [['role' => 'user', 'content' => 'ping']]);
    $live = $r['ok'] ? 'Model replied: ' . htmlspecialchars($r['text']) : 'Model call FAILED: ' . htmlspecialchars($r['error']);
}

header('Content-Type: text/html; charset=utf-8');
$pass = count(array_filter($rows, static fn($r) => $r['ok']));
echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Assistant check — VectorIT</title>'
   . '<style>body{font:15px/1.55 system-ui;margin:0;padding:24px;background:#F7F9FC;color:#0F1B33}'
   . '.w{max-width:760px;margin:0 auto}h1{font-size:20px;margin:0 0 4px}p.sub{color:#55617D;margin:0 0 18px}'
   . 'table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 6px 20px rgba(15,27,51,.08)}'
   . 'td{padding:11px 14px;border-bottom:1px solid #EEF3FA;vertical-align:top}tr:last-child td{border:0}'
   . '.s{width:34px;font-size:17px}.d{color:#55617D;font-size:13px}'
   . 'a.btn{display:inline-block;margin-top:16px;background:#2E5BDB;color:#fff;padding:10px 18px;border-radius:999px;text-decoration:none;font-weight:700}'
   . '.live{margin-top:16px;padding:12px 14px;background:#fff;border-left:4px solid #2E5BDB;border-radius:8px}</style>'
   . '<div class="w"><h1>Assistant check</h1><p class="sub">' . $pass . ' of ' . count($rows) . ' checks passing.</p><table>';
foreach ($rows as $r) {
    echo '<tr><td class="s">' . ($r['ok'] ? '✅' : '❌') . '</td><td><b>' . htmlspecialchars($r['what']) . '</b>'
       . ($r['detail'] !== '' ? '<div class="d">' . htmlspecialchars($r['detail']) . '</div>' : '') . '</td></tr>';
}
echo '</table>';
if ($live !== '') {
    echo '<div class="live">' . $live . '</div>';
}
echo '<a class="btn" href="?live=1">Send a real test message to the model</a>'
   . '<p class="d" style="margin-top:14px">This page is not indexed and needs the inbox password. The test call costs a fraction of a rupee.</p></div>';
