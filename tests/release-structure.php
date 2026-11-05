<?php
/**
 * Runs the real form handlers from a checkout of this repository, laid out like the
 * host, which deploys the whole repository into public_html:
 *
 *   <temp>/public_html/        a clean checkout of this repository
 *   <temp>/mail-config.php     mail settings above the web root (fake, local only)
 *   <temp>/viava-private/      saved submissions, created by the handlers
 *
 *   php tests/release-structure.php            (uses a clean clone of HEAD)
 *   VIAVA_TEST_SITE_ROOT=<folder> php tests/release-structure.php
 *
 * It works on a temp copy, so it can't touch anything real.
 * PHP's built-in server ignores .htaccess, so what's tested here has to be true
 * without it.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/helpers.php';

$release = getenv('VIAVA_TEST_SITE_ROOT');
$cloned = null;
if (!$release) {
    // Exactly what Hostinger would deploy: the committed files, not the working folder.
    $cloned = temp_dir('release-clone');
    exec('git clone -q ' . escapeshellarg(dirname(__DIR__)) . ' ' . escapeshellarg($cloned . '/site') . ' 2>&1', $ignored, $cloneCode);
    if ($cloneCode !== 0) {
        fwrite(STDERR, "Couldn't clone this repository for the test.\n");
        exit(2);
    }
    $release = $cloned . '/site';
}
if (!is_file($release . '/index.html')) {
    fwrite(STDERR, "VIAVA_TEST_SITE_ROOT must be a site folder.\n");
    exit(2);
}

function copy_tree(string $from, string $to): void {
    mkdir($to, 0700, true);
    foreach (scandir($from) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        is_dir("$from/$name") ? copy_tree("$from/$name", "$to/$name") : copy("$from/$name", "$to/$name");
    }
}

$home = temp_dir('release');
$root = $home . '/public_html';
copy_tree($release, $root);
$mailDir = temp_dir('release-mail');

$smtpPort = 18961;
$sitePort = 18962;
$smtp = start_fake_smtp($smtpPort, $mailDir, true);
$mailConfig = "<?php\nreturn ['host' => '127.0.0.1', 'port' => {$smtpPort}, 'encryption' => '', 'username' => 'release-test@example.com', 'password' => 'fake-local-only'];\n";
file_put_contents($home . '/mail-config.php', $mailConfig);
$before = sha1_file($home . '/mail-config.php');

// An empty setting makes the handlers use their real default folder, beside the web root.
$server = start_server($root, '', $sitePort, 1);

// --- The site ---------------------------------------------------------------

$pages = glob($root . '/*.html');
$ok = true;
foreach ($pages as $page) {
    [$status] = request($sitePort, basename($page));
    $ok = $ok && $status === 200;
}
check('all ' . count($pages) . ' pages load from the release', $ok && count($pages) >= 20);
foreach (['assets/og-image.png', 'assets/downloads/sop-starter-kit.pdf', 'viava-styles.css', 'favicon.ico', 'site.webmanifest', 'email-assets/hero-workspace.jpg'] as $path) {
    [$status] = request($sitePort, $path);
    check("{$path} is served", $status === 200);
}

// --- Forms ------------------------------------------------------------------

[$status, $location] = request($sitePort, 'contact-handler.php', contact_post());
check('contact form reports success', $status === 302 && strpos((string) $location, 'sent=1') !== false);
$records = spool_records($home . '/viava-private');
check('the inquiry is saved beside the web root, by default', count(of_type($records, 'lead')) === 1);
check('nothing is saved inside the web root', glob($root . '/leads-*') === [] && !is_dir($root . '/viava-private'));
$messages = glob($mailDir . '/*.eml');
check('contact form sends through the mail settings above the web root', count($messages) >= 1 && is_file($mailDir . '/auth.log')
    && strpos((string) base64_decode(trim((string) file_get_contents($mailDir . '/auth.log'))), 'release-test@example.com') !== false);
$found = false;
foreach ($messages as $file) {
    $found = $found || strpos((string) file_get_contents($file), 'hero-workspace') !== false || stripos((string) file_get_contents($file), 'multipart') !== false;
}
check('the branded email template loads from the release', $found);

$count = count($messages);
[$status, $location] = request($sitePort, 'resource-handler.php', ['resource' => 'sop-starter-kit', 'email' => 'reader@example.com', 'consent' => 'yes']);
check('resource form succeeds', $status === 302 && strpos((string) $location, 'viava-resources') !== false && strpos((string) $location, 'error') === false);
check('resource form sends mail through the same settings', count(glob($mailDir . '/*.eml')) > $count);

// --- Isolation --------------------------------------------------------------

// Not in the repository at all. README.md, .gitignore and the mail example are, and
// are hidden by .htaccess, which this local server doesn't apply; tests/repo-contents.php
// checks those rules instead.
foreach (['agency-hq/package.json', 'agency-hq/src/index.ts', 'tests/run.php', 'mail-config.php', '_private/01-audit-report.md', 'package.json', 'wrangler.jsonc', 'release/website.mjs', 'viava-private/crm-config.php'] as $path) {
    [$status] = request($sitePort, $path);
    check("{$path} isn't served", $status === 404);
}
[$status] = request($sitePort, 'tools/drain-spool.php');
check('the replay job refuses a web request, without any .htaccess help', $status === 404);
foreach (['lib/viava-mailer.php', 'lib/viava-lead-spool.php', 'lib/viava-worker-client.php', 'lib/PHPMailer/PHPMailer.php', 'email-templates/contact-confirmation.php', 'email-templates/resource-delivery.php'] as $path) {
    $body = (string) @file_get_contents("http://127.0.0.1:{$sitePort}/{$path}");
    check("{$path} shows nothing when requested directly", trim($body) === '');
}
$body = (string) @file_get_contents("http://127.0.0.1:{$sitePort}/index.html");
check('the home page shows no sign of the mail password', strpos($body, 'fake-local-only') === false);

// --- Mail settings ----------------------------------------------------------

check('mail-config.php above the web root is unchanged', sha1_file($home . '/mail-config.php') === $before);
stop_process($server);
stop_process($smtp);

$replay = [];
exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($root . '/tools/drain-spool.php') . ' 2>&1', $replay, $replayExit);
check('the replay job finds its folder from inside the release, and asks for its config', $replayExit === 2 && strpos(implode(' ', $replay), 'unavailable') !== false);

remove_dir($home);
remove_dir($mailDir);
if ($cloned !== null) {
    remove_dir($cloned);
}
finish();
