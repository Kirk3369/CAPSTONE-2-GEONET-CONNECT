<?php

require_once dirname(__DIR__) . "/includes/auth.php";
require_once dirname(__DIR__) . "/includes/geonet.php";
require_once dirname(__DIR__) . "/includes/interruptions.php";
require_once dirname(__DIR__) . "/includes/network-activity.php";

header("Content-Type: application/json; charset=utf-8");
header('Cache-Control: no-store');

if (!isset($_SESSION["logged_in"], $_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["ok" => false, "error" => "Sign in to view notifications."]);
    exit();
}

try {
    geonet_ensure_notifications_schema();
    $pdo = geonet_pdo();
    $userId = (int) $_SESSION["user_id"];
    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";

    if ($method === "GET") {
        $stmt = $pdo->prepare(
            "SELECT id, type, title, message, link,
                    DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%s') AS created_at,
                    read_at IS NULL AS unread
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 50"
        );
        $stmt->execute([$userId]);
        $notifications = $stmt->fetchAll();
        $activity = $notifications;
        $assignmentTechnicians = [];
        if (($_SESSION["role"] ?? "") === "admin") {
            // Show one current activity entry per interruption; its individual alerts remain in notifications.
            $activity = array_values(array_filter($activity, static function ($item) {
                $subscriptionTitles = ['New subscription request', 'Additional connection request',
                    'Technician accepted a request', 'Technician declined a request', 'Service assignment updated'];
                return !($item['type'] === 'service' && ($item['link'] === 'interruptions.php' || in_array($item['title'], $subscriptionTitles, true)));
            }));
            $activity = array_merge($activity, geonet_interruption_activity($userId), geonet_subscription_activity($userId));
            $assignmentTechnicians = $pdo->query("SELECT t.id, t.full_name, t.availability FROM technicians t
                JOIN users u ON u.id = t.user_id WHERE u.role = 'technician' AND u.is_active = 1 ORDER BY t.full_name")->fetchAll();
            $outages = $pdo->prepare(
                "SELECT o.public_id AS id, o.status, o.severity, b.name AS barangay,
                        (SELECT COUNT(*) FROM outage_subscribers os WHERE os.outage_id = o.id) AS affected,
                        o.source, o.suspected,
                        DATE_FORMAT(COALESCE(o.resolved_at, o.started_at), '%Y-%m-%dT%H:%i:%s') AS created_at
                 FROM outages o
                 JOIN barangays b ON b.id = o.barangay_id
                 WHERE COALESCE(o.resolved_at, o.started_at) > COALESCE(
                    (SELECT cleared_at FROM notification_clears WHERE user_id = ?),
                    '1000-01-01 00:00:00'
                 )
                 ORDER BY COALESCE(o.resolved_at, o.started_at) DESC, o.id DESC
                 LIMIT 20"
            );
            $outages->execute([$userId]);
            $outages = $outages->fetchAll();
            foreach ($outages as $outage) {
                $activity[] = [
                    "id" => null,
                    "activity_key" => 'outage:' . $outage['id'],
                    "type" => "outage",
                    "title" => $outage["status"] === "active" ? "Active outage" : "Outage update",
                    "message" => $outage["id"] . " · " . $outage["barangay"] . " · " .
                        $outage["affected"] . " affected · " . $outage["severity"] .
                        ($outage["status"] === "resolved" ? " · resolved" : " · " . $outage["suspected"]),
                    "link" => "dashboard.php#maintenance",
                    "created_at" => $outage["created_at"],
                    "unread" => 0,
                ];
            }
            usort($activity, static function ($left, $right) {
                return strcmp($right["created_at"], $left["created_at"]);
            });
            $activity = array_slice(geonet_filter_dismissed_activity($userId, $activity), 0, 50);
        }
        $count = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE user_id = ? AND read_at IS NULL"
        );
        $count->execute([$userId]);
        echo json_encode([
            "ok" => true,
            "notifications" => $notifications,
            "activity" => $activity,
            "assignment_technicians" => $assignmentTechnicians,
            "unread_count" => (int) $count->fetchColumn(),
        ]);
        exit();
    }

    if ($method !== "POST") {
        http_response_code(405);
        header("Allow: GET, POST");
        echo json_encode(["ok" => false, "error" => "Method not allowed."]);
        exit();
    }

    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Invalid request."]);
        exit();
    }
    if (
        !isset($_SESSION["profile_csrf_token"], $input["csrfToken"]) ||
        !is_string($input["csrfToken"]) ||
        !hash_equals($_SESSION["profile_csrf_token"], $input["csrfToken"])
    ) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "Refresh the page and try again."]);
        exit();
    }

    if (($input["action"] ?? "") === "assign_technician") {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Administrator access required.']);
            exit();
        }
        geonet_assign_activity_technician($userId, $input['kind'] ?? null, $input['id'] ?? null, $input['technicianId'] ?? null);
    } elseif (($input['action'] ?? '') === 'clear_one') {
        geonet_clear_one_notification($userId, $_SESSION['role'] ?? '', $input['notificationId'] ?? null, $input['activityKey'] ?? null);
    } elseif (($input["action"] ?? "") === "mark_read") {
        $notificationId = filter_var($input["notificationId"] ?? null, FILTER_VALIDATE_INT);
        if ($notificationId === false || $notificationId < 1) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "Notification not found."]);
            exit();
        }
        $update = $pdo->prepare(
            "UPDATE notifications SET read_at = NOW()
             WHERE id = ? AND user_id = ? AND read_at IS NULL"
        );
        $update->execute([$notificationId, $userId]);
    } elseif (($input["action"] ?? "") === "mark_all_read") {
        $update = $pdo->prepare(
            "UPDATE notifications SET read_at = NOW()
             WHERE user_id = ? AND read_at IS NULL"
        );
        $update->execute([$userId]);
    } elseif (($input["action"] ?? "") === "clear_all") {
        $pdo->beginTransaction();
        try {
            $clear = $pdo->prepare(
                "INSERT INTO notification_clears (user_id, cleared_at)
                 VALUES (?, CURRENT_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE cleared_at = VALUES(cleared_at)"
            );
            $clear->execute([$userId]);
            $delete = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
            $delete->execute([$userId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    } else {
        http_response_code(400);
        echo json_encode(["ok" => false, "error" => "Unknown action."]);
        exit();
    }

    echo json_encode(["ok" => true]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Notifications: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(["ok" => false, "error" => "Could not load or update notifications."]);
}
