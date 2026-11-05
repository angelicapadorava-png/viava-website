<?php
/**
 * Re-sends fallback records to the Agency HQ Worker. Run by hand, or from cron:
 *
 *   php tools/drain-spool.php [--limit=200]
 *
 * Suggested cron schedule: every 5 minutes. Command line only. Safe to repeat and
 * to overlap: nothing is duplicated or deleted, and the output holds counts only,
 * never personal data.
 *
 * Exit codes: 0 all sent or nothing to do; 1 some still waiting, retried next run;
 * 2 not configured.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/lib/viava-lead-spool.php';

$dir = viava_private_dir();
$config = viava_crm_config();
if ($dir === null || $config === null) {
    fwrite(STDERR, "Private folder or crm-config.php is unavailable.\n");
    exit(2);
}

$limit = 200;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = max(1, (int) $m[1]);
    }
}

$counts = viava_spool_drain($config, $dir, $limit);
if ($counts['locked']) {
    echo "another run is active; nothing done\n";
    exit(0);
}
printf(
    "sent=%d already_synced=%d failed=%d events=%d pending=%d stopped_early=%s\n",
    $counts['sent'], $counts['already'], $counts['failed'], $counts['events'], $counts['pending'], $counts['stopped_early'] ? 'yes' : 'no'
);
exit($counts['pending'] === 0 ? 0 : 1);
