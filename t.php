<?php
// VectorIT — first-party analytics beacon. No cookies, no third parties.
// Appends one JSON line per event to /home/<user>/_private/analytics.jsonl.
// Events: pv (page view) · wa (WhatsApp click) · plus server-side demo events.
declare(strict_types=1);
require_once __DIR__ . '/lib/guard.php';

@ini_set('display_errors', '0');
http_response_code(204);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

// POST only. Accepting GET made the beacon forgeable from any third-party page
// with a single <img> tag, which meant anyone could pollute the statistics.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !vit_same_origin_post()) {
    exit;
}

// Known events only — an open name field is an invitation to write junk.
const VIT_EVENTS = ['pv', 'wa'];
$e = substr((string)($_POST['e'] ?? ''), 0, 24);
$p = substr((string)($_POST['p'] ?? ''), 0, 120);
if (!in_array($e, VIT_EVENTS, true)) {
    exit;
}

// Skip crawlers and link-preview fetchers — they are not visitors.
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|whatsapp|facebookexternal|telegram|curl|wget|python|headless/i', $ua)) {
    exit;
}

// Far above any real session (one page view per load), but it stops a single
// source from writing the log to its ceiling and killing analytics for good.
if (!vit_rate_allow('beacon', 120, 600)) {
    exit;
}

$dir = VIT_PRIVATE;
if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
    exit;
}
$file = $dir . '/analytics.jsonl';
// Rotate rather than stop: hitting a hard ceiling used to disable analytics
// permanently, with nothing to say why.
if (is_file($file) && (int)@filesize($file) > 40 * 1024 * 1024) {
    @rename($file, $file . '.1');
}

$row = [
    't'  => date('Y-m-d H:i:s'),
    'e'  => $e,
    'p'  => $p,
    // Keyed hash with a secret kept outside the repository. A fixed salt would
    // not protect anyone: IPv4 is small enough to brute-force back to an address.
    'v'  => vit_visitor_hash(),
    'r'  => substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 160),
    'm'  => preg_match('/Mobile|Android|iPhone/i', $ua) ? 1 : 0,
];
@file_put_contents($file, (string)json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
