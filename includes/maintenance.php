<?php
require_once __DIR__ . '/interruptions.php';

function geonet_ensure_work_orders()
{
    geonet_pdo()->exec("SET time_zone = '+08:00'");
    geonet_ensure_interruption_schema();
    geonet_pdo()->exec("CREATE TABLE IF NOT EXISTS maintenance_work_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(160) NOT NULL,
        barangay_id INT UNSIGNED NOT NULL,
        nap_box_id INT UNSIGNED NULL,
        technician_id INT UNSIGNED NOT NULL,
        interruption_id BIGINT UNSIGNED NULL,
        priority ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
        status ENUM('scheduled','in_progress','completed') NOT NULL DEFAULT 'scheduled',
        due_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        findings TEXT NULL,
        materials TEXT NULL,
        KEY idx_work_due(status,due_at),
        FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE RESTRICT,
        FOREIGN KEY (nap_box_id) REFERENCES network_assets(id) ON DELETE SET NULL,
        FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE RESTRICT,
        FOREIGN KEY (interruption_id) REFERENCES service_interruptions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function geonet_create_work_order($input)
{
    $title = $input['title'] ?? null;
    $priority = $input['priority'] ?? 'medium';
    $due = $input['due_at'] ?? null;
    if (!is_string($title) || trim($title) === '' || strlen(trim($title)) > 160 || !is_string($priority) || !in_array($priority, ['low','medium','high','critical'], true)) {
        throw new InvalidArgumentException('Enter a title of up to 160 bytes and a valid priority.');
    }
    $date = is_string($due) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $due, new DateTimeZone('Asia/Manila')) : false;
    if (!$date || $date->format('Y-m-d\TH:i') !== $due) throw new InvalidArgumentException('Choose a valid due date and time (Manila time).');
    $pdo = geonet_pdo();
    $barangay = filter_var($input['barangay_id'] ?? null, FILTER_VALIDATE_INT);
    $technician = filter_var($input['technician_id'] ?? null, FILTER_VALIDATE_INT);
    $q = $pdo->prepare('SELECT id FROM barangays WHERE id = ?'); $q->execute([$barangay]);
    if (!$q->fetchColumn()) throw new InvalidArgumentException('Choose an existing barangay.');
    $q = $pdo->prepare('SELECT id FROM technicians WHERE id = ?'); $q->execute([$technician]);
    if (!$q->fetchColumn()) throw new InvalidArgumentException('Choose an existing technician.');
    $nap = $input['nap_box_id'] ?? null;
    $nap = $nap === '' || $nap === null ? null : filter_var($nap, FILTER_VALIDATE_INT);
    if ($nap !== null) {
        $q = $pdo->prepare("SELECT id FROM network_assets WHERE id = ? AND asset_type = 'nap' AND barangay_id = ?"); $q->execute([$nap, $barangay]);
        if (!$q->fetchColumn()) throw new InvalidArgumentException('Choose a NAP box assigned to this barangay.');
    }
    $interruption = $input['interruption_id'] ?? null;
    $interruption = $interruption === '' || $interruption === null ? null : filter_var($interruption, FILTER_VALIDATE_INT);
    if ($interruption !== null) {
        $q = $pdo->prepare("SELECT i.id FROM service_interruptions i JOIN subscribers s ON s.id=i.subscriber_id WHERE i.id=? AND s.barangay_id=?"); $q->execute([$interruption, $barangay]);
        if (!$q->fetchColumn()) throw new InvalidArgumentException('Choose an interruption from the same barangay.');
    }
    $q = $pdo->prepare('INSERT INTO maintenance_work_orders (title, barangay_id, nap_box_id, technician_id, interruption_id, priority, due_at) VALUES (?,?,?,?,?,?,?)');
    $q->execute([trim($title), $barangay, $nap, $technician, $interruption, $priority, $date->format('Y-m-d H:i:s')]);
    return (int) $pdo->lastInsertId();
}

function geonet_update_work_order($input, $technicianId = null)
{
    $status = $input['status'] ?? '';
    $findings = $input['findings'] ?? '';
    $materials = $input['materials'] ?? '';
    if (!is_string($status) || !in_array($status, ['in_progress','completed'], true) || !is_string($findings) || !is_string($materials) || strlen($findings) > 4000 || strlen($materials) > 4000) throw new InvalidArgumentException('Invalid work status or notes (maximum 4,000 bytes each).');
    if ($status === 'completed' && trim($findings) === '') throw new InvalidArgumentException('Record the work performed before completing this order.');
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $sql = "UPDATE maintenance_work_orders SET status=?, findings=?, materials=?, started_at=COALESCE(started_at,NOW()), completed_at=IF(?='completed',NOW(),NULL) WHERE id=? AND status=?";
    $params = [$status, trim($findings), trim($materials), $status, $id, $status === 'in_progress' ? 'scheduled' : 'in_progress'];
    if ($technicianId !== null) { $sql .= ' AND technician_id=?'; $params[] = $technicianId; }
    $q = geonet_pdo()->prepare($sql);
    $q->execute($params);
    if (!$q->rowCount()) throw new InvalidArgumentException('Work status changed. Refresh and follow Scheduled → In progress → Completed.');
}

function geonet_work_orders($technicianId = null)
{
    $sql = "SELECT w.*, b.name AS barangay, n.name AS nap_box, t.full_name AS technician,
        (w.status <> 'completed' AND w.due_at < NOW()) AS overdue
        FROM maintenance_work_orders w JOIN barangays b ON b.id=w.barangay_id
        JOIN technicians t ON t.id=w.technician_id LEFT JOIN network_assets n ON n.id=w.nap_box_id
        ";
    if ($technicianId !== null) $sql .= ' WHERE w.technician_id=?';
    $sql .= " ORDER BY (w.status='completed'), w.due_at, w.id";
    $q = geonet_pdo()->prepare($sql); $q->execute($technicianId === null ? [] : [$technicianId]);
    return $q->fetchAll();
}

function geonet_reporting_range($from, $to)
{
    foreach ([$from, $to] as $date) {
        $d = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (!$d || $d->format('Y-m-d') !== $date) throw new InvalidArgumentException('Choose valid start and end dates.');
    }
    if ($from > $to || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 366) throw new InvalidArgumentException('Select a range of up to 366 days with the start before the end.');
    return [$from . ' 00:00:00', (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'];
}

function geonet_operational_analytics($from, $to)
{
    [$start,$end] = geonet_reporting_range($from,$to);
    $pdo = geonet_pdo();
    $q = $pdo->prepare("SELECT i.id, s.public_id AS connection_id, s.barangay_id, b.name AS barangay, n.name AS nap_box, t.full_name AS technician,
        i.status, i.reported_at, i.completed_at, i.resolved_at,
        TIMESTAMPDIFF(SECOND,i.reported_at,(SELECT MIN(e.created_at) FROM service_interruption_events e WHERE e.interruption_id=i.id AND e.event='accept')) AS acceptance_seconds,
        TIMESTAMPDIFF(SECOND,i.reported_at,i.completed_at) AS repair_seconds
        FROM service_interruptions i JOIN subscribers s ON s.id=i.subscriber_id JOIN barangays b ON b.id=s.barangay_id
        LEFT JOIN network_assets n ON n.id=s.nap_box_id LEFT JOIN technicians t ON t.id=i.assigned_technician_id
        WHERE i.reported_at >= ? AND i.reported_at < ? ORDER BY i.reported_at DESC, i.id DESC");
    $q->execute([$start,$end]); $reports = $q->fetchAll();
    $repair=[]; $accept=[]; $repeat=[]; $barangays=[]; $days=[]; $techs=[];
    foreach ($reports as $row) {
        $repeat[$row['connection_id']] = ($repeat[$row['connection_id']] ?? 0)+1;
        $barangays[$row['barangay']] = ($barangays[$row['barangay']] ?? 0)+1;
        $day=substr($row['reported_at'],0,10); $days[$day]=($days[$day]??0)+1;
        if ($row['repair_seconds'] !== null && $row['repair_seconds'] >= 0) $repair[]=(int)$row['repair_seconds'];
        if ($row['acceptance_seconds'] !== null && $row['acceptance_seconds'] >= 0) $accept[]=(int)$row['acceptance_seconds'];
        $tech=$row['technician'] ?? 'Unassigned';
        if (!isset($techs[$tech])) $techs[$tech]=['reports'=>0,'completed'=>0];
        $techs[$tech]['reports']++; if ($row['completed_at']) $techs[$tech]['completed']++;
    }
    ksort($days); arsort($barangays);
    $q=$pdo->prepare('SELECT public_id AS id, opened_at, status, priority, component FROM tickets WHERE opened_at>=? AND opened_at<? ORDER BY opened_at'); $q->execute([$start,$end]);
    $tickets=$q->fetchAll();
    $capacity=$pdo->query("SELECT n.name, COALESCE(b.name,'Unassigned') AS barangay,
        (SELECT COUNT(*) FROM subscribers s WHERE s.nap_box_id=n.id)+(SELECT COUNT(*) FROM subscription_requests r WHERE r.nap_box_id=n.id AND r.status='pending') AS occupied
        FROM network_assets n LEFT JOIN barangays b ON b.id=n.barangay_id WHERE n.asset_type='nap' ORDER BY n.name")->fetchAll();
    foreach ($capacity as &$box) { $box['capacity']=geonet_nap_box_capacity(); $box['available']=max(0,$box['capacity']-(int)$box['occupied']); } unset($box);
    return ['from'=>$from,'to'=>$to,'reports'=>$reports,'tickets'=>$tickets,'by_day'=>$days,'by_barangay'=>$barangays,'by_technician'=>$techs,'capacity'=>$capacity,
        'summary'=>['reports'=>count($reports),'affected_connections'=>count($repeat),'repeat_connections'=>count(array_filter($repeat,fn($n)=>$n>1)),
        'avg_repair_hours'=>$repair?round(array_sum($repair)/count($repair)/3600,2):null,'repair_samples'=>count($repair),
        'avg_acceptance_hours'=>$accept?round(array_sum($accept)/count($accept)/3600,2):null,'acceptance_samples'=>count($accept)],
        'subscriber_status'=>$pdo->query('SELECT status, COUNT(*) AS total FROM subscribers GROUP BY status')->fetchAll()];
}
