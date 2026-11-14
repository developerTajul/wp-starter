<?php

declare(strict_types=1);

/**
 * Reads the tail of the WordPress debug log and greps it for
 * admin-post.php fatals. Read-only; deletes nothing.
 *
 * Usage: php tools/probe-wsd.php [lines]
 */

$pluginDir = dirname(__DIR__);
$logPath = dirname($pluginDir, 3) . '/wp-content/debug.log';
$lines = (int) ($argv[1] ?? 40);

echo "plugin dir : {$pluginDir}\n";
echo "log path   : {$logPath}\n";
echo 'log exists : ' . (is_file($logPath) ? 'yes' : 'NO') . "\n";

if (! is_file($logPath)) {
    echo "\nNo debug.log. Ensure wp-config.php has:\n";
    echo "  define('WP_DEBUG', true);\n  define('WP_DEBUG_LOG', true);\n";
    exit(1);
}

$size = filesize($logPath);
echo "log size   : {$size} bytes\n";
echo 'modified   : ' . date('Y-m-d H:i:s', (int) filemtime($logPath)) . "\n";

$handle = fopen($logPath, 'rb');
if ($handle === false) {
    echo "Cannot open log.\n";
    exit(1);
}

fseek($handle, 0, SEEK_END);
$buffer = '';
$chunkSize = 4096;
$position = $size;

while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
    $read = min($chunkSize, $position);
    $position -= $read;
    fseek($handle, $position);
    $buffer = fread($handle, $read) . $buffer;
}

fclose($handle);

$all = explode("\n", rtrim($buffer, "\n"));
$tail = array_slice($all, -$lines);

echo "\n=== LAST {$lines} LINES ===\n";
foreach ($tail as $line) {
    echo $line . "\n";
}

echo "\n=== FATALS mentioning admin-post / company store ===\n";
$matched = 0;
foreach ($all as $line) {
    if (preg_match('/Fatal|unhandled/i', $line) !== 1) {
        continue;
    }
    if (stripos($line, 'admin-post') === false && stripos($line, 'CompanyController') === false) {
        continue;
    }
    $matched++;
    echo $line . "\n";
}

echo "matched: {$matched}\n";
