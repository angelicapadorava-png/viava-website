<?php
/**
 * Local test suite for the form handlers. Standard library only:
 *
 *   php tests/run.php
 *
 * It starts PHP's built-in web server against this checkout and posts to the
 * real handlers. No Worker is configured here, so saving uses the fallback file
 * (tests/integration.php covers the Worker). Mail only ever goes to a closed
 * local port or a local fake mail server, and the suite refuses to run if a real
 * mail-config.php is reachable.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/helpers.php';

$root = dirname(__DIR__);
refuse_if_real_mail_config($root);

$contactPost = static fn (array $overrides = []): array => contact_post($overrides);

// --- Confirmation email no longer echoes free text ------------------------

require $root . '/email-templates/contact-confirmation.php';
$email = render_contact_confirmation_email([
    'firstName' => 'there', 'supportType' => '', 'details' => '', 'hours' => '20 hrs / week', 'start' => 'ASAP',
]);
check('confirmation omits the free-text rows when blank (HTML)', strpos($email['html'], 'What you need help with') === false
    && strpos($email['html'], 'Type of support') === false);
check('confirmation omits the free-text rows when blank (text)', strpos($email['text'], 'What you need help with') === false
    && strpos($email['text'], 'Type of support') === false);
check('confirmation still shows the whitelisted values', strpos($email['html'], '20 hrs / week') !== false);

// --- Saved, but mail cannot be delivered ------------------------------------

$dir = temp_dir('main');
$port = 18931;
$server = start_server($root, $dir, $port);

[$status, $location] = request($port, 'contact-handler.php');
check('GET redirects back to the form', $status === 302 && $location === 'viava-contact.html');

[$status, $location] = request($port, 'contact-handler.php', $contactPost());
check('saved inquiry reports success even though email failed', $status === 302 && strpos($location, 'sent=1') !== false);
$records = spool_records($dir);
$leads = of_type($records, 'lead');
check('inquiry is saved', count($leads) === 1);
check('saved record keeps every submitted field', ($leads[0]['fields']['email'] ?? '') === 'lead@example.com'
    && ($leads[0]['fields']['business'] ?? '') === 'Example Realty'
    && ($leads[0]['fields']['industry'] ?? '') === 'Real Estate'
    && ($leads[0]['fields']['hours'] ?? '') === '20 hrs / week'
    && ($leads[0]['fields']['supportType'] ?? '') === 'Admin support'
    && ($leads[0]['fields']['moreInfo'] ?? '') === "Line one\nLine two");
check('consent is recorded', ($leads[0]['fields']['consent'] ?? '') === 'yes');
check('raw IP address is not stored', strpos(json_encode($leads[0]), '127.0.0.1') === false);
$events = of_type($records, 'event');
$named = array_column($events, 'ok', 'event');
check('failed team notification is recorded', array_key_exists('team_notification', $named) && $named['team_notification'] === false);
check('failed confirmation is recorded', array_key_exists('confirmation', $named) && $named['confirmation'] === false);

request($port, 'contact-handler.php', $contactPost());
check('identical resubmission does not create a second lead', count(of_type(spool_records($dir), 'lead')) === 1);

[$status, $location] = request($port, 'contact-handler.php', $contactPost(['email' => 'not-an-email', 'moreInfo' => 'bad']));
check('invalid email is rejected', strpos($location, 'sent=0') !== false);
[$status, $location] = request($port, 'contact-handler.php', $contactPost(['consent' => '', 'moreInfo' => 'no consent']));
check('missing consent is rejected', strpos($location, 'sent=0') !== false);
check('rejected submissions are not saved', count(of_type(spool_records($dir), 'lead')) === 1);

[$status, $location] = request($port, 'contact-handler.php', $contactPost(['website' => 'http://spam.example', 'moreInfo' => 'bot']));
check('honeypot hit still looks like success to the bot', strpos($location, 'sent=1') !== false);
$all = spool_records($dir);
$flagged = array_values(array_filter(of_type($all, 'lead'), static fn ($r) => !empty($r['flags']['honeypot'])));
check('honeypot hit is kept for review, flagged', count($flagged) === 1);
$flaggedEvents = array_filter(of_type($all, 'event'), static fn ($e) => $e['lead_id'] === $flagged[0]['id']);
check('honeypot hit triggers no email attempts', count($flaggedEvents) === 0);

// Two submissions so far count against the limit of 5 per hour for this address.
for ($i = 0; $i < 6; $i++) {
    request($port, 'contact-handler.php', $contactPost(['email' => "lead{$i}@example.com"]));
}
$all = spool_records($dir);
$limited = array_values(array_filter(of_type($all, 'lead'), static fn ($r) => !empty($r['flags']['ip_limited'])));
check('over-limit submissions are still saved', count($limited) >= 1);
$limitedIds = array_column($limited, 'id');
$confirmsToLimited = array_filter(of_type($all, 'event'), static fn ($e) => in_array($e['lead_id'], $limitedIds, true) && $e['event'] === 'confirmation');
check('over-limit submissions get no confirmation email', count($confirmsToLimited) === 0);

stop_process($server);
remove_dir($dir);

// --- Honest failures --------------------------------------------------------

// Storage fails while the duplicate check keeps working. The monthly file's name
// is taken by a folder, which makes appending to it fail.
$dir = temp_dir('retry');
$port = 18934;
mkdir($dir . '/leads-' . gmdate('Y-m') . '.jsonl');
$server = start_server($root, $dir, $port);

[, $location] = request($port, 'contact-handler.php', $contactPost());
check('storage failure is reported to the visitor', strpos($location, 'sent=0') !== false);
[, $location] = request($port, 'contact-handler.php', $contactPost());
check('a retry after a failed save is not called a duplicate success', strpos($location, 'sent=0') !== false);

rmdir($dir . '/leads-' . gmdate('Y-m') . '.jsonl');
[, $location] = request($port, 'contact-handler.php', $contactPost());
check('a retry once storage recovers is saved', strpos($location, 'sent=1') !== false
    && count(of_type(spool_records($dir), 'lead')) === 1);

stop_process($server);
remove_dir($dir);

// A flood past the hard cap isn't saved, so it mustn't be told it succeeded.
$dir = temp_dir('flood');
$port = 18935;
file_put_contents($dir . '/salt', 'test-salt');
$ipHash = hash_hmac('sha256', '127.0.0.1', 'test-salt');
file_put_contents($dir . '/guard-state.json', json_encode(['ips' => [$ipHash => array_fill(0, 50, time())], 'global' => [], 'dedupe' => []]));
$server = start_server($root, $dir, $port);

[, $location] = request($port, 'contact-handler.php', $contactPost());
check('a dropped flood request is reported as a failure', strpos($location, 'sent=0') !== false);
check('a dropped flood request is not saved', count(of_type(spool_records($dir), 'lead')) === 0);
[, $location] = request($port, 'resource-handler.php', ['email' => 'r@example.com', 'resource' => 'sop-starter-kit', 'consent' => 'on', 'website' => '']);
check('a dropped download request is reported as a failure', strpos($location, 'dl=0') !== false);

stop_process($server);
remove_dir($dir);

// Storage unavailable while email works. Email alone isn't durable storage.
$blocker = temp_dir('blocked');
$file = $blocker . '/not-a-directory';
file_put_contents($file, 'x');
$mailDir = temp_dir('mail1');
$smtpPort = 18941;
$smtp = start_fake_smtp($smtpPort, $mailDir);
$port = 18933;
$server = start_server($root, $file . '/spool', $port, $smtpPort);

[$status, $location] = request($port, 'contact-handler.php', $contactPost());
check('email alone is not reported as success', $status === 302 && strpos($location, 'sent=0') !== false);
$mail = mail_messages($mailDir);
check('team still gets the unsaved inquiry by email, marked as not saved', count($mail) === 1
    && strpos($mail[0], 'sales@viavateam.com') !== false && strpos($mail[0], 'NOT SAVED') !== false);
check('no "we have got your inquiry" email goes out when it was not saved', count(mail_to($mailDir, 'lead@example.com')) === 0);

[, $location] = request($port, 'resource-handler.php', ['email' => 'r@example.com', 'resource' => 'sop-starter-kit', 'consent' => 'on', 'website' => '']);
check('an unsaved download request reports a failure', strpos($location, 'dl=0') !== false);
check('an unsaved download request gets no delivery email', count(mail_to($mailDir, 'r@example.com')) === 0);

stop_process($server);
stop_process($smtp);
remove_dir($mailDir);
remove_dir($blocker);

// Neither storage nor email.
$blocker = temp_dir('blocked2');
$file = $blocker . '/not-a-directory';
file_put_contents($file, 'x');
$port = 18936;
$server = start_server($root, $file . '/spool', $port);
[$status, $location] = request($port, 'contact-handler.php', $contactPost());
check('handler survives storage and email both failing, and says so', $status === 302 && strpos($location, 'sent=0') !== false);
stop_process($server);
remove_dir($blocker);

// A Worker that accepts the connection and then never answers. The visitor's
// wait must be bounded, and the submission must still be saved.
$dir = temp_dir('hung');
$hungPort = 18943;
$hung = stream_socket_server('tcp://127.0.0.1:' . $hungPort);
file_put_contents($dir . '/crm-config.php', '<?php return ' . var_export([
    'api_url' => 'http://127.0.0.1:' . $hungPort, 'key_id' => 'k1', 'secret' => str_repeat('x', 64),
], true) . ';');
// Mail uses the fake server, which answers at once, so only the Worker wait is timed.
$mailDir = temp_dir('hung-mail');
$smtpPort = 18944;
$smtp = start_fake_smtp($smtpPort, $mailDir);
$port = 18938;
$server = start_server($root, $dir, $port, $smtpPort);
[, $location, $seconds] = request($port, 'contact-handler.php', $contactPost());
check('hung Worker: saved to the fallback file, so success is true', strpos($location, 'sent=1') !== false
    && count(of_type(spool_records($dir), 'lead')) === 1);
check(sprintf('hung Worker: the form gives up after the 5-second timeout (took %.1f seconds)', $seconds), $seconds >= 4 && $seconds < 7);
fclose($hung);
stop_process($server);
stop_process($smtp);
remove_dir($mailDir);
remove_dir($dir);

// --- Normal delivery through the fake mail server ---------------------------

$dir = temp_dir('delivered');
$mailDir = temp_dir('mail2');
$smtpPort = 18942;
$smtp = start_fake_smtp($smtpPort, $mailDir);
$port = 18937;
$server = start_server($root, $dir, $port, $smtpPort);

[, $location] = request($port, 'contact-handler.php', $contactPost([
    'moreInfo' => 'UNIQUE-FREE-TEXT', 'details' => 'UNIQUE-FREE-TEXT', 'supportType' => ['UNIQUE-SUPPORT-TEXT'],
]));
check('saved and delivered inquiry reports success', strpos($location, 'sent=1') !== false);
$teamMail = mail_to($mailDir, 'sales@viavateam.com');
$confirmMail = mail_to($mailDir, 'lead@example.com');
check('team gets the inquiry, with its free text and a lead ID', count($teamMail) === 1
    && strpos($teamMail[0], 'UNIQUE-FREE-TEXT') !== false && strpos($teamMail[0], 'Lead ID: L') !== false);
check('visitor gets one confirmation', count($confirmMail) === 1);
check('confirmation does not repeat free text from a public form', strpos($confirmMail[0], 'UNIQUE-FREE-TEXT') === false
    && strpos($confirmMail[0], 'UNIQUE-SUPPORT-TEXT') === false);

request($port, 'contact-handler.php', $contactPost(['name' => 'http://spam.example/buy now', 'email' => 'other@example.com']));
$odd = mail_to($mailDir, 'other@example.com');
check('a link typed as a name does not reach the confirmation', count($odd) === 1 && strpos($odd[0], 'spam.example') === false);

stop_process($server);
stop_process($smtp);
remove_dir($mailDir);
remove_dir($dir);

// --- Free-download form ---------------------------------------------------

$dir = temp_dir('resource');
$port = 18932;
$server = start_server($root, $dir, $port);

[$status, $location] = request($port, 'resource-handler.php', [
    'email' => 'reader@example.com', 'resource' => 'sop-starter-kit', 'consent' => 'on', 'website' => '',
]);
check('undelivered download reports a failure', strpos($location, 'dl=0') !== false);
$leads = of_type(spool_records($dir), 'lead');
check('download request is saved even so', count($leads) === 1 && ($leads[0]['fields']['resource'] ?? '') === 'sop-starter-kit');

[$status, $location] = request($port, 'resource-handler.php', [
    'email' => 'reader@example.com', 'resource' => 'sop-starter-kit', 'consent' => 'on', 'website' => '',
]);
check('a retry after a failed send is not called a success', strpos($location, 'dl=0') !== false);

[$status, $location] = request($port, 'resource-handler.php', [
    'email' => 'reader2@example.com', 'resource' => '../../etc/passwd', 'consent' => 'on', 'website' => '',
]);
check('unknown resource is rejected', strpos($location, 'dl=0') !== false);
$saved = array_column(array_column(of_type(spool_records($dir), 'lead'), 'fields'), 'email');
check('rejected download request is not saved', !in_array('reader2@example.com', $saved, true));

stop_process($server);
remove_dir($dir);

finish();
