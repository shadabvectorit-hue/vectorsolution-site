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

/**
 * Tell the owner a conversation needs a person.
 *
 * A message to the owner's own number is business-initiated, so outside a 24-hour
 * window Meta only delivers an approved template. The utility template
 * "handover_alert" (English, 4 body variables: number, reason, message, number for
 * the wa.me link) is tried first; free text is the fallback, which still lands when
 * the owner has written to the line in the last 24 hours. Costs one utility message.
 */
function vit_wa_alert_owner(string $from, string $why, string $lastMessage): void {
    $owner = preg_replace('/\D+/', '', (string)vit_cfg_get('wa_owner', ''));
    if ($owner === '' || $owner === preg_replace('/\D+/', '', $from)) {
        return;
    }
    $ok = vit_wa_send_template($owner, (string)vit_cfg_get('wa_handover_template', 'handover_alert'), 'en',
        [$from, $why, mb_substr($lastMessage, 0, 300), $from]);
    if (!$ok) {
        vit_wa_send($owner, "🔔 Handover needed\nFrom: +{$from}\nWhy: {$why}\n\nThey said: \"" . mb_substr($lastMessage, 0, 300) . "\"\n\nOpen: https://wa.me/{$from}");
    }
}

/**
 * Send an approved template with plain text body variables. Meta rejects a variable
 * containing a newline, a tab or more than four spaces in a row, so each one is
 * flattened first; an empty variable is also rejected, so it becomes a dash.
 */
function vit_wa_send_template(string $to, string $name, string $lang, array $vars): bool {
    if (!vit_wa_enabled() || !function_exists('curl_init') || $name === '') {
        return false;
    }
    $to = preg_replace('/\D+/', '', $to);
    if ($to === '') {
        return false;
    }
    $params = [];
    foreach ($vars as $v) {
        $v = trim(preg_replace('/\s+/u', ' ', (string)$v));
        $params[] = ['type' => 'text', 'text' => $v === '' ? '-' : mb_substr($v, 0, 900)];
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
            'type'              => 'template',
            'template'          => [
                'name'       => $name,
                'language'   => ['code' => $lang],
                'components' => [['type' => 'body', 'parameters' => $params]],
            ],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code !== 200) {
        vit_audit('wa_template_fail', ['tpl' => $name, 'code' => $code, 'body' => substr((string)$raw, 0, 200)]);
        return false;
    }
    return true;
}

/**
 * Email the owner about every inbound WhatsApp message. The WhatsApp alert to
 * the owner's own number is a business-initiated message that Meta only sends
 * with a payment method on file, so it fails silently; email always lands.
 */
function vit_wa_mail_owner(string $from, string $text, string $reply, string $handover = ''): void {
    $when    = date('d M Y H:i');
    $subject = ($handover !== '' ? '[Handover] ' : '') . 'WhatsApp from +' . $from;
    $body    = "New WhatsApp message on +92 302 2219093\n\n"
             . "From:   +{$from}\n"
             . "When:   {$when} (server time)\n"
             . ($handover !== '' ? "Needs you: {$handover}\n" : '')
             . "\nThey wrote:\n" . mb_substr($text, 0, 1500) . "\n"
             . "\nAssistant replied:\n" . ($reply !== '' ? mb_substr($reply, 0, 1500) : '(nothing)') . "\n"
             . "\nReply as +92 302 2219093: https://vectorsolution.it/wa/inbox.php?c={$from}\n"
             . "Reply from your own phone: https://wa.me/{$from}\n";
    $headers = "From: VectorIT Website <shadab@vectorsolution.it>\r\n"
             . "Reply-To: shadab@vectorsolution.it\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";
    $sent = false;
    foreach (['shadabvectorit@gmail.com', 'shadab@vectorsolution.it'] as $to) {
        $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers) || $sent;
    }
    if (!$sent) {
        vit_audit('wa_mail_fail', ['from' => $from]);
    }
}
