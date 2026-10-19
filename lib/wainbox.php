<?php
/**
 * VectorIT — WhatsApp inbox store.
 *
 * The assistant's memory (_private/convo/) is keyed by a hash and trimmed to the
 * last few turns, which is right for the model and useless for a person reading
 * a conversation. This keeps a separate, readable log per customer so the owner
 * can see every thread and answer from the business number itself.
 *
 * One JSON file per phone number under _private/wa_threads/. Flat files, locked
 * on write, for the same reason as the rest of this site: no database here.
 */
declare(strict_types=1);

require_once __DIR__ . '/cfg.php';
require_once __DIR__ . '/guard.php';

const VIT_WA_THREAD_KEEP = 300;      // messages kept per customer
const VIT_WA_WINDOW      = 86400;    // Meta's customer-service window: 24 h after their last message
const VIT_WA_HUMAN_HOLD  = 21600;    // after the owner replies, the assistant stays quiet for 6 h

function vit_wa_thread_dir(): string {
    return VIT_PRIVATE . '/wa_threads';
}

function vit_wa_thread_path(string $phone): string {
    return vit_wa_thread_dir() . '/' . preg_replace('/\D+/', '', $phone) . '.json';
}

function vit_wa_thread_load(string $phone): array {
    $phone = preg_replace('/\D+/', '', $phone);
    $raw = @file_get_contents(vit_wa_thread_path($phone));
    $d = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($d)) {
        $d = ['phone' => $phone, 'name' => '', 'msgs' => [], 'last_in' => 0, 'last_at' => 0, 'unread' => 0, 'human_until' => 0];
    }
    $d['msgs'] = is_array($d['msgs'] ?? null) ? $d['msgs'] : [];
    return $d;
}

function vit_wa_thread_save(array $t): void {
    $dir = vit_wa_thread_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }
    if (count($t['msgs']) > VIT_WA_THREAD_KEEP) {
        $t['msgs'] = array_slice($t['msgs'], -VIT_WA_THREAD_KEEP);
    }
    @file_put_contents(vit_wa_thread_path((string)$t['phone']), (string)json_encode($t, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Append one message. $dir: 'in' (customer), 'bot' (assistant), 'out' (owner).
 * Returns the updated thread.
 */
function vit_wa_log(string $phone, string $dir, string $text, string $name = ''): array {
    $phone = preg_replace('/\D+/', '', $phone);
    if ($phone === '') {
        return [];
    }
    $t = vit_wa_thread_load($phone);
    $now = time();
    $t['msgs'][] = ['t' => $now, 'd' => $dir, 'x' => mb_substr($text, 0, 4000)];
    $t['last_at'] = $now;
    if ($dir === 'in') {
        $t['last_in'] = $now;
        $t['unread'] = (int)($t['unread'] ?? 0) + 1;
    }
    if ($name !== '' && ($t['name'] ?? '') === '') {
        $t['name'] = mb_substr($name, 0, 60);
    }
    vit_wa_thread_save($t);
    return $t;
}

/** Has the owner taken this conversation over recently? Then the assistant stays out of it. */
function vit_wa_human_active(string $phone): bool {
    return (int)(vit_wa_thread_load($phone)['human_until'] ?? 0) > time();
}

/** All threads, newest activity first. */
function vit_wa_threads(): array {
    $out = [];
    foreach ((array)@glob(vit_wa_thread_dir() . '/*.json') as $f) {
        if (!is_string($f)) {
            continue;
        }
        $d = json_decode((string)@file_get_contents($f), true);
        if (is_array($d) && !empty($d['phone'])) {
            $out[] = $d;
        }
    }
    usort($out, static fn($a, $b) => (int)($b['last_at'] ?? 0) <=> (int)($a['last_at'] ?? 0));
    return $out;
}
