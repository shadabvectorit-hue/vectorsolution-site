<?php
/**
 * VectorIT — the assistant itself.
 *
 * One brain, two mouths. The website chat widget and WhatsApp both call
 * vit_agent_reply(); everything about how the assistant thinks, remembers and
 * escalates lives here exactly once. When the pricing changes, or the FBR
 * wording has to be corrected, there is a single place to change it and both
 * channels move together.
 *
 * Conversation memory is a small JSON file per contact under _private/convo/.
 * No database, because this hosting has none — and for a few hundred concurrent
 * conversations a locked file per contact is genuinely the right tool.
 */
declare(strict_types=1);

require_once __DIR__ . '/cfg.php';
require_once __DIR__ . '/kb.php';
require_once __DIR__ . '/ai.php';

/** Keep the last N exchanges. Older context stops paying for itself and every
 *  turn is billed on every later call, so an unbounded history is a bill that
 *  grows quadratically over one conversation. */
const VIT_CONVO_TURNS = 16;
const VIT_CONVO_TTL   = 172800; // 48h — after that it is a new conversation

function vit_convo_path(string $contact): string {
    return VIT_PRIVATE . '/convo/' . substr(sha1($contact), 0, 24) . '.json';
}

function vit_convo_load(string $contact): array {
    $file = vit_convo_path($contact);
    $raw  = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return ['turns' => [], 'name' => '', 'started' => time(), 'lead_sent' => false];
    }
    $d = json_decode($raw, true);
    if (!is_array($d) || (time() - (int)($d['started'] ?? 0)) > VIT_CONVO_TTL) {
        return ['turns' => [], 'name' => '', 'started' => time(), 'lead_sent' => false];
    }
    $d['turns'] = is_array($d['turns'] ?? null) ? $d['turns'] : [];
    return $d;
}

function vit_convo_save(string $contact, array $convo): void {
    $dir = VIT_PRIVATE . '/convo';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }
    // Trim before writing, never after reading — otherwise the file grows for
    // ever and only the prompt is bounded.
    if (count($convo['turns']) > VIT_CONVO_TURNS) {
        $convo['turns'] = array_slice($convo['turns'], -VIT_CONVO_TURNS);
    }
    @file_put_contents(vit_convo_path($contact), (string)json_encode($convo, JSON_UNESCAPED_UNICODE), LOCK_EX);

    // Opportunistic sweep: one in fifty writes clears anything long dead, so the
    // directory cannot grow without bound and no cron job is required.
    if (random_int(1, 50) === 1) {
        foreach ((array)@glob($dir . '/*.json') as $f) {
            if (is_string($f) && (time() - (int)@filemtime($f)) > VIT_CONVO_TTL) {
                @unlink($f);
            }
        }
    }
}

/**
 * Pull the [[LEAD ...]] and [[HANDOVER ...]] markers out of the model's reply.
 * They are stripped before the customer ever sees the text.
 *
 * @return array{text:string,lead:array<string,string>,handover:string}
 */
function vit_agent_parse(string $text): array {
    $lead = [];
    $handover = '';

    if (preg_match('/\[\[LEAD\s+([^\]]*)\]\]/i', $text, $m)) {
        foreach (explode('|', $m[1]) as $pair) {
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $k = strtolower(trim($k));
                $v = trim($v);
                if ($v !== '' && strtolower($v) !== 'unknown' && in_array($k, ['name', 'phone', 'company', 'need', 'budget'], true)) {
                    $lead[$k] = mb_substr($v, 0, 160);
                }
            }
        }
    }
    if (preg_match('/\[\[HANDOVER:?\s*([^\]]*)\]\]/i', $text, $m)) {
        $handover = trim($m[1]) !== '' ? mb_substr(trim($m[1]), 0, 200) : 'customer asked for a person';
    }

    $clean = preg_replace('/\[\[(LEAD|HANDOVER)[^\]]*\]\]/i', '', $text);
    return ['text' => trim((string)$clean), 'lead' => $lead, 'handover' => $handover];
}

/**
 * Write a lead into the same file the contact form uses, so it appears in
 * inquiries.php alongside everything else. A separate "AI leads" store would be
 * a second inbox nobody remembers to check.
 */
function vit_agent_store_lead(array $lead, string $channel, string $contact): void {
    $row = [
        'time'     => date('Y-m-d H:i:s'),
        'leadId'   => substr(sha1($contact . '|' . date('Y-m-d')), 0, 16),
        'stage'    => 'ai',
        'source'   => 'ai-' . $channel,
        'lang'     => '',
        'name'     => (string)($lead['name'] ?? ''),
        'whatsapp' => (string)($lead['phone'] ?? ($channel === 'whatsapp' ? $contact : '')),
        'email'    => '',
        'company'  => (string)($lead['company'] ?? ''),
        'service'  => (string)($lead['need'] ?? ''),
        'budget'   => (string)($lead['budget'] ?? ''),
        'message'  => 'Captured by the AI assistant on ' . $channel . '.',
        'page'     => $channel === 'whatsapp' ? 'WhatsApp' : (string)($_SERVER['HTTP_REFERER'] ?? ''),
    ];
    $file = VIT_PRIVATE . '/inquiries.jsonl';
    if (is_file($file) && (int)@filesize($file) > 40 * 1024 * 1024) {
        return;
    }
    if (!is_dir(VIT_PRIVATE)) {
        @mkdir(VIT_PRIVATE, 0700, true);
    }
    @file_put_contents($file, (string)json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Answer one message.
 *
 * @param string $contact  stable id — a phone number on WhatsApp, a session id on the web
 * @param string $message  what the customer just said
 * @param string $channel  'whatsapp' or 'website'
 * @return array{ok:bool,text:string,handover:string,lead:array}
 */
function vit_agent_reply(string $contact, string $message, string $channel): array {
    $message = trim(mb_substr($message, 0, 1500));
    if ($message === '') {
        return ['ok' => false, 'text' => '', 'handover' => '', 'lead' => []];
    }

    // Falling back to a human is always better than failing silently. Every
    // early return below still gives the customer somewhere to go.
    $fallback = $channel === 'whatsapp'
        ? "Thanks for your message — Shadab will reply here personally shortly. If it is urgent you can call +92 302 2219093."
        : "Thanks — I could not reach the assistant just now. Message us on WhatsApp and we will pick it up: https://wa.me/923022219093";

    if (!vit_ai_enabled()) {
        return ['ok' => false, 'text' => $fallback, 'handover' => 'AI off', 'lead' => []];
    }
    if (!vit_ai_budget_ok($contact)) {
        vit_audit('ai_capped', ['ch' => $channel]);
        return ['ok' => false, 'text' => $fallback, 'handover' => 'daily cap reached', 'lead' => []];
    }

    $convo = vit_convo_load($contact);
    $convo['turns'][] = ['role' => 'user', 'content' => $message];

    $res = vit_ai_complete(vit_kb_prompt($channel, (string)($convo['name'] ?? '')), $convo['turns']);
    if (!$res['ok']) {
        // Do not persist the unanswered turn: on the retry the customer would be
        // billed for a history containing their question twice.
        return ['ok' => false, 'text' => $fallback, 'handover' => 'assistant error', 'lead' => []];
    }
    vit_ai_meter($channel, $res['in'], $res['out']);

    $parsed = vit_agent_parse($res['text']);
    if ($parsed['text'] === '') {
        $parsed['text'] = $fallback;
    }

    $convo['turns'][] = ['role' => 'assistant', 'content' => $res['text']];
    if (($parsed['lead']['name'] ?? '') !== '' && ($convo['name'] ?? '') === '') {
        $convo['name'] = $parsed['lead']['name'];
    }

    // One lead per conversation. The model re-emits the marker as it learns more,
    // and without this the owner's inbox fills with the same person five times.
    if ($parsed['lead'] !== [] && empty($convo['lead_sent'])) {
        vit_agent_store_lead($parsed['lead'], $channel, $contact);
        $convo['lead_sent'] = true;
    }
    vit_convo_save($contact, $convo);

    return ['ok' => true, 'text' => $parsed['text'], 'handover' => $parsed['handover'], 'lead' => $parsed['lead']];
}
