<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/smsgate.php';
function gate_check($value, $description) {
    if (!$value) throw new RuntimeException($description);
    echo 'PASS: ' . $description . PHP_EOL;
}
$raw = '{"event":"sms:received"}';
$timestamp = '1800000000';
$signature = hash_hmac('sha256', $raw . $timestamp, 'secret');
gate_check(geonet_smsgate_verify('secret', $raw, $timestamp, $signature, 1800000000), 'valid signature accepted');
gate_check(!geonet_smsgate_verify('wrong', $raw, $timestamp, $signature, 1800000000), 'wrong key rejected');
gate_check(!geonet_smsgate_verify('secret', $raw . ' ', $timestamp, $signature, 1800000000), 'tampered body rejected');
gate_check(!geonet_smsgate_verify('secret', $raw, $timestamp, $signature, 1800000301), 'stale signature rejected');
gate_check(!geonet_smsgate_verify('', $raw, $timestamp, $signature, 1800000000), 'missing signing key rejected');
$event = ['event' => 'sms:received', 'deviceId' => 'phone-1', 'payload' => ['messageId' => 'sms-1', 'sender' => '+639171234567', 'message' => 'DOWN SUB-1008']];
$message = geonet_smsgate_incoming($event, 'phone-1');
gate_check($message['message'] === 'DOWN SUB-1008', 'incoming command preserved');
gate_check($message['id'] === geonet_smsgate_incoming($event)['id'], 'retry identity is stable');
$other = $event; $other['deviceId'] = 'phone-2';
gate_check($message['id'] !== geonet_smsgate_incoming($other)['id'], 'different devices have separate identities');
try { geonet_smsgate_incoming($other, 'phone-1'); throw new RuntimeException('Wrong device accepted'); }
catch (InvalidArgumentException $e) { echo "PASS: wrong device rejected\n"; }
