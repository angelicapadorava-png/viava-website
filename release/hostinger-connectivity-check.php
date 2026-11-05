<?php
/**
 * One-off proof that the web host can reach the intake Worker, before any website
 * files change (PRODUCTION-RUNBOOK.md, stage 7). Upload it next to crm-config.php in
 * the private folder above public_html, run it once from a cron job, then delete it.
 *
 *   php drain-check.php   (any name works)
 *
 * It sends one signed heartbeat saying nothing is waiting, which is true before the
 * forms are connected. It prints a status only: never the secret, never the address.
 * Exit codes: 0 reached and accepted; 1 not; 2 not configured.
 */

if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

$configFile = __DIR__ . '/crm-config.php';
$config = is_file($configFile) ? require $configFile : null;
if (!is_array($config) || !isset($config['api_url'], $config['key_id'], $config['secret'])) {
    fwrite(STDOUT, "not configured: crm-config.php is missing or incomplete\n");
    exit(2);
}
$parts = parse_url((string) $config['api_url']);
$local = in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost'], true);
if (($parts['scheme'] ?? '') !== 'https' && !$local) {
    fwrite(STDOUT, "not configured: api_url must use https\n");
    exit(2);
}

$path = '/v1/intake/heartbeat';
$body = json_encode(['pending' => 0, 'oldest_pending_at' => null]);
$timestamp = (string) time();
$signature = hash_hmac('sha256', "POST\n{$path}\n{$timestamp}\n" . hash('sha256', $body), (string) $config['secret']);
$headers = ['Content-Type: application/json', 'X-Viava-Key-Id: ' . $config['key_id'], 'X-Viava-Timestamp: ' . $timestamp, 'X-Viava-Signature: ' . $signature];
$url = rtrim((string) $config['api_url'], '/') . $path;

$status = 0;
$via = '';
if (function_exists('curl_init')) {
    $via = 'curl';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($ch);
    curl_close($ch);
} else {
    $via = 'streams';
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => 10, 'ignore_errors' => true]]);
    @file_get_contents($url, false, $context);
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
    }
}

$meaning = [0 => 'no response: check outbound HTTPS and the address', 200 => 'reached and accepted', 401 => 'reached, but the signature was refused: check the secret and key ID',
    403 => 'reached, but blocked by Cloudflare Access: the intake must not be behind Access', 404 => 'reached, but that address has no intake: is it the CRM address?',
    409 => 'reached, refused as a replay: run it again', 429 => 'reached, rate limited: wait a minute', 503 => 'reached, but the Worker is misconfigured or its database is unavailable'];
fwrite(STDOUT, sprintf("%s via %s: HTTP %d, %s\n", gmdate('c'), $via, $status, $meaning[$status] ?? 'unexpected response'));
exit($status === 200 ? 0 : 1);
