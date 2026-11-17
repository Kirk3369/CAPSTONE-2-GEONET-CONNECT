<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/settings.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function settings_reply($code, $data) { http_response_code($code); echo json_encode($data); exit; }
if (empty($_SESSION['logged_in'])) settings_reply(401, ['ok' => false, 'error' => 'Sign in first.']);
if (($_SESSION['role'] ?? '') !== 'admin') settings_reply(403, ['ok' => false, 'error' => 'Admin access required.']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); settings_reply(405, ['ok' => false]); }
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) settings_reply(400, ['ok' => false, 'error' => 'Invalid request.']);
$token = $input['csrfToken'] ?? null;
if (!is_string($token) || empty($_SESSION['profile_csrf_token']) || !hash_equals($_SESSION['profile_csrf_token'], $token)) settings_reply(403, ['ok' => false, 'error' => 'Refresh the page and try again.']);
try {
    $values = geonet_map_settings($input);
    $pdo = geonet_pdo();
    $pdo->beginTransaction();
    geonet_set_setting('map_center_lat', (string) $values['lat']);
    geonet_set_setting('map_center_lng', (string) $values['lng']);
    geonet_set_setting('map_default_zoom', (string) $values['zoom']);
    $pdo->commit();
    settings_reply(200, ['ok' => true, 'message' => 'Map defaults saved.', 'settings' => $values]);
} catch (InvalidArgumentException $e) {
    settings_reply(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    settings_reply(500, ['ok' => false, 'error' => 'Could not save settings.']);
}
