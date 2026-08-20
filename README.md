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

`viava-contact.html` submits to `contact-handler.php`, which emails
`sales@viavateam.com` and redirects back to the contact page with
`?sent=1` (success) or `?sent=0` (error) so the page can show a status
banner. Requires PHP on the host (Hostinger shared hosting serves `.php`
files automatically — no extra config needed).

Static HTML/CSS + one PHP script, no build step. Deploy by uploading these
files to the web host's document root.
