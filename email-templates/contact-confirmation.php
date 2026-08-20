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
    $berry   = '#C02D6A';
    $pink    = '#FF4F8B';
    $coral   = '#FF7A5A';
    $peach   = '#FFB26E';

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
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:100%; background-color:#ffffff; border-radius:16px; overflow:hidden;">

        <!-- Header / logo -->
        <tr>
          <td align="center" bgcolor="{$berry}" style="background-color:{$berry}; background-image:linear-gradient(120deg, {$berry} 0%, {$pink} 42%, {$coral} 75%, {$peach} 100%); padding:36px 24px;">
            <div style="font-family:Georgia, 'Times New Roman', serif; font-size:28px; font-weight:bold; color:#ffffff; letter-spacing:-0.02em;">
              VIAVA<span style="font-family:'Brush Script MT', cursive; font-style:italic; font-weight:normal; color:{$peach};">team</span>
            </div>
            <div style="margin-top:6px; font-family:Arial, Helvetica, sans-serif; font-size:12px; letter-spacing:0.12em; text-transform:uppercase; color:#ffffff; opacity:0.9;">
              Your team, virtually
            </div>
          </td>
        </tr>

        <!-- Greeting -->
        <tr>
          <td style="padding:36px 40px 8px 40px; font-family:Arial, Helvetica, sans-serif;">
            <p style="margin:0 0 16px 0; font-size:20px; font-weight:bold; color:{$ink};">Hi {$firstName},</p>
            <p style="margin:0 0 16px 0; font-size:15px; line-height:1.6; color:{$inkSoft};">
              Thanks for reaching out to VIA VA! We've received your inquiry, and a member of our team will follow up within <strong>48 hours</strong> to talk through your needs and find your match.
            </p>
          </td>
        </tr>

        <!-- Recap card -->
        <tr>
          <td style="padding:8px 40px 24px 40px; font-family:Arial, Helvetica, sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="{$stone}" style="background-color:{$stone}; border-radius:12px;">
              <tr>
                <td style="padding:20px 24px;">
                  <p style="margin:0 0 12px 0; font-size:11px; font-weight:bold; letter-spacing:0.1em; text-transform:uppercase; color:{$berry};">What you told us</p>
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; color:{$ink};">
                    <tr><td style="padding:4px 0; color:{$inkSoft};">Company</td><td style="padding:4px 0; text-align:right; font-weight:bold;">{$company}</td></tr>
                    <tr><td style="padding:4px 0; color:{$inkSoft};">Hours of support needed</td><td style="padding:4px 0; text-align:right; font-weight:bold;">{$hours}</td></tr>
                    <tr><td style="padding:4px 0; color:{$inkSoft};">Type of support</td><td style="padding:4px 0; text-align:right; font-weight:bold;">{$support}</td></tr>
                    <tr><td style="padding:4px 0; color:{$inkSoft};">Preferred start</td><td style="padding:4px 0; text-align:right; font-weight:bold;">{$start}</td></tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Before you hire section -->
        <tr>
          <td style="padding:8px 40px 8px 40px; font-family:Arial, Helvetica, sans-serif;">
            <p style="margin:0 0 12px 0; font-size:11px; font-weight:bold; letter-spacing:0.1em; text-transform:uppercase; color:{$berry};">Before you bring on a VA</p>
            <p style="margin:0 0 14px 0; font-size:15px; font-weight:bold; color:{$ink};">A few things worth knowing</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="padding:0 0 12px 0; font-size:14px; line-height:1.6; color:{$inkSoft};">
                  <span style="color:{$berry}; font-weight:bold;">&#10003;&nbsp;</span><strong style="color:{$ink};">Training determines your timeline.</strong> A VA who still needs to learn your tools, your voice, and your workflows is time you're spending — not saving.
                </td>
              </tr>
              <tr>
                <td style="padding:0 0 12px 0; font-size:14px; line-height:1.6; color:{$inkSoft};">
                  <span style="color:{$berry}; font-weight:bold;">&#10003;&nbsp;</span><strong style="color:{$ink};">Fit beats a resume.</strong> The right match keeps you from starting the search over a few weeks in.
                </td>
              </tr>
              <tr>
                <td style="padding:0 0 12px 0; font-size:14px; line-height:1.6; color:{$inkSoft};">
                  <span style="color:{$berry}; font-weight:bold;">&#10003;&nbsp;</span><strong style="color:{$ink};">Support shouldn't stop at kickoff.</strong> Quality holds up when someone's actually managing performance, not just placing people.
                </td>
              </tr>
              <tr>
                <td style="padding:0 0 4px 0; font-size:14px; line-height:1.6; color:{$inkSoft};">
                  <span style="color:{$berry}; font-weight:bold;">&#10003;&nbsp;</span><strong style="color:{$ink};">Flexibility matters more than a lower rate.</strong> Being able to adjust hours or request a rematch protects you if the fit isn't right.
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Why agency vs direct -->
        <tr>
          <td style="padding:28px 40px 8px 40px; font-family:Arial, Helvetica, sans-serif;">
            <p style="margin:0 0 12px 0; font-size:11px; font-weight:bold; letter-spacing:0.1em; text-transform:uppercase; color:{$berry};">Agency vs. hiring direct</p>
            <p style="margin:0 0 14px 0; font-size:15px; font-weight:bold; color:{$ink};">Why work with an agency instead of hiring on your own?</p>
            <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{$inkSoft};">
              When you hire directly, you're the one sourcing, interviewing, and training from scratch — usually weeks before you see any real time back. With VIA VA, your VA already comes vetted and trained on core skills before you ever meet them.
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:0 40px 24px 40px; font-family:Arial, Helvetica, sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-left:4px solid {$berry};">
              <tr>
                <td style="padding:4px 0 4px 18px;">
                  <p style="margin:0; font-family:Georgia, 'Times New Roman', serif; font-style:italic; font-size:17px; line-height:1.5; color:{$ink};">
                    "The less they train, the less time they waste."
                  </p>
                </td>
              </tr>
            </table>
            <p style="margin:14px 0 0 0; font-size:14px; line-height:1.6; color:{$inkSoft};">
              That's the whole point of going through an agency — you delegate almost immediately instead of teaching. Every VIA VA placement also comes with a <strong style="color:{$ink};">free rematch guarantee</strong> and <strong style="color:{$ink};">ongoing support</strong> — protection you don't get hiring solo.
            </p>
          </td>
        </tr>

        <!-- Need more than a VA -->
        <tr>
          <td style="padding:8px 40px 28px 40px; font-family:Arial, Helvetica, sans-serif;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="{$stone}" style="background-color:{$stone}; border-radius:12px;">
              <tr>
                <td style="padding:22px 24px;">
                  <p style="margin:0 0 10px 0; font-size:15px; font-weight:bold; color:{$ink};">Need more than a VA?</p>
                  <p style="margin:0 0 10px 0; font-size:14px; line-height:1.6; color:{$inkSoft};">
                    If your business also needs help with your website or your brand, we've got you covered:
                  </p>
                  <p style="margin:0 0 6px 0; font-size:14px; line-height:1.6; color:{$inkSoft};">
                    <span style="color:{$berry}; font-weight:bold;">&#10003;&nbsp;</span><strong style="color:{$ink};">Website services</strong> — we partner with <strong style="color:{$ink};">Devectureph</strong>, our go-to team for building and maintaining websites.
                  </p>
                  <p style="margin:0; font-size:14px; line-height:1.6; color:{$inkSoft};">
                    <span style="color:{$berry}; font-weight:bold;">&#10003;&nbsp;</span><strong style="color:{$ink};">Branding</strong> — if that's something you're exploring, just mention it on your first call and our sales team will point you in the right direction.
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Sign off -->
        <tr>
          <td style="padding:0 40px 36px 40px; font-family:Arial, Helvetica, sans-serif;">
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
