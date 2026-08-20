<?php
/**
 * SMTP credentials for the contact form's confirmation/notification emails.
 *
 * HOW TO USE:
 *   1. Copy this file to `mail-config.php` in the SAME folder, on the
 *      server itself (Hostinger File Manager, or FTP/SFTP) — NOT through
 *      git, and never commit it.
 *   2. Fill in the real mailbox password below.
 *
 * `mail-config.php` is listed in .gitignore so it can never be committed
 * or pushed to GitHub (this repo is public). If it's missing, the site
 * automatically falls back to PHP's plain mail() function — the form
 * still works, just with the deliverability tradeoffs of unauthenticated
 * mail.
 *
 * Hostinger's standard SMTP settings (confirm in hPanel > Emails >
 * Domain settings > Configuration if these differ):
 *   Host: smtp.hostinger.com
 *   Port: 465 (SSL) or 587 (STARTTLS)
 */

return [
    'host'       => 'smtp.hostinger.com',
    'port'       => 465,
    'encryption' => 'ssl', // 'ssl' for port 465, 'tls' for port 587
    'username'   => 'sales@viavateam.com',
    'password'   => 'PASTE_THE_REAL_MAILBOX_PASSWORD_HERE',
];
