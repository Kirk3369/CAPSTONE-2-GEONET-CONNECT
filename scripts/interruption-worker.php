<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/interruptions.php';
$watch = in_array('--watch', $argv, true);
$runtime = dirname(__DIR__) . '/runtime';
if (!is_dir($runtime)) mkdir($runtime, 0700, true);
$lock = fopen($runtime . '/interruption-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Interruption worker is already running.\n"); exit(1); }
file_put_contents($runtime . '/interruption-worker.pid', (string) getmypid());
try {
    do {
        try {
            $closed = geonet_expire_interruption_confirmations();
            file_put_contents($runtime . '/interruption-worker.heartbeat', (string) time(), LOCK_EX);
            if ($closed || !$watch) echo date('c') . ': Auto-confirmed ' . $closed . " interruption(s).\n";
        } catch (Throwable $e) {
            fwrite(STDERR, date('c') . ': ' . $e->getMessage() . "\n");
            if (!$watch) exit(1);
        }
        if ($watch) sleep(30);
    } while ($watch && !file_exists($runtime . '/interruption-worker.stop'));
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
    if (is_file($runtime . '/interruption-worker.pid')) unlink($runtime . '/interruption-worker.pid');
}
