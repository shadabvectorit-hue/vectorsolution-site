<?php
// VectorIT — lead capture endpoint.
// Stores every inquiry in /home/<user>/_private/inquiries.jsonl (outside the webroot)
// and emails a copy. Serves both the contact form (redirect back) and the chat bot (JSON).
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

$f = static fn(string $k): string => trim((string)($_POST[$k] ?? ''));
$lead = [
    'time'     => date('Y-m-d H:i:s'),
    'source'   => $f('source') ?: 'contact-form',
    'name'     => mb_substr($f('name'), 0, 120),
    'whatsapp' => mb_substr($f('whatsapp'), 0, 40),
    'email'    => mb_substr($f('email'), 0, 160),
    'company'  => mb_substr($f('company'), 0, 160),
    'service'  => mb_substr($f('service'), 0, 120),
    'budget'   => mb_substr($f('budget'), 0, 120),
    'message'  => mb_substr($f('message'), 0, 4000),
    'page'     => mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 300),
];

$isBot = ($lead['source'] === 'bot');
$fail = static function () use ($isBot) {
    if ($isBot ?? false) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
    } else {
        header('Location: contact.html?sent=0');
    }
    exit;
};

// Minimum viable lead: a name plus at least one way to reach back.
if ($lead['name'] === '' || ($lead['whatsapp'] === '' && $lead['email'] === '')) {
    $fail();
}

// ---- store (source of truth) ----
$dir = dirname(__DIR__) . '/_private';
if (!is_dir($dir)) {
    @mkdir($dir, 0700, true);
}
@file_put_contents(
    $dir . '/inquiries.jsonl',
    json_encode($lead, JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND | LOCK_EX
);

// ---- email copies (best effort — the stored file is authoritative) ----
$subject = 'New inquiry — ' . ($lead['name'] ?: 'website visitor');
$bodyLines = [];
foreach (['time', 'source', 'name', 'whatsapp', 'email', 'company', 'service', 'budget', 'message'] as $k) {
    if ($lead[$k] !== '') {
        $bodyLines[] = strtoupper($k) . ': ' . $lead[$k];
    }
}
$body = implode("\n", $bodyLines) . "\n\nView all inquiries: https://vectorsolution.it/inquiries.php";
$headers = "From: VectorIT Website <noreply@vectorsolution.it>\r\n"
         . ($lead['email'] !== '' ? "Reply-To: {$lead['email']}\r\n" : '');
foreach (['shadabvectorit@gmail.com', 'shadab@vectorsolution.it'] as $to) {
    @mail($to, $subject, $body, $headers);
}

if ($isBot) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
} else {
    header('Location: contact.html?sent=1');
}
