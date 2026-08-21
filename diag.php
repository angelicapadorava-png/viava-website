<?php
// Temporary diagnostic — safe to delete, no secrets exposed.
header('Content-Type: text/plain');

echo "__DIR__: " . __DIR__ . "\n";
echo "dirname(__DIR__): " . dirname(__DIR__) . "\n";
echo "open_basedir: " . (ini_get('open_basedir') ?: '(none set)') . "\n";
echo "PHP version: " . PHP_VERSION . "\n\n";

$candidates = [
    dirname(__DIR__) . '/mail-config.php',
    __DIR__ . '/mail-config.php',
];

foreach ($candidates as $path) {
    echo "Checking: {$path}\n";
    echo "  file_exists: " . (file_exists($path) ? 'YES' : 'NO') . "\n";
    echo "  is_readable: " . (is_readable($path) ? 'YES' : 'NO') . "\n";
}
