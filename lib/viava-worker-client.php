<?php
/**
 * Signed server-to-server requests from these forms to the Agency HQ Worker.
 *
 * Each request signs METHOD \n PATH \n UNIX_TIMESTAMP \n SHA256(BODY) with
 * HMAC-SHA256. The shared secret lives in crm-config.php in the private folder
 * above the web root, and never in the repo or in anything sent to a browser.
 *
 * Example crm-config.php (created on the server, never committed):
 *   <?php return [
 *       'api_url' => 'https://viava-agency-hq.example.workers.dev',
 *       'key_id'  => 'k1',
 *       'secret'  => '...',
 *       // Only if Cloudflare Access covers this Worker's address:
 *       'access_client_id'     => '...',
 *       'access_client_secret' => '...',
 *   ];
 */

const VIAVA_WORKER_CONNECT_TIMEOUT = 2;
const VIAVA_WORKER_TOTAL_TIMEOUT = 5;

/** Returns the Worker settings, or null if they're absent or unsafe. */
function viava_crm_config(): ?array {
    $dir = viava_private_dir();
    if ($dir === null || !is_file($dir . '/crm-config.php')) {
        return null;
    }
    $config = require $dir . '/crm-config.php';
    if (!is_array($config) || !isset($config['api_url'], $config['key_id'], $config['secret'])) {
        error_log('VIA VA lead capture: crm-config.php is incomplete');
        return null;
    }
    // Lead data never travels over plain HTTP, other than to this machine in tests.
    $parts = parse_url((string) $config['api_url']);
    $scheme = $parts['scheme'] ?? '';
    $host = $parts['host'] ?? '';
    $isLocal = in_array($host, ['127.0.0.1', 'localhost'], true);
    if ($scheme !== 'https' && !($scheme === 'http' && $isLocal)) {
        error_log('VIA VA lead capture: crm-config.php api_url must use https');
        return null;
    }
    return $config;
}

/**
 * Posts JSON with a signature.
 * @return array{status:int,body:?array} status 0 means no response was received.
 */
function viava_worker_post(array $config, string $path, array $payload): array {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($body === false) {
        return ['status' => 0, 'body' => null];
    }
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', "POST\n{$path}\n{$timestamp}\n" . hash('sha256', $body), (string) $config['secret']);
    $headers = [
        'Content-Type: application/json',
        'X-Viava-Key-Id: ' . $config['key_id'],
        'X-Viava-Timestamp: ' . $timestamp,
        'X-Viava-Signature: ' . $signature,
    ];
    if (!empty($config['access_client_id']) && !empty($config['access_client_secret'])) {
        $headers[] = 'CF-Access-Client-Id: ' . $config['access_client_id'];
        $headers[] = 'CF-Access-Client-Secret: ' . $config['access_client_secret'];
    }
    $url = rtrim((string) $config['api_url'], '/') . $path;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => VIAVA_WORKER_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => VIAVA_WORKER_TOTAL_TIMEOUT,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body,
            'timeout' => VIAVA_WORKER_TOTAL_TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                $status = (int) $m[1];
            }
        }
    }

    $decoded = is_string($response) ? json_decode($response, true) : null;
    return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null];
}

/**
 * 'ok' is true only when the Worker confirms the record is saved in D1.
 * 'duplicate' means D1 already held this content, so no email should be repeated.
 *
 * @return array{ok:bool,duplicate:bool}
 */
function viava_worker_submit(array $config, array $record): array {
    $result = viava_worker_post($config, '/v1/intake/submissions', [
        'submission_key' => $record['id'],
        'form' => $record['form'],
        'submitted_at' => $record['received_at'],
        'fields' => $record['fields'],
        'flags' => (object) $record['flags'],
        'request' => $record['request'],
    ]);
    $ok = in_array($result['status'], [200, 201], true) && ($result['body']['ok'] ?? false) === true;
    return ['ok' => $ok, 'duplicate' => $ok && ($result['body']['duplicate'] ?? false) === true];
}

/** Tells Agency HQ how many saved submissions are still waiting to reach it. */
function viava_worker_heartbeat(array $config, int $pending, ?string $oldest): bool {
    $result = viava_worker_post($config, '/v1/intake/heartbeat', ['pending' => $pending, 'oldest_pending_at' => $oldest]);
    return $result['status'] === 200 && ($result['body']['ok'] ?? false) === true;
}

function viava_worker_event(array $config, string $id, string $event, bool $ok): bool {
    $result = viava_worker_post($config, '/v1/intake/submissions/' . rawurlencode($id) . '/events', [
        'event' => $event,
        'ok' => $ok,
    ]);
    return $result['status'] === 200 && ($result['body']['ok'] ?? false) === true;
}
