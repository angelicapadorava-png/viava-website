<?php
/**
 * Branded HTML email that delivers a requested free resource (checklist /
 * kit) to someone who submitted the download form on viava-resources.html.
 *
 * Table-based, inline-styled so it renders across Gmail, Apple Mail, and
 * Outlook. Uses the VIA VA TEAM redesign palette.
 *
 * Usage: $r = render_resource_delivery_email(['title' => ..., 'url' => ..., 'blurb' => ...]);
 *        $r['html'] / $r['text'] are ready for a mailer.
 */

function render_resource_delivery_email(array $data): array {
    $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $title = $esc($data['title'] ?? 'Your VIA VA resource');
    $url   = $esc($data['url']   ?? 'https://viavateam.com/viava-resources.html');
    $blurb = $esc($data['blurb'] ?? '');

    // Redesign palette (matches viava-redesign.css)
    $cream   = '#F7F1E9';
    $ink     = '#1F1823';
    $inkSoft = '#584E58';
    $rose    = '#C6396E';
    $roseDeep= '#A81F58';
    $coral   = '#E8734F';
    $dark    = '#17131B';

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Your download from VIA VA Team</title></head>
<body style="margin:0; padding:0; background-color:{$cream}; font-family:Arial, Helvetica, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:{$cream};">
  <tr>
    <td align="center" style="padding:32px 16px;">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:100%; background-color:#ffffff; border-radius:20px; overflow:hidden;">

        <tr>
          <td align="center" style="padding:26px 24px 6px 24px;">
            <div style="font-family:Georgia,'Times New Roman',serif; font-size:22px; font-weight:bold; color:{$ink}; letter-spacing:0.02em;">
              VIA <span style="color:{$rose};">VA</span> <span style="font-size:11px; letter-spacing:0.35em; color:{$inkSoft};">TEAM</span>
            </div>
          </td>
        </tr>

        <tr>
          <td align="center" bgcolor="{$roseDeep}" style="background-color:{$roseDeep}; background-image:linear-gradient(103deg, {$roseDeep} 0%, {$rose} 46%, {$coral} 100%); padding:34px 30px;">
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 16px auto;">
              <tr><td bgcolor="#ffffff" style="background-color:rgba(255,255,255,0.18); border-radius:999px; padding:7px 16px;">
                <span style="font-size:11px; font-weight:bold; letter-spacing:0.14em; text-transform:uppercase; color:#ffffff;">&#10022;&nbsp; Your download is ready</span>
              </td></tr>
            </table>
            <p style="margin:0 0 8px 0; font-family:Georgia,'Times New Roman',serif; font-size:26px; line-height:1.25; color:#ffffff;">{$title}</p>
            <p style="margin:0; font-size:14px; line-height:1.6; color:#ffffff; opacity:0.95;">Thanks for grabbing this &mdash; here's your copy.</p>
          </td>
        </tr>

        <tr>
          <td style="padding:28px 40px 8px 40px;">
            <p style="margin:0 0 22px 0; font-size:15px; line-height:1.7; color:{$inkSoft};">{$blurb}</p>
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
              <tr><td bgcolor="{$rose}" style="background-color:{$rose}; border-radius:999px;">
                <a href="{$url}" style="display:inline-block; padding:15px 34px; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none;">Download now &rarr;</a>
              </td></tr>
            </table>
            <p style="margin:18px 0 0 0; font-size:12px; line-height:1.6; color:{$inkSoft}; text-align:center;">
              If the button doesn't work, copy this link:<br>
              <a href="{$url}" style="color:{$rose};">{$url}</a>
            </p>
          </td>
        </tr>

        <tr>
          <td bgcolor="{$dark}" style="background-color:{$dark}; padding:26px 40px;">
            <p style="margin:0 0 6px 0; font-size:11px; font-weight:bold; letter-spacing:0.12em; text-transform:uppercase; color:{$coral};">&#10022;&nbsp; While you're here</p>
            <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:#B6ABB7;">
              Reading about working smarter is one thing &mdash; having someone help you do it is another. Our VAs are AI-trained and built for the way modern businesses work.
            </p>
            <table role="presentation" cellpadding="0" cellspacing="0"><tr>
              <td bgcolor="#ffffff" style="background-color:#ffffff; border-radius:999px;">
                <a href="https://viavateam.com/viava-contact.html" style="display:inline-block; padding:12px 26px; font-size:14px; font-weight:bold; color:{$ink}; text-decoration:none;">Find your VA &rarr;</a>
              </td>
            </tr></table>
          </td>
        </tr>

        <tr>
          <td align="center" bgcolor="{$dark}" style="background-color:{$dark}; padding:18px 24px; border-top:1px solid rgba(245,239,244,0.12);">
            <p style="margin:0; font-size:12px; color:#877C8C;">&copy; 2026 VIA VA Team &middot; AI-Trained. Human-Powered. Business-Ready.</p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;

    $text  = "YOUR DOWNLOAD IS READY\n\n";
    $text .= "{$data['title']}\n\n";
    if (($data['blurb'] ?? '') !== '') {
        $text .= $data['blurb'] . "\n\n";
    }
    $text .= "Download: {$data['url']}\n\n";
    $text .= "----\n";
    $text .= "Reading about working smarter is one thing — having someone help you do it is another. ";
    $text .= "Our VAs are AI-trained and built for the way modern businesses work.\n";
    $text .= "Find your VA: https://viavateam.com/viava-contact.html\n\n";
    $text .= "© 2026 VIA VA Team — AI-Trained. Human-Powered. Business-Ready.\n";

    return ['html' => $html, 'text' => $text];
}
