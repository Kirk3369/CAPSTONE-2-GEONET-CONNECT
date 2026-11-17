<?php
require_once dirname(__DIR__) . '/includes/sms-inbox.php';
require_once dirname(__DIR__) . '/includes/smsgate.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function smsgate_reply($status, $body)
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    smsgate_reply(405, ['ok' => false]);
}
$raw = file_get_contents('php://input', false, null, 0, 32769);
if ($raw === false || strlen($raw) > 32768) smsgate_reply(413, ['ok' => false]);
try {
    $config = geonet_sms_config();
    if (!$config['enabled'] || $config['signing_key'] === '') smsgate_reply(503, ['ok' => false, 'error' => 'Incoming SMS is not configured.']);
    if (!geonet_smsgate_verify($config['signing_key'], $raw, $_SERVER['HTTP_X_TIMESTAMP'] ?? '', $_SERVER['HTTP_X_SIGNATURE'] ?? '')) {
        smsgate_reply(401, ['ok' => false, 'error' => 'Invalid webhook signature or timestamp.']);
    }
    $message = geonet_smsgate_incoming(json_decode($raw, true), $config['device_id']);
    $result = geonet_process_incoming_sms($message['sender'], $message['message'], $message['id'], 'smsgate');
    smsgate_reply(200, ['ok' => true, 'duplicate' => $result['duplicate']]);
} catch (InvalidArgumentException $e) {
    smsgate_reply(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    smsgate_reply(500, ['ok' => false, 'error' => 'Could not process incoming SMS.']);
}
