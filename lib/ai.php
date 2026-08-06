<?php
/**
 * VectorIT — Anthropic client.
 *
 * Deliberately dependency-free: this hosting has no composer and no way to run
 * one, so the whole client is cURL and json_encode. That is a feature here —
 * there is no vendor tree to keep patched.
 *
 * The important behaviour is what happens when the API is slow or down. A chat
 * reply that never arrives is worse than a plain one, so every call has a hard
 * timeout and every failure path returns a usable fallback rather than throwing.
 */
declare(strict_types=1);

require_once __DIR__ . '/cfg.php';

const VIT_AI_ENDPOINT = 'https://api.anthropic.com/v1/messages';
const VIT_AI_VERSION  = '2023-06-01';

/**
 * One completion.
 *
 * @param string $system  system prompt
 * @param array  $turns   [['role'=>'user'|'assistant','content'=>string], ...]
 * @return array{ok:bool,text:string,error:string,in:int,out:int}
 */
function vit_ai_complete(string $system, array $turns): array {
    $fail = static fn(string $why): array => ['ok' => false, 'text' => '', 'error' => $why, 'in' => 0, 'out' => 0];

    if (!vit_ai_enabled()) {
        return $fail('ai disabled');
    }
    if (!function_exists('curl_init')) {
        return $fail('curl missing');
    }

    $payload = json_encode([
        'model'      => (string)vit_cfg_get('model'),
        'max_tokens' => (int)vit_cfg_get('max_tokens', 700),
        'system'     => $system,
        'messages'   => array_values($turns),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($payload === false) {
        return $fail('encode failed');
    }

    // Two attempts. The first failure of a network call is very often transient;
    // a third attempt just makes the customer wait longer for the same answer.
    $lastErr = 'unknown';
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init(VIT_AI_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . (string)vit_cfg_get('anthropic_key'),
                'anthropic-version: ' . VIT_AI_VERSION,
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $lastErr = 'curl: ' . $cerr;
            continue;
        }
        $data = json_decode((string)$raw, true);

        if ($code === 200 && isset($data['content']) && is_array($data['content'])) {
            $text = '';
            foreach ($data['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= (string)($block['text'] ?? '');
                }
            }
            return [
                'ok'    => trim($text) !== '',
                'text'  => trim($text),
                'error' => trim($text) === '' ? 'empty reply' : '',
                'in'    => (int)($data['usage']['input_tokens'] ?? 0),
                'out'   => (int)($data['usage']['output_tokens'] ?? 0),
            ];
        }

        $lastErr = 'http ' . $code . ': ' . substr((string)($data['error']['message'] ?? $raw), 0, 200);

        // 4xx other than 429 is our bug (bad key, bad model name). Retrying an
        // authentication failure just doubles the latency before the same answer.
        if ($code >= 400 && $code < 500 && $code !== 429) {
            break;
        }
        usleep(600000);
    }

    vit_audit('ai_fail', ['e' => substr($lastErr, 0, 200)]);
    return $fail($lastErr);
}

/**
 * Running cost ledger, one line per call. Kept so the owner can see spend in the
 * inbox without opening a billing console — an AI bill that is invisible until
 * the end of the month is how small projects get surprised.
 */
function vit_ai_meter(string $channel, int $in, int $out): void {
    $file = VIT_PRIVATE . '/ai_usage.jsonl';
    if (is_file($file) && (int)@filesize($file) > 4 * 1024 * 1024) {
        @rename($file, $file . '.1');
    }
    @file_put_contents(
        $file,
        json_encode(['t' => gmdate('Y-m-d H:i:s'), 'c' => $channel, 'in' => $in, 'out' => $out]) . "\n",
        FILE_APPEND | LOCK_EX
    );
}
