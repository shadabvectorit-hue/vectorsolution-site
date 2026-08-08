<?php
/**
 * VectorIT — website chat endpoint.
 *
 * The same assistant that answers WhatsApp, reachable from the chat widget on
 * the site. Visitors who are not ready to hand over a phone number still get a
 * real answer, and the ones who are ready get handed to WhatsApp with their
 * details already captured.
 *
 * If the AI is switched off this returns ok:false and the widget falls back to
 * the original scripted flow — the site never loses its chat.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/cfg.php';
require_once __DIR__ . '/lib/agent.php';

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

$out = static function (array $data): never {
    echo (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    $out(['ok' => false, 'error' => 'post only']);
}
if (!vit_same_origin_post()) {
    http_response_code(403);
    $out(['ok' => false, 'error' => 'cross-site']);
}
if (!vit_ai_enabled()) {
    // Not an error the visitor should see — the widget just uses its own script.
    $out(['ok' => false, 'error' => 'disabled']);
}

// Bodies arrive as JSON from fetch(). Cap the read so a huge post cannot be
// used to exhaust memory before any of the cheaper checks run.
$raw = (string)file_get_contents('php://input', false, null, 0, 8192);
$in  = json_decode($raw, true);
if (!is_array($in)) {
    $in = $_POST;
}

$message = trim((string)($in['message'] ?? ''));
if ($message === '') {
    $out(['ok' => false, 'error' => 'empty']);
}

/* A conversation id supplied by the browser identifies the thread, but it must
   never be trusted on its own — anyone could send someone else's id and read
   their history back. Binding it to the caller's IP means a guessed id belongs
   to a different bucket and returns nothing. */
$sid     = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($in['sid'] ?? ''));
$sid     = $sid !== '' ? substr($sid, 0, 40) : bin2hex(random_bytes(8));
$contact = 'web:' . substr(sha1($sid . '|' . vit_client_key()), 0, 24);

// Chat is chattier than a contact form, so the limit is higher — but it is still
// a limit. 30 messages in 10 minutes is far past any real conversation.
if (!vit_rate_allow('chat', 30, 600)) {
    $out(['ok' => false, 'error' => 'slow down', 'text' => 'You are sending messages very quickly — give me a moment.']);
}

$reply = vit_agent_reply($contact, $message, 'website');

$out([
    'ok'       => $reply['ok'],
    'sid'      => $sid,
    'text'     => $reply['text'],
    // The widget uses this to surface the WhatsApp button at the right moment
    // instead of showing it constantly.
    'handover' => $reply['handover'] !== '',
    'wa'       => 'https://wa.me/923022219093',
]);
