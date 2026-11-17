<?php
require_once __DIR__ . '/geonet.php';

function geonet_ensure_interruption_schema()
{
    static $ready = false;
    if ($ready) return;
    $pdo = geonet_pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_interruptions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        subscriber_id INT UNSIGNED NOT NULL,
        active_subscriber_id INT UNSIGNED NULL,
        status ENUM('reported','assigned','accepted','en_route','awaiting_confirmation','resolved') NOT NULL DEFAULT 'reported',
        assigned_technician_id INT UNSIGNED NULL,
        reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        assigned_at DATETIME NULL,
        completed_at DATETIME NULL,
        confirmation_due_at DATETIME NULL,
        resolved_at DATETIME NULL,
        resolution_method VARCHAR(24) NULL,
        technician_note VARCHAR(2000) NULL,
        UNIQUE KEY uq_interruption_active (active_subscriber_id),
        KEY idx_interruption_due (status, confirmation_due_at),
        CONSTRAINT fk_interruption_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id) ON DELETE CASCADE,
        CONSTRAINT fk_interruption_technician FOREIGN KEY (assigned_technician_id) REFERENCES technicians(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_interruption_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        interruption_id BIGINT UNSIGNED NOT NULL,
        event VARCHAR(40) NOT NULL,
        actor_user_id INT UNSIGNED NULL,
        note VARCHAR(2000) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_interruption_events (interruption_id, id),
        CONSTRAINT fk_interruption_event FOREIGN KEY (interruption_id) REFERENCES service_interruptions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    geonet_ensure_notifications_schema();
    $ready = true;
}

function geonet_interruption_event($id, $event, $userId = null, $note = '')
{
    geonet_pdo()->prepare('INSERT INTO service_interruption_events (interruption_id, event, actor_user_id, note) VALUES (?, ?, ?, ?)')
        ->execute([$id, $event, $userId, $note]);
}

function geonet_interruption_notify($report, $subscriber, $title, $message)
{
    $message = $subscriber['public_id'] . ': ' . $message;
    geonet_notify_role('admin', 'service', $title, $message, 'interruptions.php');
    geonet_notify_user($subscriber['user_id'] ?? null, 'service', $title, $message, 'interruptions.php');
    if (!empty($report['assigned_technician_id'])) {
        $q = geonet_pdo()->prepare('SELECT user_id FROM technicians WHERE id = ?');
        $q->execute([$report['assigned_technician_id']]);
        geonet_notify_user($q->fetchColumn(), 'service', $title, $message, 'interruptions.php');
    }
}

// Caller holds the subscriber lock and runs inside a transaction.
function geonet_close_interruption($report, $subscriber, $method, $actor = null)
{
    $pdo = geonet_pdo();
    $pdo->prepare("UPDATE service_interruptions SET status = 'resolved', active_subscriber_id = NULL,
        resolved_at = NOW(), confirmation_due_at = NULL, resolution_method = ? WHERE id = ?")
        ->execute([$method, $report['id']]);
    $pdo->prepare("UPDATE subscribers SET status = 'online' WHERE id = ?")->execute([$subscriber['id']]);
    $automatic = $method === 'automatic';
    geonet_interruption_event($report['id'], $automatic ? 'auto_confirmed' : 'subscriber_confirmed', $actor, $method);
    geonet_interruption_notify($report, $subscriber, $automatic ? 'Restoration automatically confirmed' : 'Restoration confirmed',
        $automatic ? 'Automatically closed five hours after technician completion without a subscriber response.' : 'The subscriber confirmed that the connection is restored.');
}

// Called only after the SMS processor has verified the registered sender and locked the subscriber.
function geonet_interruption_sms($subscriber, $command, $source = "simulation")
{
    $pdo = geonet_pdo();
    $q = $pdo->prepare('SELECT * FROM service_interruptions WHERE active_subscriber_id = ? FOR UPDATE');
    $q->execute([$subscriber['id']]);
    $report = $q->fetch();
    if ($command === 'RESTORED') {
        if ($report) geonet_close_interruption($report, $subscriber, 'subscriber_sms', $subscriber['user_id'] ?? null);
        return;
    }
    if (!$report) {
        $pdo->prepare('INSERT INTO service_interruptions (subscriber_id, active_subscriber_id) VALUES (?, ?)')
            ->execute([$subscriber['id'], $subscriber['id']]);
        $id = (int) $pdo->lastInsertId();
        geonet_interruption_event($id, 'reported', $subscriber['user_id'] ?? null, $source === 'smsgate' ? 'Subscriber interruption report received through SMSGate.' : 'Subscriber interruption report received by SMS simulation.');
        geonet_interruption_notify([], $subscriber, 'Interruption reported', 'A subscriber reported an interruption. Assign a technician.');
    } elseif ($report['status'] === 'awaiting_confirmation') {
        geonet_reopen_interruption($report, $subscriber, $subscriber['user_id'] ?? null, 'Subscriber reported DOWN by SMS.');
    }
}

function geonet_reopen_interruption($report, $subscriber, $actor, $note)
{
    geonet_pdo()->prepare("UPDATE service_interruptions SET status = ?, completed_at = NULL,
        confirmation_due_at = NULL, technician_note = NULL WHERE id = ?")
        ->execute([empty($report['assigned_technician_id']) ? 'reported' : 'accepted', $report['id']]);
    geonet_pdo()->prepare("UPDATE subscribers SET status = 'offline' WHERE id = ?")->execute([$subscriber['id']]);
    geonet_interruption_event($report['id'], 'subscriber_still_down', $actor, $note);
    geonet_interruption_notify($report, $subscriber, 'Repair needs further work', 'The subscriber reports the connection is still down. The automatic confirmation countdown was cancelled.');
}

function geonet_interruption_lock($id)
{
    $pdo = geonet_pdo();
    $q = $pdo->prepare('SELECT subscriber_id FROM service_interruptions WHERE id = ?');
    $q->execute([$id]);
    $subscriberId = $q->fetchColumn();
    if (!$subscriberId) throw new InvalidArgumentException('Interruption not found.');
    // All writes lock subscriber first, then interruption, including SMS and the worker.
    $q = $pdo->prepare('SELECT id, public_id, user_id, status FROM subscribers WHERE id = ? FOR UPDATE');
    $q->execute([$subscriberId]);
    $subscriber = $q->fetch();
    $q = $pdo->prepare('SELECT *, (confirmation_due_at <= NOW()) AS confirmation_overdue FROM service_interruptions WHERE id = ? FOR UPDATE');
    $q->execute([$id]);
    $report = $q->fetch();
    if (!$subscriber || !$report) throw new InvalidArgumentException('Interruption not found.');
    return [$report, $subscriber];
}

function geonet_interruption_action($id, $role, $userId, $action, $input = [])
{
    $id = filter_var($id, FILTER_VALIDATE_INT);
    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    if (!$id || !$userId) throw new InvalidArgumentException('Invalid interruption or account.');
    geonet_ensure_interruption_schema();
    $pdo = geonet_pdo();
    $pdo->beginTransaction();
    try {
        [$report, $subscriber] = geonet_interruption_lock($id);
        if ($report['status'] === 'resolved') throw new InvalidArgumentException('This interruption is already resolved.');
        if ($role === 'admin' && $action === 'assign') {
            if (!in_array($report['status'], ['reported','assigned','accepted','en_route'], true)) {
                throw new InvalidArgumentException('This repair is waiting for subscriber confirmation.');
            }
            $techId = filter_var($input['technicianId'] ?? null, FILTER_VALIDATE_INT);
            $q = $pdo->prepare("SELECT t.id FROM technicians t JOIN users u ON u.id = t.user_id
                WHERE t.id = ? AND u.role = 'technician' AND u.is_active = 1");
            $q->execute([$techId ?: 0]);
            if (!$q->fetchColumn()) throw new InvalidArgumentException('Choose an active technician account.');
            $oldTech = $report['assigned_technician_id'];
            $pdo->prepare("UPDATE service_interruptions SET assigned_technician_id = ?, status = 'assigned', assigned_at = NOW(),
                completed_at = NULL, confirmation_due_at = NULL, technician_note = NULL WHERE id = ?")
                ->execute([$techId, $id]);
            if ($oldTech && (int) $oldTech !== (int) $techId) {
                $q = $pdo->prepare('SELECT user_id FROM technicians WHERE id = ?');
                $q->execute([$oldTech]);
                geonet_notify_user($q->fetchColumn(), 'service', 'Interruption reassigned', $subscriber['public_id'] . ': This repair has been assigned to another technician.', 'interruptions.php');
            }
            $report['assigned_technician_id'] = $techId;
            geonet_interruption_event($id, 'assigned', $userId, 'Technician ID: ' . $techId);
            geonet_interruption_notify($report, $subscriber, 'Technician assigned', 'A technician has been assigned to the interruption.');
        } elseif ($role === 'technician') {
            $q = $pdo->prepare('SELECT id FROM technicians WHERE id = ? AND user_id = ?');
            $q->execute([$report['assigned_technician_id'], $userId]);
            if (!$q->fetchColumn()) throw new InvalidArgumentException('This interruption is not assigned to you.');
            $status = $report['status'];
            $transitions = ['accept' => ['assigned', 'accepted'], 'en_route' => ['accepted', 'en_route'], 'complete' => ['en_route', 'awaiting_confirmation']];
            if (isset($transitions[$action])) {
                if ($status !== $transitions[$action][0]) throw new InvalidArgumentException('Refresh the page; this work status is no longer available.');
                if ($action === 'complete') {
                    $pdo->prepare("UPDATE service_interruptions SET status = 'awaiting_confirmation', completed_at = NOW(),
                        confirmation_due_at = DATE_ADD(NOW(), INTERVAL 5 HOUR) WHERE id = ?")->execute([$id]);
                    // Technician completion alone never marks the subscriber online.
                    $pdo->prepare("UPDATE subscribers SET status = 'offline' WHERE id = ?")->execute([$subscriber['id']]);
                } else {
                    $pdo->prepare('UPDATE service_interruptions SET status = ? WHERE id = ?')->execute([$transitions[$action][1], $id]);
                }
                geonet_interruption_event($id, $action === 'complete' ? 'technician_completed' : $action, $userId);
                geonet_interruption_notify($report, $subscriber, $action === 'complete' ? 'Please confirm your connection' : 'Repair progress updated',
                    $action === 'complete' ? 'The technician marked the repair complete. Confirm restoration or report that it is still down within five hours. Otherwise restoration is automatically confirmed.' : 'Technician status: ' . $transitions[$action][1] . '.');
            } elseif (($action === 'decline' && $status === 'assigned') || ($action === 'not_completed' && in_array($status, ['accepted','en_route'], true))) {
                $note = $input['note'] ?? '';
                if (!is_string($note) || trim($note) === '' || strlen($note) > 2000) throw new InvalidArgumentException('Enter a reason of up to 2,000 bytes.');
                $pdo->prepare("UPDATE service_interruptions SET status = 'reported', assigned_technician_id = NULL,
                    assigned_at = NULL, completed_at = NULL, confirmation_due_at = NULL, technician_note = ? WHERE id = ?")
                    ->execute([trim($note), $id]);
                geonet_interruption_event($id, $action, $userId, trim($note));
                geonet_interruption_notify($report, $subscriber, 'Interruption returned for assignment', trim($note));
            } else throw new InvalidArgumentException('This technician action is not available.');
        } elseif ($role === 'subscriber' && in_array($action, ['confirm','still_down'], true)) {
            if ((int) $subscriber['user_id'] !== $userId) throw new InvalidArgumentException('This connection does not belong to your account.');
            if ($action === 'confirm') geonet_close_interruption($report, $subscriber, 'subscriber_portal', $userId);
            else {
                if ($report['status'] !== 'awaiting_confirmation') throw new InvalidArgumentException('There is no completed repair awaiting your response.');
                geonet_reopen_interruption($report, $subscriber, $userId, 'Subscriber selected still down in the portal.');
            }
        } else throw new InvalidArgumentException('Your account cannot perform this action.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function geonet_expire_interruption_confirmations()
{
    geonet_ensure_interruption_schema();
    $pdo = geonet_pdo();
    $ids = $pdo->query("SELECT id FROM service_interruptions WHERE status = 'awaiting_confirmation'
        AND confirmation_due_at <= NOW() ORDER BY id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
    $closed = 0;
    foreach ($ids as $id) {
        $pdo->beginTransaction();
        try {
            [$report, $subscriber] = geonet_interruption_lock($id);
            if ($report['status'] === 'awaiting_confirmation' && $report['confirmation_overdue']) {
                geonet_close_interruption($report, $subscriber, 'automatic');
                $closed++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    return $closed;
}

function geonet_interruption_list($role, $userId)
{
    geonet_ensure_interruption_schema();
    $where = '';
    $args = [];
    if ($role === 'subscriber') { $where = 'WHERE s.user_id = ?'; $args[] = $userId; }
    elseif ($role === 'technician') { $where = 'WHERE t.user_id = ?'; $args[] = $userId; }
    elseif ($role !== 'admin') throw new InvalidArgumentException('Access denied.');
    $q = geonet_pdo()->prepare("SELECT r.*, s.public_id AS connection_id, s.full_name, s.address, s.phone,
        s.status AS connection_status, b.name AS barangay, t.full_name AS technician_name,
        UNIX_TIMESTAMP(r.confirmation_due_at) AS confirmation_due_epoch
        FROM service_interruptions r JOIN subscribers s ON s.id = r.subscriber_id
        JOIN barangays b ON b.id = s.barangay_id LEFT JOIN technicians t ON t.id = r.assigned_technician_id
        {$where} ORDER BY (r.status = 'resolved'), r.id DESC LIMIT 200");
    $q->execute($args);
    $rows = $q->fetchAll();
    foreach ($rows as &$row) {
        $events = geonet_pdo()->prepare('SELECT event, note, created_at FROM service_interruption_events WHERE interruption_id = ? ORDER BY id DESC LIMIT 20');
        $events->execute([$row['id']]);
        $row['events'] = $events->fetchAll();
    }
    return $rows;
}

function geonet_interruption_activity($userId)
{
    geonet_ensure_interruption_schema();
    $q = geonet_pdo()->prepare("SELECT r.id AS interruption_id, r.status, r.assigned_technician_id, s.public_id AS connection_id,
        s.full_name, t.full_name AS technician_name, e.event, e.note,
        DATE_FORMAT(COALESCE(e.created_at, r.reported_at), '%Y-%m-%dT%H:%i:%s') AS created_at
        FROM service_interruptions r JOIN subscribers s ON s.id = r.subscriber_id
        LEFT JOIN technicians t ON t.id = r.assigned_technician_id
        LEFT JOIN service_interruption_events e ON e.id = (
            SELECT MAX(history.id) FROM service_interruption_events history WHERE history.interruption_id = r.id
        )
        WHERE COALESCE(e.created_at, r.reported_at) > COALESCE(
            (SELECT cleared_at FROM notification_clears WHERE user_id = ?), '1000-01-01 00:00:00'
        ) ORDER BY COALESCE(e.created_at, r.reported_at) DESC, r.id DESC LIMIT 20");
    $q->execute([$userId]);
    $labels = ['reported' => 'Needs technician assignment', 'assigned' => 'Awaiting technician response',
        'accepted' => 'Technician accepted', 'en_route' => 'Technician on route',
        'awaiting_confirmation' => 'Awaiting subscriber confirmation', 'resolved' => 'Resolved'];
    return array_map(static function ($row) use ($labels) {
        return [
            'id' => null, 'activity_key' => 'interruption:' . $row['interruption_id'], 'type' => 'interruption', 'interruption_id' => (int) $row['interruption_id'],
            'title' => 'Interruption report #' . $row['interruption_id'] . ' · ' . $row['full_name'],
            'message' => $row['connection_id'] . ' · ' . ($labels[$row['status']] ?? $row['status']) .
                ($row['technician_name'] ? ' · ' . $row['technician_name'] : '') .
                ($row['event'] === 'auto_confirmed' ? ' · Automatically confirmed after five hours' : ''),
            'link' => 'dashboard.php?interruption=' . $row['interruption_id'] . '#dashboard',
            'created_at' => $row['created_at'], 'unread' => 0,
            'assignment' => in_array($row['status'], ['reported','assigned','accepted','en_route'], true)
                ? ['kind' => 'interruption', 'id' => (int) $row['interruption_id'], 'assigned' => !empty($row['assigned_technician_id'])] : null,
        ];
    }, $q->fetchAll());
}
