<?php
/**
 * Hostinger deploys everything tracked in this repository into public_html. This
 * fails if anything tracked doesn't belong on a public web host: Agency HQ code,
 * Node or build files, local databases, private notes, secrets or credentials.
 *
 *   php tests/repo-contents.php
 *
 * Agency HQ has its own private repository. Nothing from it belongs here.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/helpers.php';

$root = dirname(__DIR__);
exec('git -C ' . escapeshellarg($root) . ' ls-files -z', $out, $code);
if ($code !== 0) {
    fwrite(STDERR, "git ls-files failed\n");
    exit(2);
}
$files = array_values(array_filter(explode("\0", implode("\n", $out)), 'strlen'));

// What a public website deploy may contain. Anything else stops the check, so a new
// file gets a decision instead of quietly going live.
$allowed = [
    '#^(index|blog-[a-z0-9-]+|viava-[a-z0-9-]+)\.html$#',
    '#^viava-[a-z0-9-]+\.css$#',
    '#^(contact|resource)-handler\.php$#',
    '#^(favicon\.ico|site\.webmanifest|\.htaccess|\.gitignore|\.gitattributes|README\.md|mail-config\.example\.php)$#',
    '#^assets/[a-z0-9/_-]+\.(jpg|jpeg|png|webp|svg|ico|pdf)$#i',
    '#^assets/downloads/README\.md$#',
    '#^email-assets/[a-z0-9_-]+\.(jpg|jpeg|png|gif)$#i',
    '#^email-templates/[a-z0-9-]+\.php$#',
    '#^lib/viava-[a-z-]+\.php$#',
    '#^lib/PHPMailer/(Exception|PHPMailer|SMTP)\.php$#',
    '#^lib/PHPMailer/LICENSE$#',
    '#^tests/[a-z-]+\.php$#',
    '#^tools/[a-z-]+\.php$#',
    '#^tools/git-hooks/pre-push$#',
];
// Never here, whatever the rules above say.
$forbidden = [
    '#(^|/)(agency-hq|node_modules|_private|\.wrangler|\.release|release|src|migrations|e2e|docs|scripts|public_html|recovery)(/|$)#i',
    '#(^|/)(package(-lock)?\.json|tsconfig[^/]*|vitest[^/]*|wrangler[^/]*|worker-configuration\.d\.ts)$#i',
    '#(^|/)(\.dev\.vars[^/]*|\.env[^/]*|\.demo-login|mail-config\.php|crm-config[^/]*\.php)$#i',
    '#\.(ts|mjs|cjs|map|sqlite3?|db|sql|csv|eml|jsonl|log|bak|zip|tar|gz|7z|pem|key|p12|pfx)$#i',
];

$unexpected = [];
$prohibited = [];
foreach ($files as $file) {
    foreach ($forbidden as $pattern) {
        if (preg_match($pattern, $file)) {
            $prohibited[] = $file;
            continue 2;
        }
    }
    $ok = false;
    foreach ($allowed as $pattern) {
        $ok = $ok || preg_match($pattern, $file) === 1;
    }
    if (!$ok) {
        $unexpected[] = $file;
    }
}
check('no Agency HQ, Node, database, private or secret files are tracked' . ($prohibited ? ': ' . implode(', ', array_slice($prohibited, 0, 5)) : ''), $prohibited === []);
check('every tracked file is one a public website deploy may contain' . ($unexpected ? ': ' . implode(', ', array_slice($unexpected, 0, 5)) : ''), $unexpected === []);

// Nothing tracked holds a credential.
$secretPatterns = ['/-----BEGIN [A-Z ]*PRIVATE KEY-----/', '/\b(ghp|gho|ghs|github_pat)_[A-Za-z0-9_]{20,}/', '/\bsk-[A-Za-z0-9_-]{20,}/',
    '/\b(FORM_API_SECRET_K\d|HQ_SETUP_TOKEN|HQ_ENCRYPTION_KEY)\s*=/', "/['\"](password|secret)['\"]\\s*=>\\s*['\"](?!PASTE_|\\.\\.\\.)[^'\"]{6,}['\"]/i"];
$leaks = [];
foreach ($files as $file) {
    if (!preg_match('#\.(php|html|css|md|webmanifest)$|^\.htaccess$|^\.gitignore$|pre-push$#', $file) || strpos($file, 'tests/') === 0) {
        continue;
    }
    $text = (string) file_get_contents($root . '/' . $file);
    foreach ($secretPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            $leaks[] = $file;
        }
    }
}
check('no tracked file holds a credential' . ($leaks ? ': ' . implode(', ', array_unique($leaks)) : ''), $leaks === []);

// Files that ship but aren't for visitors are blocked by .htaccess, and their code
// refuses web requests as well. Both layers are checked here.
$htaccess = (string) file_get_contents($root . '/.htaccess');
check('.htaccess blocks lib, tests, tools and email-templates', (bool) preg_match('#\^/\(lib\|tests\|tools\|email-templates#', $htaccess));
check('.htaccess blocks README, .gitignore, .gitattributes, .htaccess and the mail example', strpos($htaccess, 'readme\.md|\.gitignore|\.gitattributes|\.htaccess|mail-config\.example\.php') !== false);
check('.htaccess blocks .git', strpos($htaccess, '^/\.git(/|$)') !== false);
$unguarded = [];
foreach ($files as $file) {
    if (preg_match('#^(tests|tools)/[a-z-]+\.php$#', $file) && strpos((string) file_get_contents($root . '/' . $file), "PHP_SAPI !== 'cli'") === false) {
        $unguarded[] = $file;
    }
}
check('every script in tests/ and tools/ refuses web requests' . ($unguarded ? ': ' . implode(', ', $unguarded) : ''), $unguarded === []);

finish();
