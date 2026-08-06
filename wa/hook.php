<?php
/**
 * VectorIT — WhatsApp Cloud API webhook.
 *
 * Meta calls this URL twice in its life for two different reasons:
 *
 *   GET  — once, when you save the webhook in the Meta console. It echoes a
 *          challenge back to prove you own the endpoint.
 *   POST — every time a customer sends a message.
 *
 * The order of checks below is deliberate. Signature first, because an unsigned
 * body is not evidence of anything and must not reach the parser. Then the
 * duplicate check, because Meta retries and a retry must not be answered twice.
 * Only then does anything cost money.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/cfg.php';
require_once __DIR__ . '/../lib/wa.php';
require_once __DIR__ . '/../lib/agent.php';

@ini_set('display_errors', '0');
header('X-Robots-Tag: noindex, nofollow');

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');

/* ---------- verification handshake ---------- */
if ($method === 'GET') {
    $token = (string)vit_cfg_get('wa_verify_token', '');
    if ($token !== ''
        && (string)($_GET['hub_mode'] ?? '') === 'subscribe'
        && hash_equals($token, (string)($_GET['hub_verify_token'] ?? ''))) {
        header('Content-Type: text/plain');
        echo (string)($_GET['hub_challenge'] ?? '');
        vit_audit('wa_verified');
        exit;
    }
    http_response_code(403);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = (string)file_get_contents('php://input');

/* ---------- authenticity ---------- */
if (!vit_wa_signature_ok($raw)) {
    vit_audit('wa_bad_signature', ['len' => strlen($raw)]);
    http_response_code(403);
    exit;
}

/* Meta treats a slow reply as a failure and retries the whole delivery. Answer
   200 immediately, then keep working with the connection already closed. If the
   server offers no way to do that we simply carry on — the model is fast enough
   that this is a latency question, not a correctness one. */
$finish = static function (): void {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'ok';
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        @flush();
    }
};
$finish();
@ignore_user_abort(true);
@set_time_limit(60);

$body = json_decode($raw, true);
if (!is_array($body)) {
    exit;
}

foreach (($body['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        $value = $change['value'] ?? [];

        // Delivery receipts and read markers arrive on the same webhook. They are
        // not messages and must not be answered.
        foreach (($value['messages'] ?? []) as $msg) {
            $from = preg_replace('/\D+/', '', (string)($msg['from'] ?? ''));
            $mid  = (string)($msg['id'] ?? '');
            if ($from === '' || vit_wa_seen($mid)) {
                continue;
            }

            $type = (string)($msg['type'] ?? '');
            if ($type === 'text') {
                $text = (string)($msg['text']['body'] ?? '');
            } elseif ($type === 'interactive') {
                $text = (string)($msg['interactive']['button_reply']['title']
                              ?? $msg['interactive']['list_reply']['title'] ?? '');
            } else {
                // Images, audio, documents, location. The assistant cannot read
                // them, and pretending otherwise produces a confident wrong
                // answer — so say so plainly and put a human on it.
                vit_wa_send($from, "Thanks — I can only read text messages here. Shadab will look at what you sent and reply personally.");
                vit_wa_alert_owner($from, 'sent a ' . ($type ?: 'non-text') . ' message', '(attachment)');
                continue;
            }

            if (trim($text) === '') {
                continue;
            }

            $reply = vit_agent_reply($from, $text, 'whatsapp');
            if (trim($reply['text']) !== '') {
                vit_wa_send($from, $reply['text']);
            }
            if ($reply['handover'] !== '') {
                vit_wa_alert_owner($from, $reply['handover'], $text);
            }
        }
    }
}
exit;
