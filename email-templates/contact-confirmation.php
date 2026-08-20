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
            <p style="margin:0 0 28px 0; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.6; color:#ffffff; opacity:0.95;">
              A real human from VIA VA will follow up within <strong>48 hours</strong> to talk through your needs and find your match.
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

        <!-- Before you hire section -->
        <tr>
          <td style="padding:32px 40px 12px 40px; font-family:Arial, Helvetica, sans-serif;">
            <p style="margin:0 0 6px 0; font-size:11px; font-weight:bold; letter-spacing:0.12em; text-transform:uppercase; color:{$berry};">&#10022;&nbsp; Before you bring on a VA</p>
            <p style="margin:0 0 18px 0; font-family:Georgia, 'Times New Roman', serif; font-size:20px; font-weight:bold; color:{$ink};">A few things worth knowing</p>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td width="36" valign="top" style="padding:0 0 16px 0;">
                  <table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="26" height="26" align="center" valign="middle" bgcolor="{$berry}" style="background-color:{$berry}; border-radius:13px; font-size:13px; color:#ffffff; font-weight:bold; line-height:26px;">&#10003;</td></tr></table>
                </td>
                <td valign="top" style="padding:0 0 16px 0; font-size:14px; line-height:1.6; color:{$inkSoft};">
                  <strong style="color:{$ink};">Training determines your timeline.</strong> A VA who still needs to learn your tools, your voice, and your workflows is time you're spending — not saving.
                </td>
              </tr>
              <tr>
                <td width="36" valign="top" style="padding:0 0 16px 0;">
                  <table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="26" height="26" align="center" valign="middle" bgcolor="{$berry}" style="background-color:{$berry}; border-radius:13px; font-size:13px; color:#ffffff; font-weight:bold; line-height:26px;">&#10003;</td></tr></table>
                </td>
                <td valign="top" style="padding:0 0 16px 0; font-size:14px; line-height:1.6; color:{$inkSoft};">
                  <strong style="color:{$ink};">Fit beats a resume.</strong> The right match keeps you from starting the search over a few weeks in.
                </td>
              </tr>
              <tr>
                <td width="36" valign="top" style="padding:0 0 16px 0;">
                  <table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="26" height="26" align="center" valign="middle" bgcolor="{$berry}" style="background-color:{$berry}; border-radius:13px; font-size:13px; color:#ffffff; font-weight:bold; line-height:26px;">&#10003;</td></tr></table>
                </td>
                <td valign="top" style="padding:0 0 16px 0; font-size:14px; line-height:1.6; color:{$inkSoft};">
                  <strong style="color:{$ink};">Support shouldn't stop at kickoff.</strong> Quality holds up when someone's actually managing performance, not just placing people.
                </td>
              </tr>
              <tr>
                <td width="36" valign="top" style="padding:0;">
                  <table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="26" height="26" align="center" valign="middle" bgcolor="{$berry}" style="background-color:{$berry}; border-radius:13px; font-size:13px; color:#ffffff; font-weight:bold; line-height:26px;">&#10003;</td></tr></table>
                </td>
                <td valign="top" style="padding:0; font-size:14px; line-height:1.6; color:{$inkSoft};">
                  <strong style="color:{$ink};">Flexibility matters more than a lower rate.</strong> Being able to adjust hours or request a rematch protects you if the fit isn't right.
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Why agency vs direct — dark band -->
        <tr>
          <td bgcolor="{$ink}" style="background-color:{$ink}; padding:36px 40px; font-family:Arial, Helvetica, sans-serif;">
            <p style="margin:0 0 6px 0; font-size:11px; font-weight:bold; letter-spacing:0.12em; text-transform:uppercase; color:{$peach};">&#10022;&nbsp; Agency vs. hiring direct</p>
            <p style="margin:0 0 16px 0; font-family:Georgia, 'Times New Roman', serif; font-size:20px; font-weight:bold; color:#ffffff;">Why work with an agency instead of hiring on your own?</p>
            <p style="margin:0 0 20px 0; font-size:14px; line-height:1.6; color:#C3BDCB;">
              When you hire directly, you're the one sourcing, interviewing, and training from scratch — usually weeks before you see any real time back. With VIA VA, your VA already comes vetted and trained on core skills before you ever meet them.
            </p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="{$coral}" style="background-color:{$coral}; background-image:linear-gradient(120deg, {$berry} 0%, {$pink} 50%, {$coral} 100%); border-radius:14px;">
              <tr>
                <td style="padding:22px 24px;">
                  <p style="margin:0; font-family:Georgia, 'Times New Roman', serif; font-style:italic; font-size:18px; line-height:1.5; color:#ffffff;">
                    "The less they train, the less time they waste."
                  </p>
                </td>
              </tr>
            </table>
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:18px;">
              <tr>
                <td bgcolor="#271F35" style="background-color:#271F35; border-radius:999px; padding:8px 16px; margin-right:8px;">
                  <span style="font-size:12px; font-weight:bold; color:{$peach};">&#10003; Free rematch guarantee</span>
                </td>
                <td width="10"></td>
                <td bgcolor="#271F35" style="background-color:#271F35; border-radius:999px; padding:8px 16px;">
                  <span style="font-size:12px; font-weight:bold; color:{$peach};">&#10003; Ongoing support</span>
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
                  <p style="margin:0; font-size:14px; line-height:1.6; color:{$inkSoft};">We partner with <strong style="color:{$ink};">Devectureph</strong>, our go-to team for building and maintaining websites.</p>
                </td>
              </tr>
            </table>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="{$stone}" style="background-color:{$stone}; border-radius:14px;">
              <tr>
                <td style="padding:18px 22px;">
                  <p style="margin:0 0 4px 0; font-size:14px; font-weight:bold; color:{$ink};">&#127912;&nbsp; Branding</p>
                  <p style="margin:0; font-size:14px; line-height:1.6; color:{$inkSoft};">If that's something you're exploring, just mention it on your first call and our sales team will point you in the right direction.</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Sign off -->
        <tr>
          <td style="padding:32px 40px 36px 40px; font-family:Arial, Helvetica, sans-serif;">
            <p style="margin:0 0 4px 0; font-size:14px; line-height:1.6; color:{$inkSoft};">Got questions before we talk? Just reply to this email — it comes straight to us.</p>
            <p style="margin:20px 0 0 0; font-size:14px; line-height:1.6; color:{$ink};">Talk soon,<br><strong>The VIA VA Team</strong></p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td align="center" bgcolor="{$ink}" style="background-color:{$ink}; padding:22px 24px;">
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
    $text .= "Thanks for reaching out to VIA VA! We've received your inquiry and a member of our team will follow up within 48 hours to talk through your needs and find your match.\n\n";
    $text .= "WHAT YOU TOLD US\n";
    $text .= "Company: {$data['company']}\n";
    $text .= "Hours of support needed: {$data['hours']}\n";
    $text .= "Type of support: {$data['support']}\n";
    $text .= "Preferred start: {$data['start']}\n\n";
    $text .= "BEFORE YOU BRING ON A VA — a few things worth knowing:\n";
    $text .= "- Training determines your timeline. A VA who still needs to learn your tools, your voice, and your workflows is time you're spending, not saving.\n";
    $text .= "- Fit beats a resume. The right match keeps you from starting the search over a few weeks in.\n";
    $text .= "- Support shouldn't stop at kickoff. Quality holds up when someone's actually managing performance, not just placing people.\n";
    $text .= "- Flexibility matters more than a lower rate. Being able to adjust hours or request a rematch protects you if the fit isn't right.\n\n";
    $text .= "WHY WORK WITH AN AGENCY INSTEAD OF HIRING DIRECT?\n";
    $text .= "When you hire directly, you're the one sourcing, interviewing, and training from scratch — usually weeks before you see any real time back. With VIA VA, your VA already comes vetted and trained on core skills before you ever meet them.\n\n";
    $text .= "\"The less they train, the less time they waste.\" That's the whole point of going through an agency — you delegate almost immediately instead of teaching. Every VIA VA placement also comes with a free rematch guarantee and ongoing support.\n\n";
    $text .= "NEED MORE THAN A VA?\n";
    $text .= "- Website services: we partner with Devectureph, our go-to team for building and maintaining websites.\n";
    $text .= "- Branding: if that interests you, mention it on your first call and our sales team will point you in the right direction.\n\n";
    $text .= "Got questions before we talk? Just reply to this email.\n\n";
    $text .= "Talk soon,\nThe VIA VA Team\n";

    return ['html' => $html, 'text' => $text];
}
