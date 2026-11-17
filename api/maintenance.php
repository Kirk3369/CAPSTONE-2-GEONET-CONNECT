<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/maintenance.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function maintenance_reply($code,$body) { http_response_code($code); echo json_encode($body); exit; }
if (empty($_SESSION['logged_in'])) maintenance_reply(401,['ok'=>false,'error'=>'Sign in first.']);
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin','technician'], true)) maintenance_reply(403,['ok'=>false,'error'=>'Access denied.']);
try {
    geonet_ensure_work_orders();
    $technicianId = null;
    if ($role === 'technician') {
        $q = geonet_pdo()->prepare('SELECT id FROM technicians WHERE user_id=?'); $q->execute([$_SESSION['user_id'] ?? 0]);
        $technicianId = $q->fetchColumn();
        if (!$technicianId) maintenance_reply(403,['ok'=>false,'error'=>'Technician account not linked.']);
    }
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if ($method==='GET') {
        if (($_GET['action']??'')==='analytics') {
            if ($role !== 'admin') maintenance_reply(403,['ok'=>false,'error'=>'Admin access required.']);
            $today=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
            maintenance_reply(200,['ok'=>true,'analytics'=>geonet_operational_analytics($_GET['from']??$today->format('Y-m-01'),$_GET['to']??$today->format('Y-m-d'))]);
        }
        if ($role === 'technician') maintenance_reply(200,['ok'=>true,'orders'=>geonet_work_orders($technicianId)]);
        maintenance_reply(200,['ok'=>true,'orders'=>geonet_work_orders(),
            'barangays'=>geonet_pdo()->query('SELECT id,name FROM barangays ORDER BY name')->fetchAll(),
            'technicians'=>geonet_pdo()->query('SELECT id,full_name AS name FROM technicians ORDER BY full_name')->fetchAll(),
            'naps'=>geonet_pdo()->query("SELECT id,name,barangay_id FROM network_assets WHERE asset_type='nap' ORDER BY name")->fetchAll(),
            'interruptions'=>geonet_pdo()->query("SELECT i.id,s.public_id,s.barangay_id FROM service_interruptions i JOIN subscribers s ON s.id=i.subscriber_id WHERE i.status<>'resolved' ORDER BY i.id DESC")->fetchAll()]);
    }
    if ($method!=='POST') { header('Allow: GET, POST'); maintenance_reply(405,['ok'=>false]); }
    $input=json_decode(file_get_contents('php://input'),true);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid request.');
    $token=$input['csrfToken']??null;
    if (!is_string($token)||empty($_SESSION['profile_csrf_token'])||!hash_equals($_SESSION['profile_csrf_token'],$token)) maintenance_reply(403,['ok'=>false,'error'=>'Refresh the page and try again.']);
    if (($input['action']??'')==='create') {
        if ($role !== 'admin') maintenance_reply(403,['ok'=>false,'error'=>'Admin access required.']);
        geonet_create_work_order($input);
    }
    elseif (($input['action']??'')==='update') geonet_update_work_order($input, $technicianId);
    else throw new InvalidArgumentException('Unknown action.');
    maintenance_reply(200,['ok'=>true,'message'=>'Work order saved.']);
} catch (InvalidArgumentException $e) { maintenance_reply(422,['ok'=>false,'error'=>$e->getMessage()]); }
catch (Throwable $e) { error_log('Maintenance: '.$e->getMessage()); maintenance_reply(500,['ok'=>false,'error'=>'Could not load or save maintenance records.']); }
