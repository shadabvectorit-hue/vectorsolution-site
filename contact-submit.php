<?php
// VectorIT — lead capture endpoint.
// Stores every inquiry in /home/<user>/_private/inquiries.jsonl (outside the webroot)
// and emails a copy. Serves the contact form (redirect back), the chat bot and the
// demo's "save my demo data" form (JSON).
declare(strict_types=1);

header('X-Robots-Tag: noindex');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: contact.html');
    exit;
}

// Honeypot: real visitors never fill this hidden field.
if (!empty($_POST['website'])) {
    header('Location: contact.html?sent=1');
    exit;
}

// Rate limit: max 5 submissions per IP per hour — enough for any genuine visitor,
// a wall for a spam script. State lives outside the webroot.
$rlDir = dirname(__DIR__) . '/_private/ratelimit';
if (!is_dir($rlDir)) {
    @mkdir($rlDir, 0700, true);
}
$rlFile = $rlDir . '/' . substr(sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|vectorit-rl'), 0, 20) . '.json';
$hits = [];
if (is_file($rlFile)) {
    $hits = json_decode((string)@file_get_contents($rlFile), true) ?: [];
}
$hits = array_values(array_filter($hits, static fn($ts) => is_int($ts) && $ts > time() - 3600));
if (count($hits) >= 5) {
    $tooMany = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    if ($tooMany) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'too many requests — please try again later']);
    } else {
        header('Location: contact.html?sent=0');
    }
    exit;
}
$hits[] = time();
@file_put_contents($rlFile, json_encode($hits), LOCK_EX);

/** Strips control characters so nothing user-typed can forge an email header. */
$clean = static function (string $v, int $max): string {
    $v = str_replace(["\r", "\n", "\0"], ' ', $v);
    return mb_substr(trim($v), 0, $max);
};
$f = static fn(string $k): string => (string)($_POST[$k] ?? '');

$lead = [
    'time'     => date('Y-m-d H:i:s'),
    'leadId'   => $clean($f('leadId'), 40),
    'stage'    => $clean($f('stage'), 20),
    'source'   => $clean($f('source'), 40) ?: 'contact-form',
    'lang'     => $clean($f('lang'), 20),
    'name'     => $clean($f('name'), 120),
    'whatsapp' => $clean($f('whatsapp'), 40),
    'email'    => $clean($f('email'), 160),
    'company'  => $clean($f('company'), 160),
    'service'  => $clean($f('service'), 120),
    'budget'   => $clean($f('budget'), 120),
    'message'  => mb_substr(str_replace("\0", '', trim($f('message'))), 0, 4000),
    'page'     => $clean((string)($_SERVER['HTTP_REFERER'] ?? ''), 300),
];

// Anything sent by fetch() wants JSON back — not a redirect it will silently swallow.
$wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
          || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

$respond = static function (bool $ok, string $why = '') use ($wantsJson): never {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok] + ($why !== '' ? ['error' => $why] : []));
    } else {
        header('Location: contact.html?sent=' . ($ok ? '1' : '0'));
    }
    exit;
};

// Minimum viable lead: a name plus at least one way to reach back.
if ($lead['name'] === '' || ($lead['whatsapp'] === '' && $lead['email'] === '')) {
    $respond(false, 'name and a WhatsApp number or email are required');
}

// ---- store (source of truth) ----
$dir = dirname(__DIR__) . '/_private';
if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
}
$json = json_encode($lead, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false) {
    // Never write a blank line — a lead with odd bytes must still be readable.
    $json = json_encode(array_map(
        static fn($v) => is_string($v) ? mb_convert_encoding($v, 'UTF-8', 'UTF-8') : $v,
        $lead
    ));
}
$stored = $json !== false
    && @file_put_contents($dir . '/inquiries.jsonl', $json . "\n", FILE_APPEND | LOCK_EX) !== false;

// ---- email copies ----
$subject = 'New inquiry — ' . ($lead['name'] !== '' ? $lead['name'] : 'website visitor')
         . ($lead['stage'] === 'partial' ? ' (chat not finished)' : '');
$bodyLines = [];
foreach (['time', 'source', 'lang', 'stage', 'name', 'whatsapp', 'email', 'company', 'service', 'budget', 'message'] as $k) {
    if ($lead[$k] !== '') {
        $bodyLines[] = strtoupper($k) . ': ' . $lead[$k];
    }
}
$body = implode("\n", $bodyLines) . "\n\nView all inquiries: https://vectorsolution.it/inquiries.php";

$headers = "From: VectorIT Website <website@vectorsolution.it>\r\n";
// Only a genuinely valid address may enter a header — otherwise it's an injection vector.
if ($lead['email'] !== '' && filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
    $headers .= 'Reply-To: ' . $lead['email'] . "\r\n";
}
$mailed = false;
foreach (['website@vectorsolution.it', 'shadabvectorit@gmail.com'] as $to) {
    $mailed = @mail($to, $subject, $body, $headers, '-f website@vectorsolution.it') || $mailed;
}

// Only a lead that reached neither the disk nor a mailbox is a failure.
$respond($stored || $mailed, ($stored || $mailed) ? '' : 'could not be stored');
