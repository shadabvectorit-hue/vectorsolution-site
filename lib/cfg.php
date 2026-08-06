<?php
/**
 * VectorIT — configuration loader.
 *
 * Every secret (API keys, tokens, the webhook verify string) lives in
 * /home/<user>/_private/config.php — one level ABOVE the webroot, so it is
 * unreachable over HTTP even if a rewrite rule is ever broken, and it is not in
 * the git repository. Pushing a key to GitHub is the single most common way a
 * small site leaks credentials; keeping the file out of the repo removes that
 * whole class of accident rather than relying on .gitignore discipline.
 *
 * Everything degrades quietly. If the config file is absent the AI features are
 * simply OFF — the website still serves, the contact form still works, and the
 * old scripted chat still runs. Nothing here may ever be able to take the site
 * down.
 */
declare(strict_types=1);

require_once __DIR__ . '/guard.php';

/**
 * @return array<string,mixed>
 */
function vit_cfg(): array {
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $defaults = [
        // --- Anthropic (the brain) ---
        'anthropic_key'    => '',
        'model'            => 'claude-haiku-4-5-20251001',
        'max_tokens'       => 700,

        // --- WhatsApp Cloud API (Meta, direct — no reseller) ---
        'wa_token'         => '',   // permanent System User access token
        'wa_phone_id'      => '',   // Phone Number ID (NOT the phone number)
        'wa_verify_token'  => '',   // any random string; must match Meta's webhook config
        'wa_app_secret'    => '',   // used to verify every webhook signature
        'wa_owner'         => '923363138686', // where handover alerts go

        // --- spend ceilings (hard stops, not warnings) ---
        'daily_reply_cap'  => 400,  // total AI replies per day across all channels
        'per_user_cap'     => 40,   // AI replies per contact per day
        'kill_switch'      => false,
    ];
    $file = VIT_PRIVATE . '/config.php';
    $loaded = [];
    if (is_file($file)) {
        /** @noinspection PhpIncludeInspection */
        $loaded = @include $file;
        if (!is_array($loaded)) {
            $loaded = [];
        }
    }
    return $cfg = $loaded + $defaults;
}

function vit_cfg_get(string $key, mixed $fallback = null): mixed {
    $c = vit_cfg();
    return $c[$key] ?? $fallback;
}

/** True only when there is a key to call the model with and no kill switch. */
function vit_ai_enabled(): bool {
    return !vit_cfg_get('kill_switch', false)
        && trim((string)vit_cfg_get('anthropic_key', '')) !== '';
}

/** True only when WhatsApp is fully configured — all four values, not some. */
function vit_wa_enabled(): bool {
    foreach (['wa_token', 'wa_phone_id', 'wa_verify_token', 'wa_app_secret'] as $k) {
        if (trim((string)vit_cfg_get($k, '')) === '') {
            return false;
        }
    }
    return true;
}

/**
 * Two ceilings, both enforced with the same atomic limiter the rest of the site
 * uses. The per-user cap stops one person (or one loop) burning the budget; the
 * daily cap stops everyone together doing it. Without the global one, 500
 * different numbers each sending 40 messages is still a bill nobody approved.
 */
function vit_ai_budget_ok(string $contact): bool {
    if (!vit_rate_allow('aiday', (int)vit_cfg_get('daily_reply_cap', 400), 86400, 'global')) {
        return false;
    }
    return vit_rate_allow('aiuser', (int)vit_cfg_get('per_user_cap', 40), 86400, substr(sha1($contact), 0, 16));
}
