<?php
/**
 * VIA VA — contact form handler.
 * Validates the submitted inquiry, emails the team, sends the submitter
 * a confirmation, and redirects back to the contact page with a
 * success or error flag.
 *
 * Deploy alongside the HTML files on Hostinger (PHP is served automatically
 * on shared hosting plans — no extra setup needed).
 */

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

// Sending "From" a real, existing mailbox on the domain (rather than a
// made-up noreply@ address) matters for deliverability — a From address
// that doesn't correspond to an actual account is a common spam trigger.
$headers   = [];
$headers[] = 'From: VIA VA Website <sales@viavateam.com>';
$headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';

$teamSent = mail($to, $subject, $body, implode("\r\n", $headers));

// --- 2) Confirm receipt with the submitter ---------------------------------

$firstName = explode(' ', $name)[0];

$confirmSubject = "We've got your inquiry — VIA VA";

$confirmBody  = "Hi {$firstName},\n\n";
$confirmBody .= "Thanks for reaching out to VIA VA! We've received your inquiry and a member of our team will follow up within 48 hours to talk through your needs and find your match.\n\n";
$confirmBody .= "Here's what you sent us:\n";
$confirmBody .= "Company: {$company}\n";
$confirmBody .= "Hours of support needed: {$hours}\n";
$confirmBody .= "Type of support requested: {$support}\n";
$confirmBody .= "Preferred start: {$start}\n\n";
$confirmBody .= "If anything above needs correcting, just reply to this email.\n\n";
$confirmBody .= "Talk soon,\nThe VIA VA Team\n";

$confirmHeaders   = [];
$confirmHeaders[] = 'From: VIA VA <sales@viavateam.com>';
$confirmHeaders[] = 'Reply-To: VIA VA <sales@viavateam.com>';
$confirmHeaders[] = 'Content-Type: text/plain; charset=UTF-8';

// Best-effort: the team notification above is the one that must succeed for
// the inquiry to count as "received" — a failed confirmation email doesn't
// block that.
mail($email, $confirmSubject, $confirmBody, implode("\r\n", $confirmHeaders));

header('Location: viava-contact.html?sent=' . ($teamSent ? '1' : '0'));
exit;
