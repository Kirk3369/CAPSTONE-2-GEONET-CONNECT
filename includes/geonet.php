<?php

require_once __DIR__ . "/db.php";

function geonet_nap_box_capacity()
{
    return 16;
}

function geonet_nap_box_coverage_radius_meters()
{
    return 150;
}

function geonet_distance_meters($latA, $lngA, $latB, $lngB)
{
    $toRadians = M_PI / 180;
    $latDifference = ($latB - $latA) * $toRadians;
    $lngDifference = ($lngB - $lngA) * $toRadians;
    $a = sin($latDifference / 2) ** 2 +
        cos($latA * $toRadians) * cos($latB * $toRadians) *
        sin($lngDifference / 2) ** 2;
    $a = max(0, min(1, $a));
    return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function geonet_ensure_notifications_schema()
{
    $pdo = geonet_pdo();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(32) NOT NULL,
            title VARCHAR(120) NOT NULL,
            message VARCHAR(500) NOT NULL,
            link VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            KEY idx_notifications_user_created (user_id, created_at),
            KEY idx_notifications_user_read (user_id, read_at),
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id)
                ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notification_clears (
            user_id INT UNSIGNED PRIMARY KEY,
            cleared_at DATETIME(6) NOT NULL,
            CONSTRAINT fk_notification_clears_user FOREIGN KEY (user_id) REFERENCES users (id)
                ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_item_clears (
        user_id INT UNSIGNED NOT NULL,
        activity_key VARCHAR(80) NOT NULL,
        cleared_at DATETIME(6) NOT NULL,
        PRIMARY KEY(user_id, activity_key),
        CONSTRAINT fk_notification_item_clears_user FOREIGN KEY(user_id) REFERENCES users(id)
            ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function geonet_notify_user($userId, $type, $title, $message, $link = null)
{
    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        return;
    }

    $stmt = geonet_pdo()->prepare(
        "INSERT INTO notifications (user_id, type, title, message, link)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $userId,
        substr((string) $type, 0, 32),
        substr((string) $title, 0, 120),
        substr((string) $message, 0, 500),
        $link === null ? null : substr((string) $link, 0, 255),
    ]);
}

function geonet_notify_role($role, $type, $title, $message, $link = null)
{
    $stmt = geonet_pdo()->prepare(
        "INSERT INTO notifications (user_id, type, title, message, link)
         SELECT id, ?, ?, ?, ? FROM users WHERE role = ? AND is_active = 1"
    );
    $stmt->execute([
        substr((string) $type, 0, 32),
        substr((string) $title, 0, 120),
        substr((string) $message, 0, 500),
        $link === null ? null : substr((string) $link, 0, 255),
        $role,
    ]);
}

function geonet_notify_admin_map_activity($title, $message)
{
    geonet_ensure_notifications_schema();
    geonet_notify_role(
        "admin",
        "network_map",
        $title,
        $message,
        "dashboard.php#dashboard"
    );
}

function geonet_notify_outage_subscribers($outageId, $title, $message)
{
    $stmt = geonet_pdo()->prepare(
        "INSERT INTO notifications (user_id, type, title, message, link)
         SELECT DISTINCT s.user_id, 'outage', ?, ?, 'subscriber.php'
         FROM outage_subscribers os
         JOIN subscribers s ON s.id = os.subscriber_id
         WHERE os.outage_id = ? AND s.user_id IS NOT NULL"
    );
    $stmt->execute([
        substr((string) $title, 0, 120),
        substr((string) $message, 0, 500),
        $outageId,
    ]);
}

function geonet_setting($key, $default = "")
{
    $stmt = geonet_pdo()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row["setting_value"] : $default;
}

function geonet_set_setting($key, $value)
{
    $stmt = geonet_pdo()->prepare(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$key, (string) $value]);
}

function geonet_ensure_mapping_schema()
{
    $pdo = geonet_pdo();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS coverage_areas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            coordinates_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS subscription_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            existing_user_id INT UNSIGNED NULL,
            barangay_id INT UNSIGNED NOT NULL,
            nap_box_id INT UNSIGNED NULL,
            address VARCHAR(255) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            plan_name VARCHAR(80) NOT NULL,
            lat DECIMAL(10, 6) NOT NULL,
            lng DECIMAL(10, 6) NOT NULL,
            status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
            subscriber_id INT UNSIGNED NULL,
            assigned_technician_id INT UNSIGNED NULL,
            dispatched_at DATETIME NULL,
            technician_response ENUM('pending', 'accepted', 'declined') NOT NULL DEFAULT 'pending',
            technician_work_status ENUM('assigned', 'en_route', 'completed', 'not_completed') NOT NULL DEFAULT 'assigned',
            technician_work_reason TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            reviewed_by INT UNSIGNED NULL,
            KEY idx_subscription_requests_status (status),
            KEY idx_subscription_requests_email (email),
            KEY idx_subscription_requests_existing_user (existing_user_id),
            KEY idx_subscription_requests_technician (assigned_technician_id),
            KEY idx_subscription_requests_nap_box (nap_box_id),
            CONSTRAINT fk_subscription_requests_technician FOREIGN KEY (assigned_technician_id)
                REFERENCES technicians (id) ON UPDATE CASCADE ON DELETE SET NULL,
            CONSTRAINT fk_subscription_requests_existing_user FOREIGN KEY (existing_user_id)
                REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_subscription_requests_nap_box FOREIGN KEY (nap_box_id)
                REFERENCES network_assets (id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS subscriber_plan_change_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            subscriber_id INT UNSIGNED NOT NULL,
            current_plan VARCHAR(80) NOT NULL,
            requested_plan VARCHAR(80) NOT NULL,
            status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            reviewed_by INT UNSIGNED NULL,
            KEY idx_plan_change_requests_status (status),
            KEY idx_plan_change_requests_subscriber (subscriber_id),
            CONSTRAINT fk_plan_change_requests_subscriber FOREIGN KEY (subscriber_id)
                REFERENCES subscribers (id) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_plan_change_requests_reviewer FOREIGN KEY (reviewed_by)
                REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $requestColumns = [
        "activity_updated_at" => "DATETIME NULL",
        "existing_user_id" => "INT UNSIGNED NULL",
        "nap_box_id" => "INT UNSIGNED NULL",
        "assigned_technician_id" => "INT UNSIGNED NULL",
        "dispatched_at" => "DATETIME NULL",
        "technician_response" => "ENUM('pending', 'accepted', 'declined') NOT NULL DEFAULT 'pending'",
        "technician_work_status" => "ENUM('assigned', 'en_route', 'completed', 'not_completed') NOT NULL DEFAULT 'assigned'",
        "technician_work_reason" => "TEXT NULL",
    ];
    foreach ($requestColumns as $column => $definition) {
        $columnStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'subscription_requests'
               AND COLUMN_NAME = ?"
        );
        $columnStmt->execute([$column]);
        if ((int) $columnStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE subscription_requests ADD COLUMN {$column} {$definition}");
        }
    }
    $existingUserIndexStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'subscription_requests'
           AND INDEX_NAME = 'idx_subscription_requests_existing_user'"
    );
    $existingUserIndexStmt->execute();
    if ((int) $existingUserIndexStmt->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE subscription_requests
             ADD INDEX idx_subscription_requests_existing_user (existing_user_id)"
        );
    }
    $existingUserConstraintStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'subscription_requests'
           AND CONSTRAINT_NAME = 'fk_subscription_requests_existing_user'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $existingUserConstraintStmt->execute();
    if ((int) $existingUserConstraintStmt->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE subscription_requests
             ADD CONSTRAINT fk_subscription_requests_existing_user
             FOREIGN KEY (existing_user_id) REFERENCES users (id)
             ON UPDATE CASCADE ON DELETE CASCADE"
        );
    }
    $subscriberUserIndexStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'subscribers'
           AND INDEX_NAME = 'uq_subscribers_user'
           AND NON_UNIQUE = 0"
    );
    $subscriberUserIndexStmt->execute();
    if ((int) $subscriberUserIndexStmt->fetchColumn() > 0) {
        $subscriberUserLookupStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'subscribers'
               AND INDEX_NAME = 'idx_subscribers_user'"
        );
        $subscriberUserLookupStmt->execute();
        if ((int) $subscriberUserLookupStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE subscribers ADD INDEX idx_subscribers_user (user_id)");
        }
        $pdo->exec("ALTER TABLE subscribers DROP INDEX uq_subscribers_user");
    }
    $workStatusTypeStmt = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'subscription_requests'
           AND COLUMN_NAME = 'technician_work_status'"
    );
    $workStatusTypeStmt->execute();
    $workStatusType = $workStatusTypeStmt->fetchColumn();
    if (
        is_string($workStatusType) &&
        (
            strpos($workStatusType, "'en_route'") === false ||
            strpos($workStatusType, "'not_completed'") === false
        )
    ) {
        $pdo->exec(
            "ALTER TABLE subscription_requests
             MODIFY COLUMN technician_work_status
             ENUM('assigned', 'en_route', 'completed', 'not_completed') NOT NULL DEFAULT 'assigned'"
        );
    }
    $pdo->exec(
        "UPDATE subscription_requests
         SET technician_work_status = 'not_completed'
         WHERE technician_work_status = 'assigned'
           AND technician_work_reason IS NOT NULL
           AND technician_work_reason <> ''"
    );
    $indexStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'subscription_requests'
           AND INDEX_NAME = 'idx_subscription_requests_technician'"
    );
    $indexStmt->execute();
    if ((int) $indexStmt->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE subscription_requests
             ADD INDEX idx_subscription_requests_technician (assigned_technician_id)"
        );
    }
    $constraintStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'subscription_requests'
           AND CONSTRAINT_NAME = 'fk_subscription_requests_technician'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $constraintStmt->execute();
    if ((int) $constraintStmt->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE subscription_requests
             ADD CONSTRAINT fk_subscription_requests_technician
             FOREIGN KEY (assigned_technician_id) REFERENCES technicians (id)
             ON UPDATE CASCADE ON DELETE SET NULL"
        );
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'subscribers'
           AND COLUMN_NAME = 'map_pin_enabled'"
    );
    $stmt->execute();
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE subscribers
             ADD COLUMN map_pin_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER lng"
        );
    }
    foreach ([
        "subscribers" => ["idx_subscribers_nap_box", "fk_subscribers_nap_box"],
        "subscription_requests" => [
            "idx_subscription_requests_nap_box",
            "fk_subscription_requests_nap_box",
        ],
    ] as $table => [$indexName, $constraintName]) {
        $columnStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = 'nap_box_id'"
        );
        $columnStmt->execute([$table]);
        if ((int) $columnStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN nap_box_id INT UNSIGNED NULL");
        }

        $indexStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?"
        );
        $indexStmt->execute([$table, $indexName]);
        if ((int) $indexStmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE {$table} ADD INDEX {$indexName} (nap_box_id)");
        }

        $constraintStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $constraintStmt->execute([$table, $constraintName]);
        if ((int) $constraintStmt->fetchColumn() === 0) {
            $pdo->exec(
                "ALTER TABLE {$table}
                 ADD CONSTRAINT {$constraintName} FOREIGN KEY (nap_box_id)
                 REFERENCES network_assets (id)
                 ON UPDATE CASCADE ON DELETE SET NULL"
            );
        }
    }
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'network_assets'
           AND COLUMN_NAME = 'map_pin_enabled'"
    );
    $stmt->execute();
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE network_assets
             ADD COLUMN map_pin_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER lng"
        );
    }
    if (geonet_setting("legacy_map_markers_removed", "0") !== "1") {
        $pdo->exec(
            "DELETE FROM network_assets
             WHERE asset_type IN ('nap', 'landmark') AND map_pin_enabled = 0"
        );
        geonet_set_setting("legacy_map_markers_removed", "1");
    }
    if (geonet_setting("legacy_fiber_markers_removed", "0") !== "1") {
        $pdo->exec("DELETE FROM network_assets WHERE asset_type = 'fiber'");
        geonet_set_setting("legacy_fiber_markers_removed", "1");
    }
    if (geonet_setting("demo_subscribers_removed", "0") !== "1") {
        $pdo->beginTransaction();
        try {
            $userIds = $pdo->query(
                "SELECT user_id FROM subscribers WHERE user_id IS NOT NULL"
            )->fetchAll(PDO::FETCH_COLUMN);
            $pdo->exec("DELETE FROM subscribers");
            if ($userIds) {
                $placeholders = implode(",", array_fill(0, count($userIds), "?"));
                $deleteUsers = $pdo->prepare(
                    "DELETE FROM users WHERE role = 'subscriber' AND id IN ({$placeholders})"
                );
                $deleteUsers->execute($userIds);
            }
            geonet_set_setting("demo_subscribers_removed", "1");
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    if (geonet_setting("demo_technicians_removed", "0") !== "1") {
        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM jobs");
            $pdo->exec("DELETE FROM technicians");
            $pdo->exec("DELETE FROM users WHERE role = 'technician'");
            geonet_set_setting("demo_technicians_removed", "1");
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

function geonet_payload()
{
    $pdo = geonet_pdo();
    geonet_ensure_mapping_schema();

    $barangays = $pdo->query("SELECT id, name, lat, lng FROM barangays ORDER BY name")->fetchAll();
    foreach ($barangays as &$b) {
        $b["lat"] = (float) $b["lat"];
        $b["lng"] = (float) $b["lng"];
    }

    unset($b);

    $landmarks = $pdo->query(
        "SELECT n.id, n.name, n.lat, n.lng, n.barangay_id,
                b.name AS barangay, n.asset_type AS type,
                n.map_pin_enabled AS mapVisible,
                (SELECT COUNT(*) FROM subscribers s WHERE s.nap_box_id = n.id) AS subscriberCount,
                (SELECT COUNT(*) FROM subscription_requests sr
                 WHERE sr.nap_box_id = n.id AND sr.status = 'pending') AS pendingRequestCount
         FROM network_assets n
         LEFT JOIN barangays b ON b.id = n.barangay_id
         ORDER BY n.id"
    )->fetchAll();
    foreach ($landmarks as &$lm) {
        $lm["id"] = (int) $lm["id"];
        $lm["lat"] = (float) $lm["lat"];
        $lm["lng"] = (float) $lm["lng"];
        $lm["barangay_id"] = $lm["barangay_id"] === null ? null : (int) $lm["barangay_id"];
        $lm["mapVisible"] = (bool) $lm["mapVisible"];
        $lm["subscriberCount"] = (int) $lm["subscriberCount"];
        $lm["pendingRequestCount"] = (int) $lm["pendingRequestCount"];
        if ($lm["type"] === "nap") {
            $lm["occupiedSlots"] = $lm["subscriberCount"] + $lm["pendingRequestCount"];
            $lm["availableSlots"] = max(
                0,
                geonet_nap_box_capacity() - $lm["occupiedSlots"]
            );
        }
    }
    unset($lm);

    $coverageAreas = $pdo->query(
        "SELECT id, name, coordinates_json FROM coverage_areas ORDER BY id"
    )->fetchAll();
    foreach ($coverageAreas as &$area) {
        $coordinates = json_decode($area["coordinates_json"], true);
        if (!is_array($coordinates)) {
            throw new RuntimeException("A saved coverage area has invalid coordinates.");
        }
        $area["id"] = (int) $area["id"];
        $area["coordinates"] = array_map(function ($point) {
            if (!is_array($point) || !isset($point[0], $point[1])) {
                throw new RuntimeException("A saved coverage area has invalid coordinates.");
            }
            return [(float) $point[0], (float) $point[1]];
        }, $coordinates);
        unset($area["coordinates_json"]);
    }
    unset($area);

    $subscribers = $pdo->query(
        "SELECT s.public_id AS id, s.full_name AS name, s.nap_box_id AS napBoxId,
                b.name AS barangay, n.name AS napBox, s.address,
                s.phone, s.plan_name AS plan, s.status, s.lat, s.lng,
                s.map_pin_enabled AS mapVisible,
                DATE_FORMAT(s.connected_on, '%Y-%m-%d') AS connected
         FROM subscribers s
         JOIN barangays b ON b.id = s.barangay_id
         LEFT JOIN network_assets n ON n.id = s.nap_box_id
         ORDER BY s.public_id"
    )->fetchAll();
    foreach ($subscribers as &$s) {
        $s["napBoxId"] = $s["napBoxId"] === null ? null : (int) $s["napBoxId"];
        $s["lat"] = (float) $s["lat"];
        $s["lng"] = (float) $s["lng"];
        $s["mapVisible"] = (bool) $s["mapVisible"];
    }
    unset($s);
    $subscribersByNapBox = [];
    foreach ($subscribers as $subscriber) {
        if ($subscriber["napBoxId"] === null) {
            continue;
        }
        $subscribersByNapBox[$subscriber["napBoxId"]][] = [
            "id" => $subscriber["id"],
            "name" => $subscriber["name"],
        ];
    }
    foreach ($landmarks as &$landmark) {
        if ($landmark["type"] === "nap") {
            $landmark["connectedSubscribers"] =
                $subscribersByNapBox[$landmark["id"]] ?? [];
        }
    }
    unset($landmark);

    $outageRows = $pdo->query(
        "SELECT o.id AS db_id, o.public_id AS id, b.name AS barangay, o.status, o.severity,
                DATE_FORMAT(o.started_at, '%Y-%m-%d %H:%i') AS started,
                DATE_FORMAT(o.resolved_at, '%Y-%m-%d %H:%i') AS resolved,
                o.source, o.suspected, o.lat, o.lng
         FROM outages o
         JOIN barangays b ON b.id = o.barangay_id
         ORDER BY o.started_at DESC"
    )->fetchAll();

    $affectedStmt = $pdo->prepare(
        "SELECT s.public_id
         FROM outage_subscribers os
         JOIN subscribers s ON s.id = os.subscriber_id
         WHERE os.outage_id = ?
         ORDER BY s.public_id"
    );

    $outages = [];
    foreach ($outageRows as $row) {
        $affectedStmt->execute([$row["db_id"]]);
        $ids = $affectedStmt->fetchAll(PDO::FETCH_COLUMN);
        $outages[] = [
            "id" => $row["id"],
            "barangay" => $row["barangay"],
            "status" => $row["status"],
            "severity" => $row["severity"],
            "started" => $row["started"],
            "resolved" => $row["resolved"],
            "affected" => count($ids),
            "source" => $row["source"],
            "suspected" => $row["suspected"],
            "lat" => (float) $row["lat"],
            "lng" => (float) $row["lng"],
            "subscribers" => $ids,
        ];
    }

    $jobCounts = $pdo->query(
        "SELECT technician_id, COUNT(*) AS c FROM jobs WHERE status <> 'resolved' GROUP BY technician_id"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $technicians = $pdo->query(
        "SELECT public_id AS id, full_name AS name, phone, job_title AS role,
                CASE
                    WHEN EXISTS (
                        SELECT 1 FROM jobs j
                        WHERE j.technician_id = t.id AND j.status = 'en_route'
                    ) OR EXISTS (
                        SELECT 1 FROM subscription_requests sr
                        WHERE sr.assigned_technician_id = t.id
                          AND sr.status IN ('pending', 'approved')
                          AND sr.technician_response = 'accepted'
                          AND sr.technician_work_status = 'en_route'
                    ) THEN 'en_route'
                    WHEN EXISTS (
                        SELECT 1 FROM jobs j
                        WHERE j.technician_id = t.id AND j.status <> 'resolved'
                    ) OR EXISTS (
                        SELECT 1 FROM subscription_requests sr
                        WHERE sr.assigned_technician_id = t.id
                          AND sr.status IN ('pending', 'approved')
                          AND sr.technician_response = 'accepted'
                          AND sr.technician_work_status = 'assigned'
                    ) THEN 'on_duty'
                    ELSE t.availability
                END AS availability,
                t.availability AS configuredAvailability,
                photo_initials AS photo, t.id AS db_id
         FROM technicians t
         ORDER BY public_id"
    )->fetchAll();
    foreach ($technicians as &$t) {
        $t["jobs"] = (int) ($jobCounts[$t["db_id"]] ?? 0);
        unset($t["db_id"]);
    }
    unset($t);

    $jobs = $pdo->query(
        "SELECT j.public_id AS id, o.public_id AS outageId, t.public_id AS technicianId,
                j.status, DATE_FORMAT(j.assigned_at, '%Y-%m-%d %H:%i') AS assignedAt
         FROM jobs j
         JOIN outages o ON o.id = j.outage_id
         JOIN technicians t ON t.id = j.technician_id
         ORDER BY j.assigned_at DESC"
    )->fetchAll();

    $tickets = $pdo->query(
        "SELECT tk.public_id AS id,
                DATE_FORMAT(tk.opened_at, '%Y-%m-%d %H:%i') AS opened,
                tk.status, tk.priority, tk.component, b.name AS barangay,
                o.public_id AS outageId, t.public_id AS technicianId,
                tk.action_text AS action, tk.linked_note AS linkedNote
         FROM tickets tk
         JOIN barangays b ON b.id = tk.barangay_id
         LEFT JOIN outages o ON o.id = tk.outage_id
         LEFT JOIN technicians t ON t.id = tk.technician_id
         ORDER BY tk.opened_at DESC"
    )->fetchAll();

    $metrics = $pdo->query(
        "SELECT DATE_FORMAT(period_date, '%b') AS label, tickets_opened, tickets_resolved, uptime_pct
         FROM monthly_metrics
         ORDER BY period_date"
    )->fetchAll();

    $components = $pdo->query(
        "SELECT component AS label, incident_count AS `count` FROM component_incidents ORDER BY incident_count DESC"
    )->fetchAll();
    foreach ($components as &$c) {
        $c["count"] = (int) $c["count"];
    }
    unset($c);

    return [
        "center" => [
            (float) geonet_setting("map_center_lat", "10.7794"),
            (float) geonet_setting("map_center_lng", "122.4668"),
        ],
        "defaultZoom" => (int) geonet_setting("map_default_zoom", "14"),
        "napBoxCapacity" => geonet_nap_box_capacity(),
        "napBoxRadiusMeters" => geonet_nap_box_coverage_radius_meters(),
        "barangays" => $barangays,
        "landmarks" => $landmarks,
        "coverageAreas" => $coverageAreas,
        "subscribers" => $subscribers,
        "outages" => $outages,
        "technicians" => $technicians,
        "jobs" => $jobs,
        "tickets" => $tickets,
        "ticketTrend" => [
            "labels" => array_column($metrics, "label"),
            "opened" => array_map("intval", array_column($metrics, "tickets_opened")),
            "resolved" => array_map("intval", array_column($metrics, "tickets_resolved")),
        ],
        "componentMix" => $components,
        "analyticsMonthly" => [
            "labels" => array_column($metrics, "label"),
            "uptime" => array_map("floatval", array_column($metrics, "uptime_pct")),
            "tickets" => array_map("intval", array_column($metrics, "tickets_opened")),
        ],
    ];
}

function geonet_signup_map_data()
{
    geonet_ensure_mapping_schema();
    $pdo = geonet_pdo();
    $barangays = $pdo->query(
        "SELECT id, name, lat, lng FROM barangays ORDER BY name"
    )->fetchAll();
    foreach ($barangays as &$barangay) {
        $barangay["lat"] = (float) $barangay["lat"];
        $barangay["lng"] = (float) $barangay["lng"];
    }
    unset($barangay);

    $areas = $pdo->query(
        "SELECT name, coordinates_json FROM coverage_areas ORDER BY id"
    )->fetchAll();
    foreach ($areas as &$area) {
        $coordinates = json_decode($area["coordinates_json"], true);
        if (!is_array($coordinates)) {
            throw new RuntimeException("A saved coverage area has invalid coordinates.");
        }
        $area["coordinates"] = array_map(function ($point) {
            if (!is_array($point) || !isset($point[0], $point[1])) {
                throw new RuntimeException("A saved coverage area has invalid coordinates.");
            }
            return [(float) $point[0], (float) $point[1]];
        }, $coordinates);
        unset($area["coordinates_json"]);
    }
    unset($area);

    $napBoxes = $pdo->query(
        "SELECT n.id, n.name, n.lat, n.lng, n.barangay_id, b.name AS barangay,
                (SELECT COUNT(*) FROM subscribers s WHERE s.nap_box_id = n.id) +
                (SELECT COUNT(*) FROM subscription_requests sr
                 WHERE sr.nap_box_id = n.id AND sr.status = 'pending') AS occupied
         FROM network_assets n
         LEFT JOIN barangays b ON b.id = n.barangay_id
         WHERE n.asset_type = 'nap'
         ORDER BY n.name"
    )->fetchAll();
    $napBoxes = array_values(array_map(function ($napBox) {
        $occupied = (int) $napBox["occupied"];
        return [
            "id" => (int) $napBox["id"],
            "name" => $napBox["name"],
            "lat" => (float) $napBox["lat"],
            "lng" => (float) $napBox["lng"],
            "barangayId" => $napBox["barangay_id"] === null
                ? null
                : (int) $napBox["barangay_id"],
            "barangay" => $napBox["barangay"],
            "occupied" => $occupied,
            "available" => max(0, geonet_nap_box_capacity() - $occupied),
        ];
    }, $napBoxes));

    return [
        "center" => [
            (float) geonet_setting("map_center_lat", "10.7794"),
            (float) geonet_setting("map_center_lng", "122.4668"),
        ],
        "barangays" => $barangays,
        "coverageAreas" => $areas,
        "napBoxes" => $napBoxes,
        "napBoxRadiusMeters" => geonet_nap_box_coverage_radius_meters(),
    ];
}

function geonet_validate_barangay($name, $lat, $lng)
{
    $name = trim((string) $name);
    if ($name === "" || strlen($name) > 80) {
        throw new InvalidArgumentException("Barangay name must be between 1 and 80 characters.");
    }
    return [
        $name,
        geonet_map_coordinate($lat, -90, 90),
        geonet_map_coordinate($lng, -180, 180),
    ];
}

function geonet_add_barangay($name, $lat, $lng)
{
    [$name, $lat, $lng] = geonet_validate_barangay($name, $lat, $lng);
    $stmt = geonet_pdo()->prepare(
        "INSERT INTO barangays (name, lat, lng) VALUES (?, ?, ?)"
    );
    $stmt->execute([$name, $lat, $lng]);
    geonet_notify_admin_map_activity("Barangay added", $name . " was added to the network map.");
}

function geonet_update_barangay($barangayId, $name, $lat, $lng)
{
    $barangayId = filter_var($barangayId, FILTER_VALIDATE_INT);
    if ($barangayId === false || $barangayId < 1) {
        throw new InvalidArgumentException("Barangay not found.");
    }
    [$name, $lat, $lng] = geonet_validate_barangay($name, $lat, $lng);
    $stmt = geonet_pdo()->prepare(
        "UPDATE barangays SET name = ?, lat = ?, lng = ? WHERE id = ?"
    );
    $stmt->execute([$name, $lat, $lng, $barangayId]);
    if ($stmt->rowCount() === 0) {
        $check = geonet_pdo()->prepare("SELECT id FROM barangays WHERE id = ?");
        $check->execute([$barangayId]);
        if (!$check->fetch()) {
            throw new InvalidArgumentException("Barangay not found.");
        }
    }
    geonet_notify_admin_map_activity("Barangay updated", $name . " was updated on the network map.");
}

function geonet_delete_barangay($barangayId)
{
    $barangayId = filter_var($barangayId, FILTER_VALIDATE_INT);
    if ($barangayId === false || $barangayId < 1) {
        throw new InvalidArgumentException("Barangay not found.");
    }
    $pdo = geonet_pdo();
    $barangayStmt = $pdo->prepare("SELECT id, name FROM barangays WHERE id = ?");
    $barangayStmt->execute([$barangayId]);
    $barangay = $barangayStmt->fetch();
    if (!$barangay) {
        throw new InvalidArgumentException("Barangay not found.");
    }

    $references = [
        "subscribers" => "subscriber accounts",
        "subscription_requests" => "subscription requests",
        "outages" => "outage records",
        "tickets" => "maintenance tickets",
    ];
    foreach ($references as $table => $label) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE barangay_id = ?");
        $stmt->execute([$barangayId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException(
                "Cannot remove {$barangay['name']}: it is used by {$label}. Rename it or move those records first."
            );
        }
    }

    $delete = $pdo->prepare("DELETE FROM barangays WHERE id = ?");
    $delete->execute([$barangayId]);
    geonet_notify_admin_map_activity(
        "Barangay removed",
        $barangay["name"] . " was removed from the network map."
    );
}

function geonet_delete_all_barangays()
{
    $pdo = geonet_pdo();
    $pdo->beginTransaction();
    try {
        $subscriberUsers = $pdo->query(
            "SELECT DISTINCT user_id FROM subscribers
             WHERE user_id IS NOT NULL
               AND barangay_id IN (SELECT id FROM barangays)"
        )->fetchAll(PDO::FETCH_COLUMN);

        $tickets = $pdo->exec(
            "DELETE FROM tickets WHERE barangay_id IN (SELECT id FROM barangays)"
        );
        $requests = $pdo->exec(
            "DELETE FROM subscription_requests WHERE barangay_id IN (SELECT id FROM barangays)"
        );
        $subscribers = $pdo->exec(
            "DELETE FROM subscribers WHERE barangay_id IN (SELECT id FROM barangays)"
        );

        $deleteUser = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'subscriber'");
        foreach ($subscriberUsers as $userId) {
            $deleteUser->execute([$userId]);
        }

        $outages = $pdo->exec(
            "DELETE FROM outages WHERE barangay_id IN (SELECT id FROM barangays)"
        );
        $barangays = $pdo->exec("DELETE FROM barangays");
        $pdo->commit();
        geonet_notify_admin_map_activity(
            "Barangays cleared",
            "All barangays were removed from the network map."
        );

        return [
            "barangays" => $barangays,
            "subscriberAccounts" => count($subscriberUsers),
            "subscribers" => $subscribers,
            "requests" => $requests,
            "outages" => $outages,
            "tickets" => $tickets,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function geonet_map_coordinate($value, $minimum, $maximum)
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException("Map coordinates must be numeric.");
    }
    $coordinate = (float) $value;
    if (!is_finite($coordinate) || $coordinate < $minimum || $coordinate > $maximum) {
        throw new InvalidArgumentException("Map coordinates are outside the valid range.");
    }
    return $coordinate;
}

function geonet_point_in_polygon($lat, $lng, $coordinates)
{
    if (!is_array($coordinates) || count($coordinates) < 3) {
        return false;
    }

    $inside = false;
    $count = count($coordinates);
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        if (
            !is_array($coordinates[$i]) ||
            !isset($coordinates[$i][0], $coordinates[$i][1]) ||
            !is_array($coordinates[$j]) ||
            !isset($coordinates[$j][0], $coordinates[$j][1])
        ) {
            throw new RuntimeException("A saved coverage area has invalid coordinates.");
        }

        $latA = (float) $coordinates[$i][0];
        $lngA = (float) $coordinates[$i][1];
        $latB = (float) $coordinates[$j][0];
        $lngB = (float) $coordinates[$j][1];
        $cross = ($lng - $lngA) * ($latB - $latA) -
            ($lat - $latA) * ($lngB - $lngA);
        if (
            abs($cross) < 1.0e-10 &&
            $lng >= min($lngA, $lngB) - 1.0e-10 &&
            $lng <= max($lngA, $lngB) + 1.0e-10 &&
            $lat >= min($latA, $latB) - 1.0e-10 &&
            $lat <= max($latA, $latB) + 1.0e-10
        ) {
            return true;
        }

        if (($latA > $lat) !== ($latB > $lat)) {
            $intersectionLng = ($lngB - $lngA) * ($lat - $latA) /
                ($latB - $latA) + $lngA;
            if ($lng < $intersectionLng) {
                $inside = !$inside;
            }
        }
    }

    return $inside;
}

function geonet_move_subscriber_pin($publicId, $lat, $lng)
{
    $publicId = trim((string) $publicId);
    if ($publicId === "") {
        throw new InvalidArgumentException("Select a subscriber first.");
    }
    $pdo = geonet_pdo();
    $stmt = $pdo->prepare(
        "UPDATE subscribers SET lat = ?, lng = ?, map_pin_enabled = 1 WHERE public_id = ?"
    );
    $stmt->execute([
        geonet_map_coordinate($lat, -90, 90),
        geonet_map_coordinate($lng, -180, 180),
        $publicId,
    ]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT id FROM subscribers WHERE public_id = ?");
        $check->execute([$publicId]);
        if (!$check->fetch()) {
            throw new InvalidArgumentException("Subscriber not found.");
        }
    }
    geonet_notify_admin_map_activity(
        "Subscriber pin updated",
        $publicId . " was placed or moved on the network map."
    );
}

function geonet_update_subscriber($publicId, $input)
{
    $publicId = trim((string) $publicId);
    $name = trim((string) ($input["name"] ?? ""));
    $barangay = trim((string) ($input["barangay"] ?? ""));
    $address = trim((string) ($input["address"] ?? ""));
    $phone = trim((string) ($input["phone"] ?? ""));
    $plan = trim((string) ($input["plan"] ?? ""));
    $status = (string) ($input["status"] ?? "");

    if ($publicId === "") {
        throw new InvalidArgumentException("Subscriber ID is required.");
    }
    if ($name === "" || strlen($name) > 120) {
        throw new InvalidArgumentException("Name must be between 1 and 120 characters.");
    }
    if ($barangay === "" || $address === "" || strlen($address) > 255) {
        throw new InvalidArgumentException("Select a barangay and enter an address of at most 255 characters.");
    }
    if ($phone === "" || strlen($phone) > 20) {
        throw new InvalidArgumentException("Phone must be between 1 and 20 characters.");
    }
    if ($plan === "" || strlen($plan) > 80) {
        throw new InvalidArgumentException("Plan must be between 1 and 80 characters.");
    }
    if (!in_array($status, ["online", "offline", "intermittent"], true)) {
        throw new InvalidArgumentException("Choose a valid subscriber status.");
    }

    $pdo = geonet_pdo();
    geonet_ensure_notifications_schema();
    $barangayStmt = $pdo->prepare("SELECT id FROM barangays WHERE name = ?");
    $barangayStmt->execute([$barangay]);
    $barangayId = $barangayStmt->fetchColumn();
    if ($barangayId === false) {
        throw new InvalidArgumentException("Selected barangay was not found.");
    }

    $subscriberStmt = $pdo->prepare(
        "SELECT id, user_id FROM subscribers WHERE public_id = ?"
    );
    $subscriberStmt->execute([$publicId]);
    $subscriber = $subscriberStmt->fetch();
    if (!$subscriber) {
        throw new InvalidArgumentException("Subscriber not found.");
    }

    $pdo->beginTransaction();
    try {
        $updateStmt = $pdo->prepare(
            "UPDATE subscribers
             SET full_name = ?, barangay_id = ?, address = ?, phone = ?,
                 plan_name = ?, status = ?
             WHERE id = ?"
        );
        $updateStmt->execute([
            $name,
            $barangayId,
            $address,
            $phone,
            $plan,
            $status,
            $subscriber["id"],
        ]);
        if ($subscriber["user_id"] !== null) {
            $userStmt = $pdo->prepare("UPDATE users SET display_name = ? WHERE id = ?");
            $userStmt->execute([$name, $subscriber["user_id"]]);
            geonet_notify_user(
                $subscriber["user_id"],
                "account",
                "Account details updated",
                "An administrator updated your subscriber account details.",
                "profile.php"
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    geonet_notify_admin_map_activity(
        "Subscriber details updated",
        $publicId . " was updated. Its network map pin or status may have changed."
    );
}

function geonet_delete_subscriber($publicId)
{
    $publicId = trim((string) $publicId);
    if ($publicId === "") {
        throw new InvalidArgumentException("Subscriber ID is required.");
    }

    $pdo = geonet_pdo();
    $stmt = $pdo->prepare("SELECT id, user_id FROM subscribers WHERE public_id = ?");
    $stmt->execute([$publicId]);
    $subscriber = $stmt->fetch();
    if (!$subscriber) {
        throw new InvalidArgumentException("Subscriber not found.");
    }

    $loginRemoved = false;
    $pdo->beginTransaction();
    try {
        $deleteSubscriber = $pdo->prepare("DELETE FROM subscribers WHERE id = ?");
        $deleteSubscriber->execute([$subscriber["id"]]);
        if ($subscriber["user_id"] !== null) {
            $remainingConnections = $pdo->prepare(
                "SELECT id FROM subscribers WHERE user_id = ? LIMIT 1"
            );
            $remainingConnections->execute([$subscriber["user_id"]]);
            if (!$remainingConnections->fetch()) {
                $deleteUser = $pdo->prepare(
                    "DELETE FROM users WHERE id = ? AND role = 'subscriber'"
                );
                $deleteUser->execute([$subscriber["user_id"]]);
                $loginRemoved = $deleteUser->rowCount() > 0;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    geonet_notify_admin_map_activity(
        "Subscriber removed",
        $publicId . " was removed from the network map."
    );
    return $loginRemoved;
}

function geonet_technician_fields($input, $defaultAvailability = false)
{
    $name = trim((string) ($input["name"] ?? ""));
    $phone = trim((string) ($input["phone"] ?? ""));
    $jobTitle = trim((string) ($input["jobTitle"] ?? ""));
    $availability = (string) ($input["availability"] ?? ($defaultAvailability ? "available" : ""));
    if ($name === "" || strlen($name) > 120) {
        throw new InvalidArgumentException("Name must be between 1 and 120 characters.");
    }
    if ($phone === "" || strlen($phone) > 20) {
        throw new InvalidArgumentException("Phone must be between 1 and 20 characters.");
    }
    if ($jobTitle === "" || strlen($jobTitle) > 80) {
        throw new InvalidArgumentException("Job title must be between 1 and 80 characters.");
    }
    if (!in_array($availability, ["available", "on_duty", "en_route", "off_duty"], true)) {
        throw new InvalidArgumentException("Choose a valid technician availability.");
    }
    return [$name, $phone, $jobTitle, $availability];
}

function geonet_validate_account_email($email, $requiredDomain)
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new InvalidArgumentException("Enter a valid email address.");
    }

    $domain = strtolower(substr(strrchr($email, "@"), 1));
    if ($domain !== $requiredDomain) {
        throw new InvalidArgumentException("This account must use an email address ending in @" . $requiredDomain . ".");
    }
}

function geonet_add_technician($input)
{
    [$name, $phone, $jobTitle, $availability] = geonet_technician_fields($input, true);
    $username = trim((string) ($input["username"] ?? ""));
    if ($username === "" || strlen($username) > 64 || !preg_match('/^[A-Za-z0-9._%+-]+$/D', $username)) {
        throw new InvalidArgumentException("Enter a valid username using letters, numbers, dots, underscores, or hyphens.");
    }
    $email = strtolower($username) . "@tech.rlink.ph";
    $password = (string) ($input["password"] ?? "");
    geonet_validate_account_email($email, "tech.rlink.ph");
    if (strlen($password) < 8) {
        throw new InvalidArgumentException("Password must be at least 8 characters.");
    }

    $pdo = geonet_pdo();
    geonet_ensure_notifications_schema();
    $emailStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $emailStmt->execute([$email]);
    if ($emailStmt->fetch()) {
        throw new InvalidArgumentException("An account already uses this email address.");
    }

    $pdo->beginTransaction();
    try {
        $publicId = geonet_next_public_id("technicians", "TECH-");
        $userStmt = $pdo->prepare(
            "INSERT INTO users (email, password_hash, display_name, role, is_active)
             VALUES (?, ?, ?, 'technician', 1)"
        );
        $userStmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
        $userId = (int) $pdo->lastInsertId();

        $photo = "";
        foreach (preg_split("/\\s+/", $name) as $part) {
            if ($part !== "") {
                $photo .= strtoupper(substr($part, 0, 1));
                if (strlen($photo) >= 2) {
                    break;
                }
            }
        }
        $stmt = $pdo->prepare(
            "INSERT INTO technicians
                (public_id, user_id, full_name, phone, job_title, availability, photo_initials)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$publicId, $userId, $name, $phone, $jobTitle, $availability, $photo]);
        geonet_notify_user(
            $userId,
            "account",
            "Welcome to GeoNet Connect",
            "Your technician account is ready. You can review your profile and assigned work.",
            "technician.php"
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $publicId;
}

function geonet_update_technician($publicId, $input)
{
    [$name, $phone, $jobTitle, $availability] = geonet_technician_fields($input);
    $pdo = geonet_pdo();
    geonet_ensure_notifications_schema();
    $stmt = $pdo->prepare("SELECT id, user_id FROM technicians WHERE public_id = ?");
    $stmt->execute([trim((string) $publicId)]);
    $technician = $stmt->fetch();
    if (!$technician) {
        throw new InvalidArgumentException("Technician not found.");
    }

    $pdo->beginTransaction();
    try {
        $photo = "";
        foreach (preg_split("/\\s+/", $name) as $part) {
            if ($part !== "") {
                $photo .= strtoupper(substr($part, 0, 1));
                if (strlen($photo) >= 2) {
                    break;
                }
            }
        }
        $update = $pdo->prepare(
            "UPDATE technicians
             SET full_name = ?, phone = ?, job_title = ?, availability = ?, photo_initials = ?
             WHERE id = ?"
        );
        $update->execute([$name, $phone, $jobTitle, $availability, $photo, $technician["id"]]);
        if ($technician["user_id"] !== null) {
            $userUpdate = $pdo->prepare("UPDATE users SET display_name = ? WHERE id = ?");
            $userUpdate->execute([$name, $technician["user_id"]]);
            geonet_notify_user(
                $technician["user_id"],
                "account",
                "Account details updated",
                "An administrator updated your technician account details.",
                "profile.php"
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function geonet_update_own_technician_availability($userId, $availability)
{
    if (!is_string($availability)) {
        throw new InvalidArgumentException("Choose a valid availability status.");
    }
    if (!in_array($availability, ["available", "on_duty", "en_route", "off_duty"], true)) {
        throw new InvalidArgumentException("Choose a valid availability status.");
    }

    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        throw new InvalidArgumentException("Technician profile not found.");
    }

    $stmt = geonet_pdo()->prepare(
        "UPDATE technicians SET availability = ? WHERE user_id = ?"
    );
    $stmt->execute([$availability, $userId]);
    if ($stmt->rowCount() === 0) {
        $check = geonet_pdo()->prepare("SELECT id FROM technicians WHERE user_id = ?");
        $check->execute([$userId]);
        if (!$check->fetch()) {
            throw new InvalidArgumentException("Technician profile not found.");
        }
    }

    return $availability;
}

function geonet_delete_technician($publicId)
{
    $pdo = geonet_pdo();
    $stmt = $pdo->prepare("SELECT id, user_id FROM technicians WHERE public_id = ?");
    $stmt->execute([trim((string) $publicId)]);
    $technician = $stmt->fetch();
    if (!$technician) {
        throw new InvalidArgumentException("Technician not found.");
    }

    $pdo->beginTransaction();
    try {
        $deleteJobs = $pdo->prepare("DELETE FROM jobs WHERE technician_id = ?");
        $deleteJobs->execute([$technician["id"]]);
        $deleteTechnician = $pdo->prepare("DELETE FROM technicians WHERE id = ?");
        $deleteTechnician->execute([$technician["id"]]);
        if ($technician["user_id"] !== null) {
            $deleteUser = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'technician'");
            $deleteUser->execute([$technician["user_id"]]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function geonet_add_nap_pin($name, $lat, $lng)
{
    $name = trim((string) $name);
    if ($name === "" || strlen($name) > 120) {
        throw new InvalidArgumentException("NAP box name must be between 1 and 120 characters.");
    }
    $stmt = geonet_pdo()->prepare(
        "INSERT INTO network_assets (barangay_id, name, asset_type, lat, lng, map_pin_enabled)
         VALUES (NULL, ?, 'nap', ?, ?, 1)"
    );
    $stmt->execute([
        $name,
        geonet_map_coordinate($lat, -90, 90),
        geonet_map_coordinate($lng, -180, 180),
    ]);
    geonet_notify_admin_map_activity("NAP box added", $name . " was added to the network map.");
}

function geonet_update_nap_pin($assetId, $name, $lat, $lng)
{
    $assetId = filter_var($assetId, FILTER_VALIDATE_INT);
    if ($assetId === false || $assetId < 1) {
        throw new InvalidArgumentException("NAP box not found.");
    }

    $name = trim((string) $name);
    if ($name === "" || strlen($name) > 120) {
        throw new InvalidArgumentException("NAP box name must be between 1 and 120 characters.");
    }

    $stmt = geonet_pdo()->prepare(
        "UPDATE network_assets
         SET name = ?, lat = ?, lng = ?, map_pin_enabled = 1
         WHERE id = ? AND asset_type = 'nap'"
    );
    $stmt->execute([
        $name,
        geonet_map_coordinate($lat, -90, 90),
        geonet_map_coordinate($lng, -180, 180),
        $assetId,
    ]);
    if ($stmt->rowCount() === 0) {
        $exists = geonet_pdo()->prepare(
            "SELECT COUNT(*) FROM network_assets WHERE id = ? AND asset_type = 'nap'"
        );
        $exists->execute([$assetId]);
        if ((int) $exists->fetchColumn() === 0) {
            throw new InvalidArgumentException("NAP box not found.");
        }
    }
    geonet_notify_admin_map_activity(
        "NAP box updated",
        $name . " was renamed or moved on the network map."
    );
}

function geonet_assign_nap_box_barangay($assetId, $barangayId)
{
    $assetId = filter_var($assetId, FILTER_VALIDATE_INT);
    if ($assetId === false || $assetId < 1) {
        throw new InvalidArgumentException("NAP box not found.");
    }

    if ($barangayId === null || $barangayId === "") {
        $barangayId = null;
    } else {
        $barangayId = filter_var($barangayId, FILTER_VALIDATE_INT);
        if ($barangayId === false || $barangayId < 1) {
            throw new InvalidArgumentException("Choose a valid barangay.");
        }
        $barangayStmt = geonet_pdo()->prepare("SELECT id FROM barangays WHERE id = ?");
        $barangayStmt->execute([$barangayId]);
        if (!$barangayStmt->fetch()) {
            throw new InvalidArgumentException("Choose a valid barangay.");
        }
    }

    $stmt = geonet_pdo()->prepare(
        "UPDATE network_assets SET barangay_id = ?
         WHERE id = ? AND asset_type = 'nap'"
    );
    $stmt->execute([$barangayId, $assetId]);
    if ($stmt->rowCount() === 0) {
        $exists = geonet_pdo()->prepare(
            "SELECT COUNT(*) FROM network_assets WHERE id = ? AND asset_type = 'nap'"
        );
        $exists->execute([$assetId]);
        if ((int) $exists->fetchColumn() === 0) {
            throw new InvalidArgumentException("NAP box not found.");
        }
    }
    geonet_notify_admin_map_activity(
        "NAP box linked to barangay",
        "A NAP box barangay assignment was changed on the network map."
    );
}

function geonet_delete_nap_pin($assetId)
{
    $assetId = filter_var($assetId, FILTER_VALIDATE_INT);
    if ($assetId === false || $assetId < 1) {
        throw new InvalidArgumentException("NAP box not found.");
    }

    $assignedStmt = geonet_pdo()->prepare(
        "SELECT
            (SELECT COUNT(*) FROM subscribers WHERE nap_box_id = ?) +
            (SELECT COUNT(*) FROM subscription_requests
             WHERE nap_box_id = ? AND status = 'pending')"
    );
    $assignedStmt->execute([$assetId, $assetId]);
    if ((int) $assignedStmt->fetchColumn() > 0) {
        throw new InvalidArgumentException(
            "This NAP box has assigned subscribers or pending requests and cannot be removed."
        );
    }

    $stmt = geonet_pdo()->prepare(
        "DELETE FROM network_assets WHERE id = ? AND asset_type = 'nap'"
    );
    $stmt->execute([$assetId]);
    if ($stmt->rowCount() === 0) {
        throw new InvalidArgumentException("NAP box not found.");
    }
    geonet_notify_admin_map_activity("NAP box removed", "A NAP box was removed from the network map.");
}

function geonet_validate_coverage_area($name, $coordinates)
{
    $name = trim((string) $name);
    if ($name === "" || strlen($name) > 120) {
        throw new InvalidArgumentException("Coverage area name must be between 1 and 120 characters.");
    }
    if (!is_array($coordinates) || count($coordinates) < 3 || count($coordinates) > 100) {
        throw new InvalidArgumentException("A coverage area needs between 3 and 100 map points.");
    }
    $validated = [];
    foreach ($coordinates as $point) {
        if (!is_array($point) || !isset($point["lat"], $point["lng"])) {
            throw new InvalidArgumentException("Coverage area points are invalid.");
        }
        $validated[] = [
            geonet_map_coordinate($point["lat"], -90, 90),
            geonet_map_coordinate($point["lng"], -180, 180),
        ];
    }
    return [$name, $validated];
}

function geonet_add_coverage_area($name, $coordinates)
{
    [$name, $validated] = geonet_validate_coverage_area($name, $coordinates);
    $encoded = json_encode($validated);
    if ($encoded === false) {
        throw new RuntimeException("Could not encode coverage area coordinates.");
    }
    $stmt = geonet_pdo()->prepare(
        "INSERT INTO coverage_areas (name, coordinates_json) VALUES (?, ?)"
    );
    $stmt->execute([$name, $encoded]);
    geonet_notify_admin_map_activity(
        "Coverage area added",
        $name . " was added to the network map."
    );
}

function geonet_update_coverage_area($areaId, $name, $coordinates)
{
    [$name, $validated] = geonet_validate_coverage_area($name, $coordinates);
    $areaId = filter_var($areaId, FILTER_VALIDATE_INT);
    if ($areaId === false || $areaId < 1) {
        throw new InvalidArgumentException("Coverage area not found.");
    }

    $encoded = json_encode($validated);
    if ($encoded === false) {
        throw new RuntimeException("Could not encode coverage area coordinates.");
    }

    $stmt = geonet_pdo()->prepare(
        "UPDATE coverage_areas SET name = ?, coordinates_json = ? WHERE id = ?"
    );
    $stmt->execute([$name, $encoded, $areaId]);
    if ($stmt->rowCount() === 0) {
        $check = geonet_pdo()->prepare("SELECT id FROM coverage_areas WHERE id = ?");
        $check->execute([$areaId]);
        if (!$check->fetch()) {
            throw new InvalidArgumentException("Coverage area not found.");
        }
    }
    geonet_notify_admin_map_activity(
        "Coverage area updated",
        $name . " was updated on the network map."
    );
}

function geonet_available_subscriber_plans()
{
    return [
        "Fiber Starter - 50 Mbps",
        "Fiber Standard - 100 Mbps",
        "Fiber Plus - 150 Mbps",
        "Fiber Advanced - 200 Mbps",
        "Fiber Premium - 250 Mbps",
        "Fiber Enterprise - 300 Mbps",
    ];
}

function geonet_request_own_plan_change($userId, $requestedPlan, $connectionId = null)
{
    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    $requestedPlan = trim((string) $requestedPlan);
    if ($userId === false || $userId < 1) {
        throw new InvalidArgumentException("Account not found.");
    }
    if (!in_array($requestedPlan, geonet_available_subscriber_plans(), true)) {
        throw new InvalidArgumentException("Choose one of the available plans.");
    }

    geonet_ensure_mapping_schema();
    $pdo = geonet_pdo();
    $pdo->beginTransaction();
    try {
        $subscriberStmt = $pdo->prepare(
            "SELECT id, public_id, full_name, plan_name FROM subscribers
             WHERE user_id = ? AND (? IS NULL OR public_id = ?)
             ORDER BY id LIMIT 1 FOR UPDATE"
        );
        $connectionId = $connectionId === null || $connectionId === ""
            ? null
            : trim((string) $connectionId);
        $subscriberStmt->execute([$userId, $connectionId, $connectionId]);
        $subscriber = $subscriberStmt->fetch();
        if (!$subscriber) {
            throw new InvalidArgumentException("Subscriber account not found.");
        }
        if ($subscriber["plan_name"] === $requestedPlan) {
            throw new InvalidArgumentException("You are already on this plan.");
        }
        $pendingStmt = $pdo->prepare(
            "SELECT id FROM subscriber_plan_change_requests
             WHERE subscriber_id = ? AND status = 'pending' LIMIT 1"
        );
        $pendingStmt->execute([$subscriber["id"]]);
        if ($pendingStmt->fetch()) {
            throw new InvalidArgumentException("You already have a plan change request awaiting review.");
        }
        $insertStmt = $pdo->prepare(
            "INSERT INTO subscriber_plan_change_requests
                (subscriber_id, current_plan, requested_plan)
             VALUES (?, ?, ?)"
        );
        $insertStmt->execute([$subscriber["id"], $subscriber["plan_name"], $requestedPlan]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    geonet_ensure_notifications_schema();
    geonet_notify_role(
        "admin",
        "plan_change",
        "Subscriber plan change request",
        $subscriber["full_name"] . " (" . $subscriber["public_id"] .
            ") requested a plan change to " . $requestedPlan . ".",
        "dashboard.php#subscribers"
    );
    return geonet_own_plan_change_request($userId, $subscriber["public_id"]);
}

function geonet_own_plan_change_request($userId, $connectionId = null)
{
    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        throw new InvalidArgumentException("Account not found.");
    }
    geonet_ensure_mapping_schema();
    $stmt = geonet_pdo()->prepare(
        "SELECT r.current_plan AS currentPlan, r.requested_plan AS requestedPlan,
                r.status, DATE_FORMAT(r.created_at, '%Y-%m-%d %H:%i') AS requestedAt
         FROM subscriber_plan_change_requests r
         JOIN subscribers s ON s.id = r.subscriber_id
         WHERE s.user_id = ? AND (? IS NULL OR s.public_id = ?) AND r.status = 'pending'
         ORDER BY r.created_at DESC LIMIT 1"
    );
    $connectionId = $connectionId === null || $connectionId === ""
        ? null
        : trim((string) $connectionId);
    $stmt->execute([$userId, $connectionId, $connectionId]);
    return $stmt->fetch() ?: null;
}

function geonet_own_plan_change_requests($userId)
{
    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        throw new InvalidArgumentException("Account not found.");
    }
    geonet_ensure_mapping_schema();
    $stmt = geonet_pdo()->prepare(
        "SELECT s.public_id AS connectionId, r.current_plan AS currentPlan,
                r.requested_plan AS requestedPlan, r.status,
                DATE_FORMAT(r.created_at, '%Y-%m-%d %H:%i') AS requestedAt
         FROM subscriber_plan_change_requests r
         JOIN subscribers s ON s.id = r.subscriber_id
         WHERE s.user_id = ? AND r.status = 'pending'
         ORDER BY r.created_at DESC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function geonet_plan_change_requests()
{
    geonet_ensure_mapping_schema();
    return geonet_pdo()->query(
        "SELECT r.id, s.public_id AS subscriberId, s.full_name AS name,
                r.current_plan AS currentPlan, r.requested_plan AS requestedPlan,
                DATE_FORMAT(r.created_at, '%Y-%m-%d %H:%i') AS requestedAt
         FROM subscriber_plan_change_requests r
         JOIN subscribers s ON s.id = r.subscriber_id
         WHERE r.status = 'pending'
         ORDER BY r.created_at, r.id"
    )->fetchAll();
}

function geonet_review_plan_change_request($requestId, $decision, $adminUserId)
{
    $requestId = filter_var($requestId, FILTER_VALIDATE_INT);
    $adminUserId = filter_var($adminUserId, FILTER_VALIDATE_INT);
    if ($requestId === false || $requestId < 1) {
        throw new InvalidArgumentException("Choose a valid plan change request.");
    }
    if (!in_array($decision, ["approve", "reject"], true)) {
        throw new InvalidArgumentException("Choose approve or reject.");
    }
    if ($adminUserId === false || $adminUserId < 1) {
        throw new InvalidArgumentException("Admin account not found.");
    }

    geonet_ensure_mapping_schema();
    $pdo = geonet_pdo();
    $pdo->beginTransaction();
    try {
        $requestStmt = $pdo->prepare(
            "SELECT r.subscriber_id, r.requested_plan, s.user_id
             FROM subscriber_plan_change_requests r
             JOIN subscribers s ON s.id = r.subscriber_id
             WHERE r.id = ? AND r.status = 'pending' FOR UPDATE"
        );
        $requestStmt->execute([$requestId]);
        $request = $requestStmt->fetch();
        if (!$request) {
            throw new InvalidArgumentException("This plan change request is no longer pending.");
        }
        if ($decision === "approve") {
            $updateSubscriber = $pdo->prepare(
                "UPDATE subscribers SET plan_name = ? WHERE id = ?"
            );
            $updateSubscriber->execute([$request["requested_plan"], $request["subscriber_id"]]);
        }
        $updateRequest = $pdo->prepare(
            "UPDATE subscriber_plan_change_requests
             SET status = ?, reviewed_at = CURRENT_TIMESTAMP, reviewed_by = ?
             WHERE id = ? AND status = 'pending'"
        );
        $updateRequest->execute([
            $decision === "approve" ? "approved" : "rejected",
            $adminUserId,
            $requestId,
        ]);
        if ($updateRequest->rowCount() !== 1) {
            throw new RuntimeException("Could not complete the plan change review.");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    geonet_ensure_notifications_schema();
    if ($request["user_id"] !== null) {
        geonet_notify_user(
            $request["user_id"],
            "plan_change",
            $decision === "approve" ? "Plan change approved" : "Plan change declined",
            $decision === "approve"
                ? "Your plan has been changed to " . $request["requested_plan"] . "."
                : "Your request to change plans was declined.",
            "subscriber.php"
        );
    }
}

function geonet_create_subscription_request($input)
{
    $name = trim((string) ($input["name"] ?? ""));
    $email = strtolower(trim((string) ($input["email"] ?? "")));
    $password = (string) ($input["password"] ?? "");
    $barangay = trim((string) ($input["barangay"] ?? ""));
    $address = trim((string) ($input["address"] ?? ""));
    $phone = trim((string) ($input["phone"] ?? ""));
    $plan = trim((string) ($input["plan"] ?? ""));
    $napBoxId = filter_var($input["nap_box_id"] ?? null, FILTER_VALIDATE_INT);
    $lat = geonet_map_coordinate($input["lat"] ?? null, -90, 90);
    $lng = geonet_map_coordinate($input["lng"] ?? null, -180, 180);

    if ($name === "" || strlen($name) > 120) {
        throw new InvalidArgumentException("Name must be between 1 and 120 characters.");
    }
    geonet_validate_account_email($email, "sub.rlink.ph");
    if (strlen($password) < 8) {
        throw new InvalidArgumentException("Password must be at least 8 characters.");
    }
    if ($address === "" || strlen($address) > 255) {
        throw new InvalidArgumentException("Address must be between 1 and 255 characters.");
    }
    if ($phone === "" || strlen($phone) > 20) {
        throw new InvalidArgumentException("Phone must be between 1 and 20 characters.");
    }
    if (!in_array($plan, geonet_available_subscriber_plans(), true)) {
        throw new InvalidArgumentException("Choose one of the available plans.");
    }
    if ($napBoxId === false || $napBoxId < 1) {
        throw new InvalidArgumentException("Choose a NAP box for your service.");
    }

    $pdo = geonet_pdo();
    geonet_ensure_mapping_schema();
    geonet_ensure_notifications_schema();
    $userStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $userStmt->execute([$email]);
    if ($userStmt->fetch()) {
        throw new InvalidArgumentException("An account already uses this username.");
    }
    $pendingStmt = $pdo->prepare(
        "SELECT id FROM subscription_requests
         WHERE email = ? AND status = 'pending' AND existing_user_id IS NULL"
    );
    $pendingStmt->execute([$email]);
    if ($pendingStmt->fetch()) {
        throw new InvalidArgumentException("A request for this username is already awaiting review.");
    }

    $barangayStmt = $pdo->prepare("SELECT id FROM barangays WHERE name = ?");
    $barangayStmt->execute([$barangay]);
    $barangayId = $barangayStmt->fetchColumn();
    if ($barangayId === false) {
        throw new InvalidArgumentException("Select a valid barangay.");
    }

    $coverageAreas = $pdo->query(
        "SELECT coordinates_json FROM coverage_areas"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$coverageAreas) {
        throw new InvalidArgumentException("Network coverage has not been configured yet.");
    }
    $covered = false;
    foreach ($coverageAreas as $coordinatesJson) {
        $coordinates = json_decode($coordinatesJson, true);
        if (!is_array($coordinates)) {
            throw new RuntimeException("A saved coverage area has invalid coordinates.");
        }
        if (geonet_point_in_polygon($lat, $lng, $coordinates)) {
            $covered = true;
            break;
        }
    }
    if (!$covered) {
        throw new InvalidArgumentException("Choose a pin location inside the network coverage area.");
    }

    $pdo->beginTransaction();
    try {
        $napBoxStmt = $pdo->prepare(
            "SELECT id, lat, lng, barangay_id FROM network_assets
             WHERE id = ? AND asset_type = 'nap' FOR UPDATE"
        );
        $napBoxStmt->execute([$napBoxId]);
        $napBox = $napBoxStmt->fetch();
        if (!$napBox) {
            throw new InvalidArgumentException("Choose a valid NAP box.");
        }
        if ((int) $napBox["barangay_id"] !== (int) $barangayId) {
            throw new InvalidArgumentException(
                "Choose a NAP box connected to your selected barangay."
            );
        }
        if (
            geonet_distance_meters(
                $lat,
                $lng,
                (float) $napBox["lat"],
                (float) $napBox["lng"]
            ) > geonet_nap_box_coverage_radius_meters()
        ) {
            throw new InvalidArgumentException(
                "Choose a service location within " .
                geonet_nap_box_coverage_radius_meters() .
                " meters of the selected NAP box."
            );
        }
        $usageStmt = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM subscribers WHERE nap_box_id = ?) +
                (SELECT COUNT(*) FROM subscription_requests
                 WHERE nap_box_id = ? AND status = 'pending')"
        );
        $usageStmt->execute([$napBoxId, $napBoxId]);
        if ((int) $usageStmt->fetchColumn() >= geonet_nap_box_capacity()) {
            throw new InvalidArgumentException(
                "That NAP box has reached its " . geonet_nap_box_capacity() .
                "-client capacity. Choose another NAP box."
            );
        }
        $insert = $pdo->prepare(
            "INSERT INTO subscription_requests
                (full_name, email, password_hash, barangay_id, nap_box_id,
                 address, phone, plan_name, lat, lng)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insert->execute([
            $name,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $barangayId,
            $napBoxId,
            $address,
            $phone,
            $plan,
            $lat,
            $lng,
        ]);
        geonet_notify_role(
            "admin",
            "service",
            "New subscription request",
            $name . " requested " . $plan . " service in " . $barangay . ".",
            "dashboard.php#subscribers"
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function geonet_create_additional_connection_request($userId, $input)
{
    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        throw new InvalidArgumentException("Subscriber account not found.");
    }
    $barangay = trim((string) ($input["barangay"] ?? ""));
    $address = trim((string) ($input["address"] ?? ""));
    $phone = trim((string) ($input["phone"] ?? ""));
    $plan = trim((string) ($input["plan"] ?? ""));
    $napBoxId = filter_var($input["nap_box_id"] ?? null, FILTER_VALIDATE_INT);
    $lat = geonet_map_coordinate($input["lat"] ?? null, -90, 90);
    $lng = geonet_map_coordinate($input["lng"] ?? null, -180, 180);

    if ($barangay === "") {
        throw new InvalidArgumentException("Choose a barangay.");
    }
    if ($address === "" || strlen($address) > 255) {
        throw new InvalidArgumentException("Service address must be between 1 and 255 characters.");
    }
    if ($phone === "" || strlen($phone) > 20) {
        throw new InvalidArgumentException("Phone must be between 1 and 20 characters.");
    }
    if (!in_array($plan, geonet_available_subscriber_plans(), true)) {
        throw new InvalidArgumentException("Choose one of the available plans.");
    }
    if ($napBoxId === false || $napBoxId < 1) {
        throw new InvalidArgumentException("Choose a NAP box for this connection.");
    }

    $pdo = geonet_pdo();
    geonet_ensure_mapping_schema();
    geonet_ensure_notifications_schema();
    $accountStmt = $pdo->prepare(
        "SELECT u.id, u.email, u.password_hash, u.display_name
         FROM users u
         JOIN subscribers s ON s.user_id = u.id
         WHERE u.id = ? AND u.role = 'subscriber' AND u.is_active = 1
         ORDER BY s.id LIMIT 1"
    );
    $accountStmt->execute([$userId]);
    $account = $accountStmt->fetch();
    if (!$account) {
        throw new InvalidArgumentException("Subscriber account not found.");
    }

    $barangayStmt = $pdo->prepare("SELECT id FROM barangays WHERE name = ?");
    $barangayStmt->execute([$barangay]);
    $barangayId = $barangayStmt->fetchColumn();
    if ($barangayId === false) {
        throw new InvalidArgumentException("Select a valid barangay.");
    }
    $coverageAreas = $pdo->query(
        "SELECT coordinates_json FROM coverage_areas"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$coverageAreas) {
        throw new InvalidArgumentException("Network coverage has not been configured yet.");
    }
    $covered = false;
    foreach ($coverageAreas as $coordinatesJson) {
        $coordinates = json_decode($coordinatesJson, true);
        if (!is_array($coordinates)) {
            throw new RuntimeException("A saved coverage area has invalid coordinates.");
        }
        if (geonet_point_in_polygon($lat, $lng, $coordinates)) {
            $covered = true;
            break;
        }
    }
    if (!$covered) {
        throw new InvalidArgumentException("Choose a pin location inside the network coverage area.");
    }

    $pdo->beginTransaction();
    try {
        $userLockStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
        $userLockStmt->execute([$userId]);
        if (!$userLockStmt->fetch()) {
            throw new InvalidArgumentException("Subscriber account not found.");
        }
        $pendingRequestStmt = $pdo->prepare(
            "SELECT id FROM subscription_requests
             WHERE existing_user_id = ? AND status = 'pending' LIMIT 1"
        );
        $pendingRequestStmt->execute([$userId]);
        if ($pendingRequestStmt->fetch()) {
            throw new InvalidArgumentException(
                "You already have an additional connection request awaiting review."
            );
        }
        $napBoxStmt = $pdo->prepare(
            "SELECT id, lat, lng, barangay_id FROM network_assets
             WHERE id = ? AND asset_type = 'nap' FOR UPDATE"
        );
        $napBoxStmt->execute([$napBoxId]);
        $napBox = $napBoxStmt->fetch();
        if (!$napBox || (int) $napBox["barangay_id"] !== (int) $barangayId) {
            throw new InvalidArgumentException(
                "Choose a NAP box connected to your selected barangay."
            );
        }
        if (
            geonet_distance_meters(
                $lat,
                $lng,
                (float) $napBox["lat"],
                (float) $napBox["lng"]
            ) > geonet_nap_box_coverage_radius_meters()
        ) {
            throw new InvalidArgumentException(
                "Choose a service location within " .
                geonet_nap_box_coverage_radius_meters() .
                " meters of the selected NAP box."
            );
        }
        $usageStmt = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM subscribers WHERE nap_box_id = ?) +
                (SELECT COUNT(*) FROM subscription_requests
                 WHERE nap_box_id = ? AND status = 'pending')"
        );
        $usageStmt->execute([$napBoxId, $napBoxId]);
        if ((int) $usageStmt->fetchColumn() >= geonet_nap_box_capacity()) {
            throw new InvalidArgumentException(
                "That NAP box has reached its " . geonet_nap_box_capacity() .
                "-client capacity. Choose another NAP box."
            );
        }
        $insert = $pdo->prepare(
            "INSERT INTO subscription_requests
                (full_name, email, password_hash, existing_user_id, barangay_id, nap_box_id,
                 address, phone, plan_name, lat, lng)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insert->execute([
            $account["display_name"],
            $account["email"],
            $account["password_hash"],
            $userId,
            $barangayId,
            $napBoxId,
            $address,
            $phone,
            $plan,
            $lat,
            $lng,
        ]);
        geonet_notify_role(
            "admin",
            "service",
            "Additional connection request",
            $account["display_name"] . " requested an additional " . $plan .
            " connection in " . $barangay . ".",
            "dashboard.php#subscribers"
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function geonet_own_additional_connection_request($userId)
{
    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        throw new InvalidArgumentException("Subscriber account not found.");
    }
    geonet_ensure_mapping_schema();
    $stmt = geonet_pdo()->prepare(
        "SELECT sr.plan_name AS plan, sr.address, b.name AS barangay, n.name AS napBox,
                DATE_FORMAT(sr.created_at, '%Y-%m-%d %H:%i') AS requestedAt
         FROM subscription_requests sr
         JOIN barangays b ON b.id = sr.barangay_id
         LEFT JOIN network_assets n ON n.id = sr.nap_box_id
         WHERE sr.existing_user_id = ? AND sr.status = 'pending'
         ORDER BY sr.created_at DESC LIMIT 1"
    );
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function geonet_subscription_requests()
{
    geonet_ensure_mapping_schema();
    return geonet_pdo()->query(
        "SELECT sr.id, sr.full_name AS name, sr.email, sr.address, sr.phone,
                sr.plan_name AS plan, sr.lat, sr.lng, sr.status,
                (sr.existing_user_id IS NOT NULL) AS isAdditional,
                b.name AS barangay, n.name AS napBox,
                t.public_id AS technicianId, t.full_name AS technicianName,
                sr.technician_response AS technicianResponse,
                sr.technician_work_status AS technicianWorkStatus,
                sr.technician_work_reason AS technicianWorkReason,
                DATE_FORMAT(sr.created_at, '%Y-%m-%d %H:%i') AS requestedAt
         FROM subscription_requests sr
         JOIN barangays b ON b.id = sr.barangay_id
         LEFT JOIN network_assets n ON n.id = sr.nap_box_id
         LEFT JOIN technicians t ON t.id = sr.assigned_technician_id
         WHERE sr.status = 'pending'
            OR (sr.status = 'approved' AND sr.technician_work_reason IS NOT NULL
                AND sr.technician_work_reason <> '')
         ORDER BY sr.created_at ASC, sr.id ASC"
    )->fetchAll();
}

function geonet_dispatch_subscription_request($requestId, $technicianPublicId, $requireUnfinished = false)
{
    $requestId = filter_var($requestId, FILTER_VALIDATE_INT);
    $technicianPublicId = trim((string) $technicianPublicId);
    if ($requestId === false || $requestId < 1 || $technicianPublicId === "") {
        throw new InvalidArgumentException("Choose a request and technician to dispatch.");
    }

    $pdo = geonet_pdo();
    geonet_ensure_mapping_schema();
    geonet_ensure_notifications_schema();
    $technicianStmt = $pdo->prepare(
        "SELECT id, user_id FROM technicians WHERE public_id = ?"
    );
    $technicianStmt->execute([$technicianPublicId]);
    $technician = $technicianStmt->fetch();
    if (!$technician) {
        throw new InvalidArgumentException("Technician not found.");
    }

    $assignmentCondition = $requireUnfinished ? " AND technician_work_status <> 'completed'" : "";
    $requestNameStmt = $pdo->prepare(
        "SELECT full_name FROM subscription_requests WHERE id = ? AND status = 'pending'" . $assignmentCondition
    );
    $requestNameStmt->execute([$requestId]);
    $requestName = $requestNameStmt->fetchColumn();
    if ($requestName === false) {
        throw new InvalidArgumentException("Pending subscription request not found.");
    }

    $requestStmt = $pdo->prepare(
        "UPDATE subscription_requests
         SET assigned_technician_id = ?, dispatched_at = NOW(),
             technician_response = 'pending', technician_work_status = 'assigned',
             technician_work_reason = NULL, activity_updated_at = NOW()
         WHERE id = ? AND status = 'pending'" . $assignmentCondition
    );
    $requestStmt->execute([(int) $technician["id"], $requestId]);
    if ($requestStmt->rowCount() === 0) {
        $check = $pdo->prepare(
            "SELECT id FROM subscription_requests WHERE id = ? AND status = 'pending'" . $assignmentCondition
        );
        $check->execute([$requestId]);
        if (!$check->fetch()) {
            throw new InvalidArgumentException("Pending subscription request not found.");
        }
    }
    geonet_notify_user(
        $technician["user_id"],
        "service",
        "Service request assigned",
        "You have been assigned to review the service request from " . $requestName . ".",
        "technician.php#subscription-request-" . $requestId
    );
}

function geonet_technician_subscription_requests($userId)
{
    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        throw new InvalidArgumentException("Technician account not found.");
    }
    geonet_ensure_mapping_schema();
    $stmt = geonet_pdo()->prepare(
        "SELECT sr.id, sr.full_name AS name, sr.address, sr.phone,
                sr.plan_name AS plan, sr.lat, sr.lng, sr.status,
                (sr.existing_user_id IS NOT NULL) AS isAdditional,
                n.name AS napBox,
                sr.technician_response AS technicianResponse,
                sr.technician_work_status AS technicianWorkStatus,
                sr.technician_work_reason AS technicianWorkReason,
                b.name AS barangay,
                DATE_FORMAT(sr.dispatched_at, '%Y-%m-%d %H:%i') AS dispatchedAt
         FROM subscription_requests sr
         JOIN barangays b ON b.id = sr.barangay_id
         LEFT JOIN network_assets n ON n.id = sr.nap_box_id
         JOIN technicians t ON t.id = sr.assigned_technician_id
         WHERE t.user_id = ? AND sr.status IN ('pending', 'approved')
         ORDER BY sr.dispatched_at DESC, sr.id DESC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function geonet_respond_to_subscription_request($requestId, $userId, $decision)
{
    if (!in_array($decision, ["accepted", "declined"], true)) {
        throw new InvalidArgumentException("Choose whether to accept or decline the request.");
    }
    $requestId = filter_var($requestId, FILTER_VALIDATE_INT);
    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    if ($requestId === false || $requestId < 1) {
        throw new InvalidArgumentException("Subscription request not found.");
    }
    if ($userId === false || $userId < 1) {
        throw new InvalidArgumentException("Technician account not found.");
    }

    geonet_ensure_mapping_schema();
    geonet_ensure_notifications_schema();
    $pdo = geonet_pdo();
    $requestNameStmt = $pdo->prepare(
        "SELECT full_name FROM subscription_requests WHERE id = ?"
    );
    $requestNameStmt->execute([$requestId]);
    $requestName = $requestNameStmt->fetchColumn();
    $stmt = $pdo->prepare(
        "UPDATE subscription_requests sr
         JOIN technicians t ON t.id = sr.assigned_technician_id
         SET sr.technician_response = ?, sr.activity_updated_at = NOW(),
             sr.assigned_technician_id = CASE WHEN ? = 'declined' THEN NULL
                                              ELSE sr.assigned_technician_id END
         WHERE sr.id = ? AND sr.status IN ('pending', 'approved') AND t.user_id = ?"
    );
    $stmt->execute([$decision, $decision, $requestId, $userId]);
    if ($stmt->rowCount() === 0) {
        if ($decision === "accepted") {
            $check = $pdo->prepare(
                "SELECT sr.id
                 FROM subscription_requests sr
                 JOIN technicians t ON t.id = sr.assigned_technician_id
                 WHERE sr.id = ? AND sr.status IN ('pending', 'approved')
                   AND sr.technician_response = 'accepted' AND t.user_id = ?"
            );
            $check->execute([$requestId, $userId]);
            if ($check->fetchColumn()) {
                return;
            }
        }
        throw new InvalidArgumentException(
            "This request is no longer assigned to you or is no longer pending."
        );
    }
    geonet_notify_role(
        "admin",
        "service",
        "Technician " . $decision . " a request",
        ($requestName ?: "A subscriber") . "'s service request was " . $decision . ".",
        "dashboard.php#maintenance"
    );
}

function geonet_update_subscription_request_work_status($requestId, $userId, $workStatus, $reason = "")
{
    if (!in_array($workStatus, ["assigned", "en_route", "completed", "not_completed"], true)) {
        throw new InvalidArgumentException("Choose a valid assignment status.");
    }
    $reason = trim((string) $reason);
    if ($workStatus === "not_completed" && $reason === "") {
        throw new InvalidArgumentException("Enter a reason for marking this assignment not complete.");
    }
    if (strlen($reason) > 2000) {
        throw new InvalidArgumentException("The reason must be 2,000 characters or fewer.");
    }
    $requestId = filter_var($requestId, FILTER_VALIDATE_INT);
    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    if ($requestId === false || $requestId < 1) {
        throw new InvalidArgumentException("Subscription request not found.");
    }
    if ($userId === false || $userId < 1) {
        throw new InvalidArgumentException("Technician account not found.");
    }

    geonet_ensure_mapping_schema();
    geonet_ensure_notifications_schema();
    $pdo = geonet_pdo();
    $requestNameStmt = $pdo->prepare(
        "SELECT sr.full_name FROM subscription_requests sr
         JOIN technicians t ON t.id = sr.assigned_technician_id
         WHERE sr.id = ? AND t.user_id = ?"
    );
    $requestNameStmt->execute([$requestId, $userId]);
    $requestName = $requestNameStmt->fetchColumn();
    $stmt = $pdo->prepare(
        "UPDATE subscription_requests sr
         JOIN technicians t ON t.id = sr.assigned_technician_id
         SET sr.technician_work_status = ?, sr.activity_updated_at = NOW(),
             sr.technician_work_reason = CASE WHEN ? = 'not_completed' THEN ? ELSE NULL END
         WHERE sr.id = ? AND sr.status IN ('pending', 'approved')
           AND sr.technician_response = 'accepted' AND t.user_id = ?
           AND ((? = 'en_route' AND sr.technician_work_status = 'assigned')
             OR (? = 'not_completed' AND sr.technician_work_status IN ('assigned', 'en_route', 'completed'))
             OR (? = 'completed' AND sr.technician_work_status = 'en_route'))"
    );
    $stmt->execute([
        $workStatus,
        $workStatus,
        $reason,
        $requestId,
        $userId,
        $workStatus,
        $workStatus,
        $workStatus,
    ]);
    $changed = $stmt->rowCount() > 0;
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare(
            "SELECT sr.technician_work_status FROM subscription_requests sr
             JOIN technicians t ON t.id = sr.assigned_technician_id
             WHERE sr.id = ? AND sr.status IN ('pending', 'approved')
               AND sr.technician_response = 'accepted' AND t.user_id = ?
             "
        );
        $check->execute([$requestId, $userId]);
        $currentStatus = $check->fetchColumn();
        if ($workStatus === "completed" && $currentStatus === "assigned") {
            throw new InvalidArgumentException("Mark this assignment on route before marking it complete.");
        }
        if ($currentStatus !== $workStatus) {
            throw new InvalidArgumentException(
                "Accept this assigned request before changing its work status."
            );
        }
    }
    if ($changed && $requestName !== false) {
        geonet_notify_role(
            "admin",
            "service",
            "Service assignment updated",
            $requestName . "'s assignment is now " . str_replace("_", " ", $workStatus) . ".",
            "dashboard.php#maintenance"
        );
    }
}

function geonet_review_subscription_request($requestId, $decision, $adminUserId)
{
    if (!in_array($decision, ["approve", "reject"], true)) {
        throw new InvalidArgumentException("Choose a valid request decision.");
    }
    $requestId = filter_var($requestId, FILTER_VALIDATE_INT);
    if ($requestId === false || $requestId < 1) {
        throw new InvalidArgumentException("Subscription request not found.");
    }
    $adminUserId = filter_var($adminUserId, FILTER_VALIDATE_INT);
    if ($adminUserId === false || $adminUserId < 1) {
        throw new InvalidArgumentException("Administrator account not found.");
    }

    $pdo = geonet_pdo();
    geonet_ensure_mapping_schema();
    geonet_ensure_notifications_schema();
    $pdo->beginTransaction();
    try {
        $requestStmt = $pdo->prepare(
            "SELECT * FROM subscription_requests WHERE id = ? AND status = 'pending' FOR UPDATE"
        );
        $requestStmt->execute([$requestId]);
        $request = $requestStmt->fetch();
        if (!$request) {
            throw new InvalidArgumentException("This request has already been reviewed or does not exist.");
        }

        $subscriberId = null;
        $subscriberRowId = null;
        if ($decision === "approve") {
            if (
                empty($request["assigned_technician_id"]) ||
                $request["technician_response"] !== "accepted" ||
                $request["technician_work_status"] !== "completed"
            ) {
                throw new InvalidArgumentException(
                    "The assigned technician must accept and mark this request complete before approval."
                );
            }
            if ($request["existing_user_id"] !== null) {
                $userId = (int) $request["existing_user_id"];
                $existingSubscriber = $pdo->prepare(
                    "SELECT id FROM subscribers WHERE user_id = ? LIMIT 1"
                );
                $existingSubscriber->execute([$userId]);
                if (!$existingSubscriber->fetch()) {
                    throw new InvalidArgumentException("Subscriber account not found.");
                }
            } else {
                $existingUser = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $existingUser->execute([$request["email"]]);
                if ($existingUser->fetch()) {
                    throw new InvalidArgumentException("An account already uses this email address.");
                }
                $userInsert = $pdo->prepare(
                    "INSERT INTO users (email, password_hash, display_name, role, is_active)
                     VALUES (?, ?, ?, 'subscriber', 1)"
                );
                $userInsert->execute([
                    $request["email"],
                    $request["password_hash"],
                    $request["full_name"],
                ]);
                $userId = (int) $pdo->lastInsertId();
            }
            $subscriberId = geonet_next_public_id("subscribers", "SUB-");
            $subscriberInsert = $pdo->prepare(
                "INSERT INTO subscribers
                    (public_id, user_id, barangay_id, nap_box_id, full_name, address, phone, plan_name,
                     status, lat, lng, map_pin_enabled, connected_on)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'online', ?, ?, 1, ?)"
            );
            $subscriberInsert->execute([
                $subscriberId,
                $userId,
                $request["barangay_id"],
                $request["nap_box_id"],
                $request["full_name"],
                $request["address"],
                $request["phone"],
                $request["plan_name"],
                $request["lat"],
                $request["lng"],
                date("Y-m-d"),
            ]);
            $subscriberRowId = (int) $pdo->lastInsertId();
            geonet_notify_user(
                $userId,
                "service",
                $request["existing_user_id"] !== null
                    ? "Additional connection approved"
                    : "Subscription approved",
                $request["existing_user_id"] !== null
                    ? "Your additional connection has been approved and added to your account."
                    : "Your service request is approved. You can now sign in to your subscriber account.",
                "subscriber.php"
            );
        } elseif (!empty($request["assigned_technician_id"])) {
            $technicianUserStmt = $pdo->prepare(
                "SELECT user_id FROM technicians WHERE id = ?"
            );
            $technicianUserStmt->execute([$request["assigned_technician_id"]]);
            geonet_notify_user(
                $technicianUserStmt->fetchColumn(),
                "service",
                "Service request rejected",
                "The service request from " . $request["full_name"] . " was rejected by an administrator.",
                "technician.php#jobs"
            );
        }
        if ($decision === "reject" && $request["existing_user_id"] !== null) {
            geonet_notify_user(
                $request["existing_user_id"],
                "service",
                "Additional connection declined",
                "Your request for an additional connection was declined by an administrator.",
                "subscriber.php"
            );
        }

        $status = $decision === "approve" ? "approved" : "rejected";
        $review = $pdo->prepare(
            "UPDATE subscription_requests
             SET status = ?, password_hash = '', subscriber_id = ?, activity_updated_at = NOW(),
                 assigned_technician_id = ?, dispatched_at = ?,
                 reviewed_at = NOW(), reviewed_by = ?
             WHERE id = ?"
        );
        $assignedTechnicianId = $decision === "reject" ? null : $request["assigned_technician_id"];
        $dispatchedAt = $decision === "reject" ? null : $request["dispatched_at"];
        $review->execute([
            $status,
            $subscriberRowId,
            $assignedTechnicianId,
            $dispatchedAt,
            $adminUserId,
            $requestId,
        ]);
        $pdo->commit();
        return $subscriberId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function geonet_add_subscriber($input, $requireAvailablePlan = false)
{
    $name = trim((string) ($input["name"] ?? ""));
    $email = strtolower(trim((string) ($input["email"] ?? "")));
    $password = (string) ($input["password"] ?? "");
    $barangay = trim((string) ($input["barangay"] ?? ""));
    $address = trim((string) ($input["address"] ?? ""));
    $phone = trim((string) ($input["phone"] ?? ""));
    $plan = trim((string) ($input["plan"] ?? ""));
    $status = (string) ($input["status"] ?? "online");

    if ($name === "" || strlen($name) > 120) {
        throw new InvalidArgumentException("Name must be between 1 and 120 characters.");
    }
    geonet_validate_account_email($email, "sub.rlink.ph");
    if (strlen($password) < 8) {
        throw new InvalidArgumentException("Password must be at least 8 characters.");
    }
    $emailStmt = geonet_pdo()->prepare("SELECT id FROM users WHERE email = ?");
    $emailStmt->execute([$email]);
    if ($emailStmt->fetch()) {
        throw new InvalidArgumentException("An account already uses this email address.");
    }
    if ($address === "" || strlen($address) > 255) {
        throw new InvalidArgumentException("Address must be between 1 and 255 characters.");
    }
    if ($phone === "" || strlen($phone) > 20) {
        throw new InvalidArgumentException("Phone must be between 1 and 20 characters.");
    }
    if ($plan === "" || strlen($plan) > 80) {
        throw new InvalidArgumentException("Plan must be between 1 and 80 characters.");
    }
    if ($requireAvailablePlan && !in_array($plan, geonet_available_subscriber_plans(), true)) {
        throw new InvalidArgumentException("Choose one of the available plans.");
    }
    if (!in_array($status, ["online", "offline", "intermittent"], true)) {
        throw new InvalidArgumentException("Choose a valid subscriber status.");
    }

    $pdo = geonet_pdo();
    geonet_ensure_notifications_schema();
    $barangayStmt = $pdo->prepare("SELECT id, lat, lng FROM barangays WHERE name = ?");
    $barangayStmt->execute([$barangay]);
    $barangayRow = $barangayStmt->fetch();
    if (!$barangayRow) {
        throw new InvalidArgumentException("Select a valid barangay.");
    }

    $pdo->beginTransaction();
    try {
        $publicId = geonet_next_public_id("subscribers", "SUB-");
        $userStmt = $pdo->prepare(
            "INSERT INTO users (email, password_hash, display_name, role, is_active)
             VALUES (?, ?, ?, 'subscriber', 1)"
        );
        $userStmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
        $userId = (int) $pdo->lastInsertId();

        $subscriberStmt = $pdo->prepare(
            "INSERT INTO subscribers
                (public_id, user_id, barangay_id, full_name, address, phone, plan_name,
                 status, lat, lng, map_pin_enabled, connected_on)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)"
        );
        $subscriberStmt->execute([
            $publicId,
            $userId,
            $barangayRow["id"],
            $name,
            $address,
            $phone,
            $plan,
            $status,
            $barangayRow["lat"],
            $barangayRow["lng"],
            date("Y-m-d"),
        ]);
        geonet_notify_user(
            $userId,
            "account",
            "Welcome to GeoNet Connect",
            "Your subscriber account is ready. View your connection details in the subscriber portal.",
            "subscriber.php"
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    geonet_notify_admin_map_activity(
        "Subscriber added",
        $publicId . " was added to the subscriber map list and is awaiting pin placement."
    );
    return $publicId;
}

function geonet_sms_config()
{
    $ready = geonet_setting("sms_provider", "") === "smsgate";
    return [
        "provider" => "smsgate",
        "enabled" => geonet_setting("sms_enabled", "1") === "1",
        "mock_mode" => !$ready || geonet_setting("sms_mock_mode", "1") === "1",
        "username" => geonet_setting("smsgate_username", ""),
        "password" => geonet_setting("smsgate_password", ""),
        "device_id" => geonet_setting("smsgate_device_id", ""),
        "signing_key" => geonet_setting("smsgate_signing_key", ""),
    ];
}

function geonet_save_sms_config($input)
{
    $values = [];
    foreach (["username", "password", "device_id", "signing_key"] as $field) {
        $value = $input[$field] ?? "";
        if (!is_string($value) || strlen($value) > 512 || preg_match('/[\\r\\n]/', $value)) {
            throw new InvalidArgumentException("SMSGate settings must be text of at most 512 characters without line breaks.");
        }
        $values[$field] = trim($value);
    }
    if (strpos($values["username"], ":") !== false) throw new InvalidArgumentException("Username cannot contain a colon.");
    $saved = geonet_sms_config();
    foreach (["password", "signing_key"] as $secret) {
        if ($values[$secret] === "") $values[$secret] = $saved[$secret];
    }
    if (!empty($input["enabled"]) && empty($input["mock_mode"]) &&
        ($values["username"] === "" || $values["password"] === "")) {
        throw new InvalidArgumentException("Enter your SMSGate username and password or enable mock mode.");
    }
    foreach ($values as $field => $value) geonet_set_setting("smsgate_" . $field, $value);
    geonet_set_setting("sms_enabled", !empty($input["enabled"]) ? "1" : "0");
    geonet_set_setting("sms_mock_mode", !empty($input["mock_mode"]) ? "1" : "0");
    geonet_set_setting("sms_provider", "smsgate");
}

function geonet_sms_logs($limit = 20)
{
    $limit = max(1, min(100, (int) $limit));
    $sql = "SELECT public_id AS id, DATE_FORMAT(sent_at, '%Y-%m-%dT%H:%i:%s') AS `at`,
                recipient_name AS name, recipient_role AS role, phone, context,
                message, status, provider, note
         FROM sms_logs
         ORDER BY sent_at DESC, id DESC
         LIMIT {$limit}";
    return geonet_pdo()->query($sql)->fetchAll();
}

function geonet_clear_sms_logs()
{
    return geonet_pdo()->exec("DELETE FROM sms_logs");
}

function geonet_log_sms($entry)
{
    $stmt = geonet_pdo()->prepare(
        "INSERT INTO sms_logs
            (public_id, sent_at, recipient_name, recipient_role, phone, context, outage_public_id, message, status, provider, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $entry["id"],
        date("Y-m-d H:i:s"),
        $entry["name"],
        $entry["role"],
        $entry["phone"],
        $entry["context"] ?? "outage",
        $entry["outageId"] ?? null,
        $entry["message"],
        $entry["status"],
        $entry["provider"],
        $entry["note"] ?? null,
    ]);
}

function geonet_next_public_id($table, $prefix)
{
    $allowed = [
        "jobs" => "JOB-",
        "tickets" => "TKT-",
        "outages" => "OUT-",
        "subscribers" => "SUB-",
        "technicians" => "TECH-",
    ];
    if (!isset($allowed[$table]) || $allowed[$table] !== $prefix) {
        throw new InvalidArgumentException("Invalid public id table.");
    }
    $stmt = geonet_pdo()->query("SELECT public_id FROM {$table}");
    $max = $table === "subscribers" ? 1000 : 0;
    foreach ($stmt as $row) {
        $n = (int) preg_replace("/\D/", "", $row["public_id"]);
        if ($n > $max) {
            $max = $n;
        }
    }
    $nextNumber = $max + 1;
    if ($table === "technicians") {
        return "TECH-" . str_pad((string) $nextNumber, 2, "0", STR_PAD_LEFT);
    }
    return $prefix . $nextNumber;
}

function geonet_row_by_public($table, $publicId)
{
    $allowed = ["technicians", "outages", "jobs", "tickets", "subscribers"];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException("Invalid table.");
    }
    $stmt = geonet_pdo()->prepare("SELECT * FROM {$table} WHERE public_id = ?");
    $stmt->execute([$publicId]);
    return $stmt->fetch() ?: null;
}

function geonet_assign_job($technicianPublicId, $outagePublicId)
{
    geonet_ensure_notifications_schema();
    $tech = geonet_row_by_public("technicians", $technicianPublicId);
    $outage = geonet_row_by_public("outages", $outagePublicId);
    if (!$tech || !$outage) {
        throw new RuntimeException("Technician or outage not found.");
    }

    $publicId = geonet_next_public_id("jobs", "JOB-");
    $stmt = geonet_pdo()->prepare(
        "INSERT INTO jobs (public_id, outage_id, technician_id, status, assigned_at)
         VALUES (?, ?, ?, 'assigned', NOW())"
    );
    $stmt->execute([$publicId, $outage["id"], $tech["id"]]);

    geonet_pdo()->prepare("UPDATE technicians SET availability = 'on_duty' WHERE id = ?")
        ->execute([$tech["id"]]);
    $barangayStmt = geonet_pdo()->prepare(
        "SELECT b.name FROM outages o JOIN barangays b ON b.id = o.barangay_id WHERE o.id = ?"
    );
    $barangayStmt->execute([$outage["id"]]);
    $barangayName = $barangayStmt->fetchColumn();
    geonet_notify_user(
        $tech["user_id"] ?? null,
        "jobs",
        "New outage assignment",
        "You have been assigned to outage " . $outagePublicId . " in " . $barangayName . ".",
        "technician.php#jobs"
    );
    geonet_notify_outage_subscribers(
        $outage["id"],
        "Technician assigned to your outage",
        "A technician has been assigned to the outage in " . $barangayName . "."
    );
    geonet_notify_role(
        "admin",
        "outage",
        "Technician assigned to outage",
        $publicId . " was assigned to the outage in " . $barangayName . ".",
        "dashboard.php#maintenance"
    );

    return $publicId;
}

function geonet_advance_job($jobPublicId)
{
    geonet_ensure_notifications_schema();
    $job = geonet_row_by_public("jobs", $jobPublicId);
    if (!$job) {
        throw new RuntimeException("Job not found.");
    }

    $next = $job["status"] === "assigned" ? "en_route" : ($job["status"] === "en_route" ? "resolved" : null);
    if ($next === null) {
        return $job["status"];
    }

    geonet_pdo()->prepare("UPDATE jobs SET status = ? WHERE id = ?")->execute([$next, $job["id"]]);

    if ($next === "en_route") {
        geonet_pdo()->prepare("UPDATE technicians SET availability = 'en_route' WHERE id = ?")
            ->execute([$job["technician_id"]]);
    }

    $barangayStmt = geonet_pdo()->prepare(
        "SELECT b.name FROM outages o JOIN barangays b ON b.id = o.barangay_id WHERE o.id = ?"
    );
    $barangayStmt->execute([$job["outage_id"]]);
    $barangayName = $barangayStmt->fetchColumn();
    if ($next === "resolved") {
        geonet_pdo()->prepare(
            "UPDATE outages SET status = 'resolved', resolved_at = NOW() WHERE id = ?"
        )->execute([$job["outage_id"]]);
        geonet_pdo()->prepare("UPDATE technicians SET availability = 'available' WHERE id = ?")
            ->execute([$job["technician_id"]]);
        geonet_notify_outage_subscribers(
            $job["outage_id"],
            "Outage resolved",
            "The outage in " . $barangayName . " has been resolved."
        );
    }
    $technicianStmt = geonet_pdo()->prepare(
        "SELECT user_id FROM technicians WHERE id = ?"
    );
    $technicianStmt->execute([$job["technician_id"]]);
    geonet_notify_user(
        $technicianStmt->fetchColumn(),
        "jobs",
        "Job status updated",
        "Your job " . $jobPublicId . " is now " . str_replace("_", " ", $next) . ".",
        "technician.php#jobs"
    );
    geonet_notify_role(
        "admin",
        "outage",
        $next === "resolved" ? "Outage resolved" : "Technician en route",
        "The outage response in " . $barangayName . " is now " .
            str_replace("_", " ", $next) . ".",
        "dashboard.php#maintenance"
    );

    return $next;
}

function geonet_update_ticket($ticketPublicId, $status)
{
    geonet_ensure_notifications_schema();
    $allowed = ["open", "in_progress", "scheduled", "closed"];
    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException("Invalid ticket status.");
    }
    $ticket = geonet_row_by_public("tickets", $ticketPublicId);
    if (!$ticket) {
        throw new RuntimeException("Ticket not found.");
    }
    geonet_pdo()->prepare("UPDATE tickets SET status = ? WHERE id = ?")->execute([$status, $ticket["id"]]);
    $technicianStmt = geonet_pdo()->prepare(
        "SELECT user_id FROM technicians WHERE id = ?"
    );
    $technicianStmt->execute([$ticket["technician_id"]]);
    geonet_notify_user(
        $technicianStmt->fetchColumn(),
        "tickets",
        "Ticket status updated",
        "Ticket " . $ticketPublicId . " is now " . str_replace("_", " ", $status) . ".",
        "technician.php#logs"
    );
}

function geonet_find_user_by_email($email)
{
    $stmt = geonet_pdo()->prepare(
        "SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1"
    );
    $stmt->execute([strtolower(trim($email))]);
    return $stmt->fetch() ?: null;
}

function geonet_mark_login($userId)
{
    geonet_pdo()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$userId]);
}

function geonet_log_login_attempt($email, $success)
{
    $ip = $_SERVER["REMOTE_ADDR"] ?? null;
    geonet_pdo()->prepare(
        "INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)"
    )->execute([$email, $ip, $success ? 1 : 0]);
}

function geonet_profile_for_user($user)
{
    $profile = [
        "tech_id" => null,
        "sub_id" => null,
        "home" => "dashboard.php",
    ];

    if ($user["role"] === "technician") {
        $stmt = geonet_pdo()->prepare("SELECT public_id FROM technicians WHERE user_id = ?");
        $stmt->execute([$user["id"]]);
        $row = $stmt->fetch();
        $profile["tech_id"] = $row["public_id"] ?? null;
        $profile["home"] = "technician.php";
    } elseif ($user["role"] === "subscriber") {
        $stmt = geonet_pdo()->prepare("SELECT public_id FROM subscribers WHERE user_id = ?");
        $stmt->execute([$user["id"]]);
        $row = $stmt->fetch();
        $profile["sub_id"] = $row["public_id"] ?? null;
        $profile["home"] = "subscriber.php";
    }

    return $profile;
}

function geonet_own_profile($userId)
{
    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        throw new InvalidArgumentException("Account not found.");
    }
    $stmt = geonet_pdo()->prepare(
        "SELECT u.id, u.email, u.display_name AS name, u.role,
                t.phone AS technician_phone,
                s.phone AS subscriber_phone, s.address AS subscriber_address
         FROM users u
         LEFT JOIN technicians t ON t.user_id = u.id
         LEFT JOIN subscribers s ON s.user_id = u.id
         WHERE u.id = ? AND u.is_active = 1
         ORDER BY s.id
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    if (!$profile) {
        throw new InvalidArgumentException("Account not found.");
    }
    if ($profile["role"] === "technician") {
        $profile["phone"] = $profile["technician_phone"];
    } elseif ($profile["role"] === "subscriber") {
        $profile["phone"] = $profile["subscriber_phone"];
        $profile["address"] = $profile["subscriber_address"];
    }
    unset($profile["technician_phone"], $profile["subscriber_phone"], $profile["subscriber_address"]);
    return $profile;
}

function geonet_own_subscribers($userId)
{
    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        throw new InvalidArgumentException("Account not found.");
    }
    $stmt = geonet_pdo()->prepare(
        "SELECT s.public_id AS id, s.full_name AS name, s.address, s.phone,
                s.plan_name AS plan, s.status, s.lat, s.lng,
                b.name AS barangay, n.name AS napBox,
                DATE_FORMAT(s.connected_on, '%Y-%m-%d') AS connected
         FROM subscribers s
         JOIN barangays b ON b.id = s.barangay_id
         LEFT JOIN network_assets n ON n.id = s.nap_box_id
         WHERE s.user_id = ? ORDER BY s.id"
    );
    $stmt->execute([$userId]);
    $connections = $stmt->fetchAll();
    foreach ($connections as &$connection) {
        $connection["lat"] = (float) $connection["lat"];
        $connection["lng"] = (float) $connection["lng"];
    }
    unset($connection);
    return $connections;
}

function geonet_update_own_profile($userId, $input)
{
    $userId = filter_var($userId, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        throw new InvalidArgumentException("Account not found.");
    }
    $name = trim((string) ($input["name"] ?? ""));
    $email = strtolower(trim((string) ($input["email"] ?? "")));
    $password = (string) ($input["password"] ?? "");
    $confirmPassword = (string) ($input["confirmPassword"] ?? "");
    if ($name === "" || strlen($name) > 120) {
        throw new InvalidArgumentException("Name must be between 1 and 120 characters.");
    }
    if ($password !== "" && strlen($password) < 8) {
        throw new InvalidArgumentException("New password must be at least 8 characters.");
    }
    if ($password !== $confirmPassword) {
        throw new InvalidArgumentException("The new passwords do not match.");
    }

    $pdo = geonet_pdo();
    geonet_ensure_notifications_schema();
    $profile = geonet_own_profile($userId);
    $domains = [
        "admin" => "admin.rlink.ph",
        "technician" => "tech.rlink.ph",
        "subscriber" => "sub.rlink.ph",
    ];
    geonet_validate_account_email($email, $domains[$profile["role"]]);
    $emailCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
    $emailCheck->execute([$email, $userId]);
    if ($emailCheck->fetchColumn()) {
        throw new InvalidArgumentException("An account already uses this email address.");
    }

    $phone = "";
    $address = "";
    if ($profile["role"] === "technician" || $profile["role"] === "subscriber") {
        $phone = trim((string) ($input["phone"] ?? ""));
        if ($phone === "" || strlen($phone) > 20) {
            throw new InvalidArgumentException("Phone must be between 1 and 20 characters.");
        }
        if (empty($profile["phone"])) {
            throw new InvalidArgumentException("Account contact profile not found.");
        }
    }
    if ($profile["role"] === "subscriber") {
        $address = trim((string) ($input["address"] ?? ""));
        if ($address === "" || strlen($address) > 255) {
            throw new InvalidArgumentException("Address must be between 1 and 255 characters.");
        }
    }

    $pdo->beginTransaction();
    try {
        if ($password !== "") {
            $stmt = $pdo->prepare(
                "UPDATE users SET display_name = ?, email = ?, password_hash = ? WHERE id = ?"
            );
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $userId]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET display_name = ?, email = ? WHERE id = ?");
            $stmt->execute([$name, $email, $userId]);
        }

        if ($profile["role"] === "technician") {
            $stmt = $pdo->prepare(
                "UPDATE technicians SET full_name = ?, phone = ? WHERE user_id = ?"
            );
            $stmt->execute([$name, $phone, $userId]);
        } elseif ($profile["role"] === "subscriber") {
            $stmt = $pdo->prepare(
                "UPDATE subscribers SET full_name = ?, phone = ? WHERE user_id = ?"
            );
            $stmt->execute([$name, $phone, $userId]);
            $primarySubscriberStmt = $pdo->prepare(
                "SELECT id FROM subscribers WHERE user_id = ? ORDER BY id LIMIT 1"
            );
            $primarySubscriberStmt->execute([$userId]);
            $primarySubscriberId = $primarySubscriberStmt->fetchColumn();
            if ($primarySubscriberId !== false) {
                $addressStmt = $pdo->prepare("UPDATE subscribers SET address = ? WHERE id = ?");
                $addressStmt->execute([$address, $primarySubscriberId]);
            }
        }
        geonet_notify_user(
            $userId,
            "account",
            "Profile updated",
            "Your account profile was updated successfully.",
            "profile.php"
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return geonet_own_profile($userId);
}
