<?php
/**
 * VIA VA — contact form handler.
 * Validates the submitted inquiry, saves it, emails the team, sends the
 * submitter a confirmation, and redirects back to the contact page with a
 * success or error flag.
 *
 * Deploy alongside the HTML files on Hostinger (PHP is served automatically
 * on shared hosting plans — no extra setup needed).
 *
 * Lead storage: the inquiry is saved before any email is attempted, to Agency HQ
 * or else to a private fallback file (see lib/viava-lead-spool.php), so a mail
 * failure can't lose it. The visitor sees success only if it was saved.
 *
 * Email delivery: if mail-config.php exists (see mail-config.example.php),
 * mail is sent via authenticated SMTP through PHPMailer — this is what
 * keeps mail out of spam, since it's genuinely sent as the real mailbox
 * rather than through PHP's unauthenticated mail() function. If
 * mail-config.php doesn't exist yet, this falls back to mail() so the
 * form still works while SMTP is being set up.
 */

require __DIR__ . '/lib/PHPMailer/Exception.php';
require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';
require __DIR__ . '/lib/viava-lead-spool.php';
require __DIR__ . '/email-templates/contact-confirmation.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Where inquiries get sent.
$to = 'sales@viavateam.com';

// Only accept POST submissions; anything else goes back to the form.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: viava-contact.html');
    exit;
}

function field(string $key): string {
    return trim((string)($_POST[$key] ?? ''));
}

/**
 * Reads a checkbox group (posted as an array, e.g. name="foo[]") and
 * returns the non-empty, trimmed values.
 */
function fieldArray(string $key): array {
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        return [];
    }
    $values = array_map(static fn ($v) => trim((string) $v), $values);
    return array_values(array_filter($values, static fn ($v) => $v !== ''));
}

/**
 * Locates mail-config.php. Checked, in order:
 *   1. One directory above the deployed site (e.g. outside public_html) —
 *      the preferred spot, since git deploy syncs/cleans this folder and
 *      would otherwise delete the file on every push.
 *   2. Right next to this script — works, but gets wiped on deploy unless
 *      Hostinger's git integration is configured to leave it alone.
 */
function find_mail_config_path(): ?string {
    $candidates = [
        dirname(__DIR__) . '/mail-config.php',
        __DIR__ . '/mail-config.php',
    ];
    foreach ($candidates as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Sends one email. Uses SMTP (via mail-config.php) when available,
 * otherwise falls back to PHP's mail(). Returns true on success; on
 * failure, sets $GLOBALS['last_mail_error'] with the reason.
 */
function send_mail(string $toAddr, string $toName, string $fromAddr, string $fromName, ?string $replyToAddr, ?string $replyToName, string $subject, string $body, ?string $htmlBody = null): bool {
    $configPath = find_mail_config_path();

    if ($configPath !== null) {
        $config = require $configPath;
        try {
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host       = $config['host'];
            $mailer->Port       = $config['port'];
            $mailer->SMTPAuth   = true;
            $mailer->Username   = $config['username'];
            $mailer->Password   = $config['password'];
            $mailer->SMTPSecure = $config['encryption']; // 'ssl' or 'tls'
            $mailer->CharSet    = 'UTF-8';

            $mailer->setFrom($fromAddr, $fromName);
            $mailer->addAddress($toAddr, $toName);
            if ($replyToAddr) {
                $mailer->addReplyTo($replyToAddr, $replyToName ?? '');
            }
            $mailer->Subject = $subject;

            if ($htmlBody !== null) {
                $mailer->isHTML(true);
                $mailer->Body    = $htmlBody;
                $mailer->AltBody = $body;
            } else {
                $mailer->isHTML(false);
                $mailer->Body = $body;
            }

            $sent = $mailer->send();
            if (!$sent) {
                $GLOBALS['last_mail_error'] = $mailer->ErrorInfo;
            }
            return $sent;
        } catch (PHPMailerException $e) {
            $GLOBALS['last_mail_error'] = $mailer->ErrorInfo !== '' ? $mailer->ErrorInfo : $e->getMessage();
            return false;
        }
    }

    // Fallback: plain mail(), used only until mail-config.php is set up.
    $headers   = [];
    $headers[] = 'From: ' . $fromName . ' <' . $fromAddr . '>';
    if ($replyToAddr) {
        $headers[] = 'Reply-To: ' . ($replyToName ?: $replyToAddr) . ' <' . $replyToAddr . '>';
    }
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    return mail($toAddr, $subject, $body, implode("\r\n", $headers));
}

$name             = field('name');
$email            = field('email');
$supportTypes     = fieldArray('supportType'); // free text or checkbox group
$supportTypeOther = field('supportTypeOther');
$details          = field('details');
$hours            = field('hours');
$start            = field('start');
$consent          = field('consent'); // checkbox sends "on" when checked, absent otherwise
$business         = field('businessName');
$companyUrl       = field('companyUrl');
$industry         = field('industry');
$moreInfo         = field('moreInfo');

// "Other" carries its free-text detail instead of the literal word "Other".
$supportTypes = array_map(
    static fn ($v) => ($v === 'Other' && $supportTypeOther !== '') ? $supportTypeOther : $v,
    $supportTypes
);
$supportType = implode(', ', $supportTypes);

// The page's script normally combines these into `details`. If it didn't run,
// build the same summary here so the team email still has them.
if ($details === '') {
    $parts = [];
    if ($business !== '')   { $parts[] = 'Business: ' . $business; }
    if ($companyUrl !== '') { $parts[] = 'Website: ' . $companyUrl; }
    if ($industry !== '')   { $parts[] = 'Industry: ' . $industry; }
    if ($moreInfo !== '')   { $parts[] = 'Notes: ' . $moreInfo; }
    $details = implode("\n", $parts);
}

$leadFields = [
    'name'        => $name,
    'email'       => $email,
    'business'    => $business,
    'companyUrl'  => $companyUrl,
    'industry'    => $industry,
    'supportType' => $supportType,
    'hours'       => $hours,
    'start'       => $start,
    'moreInfo'    => $moreInfo,
    'details'     => $details,
    'consent'     => $consent !== '' ? 'yes' : 'no',
    'consentText' => 'I agree that VIA VA may contact me at the email address provided.',
];

// Honeypot — real visitors never fill this in; bots usually do. It's saved
// flagged rather than discarded, in case a browser's autofill ever trips it.
if (field('website') !== '') {
    viava_capture_lead('contact', $leadFields, ['honeypot' => true]);
    header('Location: viava-contact.html?sent=1');
    exit;
}

// Required fields — including explicit consent to be contacted.
if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $consent === ''
    || $supportTypes === [] || $hours === '' || $start === '') {
    header('Location: viava-contact.html?sent=0');
    exit;
}

// Strip header-injection attempts from anything that lands in mail headers.
$clean = static fn (string $v): string => str_replace(["\r", "\n"], ' ', $v);
$name  = $clean($name);
$email = $clean($email);

// --- 0) Save the inquiry before attempting any email -----------------------

$capture = viava_capture_lead('contact', $leadFields);

// Already saved by an identical recent submission, e.g. a double click.
if ($capture['duplicate']) {
    header('Location: viava-contact.html?sent=1');
    exit;
}
// A flood past the hard cap: not saved, so it must not be reported as a success.
if ($capture['dropped']) {
    header('Location: viava-contact.html?sent=0');
    exit;
}

// --- 1) Notify the team ---------------------------------------------------

$subject = 'New VIA VA inquiry from ' . $name;

$body  = "New contact form submission from viavateam.com\n\n";
$body .= "Name: {$name}\n";
$body .= "Email: {$email}\n";
$body .= "Type of support: {$supportType}\n";
$body .= "What they need help with: {$details}\n";
$body .= "Hours of support needed: {$hours}\n";
$body .= "When they need support: {$start}\n";
$body .= "Agreed to be contacted by email: Yes\n";
$body .= 'Lead ID: ' . ($capture['id'] ?? 'NOT SAVED - this email is the only copy') . "\n";

$teamSent = false;
$teamMailError = 'skipped: global hourly limit';
if (!$capture['global_limited']) {
    $teamSent = send_mail($to, 'VIA VA Sales', 'sales@viavateam.com', 'VIA VA Website', $email, $name, $subject, $body);
    $teamMailError = $teamSent ? null : ($GLOBALS['last_mail_error'] ?? 'unknown');
    viava_record_event($capture, 'team_notification', $teamSent);
}

// --- 2) Confirm receipt with the submitter ---------------------------------

// This email goes to whatever address was typed into a public form, so it only
// repeats values from the form's fixed choices. Free text stays out of it, so
// the form can't be used to send a stranger someone else's message.
$firstName = explode(' ', $name)[0];
if (!preg_match("/^[\\p{L}\\p{M}'’.-]{1,40}$/u", $firstName)) {
    $firstName = 'there';
}
$allowedHours = ['20 hrs / week', '30 hrs / week', '40 hrs / week', 'Not sure yet'];
$allowedStart = ['ASAP', 'Within 2 weeks', 'Within a month', 'Just exploring'];

$confirmSubject = "We've got your inquiry — VIA VA";

$rendered = render_contact_confirmation_email([
    'firstName'   => $firstName,
    'supportType' => '',
    'details'     => '',
    'hours'       => in_array($hours, $allowedHours, true) ? $hours : '',
    'start'       => in_array($start, $allowedStart, true) ? $start : '',
]);

// Only a saved inquiry gets "we've got your inquiry". A failed confirmation
// doesn't change the result, since the inquiry is saved, but it's still logged
// (server-side only) so delivery problems don't go unnoticed.
$confirmSent = true;
$confirmMailError = null;
if ($capture['stored'] && !$capture['ip_limited'] && !$capture['global_limited']) {
    $confirmSent = send_mail($email, $name, 'sales@viavateam.com', 'VIA VA', null, null, $confirmSubject, $rendered['text'], $rendered['html']);
    $confirmMailError = $confirmSent ? null : ($GLOBALS['last_mail_error'] ?? 'unknown');
    viava_record_event($capture, 'confirmation', $confirmSent);
}

// Log failures to PHP's own error log — visible via Hostinger's error log
// viewer, never exposed to visitors or the URL.
if (!$teamSent) {
    error_log('VIA VA contact form: team notification failed — ' . $teamMailError);
}
if (!$confirmSent) {
    error_log('VIA VA contact form: confirmation email failed — ' . $confirmMailError);
}
if (!$teamSent && !$capture['stored']) {
    error_log('VIA VA contact form: inquiry LOST — neither saved nor emailed');
}

// Success means durably saved. An email alone isn't proof of that, so if saving
// failed the visitor is told, even if the team email went out.
header('Location: viava-contact.html?sent=' . ($capture['stored'] ? '1' : '0'));
exit;
