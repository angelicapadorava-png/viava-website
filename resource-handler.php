<?php
/**
 * VIA VA — free-resource download handler.
 *
 * Someone submits the download form on viava-resources.html with their
 * email + which resource they want. This:
 *   1. Emails the team the lead (sales@viavateam.com).
 *   2. Emails the requester a branded message with the download link.
 *   3. Redirects back to viava-resources.html?dl=1 (ok) / ?dl=0 (error).
 *
 * Deploy alongside the HTML on Hostinger. Email delivery uses authenticated
 * SMTP via mail-config.php (see mail-config.example.php) when present, and
 * falls back to mail() otherwise — same as contact-handler.php.
 */

require __DIR__ . '/lib/viava-mailer.php';
require __DIR__ . '/email-templates/resource-delivery.php';

$to = 'sales@viavateam.com';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: viava-resources.html');
    exit;
}

function rh_field(string $key): string {
    return trim((string)($_POST[$key] ?? ''));
}

/**
 * Whitelist of downloadable resources. The form only ever sends one of
 * these keys; anything else is rejected so no arbitrary file can be
 * requested. Drop the matching PDFs in /assets/downloads/.
 */
$RESOURCES = [
    'va-delegation-checklist' => [
        'title' => 'The Ultimate VA Delegation Checklist',
        'file'  => 'va-delegation-checklist.pdf',
        'blurb' => '75+ tasks you can delegate across admin, marketing, operations, and customer support — so you can see, at a glance, what to take off your plate first.',
    ],
    'ai-readiness-checklist' => [
        'title' => 'AI Readiness Checklist for Small Businesses',
        'file'  => 'ai-readiness-checklist.pdf',
        'blurb' => 'A practical checklist to find where AI can save your team time, reduce costs, and improve results — without the hype.',
    ],
    'sop-starter-kit' => [
        'title' => 'SOP Starter Kit',
        'file'  => 'sop-starter-kit.pdf',
        'blurb' => 'Templates and guides to help you document your processes and build a business that runs without you.',
    ],
];

// Honeypot — bots fill this, humans never see it.
if (rh_field('website') !== '') {
    header('Location: viava-resources.html?dl=1#downloads');
    exit;
}

$email   = rh_field('email');
$key     = rh_field('resource');
$consent = rh_field('consent');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $consent === '' || !isset($RESOURCES[$key])) {
    header('Location: viava-resources.html?dl=0#downloads');
    exit;
}

$resource     = $RESOURCES[$key];
$downloadUrl  = 'https://viavateam.com/assets/downloads/' . $resource['file'];

// Strip header-injection attempts from anything used in mail headers.
$email = str_replace(["\r", "\n"], ' ', $email);

// --- 1) Notify the team --------------------------------------------------
$teamSubject = 'New resource download: ' . $resource['title'];
$teamBody    = "Someone downloaded a free resource from viavateam.com\n\n";
$teamBody   .= "Resource: {$resource['title']}\n";
$teamBody   .= "Email: {$email}\n";
$teamBody   .= "Agreed to be contacted by email: Yes\n";

$teamSent = viava_send_mail(
    $to, 'VIA VA Sales',
    'sales@viavateam.com', 'VIA VA Website',
    $email, null,
    $teamSubject, $teamBody
);
if (!$teamSent) {
    error_log('VIA VA resource form: team notification failed — ' . ($GLOBALS['viava_last_mail_error'] ?? 'unknown'));
}

// --- 2) Deliver the download to the requester ---------------------------
$rendered = render_resource_delivery_email([
    'title' => $resource['title'],
    'url'   => $downloadUrl,
    'blurb' => $resource['blurb'],
]);

$deliverySent = viava_send_mail(
    $email, '',
    'sales@viavateam.com', 'VIA VA Team',
    null, null,
    'Your download: ' . $resource['title'],
    $rendered['text'], $rendered['html']
);
if (!$deliverySent) {
    error_log('VIA VA resource form: delivery email failed — ' . ($GLOBALS['viava_last_mail_error'] ?? 'unknown'));
}

// The delivery email is the one that matters to the requester.
header('Location: viava-resources.html?dl=' . ($deliverySent ? '1' : '0') . '#downloads');
exit;
