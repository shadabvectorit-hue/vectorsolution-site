<?php
// VectorIT — first-party analytics beacon. No cookies, no third parties.
// Appends one JSON line per event to /home/<user>/_private/analytics.jsonl.
// Events: pv (page view) · wa (WhatsApp click) · plus server-side demo events.
declare(strict_types=1);

http_response_code(204);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$e = substr((string)($_POST['e'] ?? $_GET['e'] ?? ''), 0, 24);
$p = substr((string)($_POST['p'] ?? $_GET['p'] ?? ''), 0, 120);
if ($e === '' || preg_match('/[^a-z0-9_\-]/', $e)) {
    exit;
}

// Skip crawlers and link-preview fetchers — they are not visitors.
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|whatsapp|facebookexternal|telegram|curl|wget|python|headless/i', $ua)) {
    exit;
}

$dir = dirname(__DIR__) . '/_private';
if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
}
$file = $dir . '/analytics.jsonl';
// Disk-flood guard: stop logging past 50 MB (~2 years of heavy traffic).
if (is_file($file) && filesize($file) > 50 * 1024 * 1024) {
    exit;
}

$row = [
    't'  => date('Y-m-d H:i:s'),
    'e'  => $e,
    'p'  => $p,
    // Salted hash — enough to count unique visitors per day, never a raw IP.
    'v'  => substr(sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|vectorit-a7x|' . date('Y-m-d')), 0, 12),
    'r'  => substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 160),
    'm'  => preg_match('/Mobile|Android|iPhone/i', $ua) ? 1 : 0,
];
@file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
