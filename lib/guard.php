<?php
/**
 * VectorIT — shared request guards.
 *
 * Every public endpoint writes to a file outside the webroot, so all of them
 * need the same three things: a rate limit that actually holds under concurrent
 * requests, bounded reads so a large file can never exhaust PHP's memory, and
 * an audit trail for anything touching the lead database.
 *
 * Rules followed throughout: fail OPEN on filesystem trouble (a broken disk must
 * not take the site down), and never let guard state grow without bound.
 */
declare(strict_types=1);

if (!defined('VIT_PRIVATE')) {
    // /home/<user>/_private — one level above the webroot on cPanel.
    define('VIT_PRIVATE', dirname(__DIR__, 2) . '/_private');
}

/**
 * Stable per-client key. IPv6 is collapsed to its /64 because a single customer
 * is routinely handed billions of addresses in that range — limiting per full
 * address would be no limit at all.
 */
function vit_client_key(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return 'unknown';
    }
    if (strlen($bin) === 16) {
        $bin = substr($bin, 0, 8);
    }
    return bin2hex($bin);
}

/**
 * Sliding-window rate limit.
 *
 * State lives in a fixed number of bucket files (never one per IP, which would
 * let an attacker with a /64 exhaust the account's inode quota). The lock is
 * held across the read AND the write — without that, concurrent requests each
 * read the same pre-image and the cap multiplies by the number of workers.
 *
 * @param string $name   limiter name, e.g. 'beacon' or 'login'
 * @param int    $limit  allowed events per window
 * @param int    $window window length in seconds
 * @param string|null $key override the client key (defaults to the caller's IP)
 */
function vit_rate_allow(string $name, int $limit, int $window, ?string $key = null): bool {
    $key = $key ?? vit_client_key();
    $dir = VIT_PRIVATE . '/ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return true; // fail open
    }
    $bucket = sprintf('%s_%02x.json', preg_replace('/[^a-z0-9]/', '', $name), crc32($key) & 0xFF);
    $fh = @fopen($dir . '/' . $bucket, 'c+');
    if (!$fh) {
        return true; // fail open
    }
    $allowed = true;
    try {
        if (!flock($fh, LOCK_EX)) {
            return true;
        }
        $raw = stream_get_contents($fh);
        $map = json_decode($raw === '' ? '{}' : (string)$raw, true);
        if (!is_array($map)) {
            $map = [];
        }
        $now = time();
        foreach ($map as $k => $stamps) {
            if (!is_array($stamps)) {
                unset($map[$k]);
                continue;
            }
            $stamps = array_values(array_filter($stamps, static fn($t) => is_int($t) && $t > $now - $window));
            if ($stamps === []) {
                unset($map[$k]);
            } else {
                $map[$k] = $stamps;
            }
        }
        if (count($map) > 500) {
            $map = array_slice($map, -500, null, true);
        }
        $hits = $map[$key] ?? [];
        $allowed = count($hits) < $limit;
        if ($allowed) {
            $hits[] = $now;
            $map[$key] = $hits;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string)json_encode($map));
        fflush($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    return $allowed;
}

/** Forget a client's hits for one limiter — used after a successful login. */
function vit_rate_clear(string $name, ?string $key = null): void {
    $key = $key ?? vit_client_key();
    $file = VIT_PRIVATE . '/ratelimit/' . sprintf('%s_%02x.json', preg_replace('/[^a-z0-9]/', '', $name), crc32($key) & 0xFF);
    $fh = @fopen($file, 'c+');
    if (!$fh) {
        return;
    }
    if (flock($fh, LOCK_EX)) {
        $raw = stream_get_contents($fh);
        $map = json_decode($raw === '' ? '{}' : (string)$raw, true);
        if (is_array($map)) {
            unset($map[$key]);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string)json_encode($map));
            fflush($fh);
        }
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

/**
 * Read at most the last $maxBytes of a line-oriented file.
 *
 * inquiries.php used to pull whole logs into an array before trimming them,
 * which turns a large file into a fatal memory error — i.e. anyone able to grow
 * the log could lock the owner out of their own inbox.
 */
function vit_tail_lines(string $file, int $maxBytes = 25165824): array {
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return [];
    }
    $size = (int)@filesize($file);
    if ($size > $maxBytes) {
        fseek($fh, -$maxBytes, SEEK_END);
        fgets($fh); // discard the partial first line
    }
    $out = [];
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line !== '') {
            $out[] = $line;
        }
    }
    fclose($fh);
    return $out;
}

/**
 * Append-only security log. Kept separate from the lead and analytics files so
 * that filling either one can never erase the record of who tried what.
 */
function vit_audit(string $event, array $detail = []): void {
    $dir = VIT_PRIVATE;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;
    }
    $file = $dir . '/audit.jsonl';
    if (is_file($file) && (int)@filesize($file) > 8 * 1024 * 1024) {
        @rename($file, $file . '.1'); // one generation, then start fresh
    }
    $row = [
        't'  => gmdate('Y-m-d H:i:s'),
        'e'  => $event,
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
        'd'  => $detail,
    ];
    @file_put_contents(
        $file,
        (string)json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Secret used to pseudonymise visitor IPs in the analytics log. A hardcoded
 * salt is no protection: IPv4 is only 4 billion values, so anyone who reads the
 * log can brute-force every hash back to an address in minutes. Generated once,
 * kept outside the webroot, never in the git repository.
 */
function vit_analytics_salt(): string {
    static $salt = null;
    if ($salt !== null) {
        return $salt;
    }
    $file = VIT_PRIVATE . '/analytics_salt.txt';
    $existing = @file_get_contents($file);
    if (is_string($existing) && strlen(trim($existing)) >= 32) {
        return $salt = trim($existing);
    }
    try {
        $new = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $new = hash('sha256', __FILE__ . php_uname() . getmypid());
    }
    if (!is_dir(VIT_PRIVATE)) {
        @mkdir(VIT_PRIVATE, 0700, true);
    }
    @file_put_contents($file, $new, LOCK_EX);
    return $salt = $new;
}

/** Daily-rotating pseudonym for one visitor — not reversible without the salt. */
function vit_visitor_hash(): string {
    return substr(hash_hmac('sha256', vit_client_key() . '|' . gmdate('Y-m-d'), vit_analytics_salt()), 0, 16);
}

/**
 * Reject obvious cross-site posts. Absent Origin/Referer is allowed on purpose:
 * privacy tooling strips them, and refusing those would silently lose real leads.
 */
function vit_same_origin_post(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        $val = (string)($_SERVER[$h] ?? '');
        if ($val === '') {
            continue;
        }
        $vh = strtolower((string)parse_url($val, PHP_URL_HOST));
        if ($vh === '') {
            continue;
        }
        return $vh === $host || $vh === 'www.' . $host || 'www.' . $vh === $host;
    }
    return true;
}
