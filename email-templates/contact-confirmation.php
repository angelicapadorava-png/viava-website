<?php
/**
 * Branded HTML confirmation email sent to someone who submits the
 * contact form. Table-based, inline-styled layout so it renders
 * consistently across Gmail, Apple Mail, and Outlook (which ignores
 * modern CSS like gradients and border-radius — every element below
 * has a plain fallback for that).
 *
 * Usage: $rendered = render_contact_confirmation_email($data);
 * $rendered['html'] and $rendered['text'] are ready to hand to a mailer.
 */

function render_contact_confirmation_email(array $data): array {
    $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $firstName = $esc($data['firstName']);
    $company   = $esc($data['company'] !== '' ? $data['company'] : '—');
    $hours     = $esc($data['hours'] !== '' ? $data['hours'] : '—');
    $support   = $esc($data['support'] !== '' ? $data['support'] : '—');
    $start     = $esc($data['start'] !== '' ? $data['start'] : '—');
    $companyForClosing = $esc($data['company'] !== '' ? $data['company'] : 'your business');

    // Brand palette (matches viava-styles.css)
    $ink     = '#14101C';
    $inkSoft = '#5B5568';
    $stone   = '#FFE3EC';
    $stone2  = '#F7A6C7';
    $berry   = '#C02D6A';
    $pink    = '#FF4F8B';
    $coral   = '#FF7A5A';
    $peach   = '#FFB26E';

    // Optional hero photo — drop a file in /email-assets/hero-workspace.*
    // (jpg/jpeg/png/webp) and it's picked up automatically; otherwise the
    // hero falls back to an illustrated badge, no photo required.
    $heroImageUrl = null;
    $assetsDir = __DIR__ . '/../email-assets';
    if (is_dir($assetsDir)) {
        foreach (glob($assetsDir . '/hero-workspace.*') as $f) {
            if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $heroImageUrl = 'https://viavateam.com/email-assets/' . basename($f);
                break;
            }
        }
    }

    if ($heroImageUrl) {
        $heroVisual = <<<HTML
            <tr>
              <td align="center" style="padding:0 24px 32px 24px;">
                <img src="{$heroImageUrl}" width="480" alt="" style="width:100%; max-width:480px; height:auto; display:block; border-radius:16px; border:4px solid rgba(255,255,255,0.35);">
              </td>
            </tr>
HTML;
    } else {
        $heroVisual = <<<HTML
            <tr>
              <td align="center" style="padding:0 24px 28px 24px;">
                <table role="presentation" cellpadding="0" cellspacing="0">
                  <tr>
                    <td width="128" height="128" align="center" valign="middle" bgcolor="{$peach}" style="background-color:{$peach}; border-radius:64px; font-size:56px; line-height:128px;">&#128187;</td>
                  </tr>
                </table>
              </td>
            </tr>
HTML;
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>We've got your inquiry — VIA VA</title>
</head>
<body style="margin:0; padding:0; background-color:{$stone}; font-family:Arial, Helvetica, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:{$stone};">
  <tr>
    <td align="center" style="padding:32px 16px;">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:100%; background-color:#ffffff; border-radius:20px; overflow:hidden;">

        <!-- Top bar / wordmark -->
        <tr>
          <td align="center" style="padding:24px 24px 8px 24px; background-color:#ffffff;">
            <div style="font-family:Georgia, 'Times New Roman', serif; font-size:22px; font-weight:bold; color:{$berry}; letter-spacing:-0.01em;">
              VIAVA<span style="font-style:italic; font-weight:normal; color:{$coral};">team</span>
            </div>
          </td>
        </tr>

        <!-- Hero -->
        <tr>
          <td align="center" bgcolor="{$berry}" style="background-color:{$berry}; background-image:linear-gradient(135deg, {$berry} 0%, {$pink} 45%, {$coral} 78%, {$peach} 100%); padding:36px 24px 8px 24px;">
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 20px auto;">
              <tr>
                <td bgcolor="#ffffff" style="background-color:rgba(255,255,255,0.18); border-radius:999px; padding:8px 18px;">
                  <span style="font-family:Arial, Helvetica, sans-serif; font-size:11px; font-weight:bold; letter-spacing:0.14em; text-transform:uppercase; color:#ffffff;">&#10022;&nbsp; Inquiry received</span>
                </td>
              </tr>
            </table>
            <p style="margin:0 0 12px 0; font-family:Georgia, 'Times New Roman', serif; font-weight:bold; font-size:32px; line-height:1.2; color:#ffffff;">You're on our radar, {$firstName}.</p>
            <p style="margin:0 0 6px 0; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.6; color:#ffffff; opacity:0.95;">
              Thanks for reaching out to VIA VA. We've received your inquiry.
            </p>
            <p style="margin:0 0 28px 0; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.6; color:#ffffff;">
              <strong>Our team will be in touch within 24 hours</strong> to learn more about what you need and how we can help.
            </p>
          </td>
        </tr>
{$heroVisual}

        <!-- Recap card -->
        <tr>
          <td style="padding:32px 40px 8px 40px; font-family:Arial, Helvetica, sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="{$stone}" style="background-color:{$stone}; border-radius:14px;">
              <tr>
                <td style="padding:22px 24px;">
                  <p style="margin:0 0 12px 0; font-size:11px; font-weight:bold; letter-spacing:0.12em; text-transform:uppercase; color:{$berry};">&#10022;&nbsp; What you told us</p>
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; color:{$ink};">
                    <tr><td style="padding:5px 0; color:{$inkSoft};">Company</td><td style="padding:5px 0; text-align:right; font-weight:bold;">{$company}</td></tr>
                    <tr><td style="padding:5px 0; color:{$inkSoft};">Hours of support needed</td><td style="padding:5px 0; text-align:right; font-weight:bold;">{$hours}</td></tr>
                    <tr><td style="padding:5px 0; color:{$inkSoft};">Type of support</td><td style="padding:5px 0; text-align:right; font-weight:bold;">{$support}</td></tr>
                    <tr><td style="padding:5px 0; color:{$inkSoft};">Preferred start</td><td style="padding:5px 0; text-align:right; font-weight:bold;">{$start}</td></tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- What happens next -->
        <tr>
          <td style="padding:32px 40px 12px 40px; font-family:Arial, Helvetica, sans-serif;">
            <p style="margin:0 0 18px 0; font-size:11px; font-weight:bold; letter-spacing:0.12em; text-transform:uppercase; color:{$berry};">&#10022;&nbsp; What happens next</p>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td width="40" valign="top" style="padding:0 0 20px 0;">
                  <table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="28" height="28" align="center" valign="middle" bgcolor="{$berry}" style="background-color:{$berry}; border-radius:14px; font-family:Arial, Helvetica, sans-serif; font-size:12px; color:#ffffff; font-weight:bold; line-height:28px;">01</td></tr></table>
                </td>
                <td valign="top" style="padding:0 0 20px 0;">
                  <p style="margin:0 0 3px 0; font-size:15px; font-weight:bold; color:{$ink};">We talk through your needs</p>
                  <p style="margin:0; font-size:14px; line-height:1.6; color:{$inkSoft};">We'll learn more about your business, the work you need help with, and what you'd like to take off your plate.</p>
                </td>
              </tr>
              <tr>
                <td width="40" valign="top" style="padding:0 0 20px 0;">
                  <table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="28" height="28" align="center" valign="middle" bgcolor="{$berry}" style="background-color:{$berry}; border-radius:14px; font-family:Arial, Helvetica, sans-serif; font-size:12px; color:#ffffff; font-weight:bold; line-height:28px;">02</td></tr></table>
                </td>
                <td valign="top" style="padding:0 0 20px 0;">
                  <p style="margin:0 0 3px 0; font-size:15px; font-weight:bold; color:{$ink};">We find the right fit</p>
                  <p style="margin:0; font-size:14px; line-height:1.6; color:{$inkSoft};">We'll help you identify the kind of VA support that makes sense for your workflow, hours, and goals.</p>
                </td>
              </tr>
              <tr>
                <td width="40" valign="top" style="padding:0 0 20px 0;">
                  <table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="28" height="28" align="center" valign="middle" bgcolor="{$berry}" style="background-color:{$berry}; border-radius:14px; font-family:Arial, Helvetica, sans-serif; font-size:12px; color:#ffffff; font-weight:bold; line-height:28px;">03</td></tr></table>
                </td>
                <td valign="top" style="padding:0 0 20px 0;">
                  <p style="margin:0 0 3px 0; font-size:15px; font-weight:bold; color:{$ink};">You meet your VA</p>
                  <p style="margin:0; font-size:14px; line-height:1.6; color:{$inkSoft};">Once we've found a strong fit, you'll have the opportunity to meet them and make sure the working relationship feels right.</p>
                </td>
              </tr>
              <tr>
                <td width="40" valign="top" style="padding:0;">
                  <table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="28" height="28" align="center" valign="middle" bgcolor="{$berry}" style="background-color:{$berry}; border-radius:14px; font-family:Arial, Helvetica, sans-serif; font-size:12px; color:#ffffff; font-weight:bold; line-height:28px;">04</td></tr></table>
                </td>
                <td valign="top" style="padding:0;">
                  <p style="margin:0 0 3px 0; font-size:15px; font-weight:bold; color:{$ink};">We stay involved</p>
                  <p style="margin:0; font-size:14px; line-height:1.6; color:{$inkSoft};">Our support doesn't stop when your VA starts. We're here to help keep things running smoothly and step in if you ever need additional support.</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Why VIA VA — dark band -->
        <tr>
          <td bgcolor="{$ink}" style="background-color:{$ink}; padding:36px 40px; font-family:Arial, Helvetica, sans-serif;">
            <p style="margin:0 0 6px 0; font-size:11px; font-weight:bold; letter-spacing:0.12em; text-transform:uppercase; color:{$peach};">&#10022;&nbsp; Why VIA VA</p>
            <p style="margin:0 0 14px 0; font-size:14px; line-height:1.6; color:#C3BDCB;">
              Hiring a VA shouldn't mean creating another job for yourself. You shouldn't have to spend weeks sorting through applicants, figuring out who to hire, training someone from scratch, and then hoping it works out.
            </p>
            <p style="margin:0 0 20px 0; font-family:Georgia, 'Times New Roman', serif; font-size:18px; font-weight:bold; color:#ffffff;">That's where we come in.</p>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td width="30" valign="top" style="padding:0 0 12px 0;"><span style="display:inline-block; width:20px; height:20px; border-radius:10px; background-color:{$peach}; color:{$ink}; font-size:12px; font-weight:bold; text-align:center; line-height:20px;">&#10003;</span></td>
                <td valign="top" style="padding:0 0 12px 0; font-size:14px; line-height:1.6; color:#C3BDCB;"><strong style="color:#ffffff;">Vetted talent.</strong> Skip the endless search and connect with people who are ready to work.</td>
              </tr>
              <tr>
                <td width="30" valign="top" style="padding:0 0 12px 0;"><span style="display:inline-block; width:20px; height:20px; border-radius:10px; background-color:{$peach}; color:{$ink}; font-size:12px; font-weight:bold; text-align:center; line-height:20px;">&#10003;</span></td>
                <td valign="top" style="padding:0 0 12px 0; font-size:14px; line-height:1.6; color:#C3BDCB;"><strong style="color:#ffffff;">Less training from scratch.</strong> Your time should be spent running your business — not teaching someone how to do everything from the ground up.</td>
              </tr>
              <tr>
                <td width="30" valign="top" style="padding:0 0 12px 0;"><span style="display:inline-block; width:20px; height:20px; border-radius:10px; background-color:{$peach}; color:{$ink}; font-size:12px; font-weight:bold; text-align:center; line-height:20px;">&#10003;</span></td>
                <td valign="top" style="padding:0 0 12px 0; font-size:14px; line-height:1.6; color:#C3BDCB;"><strong style="color:#ffffff;">Flexible support.</strong> Whether you need a few hours or ongoing support, we can build around what your business actually needs.</td>
              </tr>
              <tr>
                <td width="30" valign="top" style="padding:0 0 12px 0;"><span style="display:inline-block; width:20px; height:20px; border-radius:10px; background-color:{$peach}; color:{$ink}; font-size:12px; font-weight:bold; text-align:center; line-height:20px;">&#10003;</span></td>
                <td valign="top" style="padding:0 0 12px 0; font-size:14px; line-height:1.6; color:#C3BDCB;"><strong style="color:#ffffff;">Ongoing support.</strong> We're here beyond the initial placement to help make sure things continue to work.</td>
              </tr>
              <tr>
                <td width="30" valign="top" style="padding:0;"><span style="display:inline-block; width:20px; height:20px; border-radius:10px; background-color:{$peach}; color:{$ink}; font-size:12px; font-weight:bold; text-align:center; line-height:20px;">&#10003;</span></td>
                <td valign="top" style="padding:0; font-size:14px; line-height:1.6; color:#C3BDCB;"><strong style="color:#ffffff;">Free rematch guarantee.</strong> If the fit isn't right, we'll help you find a better one.</td>
              </tr>
            </table>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="{$coral}" style="background-color:{$coral}; background-image:linear-gradient(120deg, {$berry} 0%, {$pink} 50%, {$coral} 100%); border-radius:14px; margin-top:22px;">
              <tr>
                <td style="padding:22px 24px;">
                  <p style="margin:0; font-family:Georgia, 'Times New Roman', serif; font-style:italic; font-size:18px; line-height:1.5; color:#ffffff;">
                    "The right VA doesn't just complete tasks. They give you time back."
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Need more than a VA -->
        <tr>
          <td style="padding:36px 40px 8px 40px; font-family:Arial, Helvetica, sans-serif;">
            <p style="margin:0 0 6px 0; font-size:11px; font-weight:bold; letter-spacing:0.12em; text-transform:uppercase; color:{$berry};">&#10022;&nbsp; Beyond a VA</p>
            <p style="margin:0 0 18px 0; font-family:Georgia, 'Times New Roman', serif; font-size:20px; font-weight:bold; color:{$ink};">Need more than a VA?</p>
          </td>
        </tr>
        <tr>
          <td style="padding:0 40px 8px 40px; font-family:Arial, Helvetica, sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="{$stone}" style="background-color:{$stone}; border-radius:14px; margin-bottom:12px;">
              <tr>
                <td style="padding:18px 22px;">
                  <p style="margin:0 0 4px 0; font-size:14px; font-weight:bold; color:{$ink};">&#127760;&nbsp; Website services</p>
                  <p style="margin:0; font-size:14px; line-height:1.6; color:{$inkSoft};">Need a website built, updated, or maintained? We work with our trusted web development partner, <strong style="color:{$ink};">Devectureph</strong>.</p>
                </td>
              </tr>
            </table>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="{$stone}" style="background-color:{$stone}; border-radius:14px;">
              <tr>
                <td style="padding:18px 22px;">
                  <p style="margin:0 0 4px 0; font-size:14px; font-weight:bold; color:{$ink};">&#127912;&nbsp; Branding</p>
                  <p style="margin:0; font-size:14px; line-height:1.6; color:{$inkSoft};">Working on your brand? Let us know — our team can point you in the right direction.</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Sign off -->
        <tr>
          <td style="padding:32px 40px 8px 40px; font-family:Arial, Helvetica, sans-serif;">
            <p style="margin:0 0 4px 0; font-size:14px; line-height:1.6; color:{$inkSoft};">Have a question? Just reply to this email — it comes straight to the VIA VA team.</p>
            <p style="margin:0 0 20px 0; font-size:14px; line-height:1.6; color:{$inkSoft};">Otherwise, keep an eye on your inbox — we'll be in touch within 24 hours. We're looking forward to learning more about <strong style="color:{$ink};">{$companyForClosing}</strong> and seeing how we can help.</p>
            <p style="margin:0; font-size:14px; line-height:1.6; color:{$ink};">Talk soon,<br><strong>The VIA VA Team</strong></p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td align="center" bgcolor="{$ink}" style="background-color:{$ink}; padding:22px 24px;">
            <p style="margin:0 0 4px 0; font-family:Georgia, 'Times New Roman', serif; font-style:italic; font-size:13px; color:{$peach};">VIA VA &mdash; Virtual support that gives you time back.</p>
            <p style="margin:0; font-family:Arial, Helvetica, sans-serif; font-size:12px; color:#C3BDCB;">&copy; 2026 VIA VA &middot; Your team, virtually</p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;

    $text  = "Hi {$data['firstName']},\n\n";
    $text .= "Thanks for reaching out to VIA VA. We've received your inquiry.\n";
    $text .= "Our team will be in touch within 24 hours to learn more about what you need and how we can help.\n\n";
    $text .= "WHAT YOU TOLD US\n";
    $text .= "Company: {$data['company']}\n";
    $text .= "Hours of support needed: {$data['hours']}\n";
    $text .= "Type of support: {$data['support']}\n";
    $text .= "Preferred start: {$data['start']}\n\n";
    $text .= "WHAT HAPPENS NEXT\n";
    $text .= "01 - We talk through your needs. We'll learn more about your business, the work you need help with, and what you'd like to take off your plate.\n";
    $text .= "02 - We find the right fit. We'll help you identify the kind of VA support that makes sense for your workflow, hours, and goals.\n";
    $text .= "03 - You meet your VA. Once we've found a strong fit, you'll have the opportunity to meet them and make sure the working relationship feels right.\n";
    $text .= "04 - We stay involved. Our support doesn't stop when your VA starts. We're here to help keep things running smoothly and step in if you ever need additional support.\n\n";
    $text .= "WHY VIA VA\n";
    $text .= "Hiring a VA shouldn't mean creating another job for yourself. You shouldn't have to spend weeks sorting through applicants, figuring out who to hire, training someone from scratch, and then hoping it works out. That's where we come in.\n\n";
    $text .= "- Vetted talent. Skip the endless search and connect with people who are ready to work.\n";
    $text .= "- Less training from scratch. Your time should be spent running your business, not teaching someone how to do everything from the ground up.\n";
    $text .= "- Flexible support. Whether you need a few hours or ongoing support, we can build around what your business actually needs.\n";
    $text .= "- Ongoing support. We're here beyond the initial placement to help make sure things continue to work.\n";
    $text .= "- Free rematch guarantee. If the fit isn't right, we'll help you find a better one.\n\n";
    $text .= "\"The right VA doesn't just complete tasks. They give you time back.\"\n\n";
    $text .= "NEED MORE THAN A VA?\n";
    $text .= "- Website services: need a website built, updated, or maintained? We work with our trusted web development partner, Devectureph.\n";
    $text .= "- Branding: working on your brand? Let us know, our team can point you in the right direction.\n\n";
    $text .= "Have a question? Just reply to this email — it comes straight to the VIA VA team.\n";
    $companyForClosingText = $data['company'] !== '' ? $data['company'] : 'your business';
    $text .= "Otherwise, keep an eye on your inbox — we'll be in touch within 24 hours. We're looking forward to learning more about {$companyForClosingText} and seeing how we can help.\n\n";
    $text .= "Talk soon,\nThe VIA VA Team\n\n";
    $text .= "VIA VA — Virtual support that gives you time back.\n";

    return ['html' => $html, 'text' => $text];
}
