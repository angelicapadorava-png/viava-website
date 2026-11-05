<?php
/**
 * Lead capture for the website forms.
 *
 * Every accepted submission must reach durable storage before the visitor is
 * told it worked. In order:
 *   1. The Agency HQ Worker, which saves to Cloudflare D1 (the primary store).
 *   2. If that fails, a private JSON-lines file on this server (a temporary
 *      fallback only), re-sent later by tools/drain-spool.php.
 *   3. If that fails too, the capture is reported as failed, and the visitor
 *      must be told so. An email alone doesn't count as durable storage.
 *
 * The private folder is outside the web root, because Hostinger's git deploy
 * clean-syncs the site folder and nothing in there may hold personal data. It
 * also holds crm-config.php. Without that file the Worker is skipped.
 *
 * Rules: fallback files are append-only, and only lead IDs reach error_log().
 *
 * Location: VIAVA_PRIVATE_DIR if set (tests only), otherwise a viava-private
 * folder beside the site folder, e.g. next to public_html.
 */

require_once __DIR__ . '/viava-worker-client.php';

const VIAVA_SPOOL_SCHEMA = 1;
const VIAVA_IP_HOURLY_LIMIT = 5;        // Above this, no email goes to the submitted address.
const VIAVA_IP_HOURLY_DROP = 50;        // Above this, the request is not stored at all.
const VIAVA_GLOBAL_HOURLY_LIMIT = 30;   // Above this, all outbound form email pauses.
const VIAVA_DEDUPE_WINDOW = 900;        // Identical resubmissions within 15 minutes.
const VIAVA_DRAIN_STOP_AFTER = 3;       // Give up a replay run after this many failures in a row.

function viava_private_dir(): ?string {
    $dir = getenv('VIAVA_PRIVATE_DIR');
    if ($dir === false || $dir === '') {
        $dir = dirname(__DIR__, 2) . '/viava-private';
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }
    return is_writable($dir) ? $dir : null;
}

function viava_clip(string $value, int $max): string {
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

/** Per-install random salt, so stored IP hashes can't be reversed by lookup. */
function viava_salt(string $dir): string {
    $path = $dir . '/salt';
    $salt = is_file($path) ? (string) @file_get_contents($path) : '';
    if ($salt !== '') {
        return $salt;
    }
    $fresh = bin2hex(random_bytes(32));
    $fh = @fopen($path, 'x');
    if ($fh !== false) {
        fwrite($fh, $fresh);
        fclose($fh);
        @chmod($path, 0600);
        return $fresh;
    }
    // Another request created it first.
    $salt = is_file($path) ? (string) @file_get_contents($path) : '';
    return $salt !== '' ? $salt : $fresh;
}

/** Appends one record as a single JSON line. Returns true only on a full write. */
function viava_spool_append(array $record): bool {
    $dir = viava_private_dir();
    if ($dir === null) {
        return false;
    }
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($line === false) {
        return false;
    }
    $path = $dir . '/leads-' . gmdate('Y-m') . '.jsonl';
    $fh = @fopen($path, 'ab');
    if ($fh === false) {
        return false;
    }
    $ok = false;
    if (flock($fh, LOCK_EX)) {
        $written = fwrite($fh, $line . "\n");
        fflush($fh);
        if (function_exists('fsync')) {
            // Available from PHP 8.1: pushes the write to disk, not just the OS cache.
            @fsync($fh);
        }
        flock($fh, LOCK_UN);
        $ok = ($written === strlen($line) + 1);
    }
    fclose($fh);
    @chmod($path, 0600);
    return $ok;
}

/**
 * Records whether an email went out. Goes to the Worker if that's where the
 * lead was saved, and to the fallback file otherwise or if the Worker fails.
 */
function viava_record_event(array $capture, string $event, bool $ok): void {
    $id = $capture['id'];
    if ($id === null) {
        return;
    }
    try {
        $config = $capture['via'] === 'worker' ? viava_crm_config() : null;
        if ($config !== null && viava_worker_event($config, $id, $event, $ok)) {
            return;
        }
        viava_spool_append([
            'record' => 'event',
            'schema' => VIAVA_SPOOL_SCHEMA,
            'event_id' => bin2hex(random_bytes(8)),
            'lead_id' => $id,
            'event' => $event,
            'ok' => $ok,
            'at' => gmdate('c'),
        ]);
    } catch (Throwable $e) {
        error_log('VIA VA lead capture: could not record event for ' . $id);
    }
}

/**
 * Duplicate and rate checks, held in one locked state file. These stop abuse
 * before any network call; the Worker makes the final duplicate decision.
 * Fails open: if the state can't be read, nothing is treated as limited.
 */
function viava_guard(string $dir, string $ipHash, string $dedupeKey, ?int $now = null): array {
    $result = ['duplicate' => false, 'ip_limited' => false, 'global_limited' => false, 'dropped' => false];
    $now = $now ?? time();
    $fh = @fopen($dir . '/guard-state.json', 'c+');
    if ($fh === false) {
        return $result;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return $result;
    }

    $state = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($state)) {
        $state = [];
    }
    $recent = static fn (array $times): array => array_values(array_filter(
        $times,
        static fn ($t) => is_int($t) && $t > $now - 3600
    ));

    $ips = [];
    foreach ((array) ($state['ips'] ?? []) as $hash => $times) {
        $kept = $recent((array) $times);
        if ($kept !== []) {
            $ips[$hash] = $kept;
        }
    }
    $global = $recent((array) ($state['global'] ?? []));
    $dedupe = array_filter(
        (array) ($state['dedupe'] ?? []),
        static fn ($t) => is_int($t) && $t > $now - VIAVA_DEDUPE_WINDOW
    );

    $ipCount = count($ips[$ipHash] ?? []);
    $result['duplicate'] = isset($dedupe[$dedupeKey]);
    $result['ip_limited'] = $ipCount >= VIAVA_IP_HOURLY_LIMIT;
    $result['global_limited'] = count($global) >= VIAVA_GLOBAL_HOURLY_LIMIT;
    $result['dropped'] = $ipCount >= VIAVA_IP_HOURLY_DROP;

    // Only counts toward the limits here. A submission is remembered as a duplicate
    // by viava_capture_confirm(), and only once it has really succeeded. So two
    // simultaneous requests both carry on, and neither can be told "already saved"
    // about a save that later fails. The Worker settles which one counts.
    if (!$result['duplicate']) {
        $ips[$ipHash][] = $now;
        $global[] = $now;
    }

    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, (string) json_encode(['ips' => $ips, 'global' => $global, 'dedupe' => $dedupe]));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod($dir . '/guard-state.json', 0600);
    return $result;
}

/**
 * Remembers a submission as done, so an identical resubmission within the window
 * is answered without repeating work. Called only after it has truly succeeded:
 * saved for an inquiry, saved and sent for a download request. A failure is never
 * remembered, so a retry always gets a real attempt.
 */
function viava_capture_confirm(array $capture): void {
    $key = $capture['dedupe_key'] ?? null;
    $dir = viava_private_dir();
    if ($key === null || $dir === null) {
        return;
    }
    $fh = @fopen($dir . '/guard-state.json', 'c+');
    if ($fh === false) {
        return;
    }
    if (flock($fh, LOCK_EX)) {
        $state = json_decode((string) stream_get_contents($fh), true);
        $state = is_array($state) ? $state : [];
        $state['dedupe'][$key] = time();
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, (string) json_encode($state));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

/**
 * Saves a submission. Never throws: a storage problem must be reported through
 * the return value, so the handler can tell the visitor the truth.
 *
 * $fields is a flat map of submitted values; $flags marks things like a
 * honeypot hit. 'stored' is true only once the submission is durably saved.
 */
function viava_capture_lead(string $form, array $fields, array $flags = []): array {
    $capture = [
        'id' => null,
        'via' => null,        // 'worker' or 'fallback'
        'stored' => false,
        'dedupe_key' => null,
        'remote_duplicate' => false,  // The Worker says this content is already saved.
        'duplicate' => false,
        'ip_limited' => false,
        'global_limited' => false,
        'dropped' => false,
    ];

    try {
        $dir = viava_private_dir();
        if ($dir === null) {
            error_log('VIA VA lead capture: private directory unavailable; submission NOT saved');
            return $capture;
        }

        $clipped = [];
        foreach ($fields as $key => $value) {
            $limit = in_array($key, ['details', 'moreInfo', 'supportType'], true) ? 10000 : 500;
            $clipped[$key] = viava_clip(trim((string) $value), $limit);
        }

        $ipHash = hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), viava_salt($dir));
        $dedupeKey = hash('sha256', (string) json_encode(
            [$form, strtolower($clipped['email'] ?? ''), $clipped],
            JSON_INVALID_UTF8_SUBSTITUTE
        ));
        $guard = viava_guard($dir, $ipHash, $dedupeKey);
        $capture = array_merge($capture, $guard);
        $capture['dedupe_key'] = $dedupeKey;

        // A duplicate is already saved; a flood past the hard cap isn't a lead.
        if ($guard['duplicate'] || $guard['dropped']) {
            return $capture;
        }

        $id = 'L' . gmdate('Ymd') . '-' . bin2hex(random_bytes(6));
        $record = [
            'record' => 'lead',
            'schema' => VIAVA_SPOOL_SCHEMA,
            'id' => $id,
            'form' => $form,
            'received_at' => gmdate('c'),
            'fields' => $clipped,
            'flags' => array_merge($flags, array_filter([
                'ip_limited' => $guard['ip_limited'],
                'global_limited' => $guard['global_limited'],
            ])),
            'request' => [
                'ip_hash' => $ipHash,
                'user_agent' => viava_clip((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 300),
                'referer' => viava_clip((string) ($_SERVER['HTTP_REFERER'] ?? ''), 500),
            ],
        ];

        $config = viava_crm_config();
        $saved = $config !== null ? viava_worker_submit($config, $record) : ['ok' => false, 'duplicate' => false];
        if ($saved['ok']) {
            $capture['via'] = 'worker';
            $capture['remote_duplicate'] = $saved['duplicate'];
        } elseif (viava_spool_append($record)) {
            $capture['via'] = 'fallback';
            if ($config !== null) {
                error_log('VIA VA lead capture: Worker unavailable; ' . $id . ' saved to fallback file');
            }
        }

        if ($capture['via'] !== null) {
            $capture['stored'] = true;
            $capture['id'] = $id;
        } else {
            error_log('VIA VA lead capture: Worker and fallback file both failed; submission NOT saved');
        }
    } catch (Throwable $e) {
        error_log('VIA VA lead capture: error of type ' . get_class($e));
    }

    return $capture;
}

/**
 * Re-sends fallback records to the Worker. Built to run from cron every few
 * minutes:
 *   - Only one run at a time. An overlapping run exits straight away.
 *   - Stops early when the Worker is plainly down, so a run doesn't sit through a
 *     timeout per record, and leaves everything for the next run.
 *   - A limited number per run, so a large backlog can't overrun a cron slot.
 *   - Safe to repeat: submission keys are unique, so nothing is duplicated.
 *   - Files are never edited or deleted. Progress is recorded by appending markers.
 *   - Reports the backlog to Agency HQ, so the dashboard can warn about it.
 *
 * @return array{locked:bool,sent:int,already:int,failed:int,events:int,pending:int,oldest_pending_at:?string,stopped_early:bool}
 */
function viava_spool_drain(array $config, string $dir, int $limit = 200): array {
    $counts = ['locked' => false, 'sent' => 0, 'already' => 0, 'failed' => 0, 'events' => 0, 'pending' => 0, 'oldest_pending_at' => null, 'stopped_early' => false];

    $lock = @fopen($dir . '/drain.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        $counts['locked'] = true;
        if ($lock !== false) {
            fclose($lock);
        }
        return $counts;
    }

    $leads = [];
    $events = [];
    $syncedLeads = [];
    $syncedEvents = [];
    foreach (glob($dir . '/leads-*.jsonl') ?: [] as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            switch ($row['record'] ?? '') {
                case 'lead':
                    $leads[$row['id']] = $row;
                    break;
                case 'event':
                    $events[] = $row;
                    break;
                case 'synced':
                    $syncedLeads[$row['lead_id']] = true;
                    break;
                case 'event_synced':
                    $syncedEvents[$row['event_id']] = true;
                    break;
            }
        }
    }

    $attempted = 0;
    $consecutiveFailures = 0;
    foreach ($leads as $id => $record) {
        if (isset($syncedLeads[$id])) {
            $counts['already']++;
            continue;
        }
        if ($attempted >= $limit || $consecutiveFailures >= VIAVA_DRAIN_STOP_AFTER) {
            $counts['stopped_early'] = true;
            continue;
        }
        $attempted++;
        if (viava_worker_submit($config, $record)['ok']) {
            viava_spool_append(['record' => 'synced', 'lead_id' => $id, 'at' => gmdate('c')]);
            $syncedLeads[$id] = true;
            $counts['sent']++;
            $consecutiveFailures = 0;
        } else {
            $counts['failed']++;
            $consecutiveFailures++;
            error_log('VIA VA lead capture: replay could not send ' . $id . '; it stays in the fallback file');
        }
    }

    // Events can only be delivered once their lead is in the database.
    if ($consecutiveFailures < VIAVA_DRAIN_STOP_AFTER) {
        foreach ($events as $event) {
            $eventId = $event['event_id'] ?? '';
            if ($eventId === '' || isset($syncedEvents[$eventId])) {
                continue;
            }
            $leadPending = isset($leads[$event['lead_id']]) && !isset($syncedLeads[$event['lead_id']]);
            if (!$leadPending && viava_worker_event($config, $event['lead_id'], $event['event'], (bool) $event['ok'])) {
                viava_spool_append(['record' => 'event_synced', 'event_id' => $eventId, 'at' => gmdate('c')]);
                $counts['events']++;
            }
        }
    }

    foreach ($leads as $id => $record) {
        if (!isset($syncedLeads[$id])) {
            $counts['pending']++;
            $at = $record['received_at'] ?? null;
            if ($at !== null && ($counts['oldest_pending_at'] === null || strcmp($at, $counts['oldest_pending_at']) < 0)) {
                $counts['oldest_pending_at'] = $at;
            }
        }
    }

    // Best effort: if the Worker is down, this fails too, and the next run reports.
    if ($consecutiveFailures < VIAVA_DRAIN_STOP_AFTER) {
        viava_worker_heartbeat($config, $counts['pending'], $counts['oldest_pending_at']);
    }
    @file_put_contents($dir . '/drain-status.json', (string) json_encode([
        'last_run_at' => gmdate('c'),
        'pending' => $counts['pending'],
        'oldest_pending_at' => $counts['oldest_pending_at'],
        'sent' => $counts['sent'],
        'failed' => $counts['failed'],
    ]));

    flock($lock, LOCK_UN);
    fclose($lock);
    return $counts;
}
