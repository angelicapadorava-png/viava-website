# viava-website

Marketing site for VIA VA — a virtual assistant agency ("Your team, virtually").

## Pages

- `index.html` — home page (hero, evolving support, services, stats, approach, testimonial)
- `viava-about.html` — the VIA story, pillars, what makes a VIA VA, mission, philosophy, impact
- `viava-services.html` — service categories, proven process, industries, AI, results, pricing overview
- `viava-our-vas.html` — the VIA standard, how VAs are built, AI training, why clients choose VIA VAs
- `viava-resources.html` — categories, featured articles, free downloads, tools we love
- `viava-for-clients.html` — is VIA for me, how VIA works, what you can delegate, engagement options
- `viava-how-it-works.html` — the 6-step VIA process, behind the scenes, what we need / what VIA handles
- `viava-pricing.html` — Part-Time / Full-Time / Specialized plans, comparison table, trust bar
- `viava-contact.html` — "Let's Talk": discovery-call panel + inquiry form (posts to `contact-handler.php`)
- `viava-faq.html` — full FAQ across hiring, pricing, VA quality, and working together
- `viava-redesign.css` — shared stylesheet for all pages (`viava-styles.css` is the retired original)

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
   copy `mail-config.example.php` to `mail-config.php`, and place it
   **one directory above** this site's folder (i.e. above `public_html`,
   as a sibling of it — not inside it). This matters: Hostinger's git
   deploy does a clean sync of the site folder on every push, which
   silently deletes any file placed inside it that isn't part of the
   repo. Putting it one level up keeps it outside that sync entirely.
2. Fill in the real password for the `sales@viavateam.com` mailbox.
3. That's it — `contact-handler.php` automatically checks that
   location first (falling back to right next to itself if not found
   there) and switches to authenticated SMTP (via the bundled
   PHPMailer library in `lib/PHPMailer/`) instead of `mail()`.

`mail-config.php` is listed in `.gitignore` — it must **never** be
committed, since this repo is public.

If either email fails to send, the reason is logged server-side via
PHP's `error_log()` (visible in Hostinger's error log viewer) — never
exposed to visitors or in the URL.

Static HTML/CSS + one PHP script (plus the small PHPMailer library), no
build step. Deploy by uploading these files to the web host's document
root.

### Note on deploy timing

After pushing to `main`, Hostinger's auto-deploy and PHP's opcache can
take **30–60 seconds** to fully catch up — testing the contact form
immediately after a push can show stale behavior. Wait a minute before
retesting.
