<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require dirname(__DIR__) . '/config/database.php';
if (!in_array($config['host'], ['127.0.0.1','localhost','::1'], true)) throw new RuntimeException('Local MySQL only.');
$testDb = 'geonet_repairs_test_' . bin2hex(random_bytes(6));
$server = new PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'], $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
putenv('DB_NAME=' . $testDb);
require dirname(__DIR__) . '/includes/sms-inbox.php';
require dirname(__DIR__) . '/includes/network-activity.php';
function repair_check($condition, $message)
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
function repair_denied($callback, $message)
{
    try { $callback(); } catch (InvalidArgumentException $e) { repair_check(true, $message); return; }
    throw new RuntimeException('FAIL: ' . $message);
}
try {
    $pdo = geonet_pdo();
    geonet_run_sql_file($pdo, dirname(__DIR__) . '/sql/schema.sql');
    // Complete legacy/demo migrations before inserting independent test accounts.
    geonet_ensure_mapping_schema();
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    $q = $pdo->prepare('INSERT INTO users (id, email, password_hash, display_name, role) VALUES (?, ?, ?, ?, ?)');
    foreach (['admin','technician','technician','subscriber','subscriber'] as $i => $role) {
        $q->execute([$i + 1, 'test' . $i . '@example.test', password_hash('SmsRepairTest!123', PASSWORD_DEFAULT), 'Test ' . $i, $role]);
    }
    $pdo->exec("INSERT INTO technicians (id, public_id, user_id, full_name, phone, job_title, photo_initials) VALUES
        (1, 'TECH-01', 2, 'Tech one', '09170000001', 'Tech', 'T1'), (2, 'TECH-02', 3, 'Tech two', '09170000002', 'Tech', 'T2')");
    $pdo->exec("INSERT INTO barangays (id, name, lat, lng) VALUES (1, 'Test', 10, 122)");
    $pdo->exec("INSERT INTO subscribers (id, public_id, user_id, barangay_id, full_name, address, phone, plan_name, lat, lng, connected_on) VALUES
        (1, 'SUB-1008', 4, 1, 'Subscriber one', 'Test', '09171234567', 'Test', 10, 122, CURRENT_DATE),
        (2, 'SUB-1009', 5, 1, 'Subscriber two', 'Test', '09171234568', 'Test', 10, 122, CURRENT_DATE)");
    $smsSequence = 0;
    $sms = function ($command) use (&$smsSequence) { return geonet_process_simulated_sms('09171234567', $command . ' SUB-1008', 'repair-test-' . ++$smsSequence); };
    $get = function ($id) use ($pdo) { $q = $pdo->prepare('SELECT * FROM service_interruptions WHERE id = ?'); $q->execute([$id]); return $q->fetch(); };
    $connectionStatus = function () use ($pdo) { return $pdo->query('SELECT status FROM subscribers WHERE id = 1')->fetchColumn(); };
    $sms('DOWN');
    $id = (int) $pdo->query('SELECT id FROM service_interruptions')->fetchColumn();
    repair_check($get($id)['status'] === 'reported', 'SMS DOWN creates an assignable interruption');
    $sms('DOWN');
    repair_check((int) $pdo->query('SELECT COUNT(*) FROM service_interruptions')->fetchColumn() === 1, 'repeated DOWN keeps one active report');
    repair_denied(fn() => geonet_interruption_action($id, 'subscriber', 5, 'confirm'), 'another subscriber cannot confirm restoration');
    repair_denied(fn() => geonet_interruption_action($id, 'admin', 1, 'confirm'), 'administrator cannot confirm restoration');
    geonet_interruption_action($id, 'admin', 1, 'assign', ['technicianId' => 1]);
    repair_check($get($id)['status'] === 'assigned', 'administrator assigns technician');
    repair_denied(fn() => geonet_interruption_action($id, 'technician', 3, 'accept'), 'unassigned technician cannot accept');
    repair_denied(fn() => geonet_interruption_action($id, 'technician', 2, 'complete'), 'technician cannot skip acceptance and on-route steps');
    geonet_interruption_action($id, 'technician', 2, 'accept');
    geonet_interruption_action($id, 'technician', 2, 'en_route');
    geonet_interruption_action($id, 'technician', 2, 'complete');
    $r = $get($id);
    $delta = $pdo->query('SELECT TIMESTAMPDIFF(SECOND, completed_at, confirmation_due_at) FROM service_interruptions WHERE id = ' . $id)->fetchColumn();
    repair_check($r['status'] === 'awaiting_confirmation' && (int) $delta === 18000 && $connectionStatus() === 'offline', 'completion starts exactly five hours and keeps connection offline');
    repair_check(geonet_expire_interruption_confirmations() === 0, 'worker cannot close before deadline');
    repair_denied(fn() => geonet_interruption_action($id, 'technician', 2, 'confirm'), 'technician cannot verify restoration');
    repair_denied(fn() => geonet_interruption_action($id, 'admin', 1, 'assign', ['technicianId' => 2]), 'awaiting-confirmation report cannot be reassigned');
    geonet_interruption_action($id, 'subscriber', 4, 'still_down');
    repair_check($get($id)['status'] === 'accepted' && $get($id)['confirmation_due_at'] === null && $connectionStatus() === 'offline', 'subscriber still-down response cancels countdown and reopens repair');
    geonet_interruption_action($id, 'technician', 2, 'en_route');
    geonet_interruption_action($id, 'technician', 2, 'complete');
    $sms('DOWN');
    repair_check($get($id)['status'] === 'accepted' && $get($id)['confirmation_due_at'] === null, 'SMS DOWN also cancels pending automatic confirmation');
    geonet_interruption_action($id, 'technician', 2, 'en_route');
    geonet_interruption_action($id, 'technician', 2, 'complete');
    geonet_interruption_action($id, 'subscriber', 4, 'confirm');
    repair_check($get($id)['status'] === 'resolved' && $get($id)['resolution_method'] === 'subscriber_portal' && $connectionStatus() === 'online', 'owning subscriber confirms restoration through portal');
    repair_denied(fn() => geonet_interruption_action($id, 'technician', 2, 'complete'), 'closed repair cannot be completed again');
    $sms('DOWN');
    $id = (int) $pdo->query('SELECT MAX(id) FROM service_interruptions')->fetchColumn();
    repair_check($get($id)['status'] === 'reported', 'later interruption creates a separate report');
    geonet_interruption_action($id, 'admin', 1, 'assign', ['technicianId' => 1]);
    geonet_interruption_action($id, 'admin', 1, 'assign', ['technicianId' => 2]);
    repair_denied(fn() => geonet_interruption_action($id, 'technician', 2, 'accept'), 'previous technician loses access after reassignment');
    geonet_interruption_action($id, 'technician', 3, 'decline', ['note' => 'Not available']);
    repair_check($get($id)['status'] === 'reported' && $get($id)['assigned_technician_id'] === null, 'decline returns report for assignment');
    geonet_interruption_action($id, 'admin', 1, 'assign', ['technicianId' => 1]);
    geonet_interruption_action($id, 'technician', 2, 'accept');
    geonet_interruption_action($id, 'technician', 2, 'not_completed', ['note' => 'Needs replacement cable']);
    repair_check($get($id)['status'] === 'reported' && $get($id)['technician_note'] === 'Needs replacement cable', 'incomplete repair retains reason for administrator');
    geonet_interruption_action($id, 'admin', 1, 'assign', ['technicianId' => 2]);
    geonet_interruption_action($id, 'technician', 3, 'accept');
    geonet_interruption_action($id, 'technician', 3, 'en_route');
    geonet_interruption_action($id, 'technician', 3, 'complete');
    $pdo->exec('UPDATE service_interruptions SET confirmation_due_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ' . $id);
    repair_check(geonet_expire_interruption_confirmations() === 1, 'expired five-hour confirmation is automatically processed');
    repair_check($get($id)['resolution_method'] === 'automatic' && $get($id)['active_subscriber_id'] === null && $connectionStatus() === 'online', 'automatic closure records its source and restores connection status');
    repair_check(geonet_expire_interruption_confirmations() === 0, 'automatic confirmation is idempotent');
    $sms('DOWN');
    $id = (int) $pdo->query('SELECT MAX(id) FROM service_interruptions')->fetchColumn();
    $sms('RESTORED');
    repair_check($get($id)['status'] === 'resolved' && $get($id)['resolution_method'] === 'subscriber_sms', 'registered subscriber SMS confirms restoration');
    repair_check(count(geonet_interruption_list('subscriber', 5)) === 0, 'subscriber report list hides other accounts');
    repair_check(count(geonet_interruption_list('subscriber', 4)) === 3, 'owner can see current and historical reports');
    repair_check(count(geonet_interruption_list('technician', 2)) === 1 && count(geonet_interruption_list('technician', 3)) === 1, 'technician lists contain only their assigned reports');
    repair_check((int) $pdo->query('SELECT COUNT(*) FROM service_interruption_events')->fetchColumn() > 20, 'workflow history records assignment, completion, subscriber response, and auto-confirmation');

    // Exercise real HTTP sessions, role filters, and CSRF on an isolated local server.
    $sms('DOWN');
    $httpReportId = (int) $pdo->query('SELECT MAX(id) FROM service_interruptions')->fetchColumn();
    $pdo->exec("INSERT INTO subscription_requests (full_name, email, password_hash, barangay_id, address, phone, plan_name, lat, lng)
        VALUES ('New service test', 'new-service@example.test', 'test-only', 1, 'Test', '09171234569', 'Test plan', 10, 122)");
    $subscriptionId = (int) $pdo->lastInsertId();
    $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    if (!$socket) throw new RuntimeException('Could not allocate test HTTP port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $httpLog = tempnam(sys_get_temp_dir(), 'geonet-repairs-');
    $sessionDirectory = dirname(__DIR__) . '/runtime/repair-http-sessions-' . bin2hex(random_bytes(6));
    mkdir($sessionDirectory, 0700, true);
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionDirectory, '-S', $address, '-t', dirname(__DIR__)],
        [0 => ['pipe','r'], 1 => ['file', $httpLog, 'a'], 2 => ['file', $httpLog, 'a']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) throw new RuntimeException('Could not start isolated HTTP server.');
    fclose($pipes[0]);
    try {
        $http = function ($path, $method = 'GET', $body = null, $cookie = '', $json = true) use ($address) {
            $headers = 'Content-Type: ' . ($json ? 'application/json' : 'application/x-www-form-urlencoded') . "\r\n";
            if ($cookie) $headers .= 'Cookie: ' . $cookie . "\r\n";
            $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers,
                'content' => $body === null ? '' : ($json ? json_encode($body) : http_build_query($body)),
                'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5]]);
            $text = file_get_contents('http://' . $address . '/' . $path, false, $context);
            preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
            foreach ($http_response_header as $header) {
                if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i', $header, $match)) $cookie = $match[1];
            }
            return [(int) ($status[1] ?? 0), $text, $cookie];
        };
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $probe = @stream_socket_client('tcp://' . $address, $probeError, $probeMessage, 0.1);
            if ($probe) { fclose($probe); break; }
            usleep(100000);
        }
        [$status] = $http('api/interruptions.php');
        repair_check($status === 401, 'HTTP API rejects unauthenticated requests');
        $login = function ($email) use ($http, $httpLog) {
            [$status, $text, $cookie] = $http('index.php', 'POST', ['username' => $email, 'password' => 'SmsRepairTest!123'], '', false);
            if ($status !== 302) throw new RuntimeException('Isolated test login failed.');
            [$status, $text] = $http('interruptions.php', 'GET', null, $cookie);
            if ($status !== 200 || !preg_match('/window\.geonetReports = (.*);/', $text, $match)) throw new RuntimeException('Could not load interruption portal. HTTP ' . $status . ': ' . substr(strip_tags($text), 0, 250) . ' Log: ' . file_get_contents($httpLog));
            return [$cookie, json_decode($match[1], true)['csrfToken']];
        };
        [$adminCookie, $adminToken] = $login('test0@example.test');
        [$status, $body] = $http('api/notifications.php', 'GET', null, $adminCookie);
        $activity = json_decode($body, true)['activity'] ?? [];
        $reportActivity = array_values(array_filter($activity, fn($item) => ($item['interruption_id'] ?? null) === $httpReportId));
        repair_check($status === 200 && count($reportActivity) === 1 && str_contains($reportActivity[0]['message'], 'SUB-1008'), 'Network Activity contains one interruption summary per report');
        repair_check($reportActivity[0]['link'] === 'dashboard.php?interruption=' . $httpReportId . '#dashboard', 'interruption activity links to the subscriber map popup');
        $subscriptionActivity = array_values(array_filter($activity, fn($item) => ($item['subscription_request_id'] ?? null) === $subscriptionId));
        repair_check(count($subscriptionActivity) === 1 && $subscriptionActivity[0]['assignment']['kind'] === 'subscription'
            && !$subscriptionActivity[0]['assignment']['assigned'], 'subscription activity exposes an Assign technician action');
        repair_check($reportActivity[0]['assignment']['kind'] === 'interruption'
            && !$reportActivity[0]['assignment']['assigned'], 'interruption activity exposes an Assign technician action');
        [$status] = $http('api/notifications.php', 'POST', ['action' => 'assign_technician', 'kind' => 'subscription', 'id' => $subscriptionId, 'technicianId' => 1], $adminCookie);
        repair_check($status === 403, 'activity assignments require a CSRF token');
        [$status, $assignmentBody] = $http('api/notifications.php', 'POST', ['action' => 'assign_technician', 'kind' => 'subscription', 'id' => $subscriptionId,
            'technicianId' => 1, 'csrfToken' => $adminToken], $adminCookie);
        if ($status !== 200) throw new RuntimeException('Activity assignment HTTP ' . $status . ': ' . $assignmentBody . ' Log: ' . file_get_contents($httpLog));
        repair_check($status === 200 && (int) $pdo->query('SELECT assigned_technician_id FROM subscription_requests WHERE id = ' . $subscriptionId)->fetchColumn() === 1,
            'administrator assigns subscription technician directly from activity');
        [$status] = $http('api/notifications.php', 'POST', ['action' => 'assign_technician', 'kind' => 'subscription', 'id' => $subscriptionId,
            'technicianId' => 2, 'csrfToken' => $adminToken], $adminCookie);
        repair_check($status === 200 && (int) $pdo->query('SELECT assigned_technician_id FROM subscription_requests WHERE id = ' . $subscriptionId)->fetchColumn() === 2,
            'administrator reassigns subscription technician directly from activity');
        [$status, $body] = $http('api/notifications.php', 'GET', null, $adminCookie);
        $assignedActivity = array_values(array_filter(json_decode($body, true)['activity'], fn($item) => ($item['subscription_request_id'] ?? null) === $subscriptionId));
        repair_check($assignedActivity[0]['assignment']['assigned'] && str_contains($assignedActivity[0]['message'], 'Tech two'), 'subscription activity refreshes current technician and Reassign action');
        $pdo->exec("UPDATE subscription_requests SET technician_work_status = 'completed' WHERE id = " . $subscriptionId);
        [$status] = $http('api/notifications.php', 'POST', ['action' => 'assign_technician', 'kind' => 'subscription', 'id' => $subscriptionId,
            'technicianId' => 1, 'csrfToken' => $adminToken], $adminCookie);
        repair_check($status === 422, 'activity shortcut cannot reset completed subscription work');
        [$status] = $http('api/interruptions.php', 'POST', ['id' => $httpReportId, 'action' => 'assign', 'technicianId' => 1], $adminCookie);
        repair_check($status === 403, 'HTTP mutations require a CSRF token');
        [$status] = $http('api/interruptions.php', 'POST', ['id' => $httpReportId, 'action' => 'assign', 'technicianId' => 1, 'csrfToken' => $adminToken], $adminCookie);
        repair_check($status === 200 && $get($httpReportId)['status'] === 'assigned', 'HTTP administrator can assign a repair');
        [$status] = $http('api/notifications.php', 'POST', ['action' => 'assign_technician', 'kind' => 'interruption', 'id' => $httpReportId,
            'technicianId' => 2, 'csrfToken' => $adminToken], $adminCookie);
        repair_check($status === 200 && (int) $get($httpReportId)['assigned_technician_id'] === 2, 'administrator assigns interruption technician directly from activity');
        [$status] = $http('api/notifications.php', 'POST', ['action' => 'assign_technician', 'kind' => 'interruption', 'id' => $httpReportId,
            'technicianId' => 1, 'csrfToken' => $adminToken], $adminCookie);
        repair_check($status === 200 && (int) $get($httpReportId)['assigned_technician_id'] === 1, 'administrator reassigns interruption technician directly from activity');
        [$status, $body] = $http('api/interruptions.php', 'GET', null, $adminCookie);
        $mapData = json_decode($body, true);
        $mapReport = array_values(array_filter($mapData['reports'], fn($row) => (int) $row['id'] === $httpReportId))[0];
        repair_check($mapReport['connection_id'] === 'SUB-1008' && $mapReport['technician_name'] === 'Tech one' && count($mapData['technicians']) === 2, 'map popup receives connection, assignment, and active technician options');
        [$status] = $http('api/interruptions.php', 'POST', ['id' => $httpReportId, 'action' => 'confirm', 'csrfToken' => $adminToken], $adminCookie);
        repair_check($status === 422 && $connectionStatus() === 'offline', 'HTTP administrator cannot verify restoration');
        [$techCookie, $techToken] = $login('test1@example.test');
        [$status] = $http('api/notifications.php', 'POST', ['action' => 'assign_technician', 'kind' => 'interruption', 'id' => $httpReportId,
            'technicianId' => 2, 'csrfToken' => $techToken], $techCookie);
        repair_check($status === 403, 'technicians cannot use activity assignment actions');
        [$status] = $http('api/interruptions.php', 'POST', ['id' => $httpReportId, 'action' => 'accept', 'csrfToken' => $techToken], $techCookie);
        repair_check($status === 200 && $get($httpReportId)['status'] === 'accepted', 'HTTP assigned technician can accept repair');
        [$otherCookie, $otherToken] = $login('test4@example.test');
        [$status, $body] = $http('api/interruptions.php', 'GET', null, $otherCookie);
        repair_check($status === 200 && json_decode($body, true)['reports'] === [], 'HTTP subscriber cannot list another account reports');
        [$status] = $http('api/interruptions.php', 'POST', ['id' => $httpReportId, 'action' => 'confirm', 'csrfToken' => $otherToken], $otherCookie);
        repair_check($status === 422, 'HTTP subscriber cannot confirm another account repair');
        [$ownerCookie, $ownerToken] = $login('test3@example.test');
        [$status] = $http('api/interruptions.php', 'POST', ['id' => $httpReportId, 'action' => 'confirm', 'csrfToken' => $ownerToken], $ownerCookie);
        repair_check($status === 200 && $get($httpReportId)['resolution_method'] === 'subscriber_portal', 'HTTP owning subscriber can confirm restoration');
        [$status] = $http('api/notifications.php', 'POST', ['action'=>'clear_one','notificationId'=>1]);
        repair_check($status===401, 'individual notification dismissal requires authentication');
        foreach ([[$adminCookie,$adminToken,1],[$techCookie,$techToken,2],[$ownerCookie,$ownerToken,4]] as [$cookie,$token,$uid]) {
            geonet_notify_user($uid,'test','Dismiss test','Only this notification should be removed.');
            $notificationId=(int)$pdo->lastInsertId();
            [$status,$beforeBody]=$http('api/notifications.php','GET',null,$cookie);
            $beforeCount=json_decode($beforeBody,true)['unread_count'];
            [$status]=$http('api/notifications.php','POST',['action'=>'clear_one','notificationId'=>$notificationId],$cookie);
            repair_check($status===403,'individual dismissal requires a CSRF token for user '.$uid);
            [$status]=$http('api/notifications.php','POST',['action'=>'clear_one','notificationId'=>$notificationId,'csrfToken'=>$otherToken],$otherCookie);
            repair_check($status===422 && (int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE id='.$notificationId)->fetchColumn()===1,'another account cannot dismiss notification for user '.$uid);
            [$status]=$http('api/notifications.php','POST',['action'=>'clear_one','notificationId'=>$notificationId,'csrfToken'=>$token],$cookie);
            [$readStatus,$afterBody]=$http('api/notifications.php','GET',null,$cookie);
            repair_check($status===200 && json_decode($afterBody,true)['unread_count']===$beforeCount-1,'owner can dismiss individual notification and unread count updates for user '.$uid);
        }
        [$status]=$http('api/notifications.php','POST',['action'=>'clear_one','activityKey'=>'interruption:'.$httpReportId,'csrfToken'=>$adminToken],$adminCookie);
        [$status,$body]=$http('api/notifications.php','GET',null,$adminCookie);
        $remaining=array_filter(json_decode($body,true)['activity'],fn($item)=>($item['interruption_id']??null)===$httpReportId);
        repair_check($status===200 && count($remaining)===0 && $get($httpReportId)!==false,'dismissed activity stays hidden while underlying repair is preserved');
        $newUpdate=[['activity_key'=>'interruption:'.$httpReportId,'created_at'=>'2999-01-01T00:00:00']];
        repair_check(count(geonet_filter_dismissed_activity(1,$newUpdate))===1,'new activity after an individual dismissal can appear again');
        $oldUpdate=[['activity_key'=>'interruption:'.$httpReportId,'created_at'=>'2000-01-01T00:00:00']];
        repair_check(count(geonet_filter_dismissed_activity(2,$oldUpdate))===1,'activity dismissal is scoped to the current user');
        [$status] = $http('api/notifications.php', 'POST', ['action' => 'clear_all', 'csrfToken' => $adminToken], $adminCookie);
        [$status, $body] = $http('api/notifications.php', 'GET', null, $adminCookie);
        $remaining = array_filter(json_decode($body, true)['activity'] ?? [], fn($item) => $item['type'] === 'interruption');
        repair_check($status === 200 && count($remaining) === 0, 'clearing Network Activity also clears synthesized interruption entries');
    } finally {
        proc_terminate($process);
        proc_close($process);
        unlink($httpLog);
        foreach (glob($sessionDirectory . '/sess_*') as $sessionFile) unlink($sessionFile);
        rmdir($sessionDirectory);
    }
} finally {
    if (!preg_match('/^geonet_repairs_test_[a-f0-9]{12}$/D', $testDb)) throw new RuntimeException('Unsafe test database name');
    $server->exec("DROP DATABASE `{$testDb}`");
    echo 'Temporary test database removed.' . PHP_EOL;
}
