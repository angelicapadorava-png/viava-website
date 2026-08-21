<?php
/**
 * VIA VA — contact form handler.
 * Validates the submitted inquiry, emails the team, sends the submitter
 * a confirmation, and redirects back to the contact page with a
 * success or error flag.
 *
 * Deploy alongside the HTML files on Hostinger (PHP is served automatically
 * on shared hosting plans — no extra setup needed).
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
 * Sends one email. Uses SMTP (via mail-config.php) when available,
 * otherwise falls back to PHP's mail(). Returns true on success.
 */
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
                // Temporary debug capture while diagnosing delivery issues.
                // Safe to remove once resolved — see $GLOBALS['last_mail_error'].
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

// Honeypot — real visitors never fill this in; bots usually do.
if (field('website') !== '') {
    header('Location: viava-contact.html?sent=1');
    exit;
}

$name    = field('name');
$email   = field('email');
$company = field('company');
$hours   = field('hours');
$what    = field('what');
$support = field('support');
$start   = field('start');
$consent = field('consent'); // checkbox sends "on" when checked, absent otherwise

// Required fields — including explicit consent to be contacted.
if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $consent === '') {
    header('Location: viava-contact.html?sent=0');
    exit;
}

// Strip header-injection attempts from anything that lands in mail headers.
$clean = static fn (string $v): string => str_replace(["\r", "\n"], ' ', $v);
$name  = $clean($name);
$email = $clean($email);

// --- 1) Notify the team ---------------------------------------------------

$subject = 'New VIA VA inquiry from ' . $name;

$body  = "New contact form submission from viavateam.com\n\n";
$body .= "Name: {$name}\n";
$body .= "Email: {$email}\n";
$body .= "Company: {$company}\n";
$body .= "Hours of support needed: {$hours}\n";
$body .= "What the business does: {$what}\n";
$body .= "Type of support requested: {$support}\n";
$body .= "Preferred start: {$start}\n";
$body .= "Agreed to be contacted by email: Yes\n";

$teamSent = send_mail($to, 'VIA VA Sales', 'sales@viavateam.com', 'VIA VA Website', $email, $name, $subject, $body);

// --- 2) Confirm receipt with the submitter ---------------------------------

$firstName = explode(' ', $name)[0];

$confirmSubject = "We've got your inquiry — VIA VA";

$rendered = render_contact_confirmation_email([
    'firstName' => $firstName,
    'company'   => $company,
    'hours'     => $hours,
    'support'   => $support,
    'start'     => $start,
]);

// The team notification above is the one that must succeed for the inquiry
// to count as "received" — a failed confirmation email doesn't block that,
// but we still capture whether it worked (see confirmSent below) so
// delivery problems don't go unnoticed.
$confirmMailError = null;
$confirmSent = send_mail($email, $name, 'sales@viavateam.com', 'VIA VA', null, null, $confirmSubject, $rendered['text'], $rendered['html']);
if (!$confirmSent) {
    $confirmMailError = $GLOBALS['last_mail_error'] ?? 'unknown';
}

$redirect = 'viava-contact.html?sent=' . ($teamSent ? '1' : '0');
// Temporary: surface the real SMTP error(s) for debugging. Remove this
// once delivery is confirmed working — don't leak errors long-term.
if (!$teamSent && isset($GLOBALS['last_mail_error'])) {
    $redirect .= '&err=' . urlencode($GLOBALS['last_mail_error']);
}
if (!$confirmSent) {
    $redirect .= '&confirmSent=0&confirmErr=' . urlencode($confirmMailError);
}
header('Location: ' . $redirect);
exit;
