# viava-website

Marketing site for VIA VA — a virtual assistant agency ("Your team, virtually").

## Pages

- `index.html` — home page (hero, why VIA VA, cost comparison, industries, FAQ teaser)
- `viava-how-it-works.html` — the matching/onboarding process in detail
- `viava-services-pricing.html` — full service categories + pricing tiers
- `viava-about.html` — mission, values, join the team, FAQ
- `viava-contact.html` — dedicated contact page with the inquiry form
- `viava-styles.css` — shared stylesheet for all pages

## Contact form

`viava-contact.html` submits to `contact-handler.php`, which:

- Requires the visitor to check "I agree that VIA VA may contact me at
  the email address provided above" before it accepts the submission
  (enforced both in the browser and on the server).
- Emails the inquiry to `sales@viavateam.com`.
- Sends the submitter an automatic confirmation email.
- Redirects back to the contact page with `?sent=1` (success) or
  `?sent=0` (error) so the page can show a status banner.

Requires PHP on the host (Hostinger shared hosting serves `.php` files
automatically — no extra config needed).

### Email delivery (SMTP setup — do this once, on the server)

By default, `contact-handler.php` falls back to PHP's plain `mail()`,
which tends to land in spam because it isn't authenticated as your real
mailbox. To fix that:

1. On the **server** (Hostinger File Manager or FTP/SFTP — not git),
   copy `mail-config.example.php` to `mail-config.php`, in the same
   folder as `contact-handler.php`.
2. Fill in the real password for the `sales@viavateam.com` mailbox.
3. That's it — `contact-handler.php` automatically detects
   `mail-config.php` and switches to authenticated SMTP (via the
   bundled PHPMailer library in `lib/PHPMailer/`) instead of `mail()`.

`mail-config.php` is listed in `.gitignore` — it must **never** be
committed, since this repo is public. If your Hostinger deploy pulls a
clean copy of the repo on every push, double-check `mail-config.php`
survives each deploy; if it gets wiped, just re-create it (or ask
Hostinger support how to exclude a path from their git deployment sync).

Static HTML/CSS + one PHP script (plus the small PHPMailer library), no
build step. Deploy by uploading these files to the web host's document
root.
