<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/interruptions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function interruption_response($code, $data)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}
$role = $_SESSION['role'] ?? '';
$userId = $_SESSION['user_id'] ?? null;
if (empty($_SESSION['logged_in']) || !$userId) interruption_response(401, ['ok' => false, 'error' => 'Sign in first.']);
if (!in_array($role, ['admin','technician','subscriber'], true)) interruption_response(403, ['ok' => false, 'error' => 'Access denied.']);
try {
    $account = geonet_pdo()->prepare('SELECT id FROM users WHERE id = ? AND role = ? AND is_active = 1');
    $account->execute([$userId, $role]);
    if (!$account->fetchColumn()) interruption_response(403, ['ok' => false, 'error' => 'Your account is not active.']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) interruption_response(400, ['ok' => false, 'error' => 'Expected a JSON object.']);
        $token = $input['csrfToken'] ?? null;
        if (!is_string($token) || empty($_SESSION['profile_csrf_token']) || !hash_equals($_SESSION['profile_csrf_token'], $token)) {
            interruption_response(403, ['ok' => false, 'error' => 'Refresh the page and try again.']);
        }
        if (!is_string($input['action'] ?? null)) interruption_response(422, ['ok' => false, 'error' => 'Choose an action.']);
        geonet_interruption_action($input['id'] ?? null, $role, $userId, $input['action'], $input);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET, POST');
        interruption_response(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    geonet_expire_interruption_confirmations();
    $technicians = [];
    if ($role === 'admin') {
        $technicians = geonet_pdo()->query("SELECT t.id, t.full_name, t.availability FROM technicians t
            JOIN users u ON u.id = t.user_id WHERE u.role = 'technician' AND u.is_active = 1 ORDER BY t.full_name")->fetchAll();
    }
    $heartbeat = dirname(__DIR__) . '/runtime/interruption-worker.heartbeat';
    $local = getenv('RENDER') !== 'true' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true);
    $workerActive = is_file($heartbeat) && (time() - (int) file_get_contents($heartbeat)) < 90;
    interruption_response(200, ['ok' => true, 'reports' => geonet_interruption_list($role, $userId), 'technicians' => $technicians,
        'localWorkerExpected' => $local, 'localWorkerActive' => $workerActive]);
} catch (InvalidArgumentException $e) {
    interruption_response(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Interruption workflow: ' . $e->getMessage());
    interruption_response(500, ['ok' => false, 'error' => 'Could not update interruption reports. Check the PHP error log.']);
}
