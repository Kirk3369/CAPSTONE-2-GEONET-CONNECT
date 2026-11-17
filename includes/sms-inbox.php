<?php

require_once __DIR__ . "/geonet.php";
require_once __DIR__ . "/interruptions.php";

function geonet_sms_simulation_allowed()
{
    return geonet_sms_config()["mock_mode"] && getenv("RENDER") !== "true" &&
        in_array($_SERVER["REMOTE_ADDR"] ?? "", ["127.0.0.1", "::1"], true);
}

function geonet_sms_phone($value)
{
    if (!is_string($value) || !preg_match('/^\+?[0-9 ()-]+$/D', trim($value))) return null;
    $digits = preg_replace('/\D/', '', $value);
    if (preg_match('/^09\d{9}$/D', $digits)) $digits = '63' . substr($digits, 1);
    if (preg_match('/^9\d{9}$/D', $digits)) $digits = '63' . $digits;
    return preg_match('/^639\d{9}$/D', $digits) ? $digits : null;
}

function geonet_ensure_sms_inbox_schema()
{
    geonet_pdo()->exec("CREATE TABLE IF NOT EXISTS sms_inbox (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        message_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        sender VARCHAR(32) NOT NULL,
        message TEXT NOT NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'simulation',
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        subscriber_id INT UNSIGNED NULL,
        connection_public_id VARCHAR(20) NULL,
        command VARCHAR(16) NULL,
        result VARCHAR(20) NOT NULL DEFAULT 'received',
        note VARCHAR(255) NOT NULL DEFAULT '',
        previous_status VARCHAR(20) NULL,
        new_status VARCHAR(20) NULL,
        UNIQUE KEY uq_sms_inbox_message (source, message_id),
        KEY idx_sms_inbox_received (received_at),
        CONSTRAINT fk_sms_inbox_subscriber FOREIGN KEY (subscriber_id)
            REFERENCES subscribers(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    geonet_ensure_notifications_schema();
    geonet_ensure_interruption_schema();
}

// Authenticate the caller before processing an incoming message.
function geonet_process_simulated_sms($sender, $message, $messageId)
{
    return geonet_process_incoming_sms($sender, $message, $messageId, "simulation");
}

function geonet_process_incoming_sms($sender, $message, $messageId, $source)
{
    if (!in_array($source, ["simulation", "smsgate"], true)) throw new InvalidArgumentException("Invalid SMS source.");
    if (!is_string($messageId) || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $messageId)) {
        throw new InvalidArgumentException("Use a message ID of 1-100 letters, digits, dots, underscores, colons, or hyphens.");
    }
    $phone = geonet_sms_phone($sender);
    if ($phone === null) throw new InvalidArgumentException("Enter a valid Philippine mobile number.");
    if (!is_string($message) || trim($message) === '' || strlen($message) > 2000 || preg_match('//u', $message) !== 1) {
        throw new InvalidArgumentException("Enter a valid SMS message of up to 2,000 bytes.");
    }
    $message = trim($message);
    geonet_ensure_sms_inbox_schema();
    $pdo = geonet_pdo();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("INSERT INTO sms_inbox (message_id, sender, message, source) VALUES (?, ?, ?, ?)");
        try {
            $insert->execute([$messageId, $phone, $message, $source]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) throw $e;
            $pdo->rollBack();
            $existing = $pdo->prepare("SELECT * FROM sms_inbox WHERE source = ? AND message_id = ?");
            $existing->execute([$source, $messageId]);
            $row = $existing->fetch();
            if (!$row || $row['sender'] !== $phone || $row['message'] !== $message) {
                throw new InvalidArgumentException("This message ID already belongs to a different message. Use a new ID.");
            }
            return ['duplicate' => true, 'entry' => $row];
        }
        $inboxId = (int) $pdo->lastInsertId();
        $result = 'rejected';
        $note = 'Use DOWN SUB-1008 or RESTORED SUB-1008.';
        $subscriber = null;
        $command = null;
        $connection = null;
        $previous = null;
        $next = null;
        if (preg_match('/^(DOWN|RESTORED)\s+(SUB-[0-9]{1,16})$/iD', $message, $matches)) {
            $command = strtoupper($matches[1]);
            $connection = strtoupper($matches[2]);
            $find = $pdo->prepare("SELECT id, public_id, user_id, phone, status FROM subscribers WHERE public_id = ? FOR UPDATE");
            $find->execute([$connection]);
            $candidate = $find->fetch();
            $note = 'The sender does not match a registered connection.';
            if ($candidate && geonet_sms_phone($candidate['phone']) === $phone) {
                $subscriber = $candidate;
                $previous = $candidate['status'];
                $next = $command === 'DOWN' ? 'offline' : 'online';
                $result = $previous === $next ? 'unchanged' : 'processed';
                $note = $result === 'unchanged'
                    ? 'Connection already ' . $next . '; no status change.'
                    : 'Subscriber reported ' . ($next === 'offline' ? 'an interruption.' : 'restoration.');
                if ($result === 'processed') {
                    $pdo->prepare("UPDATE subscribers SET status = ? WHERE id = ?")->execute([$next, $subscriber['id']]);
                }
                geonet_interruption_sms($subscriber, $command, $source);
            }
        }
        $update = $pdo->prepare("UPDATE sms_inbox SET subscriber_id = ?, connection_public_id = ?, command = ?,
            result = ?, note = ?, previous_status = ?, new_status = ? WHERE id = ?");
        $update->execute([$subscriber ? $subscriber['id'] : null, $connection, $command, $result, $note, $previous, $next, $inboxId]);
        $fetch = $pdo->prepare("SELECT * FROM sms_inbox WHERE id = ?");
        $fetch->execute([$inboxId]);
        $entry = $fetch->fetch();
        $pdo->commit();
        return ['duplicate' => false, 'entry' => $entry];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function geonet_sms_inbox_entries()
{
    geonet_ensure_sms_inbox_schema();
    return geonet_pdo()->query("SELECT * FROM sms_inbox ORDER BY id DESC LIMIT 100")->fetchAll();
}
