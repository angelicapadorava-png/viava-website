<?php
/**
 * Re-sends fallback records to the Agency HQ Worker. Run by hand or from cron:
 *
 *   php tools/drain-spool.php
 *
 * Command line only. Safe to repeat: nothing is duplicated or deleted, and the
 * output holds counts only, never personal data.
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

$counts = viava_spool_drain($config, $dir);
printf(
    "sent=%d already_synced=%d failed=%d events=%d\n",
    $counts['sent'], $counts['already'], $counts['failed'], $counts['events']
);
exit($counts['failed'] === 0 ? 0 : 1);
