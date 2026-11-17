<?php

function geonet_smsgate_verify($key, $raw, $timestamp, $signature, $now = null)
{
    if ($key === '' || !preg_match('/^[0-9]{1,12}$/D', $timestamp) ||
        abs(($now ?? time()) - (int) $timestamp) > 300 ||
        !preg_match('/^[a-fA-F0-9]{64}$/D', $signature)) return false;
    return hash_equals(hash_hmac('sha256', $raw . $timestamp, $key), strtolower($signature));
}

function geonet_smsgate_incoming($event, $deviceId = '')
{
    if (!is_array($event) || ($event['event'] ?? null) !== 'sms:received' ||
        !is_string($event['deviceId'] ?? null) || $event['deviceId'] === '' ||
        ($deviceId !== '' && $event['deviceId'] !== $deviceId)) {
        throw new InvalidArgumentException('Expected an sms:received event from the configured device.');
    }
    $payload = $event['payload'] ?? null;
    if (!is_array($payload) || !is_string($payload['messageId'] ?? null) || $payload['messageId'] === '' ||
        !is_string($payload['sender'] ?? null) || !is_string($payload['message'] ?? null)) {
        throw new InvalidArgumentException('Invalid incoming SMS payload.');
    }
    return [
        'sender' => $payload['sender'], 'message' => $payload['message'],
        'id' => hash('sha256', $event['deviceId'] . ':' . $payload['messageId']),
    ];
}
