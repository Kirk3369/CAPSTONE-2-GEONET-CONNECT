<?php
require_once __DIR__ . '/interruptions.php';

function geonet_clear_one_notification($userId, $role, $notificationId, $activityKey = null)
{
    if ($notificationId !== null) {
        $id = filter_var($notificationId, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException('Notification not found.');
        $q = geonet_pdo()->prepare('DELETE FROM notifications WHERE id=? AND user_id=?');
        $q->execute([$id, $userId]);
        if (!$q->rowCount()) throw new InvalidArgumentException('Notification not found.');
        return;
    }
    if ($role !== 'admin' || !is_string($activityKey) || !preg_match('/^(interruption|subscription|outage):[A-Za-z0-9-]{1,40}$/D', $activityKey)) {
        throw new InvalidArgumentException('Activity item not found.');
    }
    $q = geonet_pdo()->prepare('INSERT INTO notification_item_clears(user_id,activity_key,cleared_at) VALUES(?,?,CURRENT_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE cleared_at=VALUES(cleared_at)');
    $q->execute([$userId,$activityKey]);
}

function geonet_filter_dismissed_activity($userId, $activity)
{
    $q = geonet_pdo()->prepare('SELECT activity_key,cleared_at FROM notification_item_clears WHERE user_id=?');
    $q->execute([$userId]); $clears=$q->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_values(array_filter($activity, static function($item) use ($clears) {
        $key=$item['activity_key']??null;
        return $key===null || !isset($clears[$key]) || str_replace('T',' ',$item['created_at']) > $clears[$key];
    }));
}

function geonet_subscription_activity($userId)
{
    geonet_ensure_mapping_schema();
    $q = geonet_pdo()->prepare("SELECT sr.id, sr.full_name, sr.status, sr.existing_user_id,
        sr.assigned_technician_id, sr.technician_response, sr.technician_work_status,
        t.full_name AS technician_name, b.name AS barangay,
        DATE_FORMAT(COALESCE(sr.activity_updated_at, sr.reviewed_at, sr.dispatched_at, sr.created_at), '%Y-%m-%dT%H:%i:%s') AS created_at
        FROM subscription_requests sr JOIN barangays b ON b.id = sr.barangay_id
        LEFT JOIN technicians t ON t.id = sr.assigned_technician_id
        WHERE COALESCE(sr.activity_updated_at, sr.reviewed_at, sr.dispatched_at, sr.created_at) > COALESCE(
            (SELECT cleared_at FROM notification_clears WHERE user_id = ?), '1000-01-01 00:00:00')
        ORDER BY COALESCE(sr.activity_updated_at, sr.reviewed_at, sr.dispatched_at, sr.created_at) DESC, sr.id DESC LIMIT 20");
    $q->execute([$userId]);
    return array_map(static function ($row) {
        $progress = $row['status'] !== 'pending' ? ucfirst($row['status'])
            : ($row['technician_response'] === 'declined' ? 'Needs reassignment'
            : ($row['technician_work_status'] === 'completed' ? 'Awaiting administrator approval'
            : ($row['technician_response'] === 'accepted' ? 'Technician ' . str_replace('_', ' ', $row['technician_work_status'])
            : ($row['assigned_technician_id'] ? 'Awaiting technician response' : 'Needs technician assignment'))));
        return [
            'id' => null, 'activity_key' => 'subscription:' . $row['id'], 'type' => 'subscription_request', 'subscription_request_id' => (int) $row['id'],
            'title' => ($row['existing_user_id'] ? 'Additional connection' : 'Subscription request') . ' #' . $row['id'] . ' · ' . $row['full_name'],
            'message' => $row['barangay'] . ' · ' . $progress . ($row['technician_name'] ? ' · ' . $row['technician_name'] : ''),
            'link' => 'dashboard.php#subscribers', 'created_at' => $row['created_at'], 'unread' => 0,
            // Completed service work awaits admin review; it must not be reset by an activity shortcut.
            'assignment' => $row['status'] === 'pending' && $row['technician_work_status'] !== 'completed'
                ? ['kind' => 'subscription', 'id' => (int) $row['id'], 'assigned' => !empty($row['assigned_technician_id'])] : null,
        ];
    }, $q->fetchAll());
}

function geonet_assign_activity_technician($userId, $kind, $id, $technicianId)
{
    $pdo = geonet_pdo();
    $account = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_active = 1");
    $account->execute([$userId]);
    if (!$account->fetchColumn()) throw new InvalidArgumentException('Administrator access required.');
    if (!is_string($kind) || !in_array($kind, ['subscription','interruption'], true)) throw new InvalidArgumentException('Choose a valid activity item.');
    $id = filter_var($id, FILTER_VALIDATE_INT);
    $technicianId = filter_var($technicianId, FILTER_VALIDATE_INT);
    if (!$id || !$technicianId) throw new InvalidArgumentException('Choose a report and technician.');
    $q = $pdo->prepare("SELECT t.public_id FROM technicians t JOIN users u ON u.id = t.user_id
        WHERE t.id = ? AND u.role = 'technician' AND u.is_active = 1");
    $q->execute([$technicianId]);
    $publicId = $q->fetchColumn();
    if (!$publicId) throw new InvalidArgumentException('Choose an active technician account.');
    if ($kind === 'interruption') {
        geonet_interruption_action($id, 'admin', $userId, 'assign', ['technicianId' => $technicianId]);
    } else {
        geonet_ensure_mapping_schema();
        $q = $pdo->prepare("SELECT id FROM subscription_requests WHERE id = ? AND status = 'pending' AND technician_work_status <> 'completed'");
        $q->execute([$id]);
        if (!$q->fetchColumn()) throw new InvalidArgumentException('This request is no longer available for assignment.');
        geonet_dispatch_subscription_request($id, $publicId, true);
    }
}
