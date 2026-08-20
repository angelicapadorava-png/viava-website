<?php
/**
 * VIA VA — contact form handler.
 * Validates the submitted inquiry, emails it to the team inbox, and
 * redirects back to the contact page with a success or error flag.
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

// Required fields.
if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: viava-contact.html?sent=0');
    exit;
}

// Strip header-injection attempts from anything that lands in mail headers.
$clean = static fn (string $v): string => str_replace(["\r", "\n"], ' ', $v);
$name  = $clean($name);
$email = $clean($email);

$subject = 'New VIA VA inquiry from ' . $name;

$body  = "New contact form submission from viavateam.com\n\n";
$body .= "Name: {$name}\n";
$body .= "Email: {$email}\n";
$body .= "Company: {$company}\n";
$body .= "Hours of support needed: {$hours}\n";
$body .= "What the business does: {$what}\n";
$body .= "Type of support requested: {$support}\n";
$body .= "Preferred start: {$start}\n";

$headers   = [];
$headers[] = 'From: VIA VA Website <noreply@viavateam.com>';
$headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';

$sent = mail($to, $subject, $body, implode("\r\n", $headers));

header('Location: viava-contact.html?sent=' . ($sent ? '1' : '0'));
exit;
