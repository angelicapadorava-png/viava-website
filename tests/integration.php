<?php
/**
 * End-to-end rehearsal on this machine: the real PHP handlers, a local Worker
 * and a local D1 database, including outages.
 *
 *   php tests/integration.php
 *
 * Wrangler runs in local mode with a throwaway secret and a temporary database,
 * and without any Cloudflare login. Mail only reaches a local fake mail server.
 * Set TEST_PHP_CURL=1 to run the handlers with PHP's curl extension loaded.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/helpers.php';

$root = dirname(__DIR__);
refuse_if_real_mail_config($root);
$hq = $root . '/agency-hq';

putenv('WRANGLER_SEND_METRICS=false');
$secret = bin2hex(random_bytes(32));
$workerPort = 18787;
$phpPort = 18951;
$smtpPort = 18952;
$state = '.wrangler/integration-' . bin2hex(random_bytes(4));

$phpOptions = [];
if (getenv('TEST_PHP_CURL') === '1') {
    $phpOptions = ['-d', 'extension_dir=' . dirname(PHP_BINARY) . '/ext', '-d', 'extension=curl'];
}

function wrangler(array $args): array {
    global $hq, $state;
    $cmd = array_merge(['node', 'node_modules/wrangler/bin/wrangler.js'], $args, ['--persist-to', $state]);
    $null = null_device();
    $proc = proc_open($cmd, [['file', $null, 'r'], ['pipe', 'w'], ['file', $null, 'w']], $pipes, $hq);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($proc), (string) $output];
}

function d1_query(string $sql): array {
    [$code, $output] = wrangler(['d1', 'execute', 'viava-agency-hq', '--local', '--json', '--command', $sql]);
    $decoded = json_decode(substr($output, (int) strpos($output, '[')), true);
    if ($code !== 0 || !is_array($decoded)) {
        fwrite(STDERR, "D1 query failed\n");
        exit(2);
    }
    return $decoded[0]['results'] ?? [];
}

function d1_count(string $table, string $where): int {
    return (int) d1_query("SELECT COUNT(*) AS n FROM {$table} WHERE {$where}")[0]['n'];
}

function start_worker() {
    global $hq, $state, $secret, $workerPort;
    $null = null_device();
    $proc = proc_open(
        ['node', 'node_modules/wrangler/bin/wrangler.js', 'dev', '--ip', '127.0.0.1', '--port', (string) $workerPort, '--persist-to', $state, '--var', 'FORM_API_SECRET_K1:' . $secret],
        [['file', $null, 'r'], ['file', $null, 'w'], ['file', $null, 'w']],
        $pipes,
        $hq
    );
    track_process($proc);
    wait_for_port($workerPort);
    // The port can open a moment before requests are served.
    for ($i = 0; $i < 100; $i++) {
        if (@file_get_contents("http://127.0.0.1:{$workerPort}/healthz") !== false) {
            return $proc;
        }
        usleep(200000);
    }
    fwrite(STDERR, "Worker did not become healthy\n");
    exit(2);
}

function write_config(string $dir, string $secretValue): void {
    global $workerPort;
    file_put_contents($dir . '/crm-config.php', '<?php return ' . var_export([
        'api_url' => "http://127.0.0.1:{$workerPort}",
        'key_id' => 'k1',
        'secret' => $secretValue,
    ], true) . ';');
}

function run_drain(string $root, string $dir, array $phpOptions): array {
    putenv('VIAVA_PRIVATE_DIR=' . $dir);
    $null = null_device();
    $cmd = array_merge([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0'], $phpOptions, [$root . '/tools/drain-spool.php']);
    $proc = proc_open($cmd, [['file', $null, 'r'], ['pipe', 'w'], ['file', $null, 'w']], $pipes);
    $output = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    return [proc_close($proc), $output];
}

[$code] = wrangler(['d1', 'migrations', 'apply', 'viava-agency-hq', '--local']);
check('migrations apply to a fresh local D1', $code === 0);

$dir = temp_dir('integration');
$mailDir = temp_dir('integration-mail');
write_config($dir, $secret);
$smtp = start_fake_smtp($smtpPort, $mailDir);
$php = start_server($root, $dir, $phpPort, $smtpPort, $phpOptions);
$worker = start_worker();
echo 'PHP HTTP client: ' . (getenv('TEST_PHP_CURL') === '1' ? 'curl' : 'streams') . "\n";

// --- Normal path --------------------------------------------------------------

[, $location] = request($phpPort, 'contact-handler.php', contact_post(['email' => 'one@example.com', 'moreInfo' => 'UNIQUE-ONE']));
check('valid inquiry reports success', strpos($location, 'sent=1') !== false);
check('inquiry is in D1', d1_count('inquiries', "payload_json LIKE '%UNIQUE-ONE%'") === 1);
check('contact and lead are in D1', d1_count('contacts', "email_normalized = 'one@example.com'") === 1
    && d1_count('opportunities', "primary_contact_id = (SELECT id FROM contacts WHERE email_normalized = 'one@example.com')") === 1);
check('no fallback copy is left on Hostinger when D1 succeeded', count(of_type(spool_records($dir), 'lead')) === 0);
check('email results reach D1', d1_count('inquiries', "payload_json LIKE '%UNIQUE-ONE%' AND team_notification_status = 'sent' AND confirmation_status = 'sent'") === 1);
check('team and visitor both got email', count(mail_to($mailDir, 'sales@viavateam.com')) === 1 && count(mail_to($mailDir, 'one@example.com')) === 1);

request($phpPort, 'contact-handler.php', contact_post(['email' => 'one@example.com', 'moreInfo' => 'UNIQUE-ONE']));
check('a double submit creates one record and no extra email', d1_count('inquiries', "payload_json LIKE '%UNIQUE-ONE%'") === 1
    && count(mail_to($mailDir, 'one@example.com')) === 1);

// The local abuse check is cleared so this tests the Worker's own duplicate rule.
unlink($dir . '/guard-state.json');
[, $location] = request($phpPort, 'contact-handler.php', contact_post(['email' => 'one@example.com', 'moreInfo' => 'UNIQUE-ONE']));
check('the Worker catches a duplicate the local check missed', strpos($location, 'sent=1') !== false
    && d1_count('inquiries', "payload_json LIKE '%UNIQUE-ONE%'") === 1);
check('and it sends no second round of email', count(mail_to($mailDir, 'one@example.com')) === 1 && count(mail_to($mailDir, 'sales@viavateam.com')) === 1);

request($phpPort, 'contact-handler.php', contact_post(['email' => 'one@example.com', 'moreInfo' => 'UNIQUE-TWO']));
check('a new message joins the existing lead', d1_count('inquiries', "payload_json LIKE '%UNIQUE-TWO%'") === 1
    && d1_count('opportunities', "primary_contact_id = (SELECT id FROM contacts WHERE email_normalized = 'one@example.com')") === 1);

[, $location] = request($phpPort, 'resource-handler.php', ['email' => 'reader@example.com', 'resource' => 'sop-starter-kit', 'consent' => 'on', 'website' => '']);
check('download request is saved in D1 and delivered', strpos($location, 'dl=1') !== false
    && d1_count('inquiries', "form = 'resource' AND confirmation_status = 'sent'") === 1
    && count(mail_to($mailDir, 'reader@example.com')) === 1);
check('a download request opens no sales opportunity', d1_count('opportunities', "primary_contact_id = (SELECT id FROM contacts WHERE email_normalized = 'reader@example.com')") === 0);
check('a sales inquiry links contact, company and opportunity', d1_count('opportunities', "company_id IS NOT NULL AND primary_contact_id = (SELECT id FROM contacts WHERE email_normalized = 'one@example.com')") === 1
    && d1_count('company_contacts', "contact_id = (SELECT id FROM contacts WHERE email_normalized = 'one@example.com')") === 1);

request($phpPort, 'contact-handler.php', contact_post(['email' => 'nojs@example.com', 'details' => '', 'moreInfo' => '', 'businessName' => '', 'companyUrl' => '', 'industry' => '']));
check('a submission with no script is held for review, outside the pipeline', d1_count('inquiries', "spam_status = 'review' AND contact_id = (SELECT id FROM contacts WHERE email_normalized = 'nojs@example.com')") === 1
    && d1_count('opportunities', "primary_contact_id = (SELECT id FROM contacts WHERE email_normalized = 'nojs@example.com')") === 0);

request($phpPort, 'contact-handler.php', contact_post(['website' => 'http://spam.example', 'moreInfo' => 'UNIQUE-BOT', 'email' => 'bot@example.com']));
check('honeypot hit is in D1 as spam, with no contact', d1_count('inquiries', "payload_json LIKE '%UNIQUE-BOT%' AND spam_status = 'suspected'") === 1
    && d1_count('contacts', "email_normalized = 'bot@example.com'") === 0);

// --- Worker rejects the credentials -------------------------------------------

unlink($dir . '/guard-state.json');
write_config($dir, str_repeat('x', 64));
[, $location] = request($phpPort, 'contact-handler.php', contact_post(['email' => 'badkey@example.com', 'moreInfo' => 'UNIQUE-BADKEY']));
check('wrong secret: Worker refuses, D1 has nothing', d1_count('inquiries', "payload_json LIKE '%UNIQUE-BADKEY%'") === 0);
check('wrong secret: saved to the fallback file, so success is true', strpos($location, 'sent=1') !== false
    && count(of_type(spool_records($dir), 'lead')) === 1);
write_config($dir, $secret);

// --- Worker outage ---------------------------------------------------------------

stop_process($worker);
[, $location, $seconds] = request($phpPort, 'contact-handler.php', contact_post(['email' => 'outage@example.com', 'moreInfo' => 'UNIQUE-OUTAGE']));
check('Worker down: saved to the fallback file, so success is true', strpos($location, 'sent=1') !== false
    && count(of_type(spool_records($dir), 'lead')) === 2);
echo sprintf("      form took %.1f seconds with the Worker down\n", $seconds);
check('Worker down: the visitor is not left waiting beyond the timeout', $seconds < 20);

// Worker down and the fallback file unavailable: nothing is durable.
$month = $dir . '/leads-' . gmdate('Y-m') . '.jsonl';
rename($month, $month . '.moved');
mkdir($month);
[, $location] = request($phpPort, 'contact-handler.php', contact_post(['email' => 'nowhere@example.com', 'moreInfo' => 'UNIQUE-NOWHERE']));
check('Worker and fallback both down: the visitor is told it failed', strpos($location, 'sent=0') !== false);
check('Worker and fallback both down: no confirmation goes out', count(mail_to($mailDir, 'nowhere@example.com')) === 0);
$unsaved = mail_to($mailDir, 'sales@viavateam.com');
check('Worker and fallback both down: team email says not saved', strpos(end($unsaved), 'NOT SAVED') !== false);
rmdir($month);
rename($month . '.moved', $month);

// --- Recovery --------------------------------------------------------------------

[$code] = run_drain($root, $dir, $phpOptions);
check('drain reports a failure while the Worker is down', $code !== 0);
check('drain loses nothing while the Worker is down', count(of_type(spool_records($dir), 'lead')) === 2);

$worker = start_worker();
[$code, $output] = run_drain($root, $dir, $phpOptions);
echo "      drain: {$output}\n";
check('drain succeeds once the Worker is back', $code === 0 && strpos($output, 'sent=2') !== false);
check('fallback inquiries are now in D1', d1_count('inquiries', "payload_json LIKE '%UNIQUE-BADKEY%'") === 1
    && d1_count('inquiries', "payload_json LIKE '%UNIQUE-OUTAGE%'") === 1);
check('their email results came too', d1_count('inquiries', "payload_json LIKE '%UNIQUE-OUTAGE%' AND team_notification_status = 'sent' AND confirmation_status = 'sent'") === 1);

[$code, $output] = run_drain($root, $dir, $phpOptions);
check('draining again sends nothing and duplicates nothing', $code === 0 && strpos($output, 'sent=0') !== false
    && d1_count('inquiries', "payload_json LIKE '%UNIQUE-OUTAGE%'") === 1);
check('the unsaved inquiry never appears in D1', d1_count('inquiries', "payload_json LIKE '%UNIQUE-NOWHERE%'") === 0);
check('fallback files are not deleted by draining', count(of_type(spool_records($dir), 'lead')) === 2);

stop_process($worker);
stop_process($php);
stop_process($smtp);
remove_dir($dir);
remove_dir($mailDir);
remove_dir($hq . '/' . $state);

finish();
