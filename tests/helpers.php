<?php
/** Shared helpers for the local test suites. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function check(string $name, bool $ok): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . "\n";
    $GLOBALS[$ok ? 'passed' : 'failed']++;
}

function finish(): void {
    echo "\n{$GLOBALS['passed']} passed, {$GLOBALS['failed']} failed\n";
    exit($GLOBALS['failed'] === 0 ? 0 : 1);
}

function refuse_if_real_mail_config(string $root): void {
    foreach ([dirname($root) . '/mail-config.php', $root . '/mail-config.php'] as $path) {
        if (file_exists($path)) {
            fwrite(STDERR, "Refusing to run: a real mail config exists at {$path}\n");
            exit(2);
        }
    }
}

function temp_dir(string $label): string {
    $dir = sys_get_temp_dir() . '/viava-test-' . $label . '-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    return $dir;
}

function remove_dir(string $dir): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
        if (in_array(basename($file), ['.', '..'], true)) {
            continue;
        }
        is_dir($file) ? remove_dir($file) : @unlink($file);
    }
    @rmdir($dir);
}

/** @return array<int, array> every record in the fallback files, oldest first */
function spool_records(string $dir): array {
    $records = [];
    foreach (glob($dir . '/leads-*.jsonl') ?: [] as $file) {
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $records[] = json_decode($line, true);
            }
        }
    }
    return $records;
}

function of_type(array $records, string $type): array {
    return array_values(array_filter($records, static fn ($r) => ($r['record'] ?? '') === $type));
}

function null_device(): string {
    return strncasecmp(PHP_OS, 'WIN', 3) === 0 ? 'NUL' : '/dev/null';
}

function wait_for_port(int $port): void {
    for ($i = 0; $i < 300; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($socket) {
            fclose($socket);
            return;
        }
        usleep(100000);
    }
    fwrite(STDERR, "Nothing started listening on port {$port}\n");
    exit(2);
}

/** Anything still running when the script ends, even on an early exit, gets stopped. */
function track_process($proc): void {
    if (!isset($GLOBALS['tracked_processes'])) {
        $GLOBALS['tracked_processes'] = [];
        register_shutdown_function(static function (): void {
            foreach ($GLOBALS['tracked_processes'] as $tracked) {
                if (is_resource($tracked)) {
                    stop_process($tracked);
                }
            }
        });
    }
    $GLOBALS['tracked_processes'][] = $proc;
}

function stop_process($proc): void {
    $status = proc_get_status($proc);
    if ($status['running'] && strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        // Also stops child processes, which proc_terminate leaves running on Windows.
        exec('taskkill /T /F /PID ' . (int) $status['pid'] . ' >NUL 2>&1');
    } elseif ($status['running']) {
        proc_terminate($proc);
    }
    proc_close($proc);
}

/** Port 1 is closed, so by default mail fails immediately and goes nowhere. */
function start_server(string $root, string $privateDir, int $port, int $smtpPort = 1, array $phpOptions = []) {
    putenv('VIAVA_PRIVATE_DIR=' . $privateDir);
    $cmd = array_merge([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'SMTP=127.0.0.1', '-d', 'smtp_port=' . $smtpPort], $phpOptions, ['-S', '127.0.0.1:' . $port, '-t', $root]);
    $null = null_device();
    $proc = proc_open($cmd, [['file', $null, 'r'], ['file', $null, 'w'], ['file', $null, 'w']], $pipes, $root);
    track_process($proc);
    wait_for_port($port);
    return $proc;
}

function start_fake_smtp(int $port, string $dir) {
    $null = null_device();
    $proc = proc_open([PHP_BINARY, __DIR__ . '/fake-smtp.php', (string) $port, $dir], [['file', $null, 'r'], ['file', $null, 'w'], ['file', $null, 'w']], $pipes);
    track_process($proc);
    wait_for_port($port);
    return $proc;
}

/** @return string[] raw messages received by the fake mail server */
function mail_messages(string $dir): array {
    $files = glob($dir . '/*.eml') ?: [];
    sort($files);
    return array_map('file_get_contents', $files);
}

/** @return string[] messages whose envelope recipient is this address */
function mail_to(string $dir, string $address): array {
    return array_values(array_filter(mail_messages($dir), static function (string $message) use ($address): bool {
        $envelope = strstr($message, "\n\n", true);
        return $envelope !== false && stripos($envelope, $address) !== false;
    }));
}

/** @return array{0:int,1:string,2:float} status code, Location header, seconds taken */
function request(int $port, string $path, ?array $post = null): array {
    $options = ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30, 'method' => $post === null ? 'GET' : 'POST'];
    if ($post !== null) {
        $options['header'] = "Content-Type: application/x-www-form-urlencoded\r\n";
        $options['content'] = http_build_query($post);
    }
    $started = microtime(true);
    @file_get_contents('http://127.0.0.1:' . $port . '/' . $path, false, stream_context_create(['http' => $options]));
    $status = 0;
    $location = '';
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
            $status = (int) $m[1];
        } elseif (stripos($header, 'Location:') === 0) {
            $location = trim(substr($header, 9));
        }
    }
    return [$status, $location, microtime(true) - $started];
}

function contact_post(array $overrides = []): array {
    return array_merge([
        'name' => 'Test Person',
        'email' => 'lead@example.com',
        'businessName' => 'Example Realty',
        'companyUrl' => 'https://example.com',
        'industry' => 'Real Estate',
        'supportType' => ['Admin support'],
        'hours' => '20 hrs / week',
        'start' => 'ASAP',
        'moreInfo' => "Line one\nLine two",
        'details' => 'Business: Example Realty',
        'consent' => 'on',
        'website' => '',
    ], $overrides);
}
