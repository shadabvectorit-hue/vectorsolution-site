<?php
/**
 * VectorIT — WhatsApp Cloud API (Meta, direct).
 *
 * This talks to Meta's Graph API with no reseller in between. Resellers (Wati,
 * Twilio, Respond.io and friends) charge a monthly platform fee on top of what
 * Meta charges; going direct removes the fee entirely. What it costs instead is
 * this file — about a hundred lines — plus a one-off setup in Meta's console.
 */
declare(strict_types=1);

require_once __DIR__ . '/cfg.php';

const VIT_WA_GRAPH = 'https://graph.facebook.com/v21.0/';

/**
 * Send a plain text message.
 *
 * Only valid inside the 24-hour service window — that is, in reply to something
 * the customer sent. Outside it Meta requires a pre-approved template and
 * charges per message. Every call this system makes is a reply, so it stays in
 * the free window by construction.
 */
function vit_wa_send(string $to, string $text): bool {
    if (!vit_wa_enabled() || !function_exists('curl_init')) {
        return false;
    }
    $to = preg_replace('/\D+/', '', $to);
    if ($to === '' || $text === '') {
        return false;
    }

    $url = VIT_WA_GRAPH . rawurlencode((string)vit_cfg_get('wa_phone_id')) . '/messages';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . (string)vit_cfg_get('wa_token'),
        ],
        CURLOPT_POSTFIELDS     => (string)json_encode([
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'text',
            // Link previews off: the demo URL would otherwise render a card and
            // push the actual answer off the first screen on a phone.
            'text'              => ['preview_url' => false, 'body' => mb_substr($text, 0, 4000)],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($code !== 200) {
        vit_audit('wa_send_fail', ['code' => $code, 'body' => substr((string)$raw, 0, 200)]);
        return false;
    }
    return true;
}

/**
 * Verify Meta's webhook signature.
 *
 * Without this anyone who learns the URL can post fabricated messages and make
 * the assistant answer — and burn the AI budget doing it. The signature is an
 * HMAC of the RAW body, so it must be computed before any parsing or
 * normalisation touches the bytes.
 */
function vit_wa_signature_ok(string $rawBody): bool {
    $secret = (string)vit_cfg_get('wa_app_secret');
    if ($secret === '') {
        return false;
    }
    $given = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    if (!str_starts_with($given, 'sha256=')) {
        return false;
    }
    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, $given);
}

/**
 * Meta retries a webhook it thinks failed, and a retry that is answered again
 * means the customer gets the same reply twice and we pay for it twice. Message
 * ids are unique, so remembering the recent ones is enough to make delivery
 * idempotent.
 */
function vit_wa_seen(string $messageId): bool {
    if ($messageId === '') {
        return false;
    }
    $dir = VIT_PRIVATE . '/wa_seen';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false; // fail open: better a duplicate reply than no reply
    }
    $mark = $dir . '/' . substr(sha1($messageId), 0, 24);
    if (is_file($mark)) {
        return true;
    }
    @touch($mark);
    if (random_int(1, 30) === 1) {
        foreach ((array)@glob($dir . '/*') as $f) {
            if (is_string($f) && (time() - (int)@filemtime($f)) > 86400) {
                @unlink($f);
            }
        }
    }
    return false;
}

/** Tell the owner a conversation needs a person. */
function vit_wa_alert_owner(string $from, string $why, string $lastMessage): void {
    $owner = preg_replace('/\D+/', '', (string)vit_cfg_get('wa_owner', ''));
    if ($owner === '' || $owner === preg_replace('/\D+/', '', $from)) {
        return;
    }
    vit_wa_send($owner, "🔔 Handover needed\nFrom: +{$from}\nWhy: {$why}\n\nThey said: \"" . mb_substr($lastMessage, 0, 300) . "\"\n\nOpen: https://wa.me/{$from}");
}
