<?php
/**
 * Shared mail helper for VIA VA form handlers.
 *
 * Uses authenticated SMTP (via mail-config.php + the bundled PHPMailer) when
 * available, otherwise falls back to PHP's mail(). This mirrors the logic in
 * contact-handler.php so the two handlers behave identically; contact-handler
 * keeps its own inline copy and is intentionally left untouched.
 */

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!function_exists('viava_find_mail_config_path')) {
    /**
     * Locates mail-config.php: one directory above the site first (survives
     * Hostinger's git deploy clean-sync), then next to the handler.
     */
    function viava_find_mail_config_path(): ?string {
        $candidates = [
            dirname(__DIR__, 2) . '/mail-config.php',
            dirname(__DIR__) . '/mail-config.php',
        ];
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        return null;
    }
}

if (!function_exists('viava_send_mail')) {
    /**
     * Sends one email. Returns true on success; on failure sets
     * $GLOBALS['viava_last_mail_error'] with the reason.
     */
    function viava_send_mail(
        string $toAddr, string $toName,
        string $fromAddr, string $fromName,
        ?string $replyToAddr, ?string $replyToName,
        string $subject, string $body, ?string $htmlBody = null
    ): bool {
        $configPath = viava_find_mail_config_path();

        if ($configPath !== null) {
            $config = require $configPath;
            $mailer = null;
            try {
                $mailer = new PHPMailer(true);
                $mailer->isSMTP();
                $mailer->Host       = $config['host'];
                $mailer->Port       = $config['port'];
                $mailer->SMTPAuth   = true;
                $mailer->Username   = $config['username'];
                $mailer->Password   = $config['password'];
                $mailer->SMTPSecure = $config['encryption'];
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
                    $GLOBALS['viava_last_mail_error'] = $mailer->ErrorInfo;
                }
                return $sent;
            } catch (PHPMailerException $e) {
                $GLOBALS['viava_last_mail_error'] =
                    ($mailer && $mailer->ErrorInfo !== '') ? $mailer->ErrorInfo : $e->getMessage();
                return false;
            }
        }

        // Fallback: plain mail().
        $headers   = [];
        $headers[] = 'From: ' . $fromName . ' <' . $fromAddr . '>';
        if ($replyToAddr) {
            $headers[] = 'Reply-To: ' . ($replyToName ?: $replyToAddr) . ' <' . $replyToAddr . '>';
        }
        $headers[] = 'MIME-Version: 1.0';
        if ($htmlBody !== null) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            return mail($toAddr, $subject, $htmlBody, implode("\r\n", $headers));
        }
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        return mail($toAddr, $subject, $body, implode("\r\n", $headers));
    }
}
