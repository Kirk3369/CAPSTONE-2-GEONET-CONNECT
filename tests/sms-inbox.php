<?php
// CLI-only integration tests. Creates and removes its own local MySQL database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require dirname(__DIR__) . '/config/database.php';
if (!in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Tests require local MySQL.');
}
$testDb = 'geonet_sms_test_' . bin2hex(random_bytes(6));
$server = new PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'], $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
putenv('DB_NAME=' . $testDb);
require dirname(__DIR__) . '/includes/sms-inbox.php';
function check_sms($condition, $description)
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    echo 'PASS: ' . $description . PHP_EOL;
}
try {
    $pdo = geonet_pdo();
    geonet_run_sql_file($pdo, dirname(__DIR__) . '/sql/schema.sql');
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    $pdo->exec("INSERT INTO users (email, password_hash, display_name, role) VALUES ('sms-test@example.test', 'test-only', 'Test admin', 'admin')");
    $pdo->exec("INSERT INTO barangays (id, name, lat, lng) VALUES (1, 'Test', 10, 122)");
    $insert = $pdo->prepare("INSERT INTO subscribers (public_id, barangay_id, full_name, address, phone, plan_name, lat, lng, connected_on)
        VALUES (?, 1, 'Test', 'Test', ?, 'Test', 10, 122, CURRENT_DATE)");
    $insert->execute(['SUB-1008', '09171234567']);
    $insert->execute(['SUB-1009', '+63 917 123 4567']);
    $state = function ($publicId) use ($pdo) {
        $q = $pdo->prepare('SELECT status FROM subscribers WHERE public_id = ?');
        $q->execute([$publicId]);
        return $q->fetchColumn();
    };
    $counts = function () use ($pdo) { return (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(); };
    $r = geonet_process_simulated_sms('+63 917 123 4567', 'down sub-1008', 'test-1');
    check_sms($r['entry']['result'] === 'processed' && $state('SUB-1008') === 'offline', 'DOWN accepts normalized phone and case-insensitive command');
    check_sms($state('SUB-1009') === 'online', 'another connection sharing the phone is unchanged');
    check_sms($r['entry']['subscriber_id'] == 1 && $counts() === 1, 'numeric subscriber ID and admin notification saved');
    $r = geonet_process_simulated_sms('09171234567', 'down sub-1008', 'test-1');
    check_sms($r['duplicate'] && $counts() === 1, 'duplicate delivery does not repeat notifications');
    try {
        geonet_process_simulated_sms('09171234567', 'RESTORED SUB-1008', 'test-1');
        throw new RuntimeException('Message ID collision was accepted');
    } catch (InvalidArgumentException $e) {
        check_sms($state('SUB-1008') === 'offline', 'reused ID with changed content is rejected');
    }
    $r = geonet_process_simulated_sms('09171234567', 'DOWN SUB-1008', 'test-2');
    check_sms($r['entry']['result'] === 'unchanged' && $counts() === 1, 'repeat DOWN with new ID records unchanged status');
    foreach ([['09999999999', 'RESTORED SUB-1008'], ['09171234567', 'DOWN SUB-9999'], ['09171234567', 'internet down please']] as $i => $input) {
        $r = geonet_process_simulated_sms($input[0], $input[1], 'reject-' . $i);
        check_sms($r['entry']['result'] === 'rejected' && $state('SUB-1008') === 'offline', 'invalid sender, connection, or command is logged without status change (' . $i . ')');
    }
    $r = geonet_process_simulated_sms('9171234567', 'RESTORED SUB-1008', 'test-3');
    check_sms($r['entry']['previous_status'] === 'offline' && $state('SUB-1008') === 'online' && $counts() === 2, 'RESTORED updates status with audit history and notification');
    $r = geonet_process_simulated_sms('09171234567', 'down sub-1008', 'test-1');
    check_sms($r['duplicate'] && $state('SUB-1008') === 'online', 'old duplicate DOWN cannot overwrite restoration');
    try {
        geonet_process_simulated_sms('invalid', 'DOWN SUB-1008', 'invalid-phone');
        throw new RuntimeException('Invalid phone accepted');
    } catch (InvalidArgumentException $e) {
        check_sms(true, 'malformed phone is rejected');
    }
    $_SERVER['REMOTE_ADDR'] = '192.168.1.10';
    check_sms(!geonet_sms_simulation_allowed(), 'simulator rejects remote clients');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    putenv('RENDER=true');
    check_sms(!geonet_sms_simulation_allowed(), 'simulator disabled on Render');
    putenv('RENDER');
    check_sms(geonet_sms_simulation_allowed(), 'simulator available on localhost');
    check_sms(count(geonet_sms_inbox_entries()) === 6, 'inbox contains processed, unchanged, and rejected messages exactly once');
    geonet_save_sms_config(['enabled' => true, 'mock_mode' => false, 'username' => 'gateway', 'password' => 'secret', 'signing_key' => 'webhook-secret']);
    geonet_save_sms_config(['enabled' => true, 'mock_mode' => false, 'username' => 'gateway']);
    check_sms(geonet_sms_config()['password'] === 'secret' && geonet_sms_config()['signing_key'] === 'webhook-secret', 'blank secrets preserve stored SMSGate credentials');
    check_sms(geonet_sms_config()['provider'] === 'smsgate' && !geonet_sms_config()['mock_mode'], 'saved SMSGate configuration enables live mode');
    check_sms(!geonet_sms_simulation_allowed(), 'live mode disables the inbox simulator');
    try {
        geonet_save_sms_config(['username' => "invalid\nusername"]);
        throw new RuntimeException('Invalid username accepted');
    } catch (InvalidArgumentException $e) { check_sms(true, 'line breaks in credentials rejected'); }
    $r = geonet_process_incoming_sms('09171234567', 'DOWN SUB-1008', 'gateway-1', 'smsgate');
    check_sms($r['entry']['source'] === 'smsgate' && $state('SUB-1008') === 'offline', 'live gateway report updates matching connection with gateway source');
    $r = geonet_process_incoming_sms('09171234567', 'DOWN SUB-1008', 'gateway-1', 'smsgate');
    check_sms($r['duplicate'], 'live gateway retry is deduplicated');
    geonet_process_incoming_sms('09171234567', 'RESTORED SUB-1008', 'gateway-2', 'smsgate');
    check_sms($state('SUB-1008') === 'online', 'live gateway restoration updates connection');
} finally {
    if (!preg_match('/^geonet_sms_test_[a-f0-9]{12}$/D', $testDb)) throw new RuntimeException('Unsafe test database cleanup target');
    $server->exec("DROP DATABASE `{$testDb}`");
    echo 'Temporary test database removed.' . PHP_EOL;
}
