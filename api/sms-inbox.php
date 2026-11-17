<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/sms-inbox.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function inbox_response($code, $data)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}
if (empty($_SESSION['logged_in'])) inbox_response(401, ['ok' => false, 'error' => 'Sign in first.']);
if (($_SESSION['role'] ?? '') !== 'admin') inbox_response(403, ['ok' => false, 'error' => 'Admin access required.']);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') inbox_response(200, ['ok' => true, 'entries' => geonet_sms_inbox_entries()]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        inbox_response(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    if (!geonet_sms_simulation_allowed()) inbox_response(403, ['ok' => false, 'error' => 'Simulation requires Mock mode and local XAMPP access.']);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) inbox_response(400, ['ok' => false, 'error' => 'Expected a JSON object.']);
    $token = $input['csrfToken'] ?? null;
    if (!is_string($token) || empty($_SESSION['profile_csrf_token']) || !hash_equals($_SESSION['profile_csrf_token'], $token)) {
        inbox_response(403, ['ok' => false, 'error' => 'Refresh the page and try again.']);
    }
    $result = geonet_process_simulated_sms($input['sender'] ?? null, $input['message'] ?? null, $input['messageId'] ?? null);
    inbox_response(200, ['ok' => true] + $result);
} catch (InvalidArgumentException $e) {
    inbox_response(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('SMS inbox: ' . $e->getMessage());
    inbox_response(500, ['ok' => false, 'error' => 'Could not process the SMS. Check the local PHP error log.']);
}
