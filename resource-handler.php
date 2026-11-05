<?php
/**
 * VIA VA — free-resource download handler.
 *
 * Someone submits the download form on viava-resources.html with their
 * email + which resource they want. This:
 *   1. Emails the team the lead (sales@viavateam.com).
 *   2. Emails the requester a branded message with the download link.
 *   3. Redirects back to viava-resources.html?dl=1 (saved and sent) / ?dl=0 (error).
 *
 * Deploy alongside the HTML on Hostinger. Email delivery uses authenticated
 * SMTP via mail-config.php (see mail-config.example.php) when present, and
 * falls back to mail() otherwise — same as contact-handler.php.
 */

require __DIR__ . '/lib/viava-mailer.php';
require __DIR__ . '/lib/viava-lead-spool.php';
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
    viava_capture_lead('resource', [
        'email'    => rh_field('email'),
        'resource' => rh_field('resource'),
        'consent'  => rh_field('consent') !== '' ? 'yes' : 'no',
    ], ['honeypot' => true]);
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

// --- 0) Save the request before attempting any email ---------------------
$capture = viava_capture_lead('resource', [
    'email'       => $email,
    'resource'    => $key,
    'consent'     => 'yes',
    'consentText' => 'Email me this download and the occasional VIA VA resource.',
]);

// Already saved and sent, so there's nothing further to do. This is only ever
// remembered after a successful send, so a retry after a failed one still sends.
if ($capture['duplicate']) {
    header('Location: viava-resources.html?dl=1#downloads');
    exit;
}
// A flood past the hard cap: not saved, so it must not be reported as a success.
if ($capture['dropped']) {
    header('Location: viava-resources.html?dl=0#downloads');
    exit;
}

// --- 1) Notify the team --------------------------------------------------
$teamSubject = 'New resource download: ' . $resource['title'];
$teamBody    = "Someone downloaded a free resource from viavateam.com\n\n";
$teamBody   .= "Resource: {$resource['title']}\n";
$teamBody   .= "Email: {$email}\n";
$teamBody   .= "Agreed to be contacted by email: Yes\n";
$teamBody   .= 'Lead ID: ' . ($capture['id'] ?? 'NOT SAVED - this email is the only copy') . "\n";

// A retry of something already saved doesn't need to alert the team a second time.
$teamSent = false;
if (!$capture['global_limited'] && !$capture['remote_duplicate']) {
    $teamSent = viava_send_mail(
        $to, 'VIA VA Sales',
        'sales@viavateam.com', 'VIA VA Website',
        $email, null,
        $teamSubject, $teamBody
    );
    viava_record_event($capture, 'team_notification', $teamSent);
}
if (!$teamSent && !$capture['remote_duplicate']) {
    error_log('VIA VA resource form: team notification failed — ' . ($GLOBALS['viava_last_mail_error'] ?? 'unknown'));
}

// --- 2) Deliver the download to the requester ---------------------------
$rendered = render_resource_delivery_email([
    'title' => $resource['title'],
    'url'   => $downloadUrl,
    'blurb' => $resource['blurb'],
]);

// Nothing goes to the visitor unless the request was saved, or past a rate limit.
$deliverySent = false;
$deliveryHeld = !$capture['stored'] || $capture['ip_limited'] || $capture['global_limited'];
if (!$deliveryHeld) {
    $deliverySent = viava_send_mail(
        $email, '',
        'sales@viavateam.com', 'VIA VA Team',
        null, null,
        'Your download: ' . $resource['title'],
        $rendered['text'], $rendered['html']
    );
    viava_record_event($capture, 'resource_delivery', $deliverySent);
}
if (!$deliverySent && !$deliveryHeld) {
    error_log('VIA VA resource form: delivery email failed — ' . ($GLOBALS['viava_last_mail_error'] ?? 'unknown'));
}

// Success means both saved and sent.
if ($deliverySent) {
    viava_capture_confirm($capture);
}
header('Location: viava-resources.html?dl=' . ($deliverySent ? '1' : '0') . '#downloads');
exit;
