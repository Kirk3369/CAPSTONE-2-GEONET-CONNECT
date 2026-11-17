<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config=require dirname(__DIR__).'/config/database.php';
if (!in_array($config['host'],['127.0.0.1','localhost','::1'],true)) throw new RuntimeException('Local MySQL only.');
$testDb='geonet_work_test_'.bin2hex(random_bytes(6));
$server=new PDO('mysql:host='.$config['host'].';port='.$config['port'],$config['user'],$config['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
putenv('DB_NAME='.$testDb);
require dirname(__DIR__).'/includes/maintenance.php';
function work_check($value,$message) { if (!$value) throw new RuntimeException('FAIL: '.$message); echo 'PASS: '.$message.PHP_EOL; }
function work_denied($callback,$message) { try {$callback();} catch (InvalidArgumentException $e) {work_check(true,$message);return;} throw new RuntimeException('FAIL: '.$message); }
function work_api($role, $method, $action = '') {
    $code = 'session_save_path(sys_get_temp_dir()); session_start(); $_SESSION=[];';
    if ($role) $code .= '$_SESSION=["logged_in"=>true,"role"=>'.var_export($role,true).',"user_id"=>999];';
    $code .= '$_SERVER["REQUEST_METHOD"]='.var_export($method,true).'; $_GET["action"]='.var_export($action,true).'; require '.var_export(dirname(__DIR__).'/api/maintenance.php',true).';';
    $process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
    $data=json_decode($out,true);if(!is_array($data))throw new RuntimeException('API invalid JSON: '.$error);
    return $data;
}
try {
    $pdo=geonet_pdo(); geonet_run_sql_file($pdo,dirname(__DIR__).'/sql/schema.sql'); geonet_ensure_mapping_schema(); geonet_ensure_work_orders();
    $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    $pdo->exec("INSERT INTO barangays(id,name,lat,lng) VALUES(1,'First',10,122),(2,'Second',11,123)");
    $pdo->exec("INSERT INTO technicians(id,public_id,full_name,phone,job_title,photo_initials) VALUES(1,'TECH-1','Tech','09170000000','Tech','T')");
    $pdo->exec("INSERT INTO network_assets(id,name,asset_type,barangay_id,lat,lng) VALUES(1,'NAP one','nap',1,10,122),(2,'NAP two','nap',2,11,123)");
    $pdo->exec("INSERT INTO subscribers(id,public_id,nap_box_id,barangay_id,full_name,address,phone,plan_name,lat,lng,connected_on) VALUES(1,'SUB-1',1,1,'Subscriber','Address','09170000000','Plan',10,122,CURRENT_DATE)");
    $input=['title'=>'Inspect NAP','barangay_id'=>1,'nap_box_id'=>1,'technician_id'=>1,'due_at'=>'2026-11-01T09:00','priority'=>'high'];
    $id=geonet_create_work_order($input); work_check($id>0,'scheduled work created');
    work_check((int)geonet_work_orders()[0]['overdue']===1,'past-due scheduled work marked overdue');
    work_denied(fn()=>geonet_create_work_order(array_replace($input,['nap_box_id'=>2])),'NAP from different barangay rejected');
    work_denied(fn()=>geonet_create_work_order(array_replace($input,['due_at'=>'2026-02-30T09:00'])),'invalid calendar date rejected');
    work_denied(fn()=>geonet_update_work_order(['id'=>$id,'status'=>'completed','findings'=>'Done']),'cannot skip start transition');
    work_denied(fn()=>geonet_update_work_order(['id'=>$id,'status'=>'in_progress'],999),'another technician cannot update the order');
    work_check(count(geonet_work_orders(999))===0,'another technician cannot list the order');
    work_check(count(geonet_work_orders(1))===1,'assigned technician can list the order');
    geonet_update_work_order(['id'=>$id,'status'=>'in_progress'],1);
    work_denied(fn()=>geonet_update_work_order(['id'=>$id,'status'=>'completed']),'completion requires work notes');
    geonet_update_work_order(['id'=>$id,'status'=>'completed','findings'=>'Cleaned and inspected','materials'=>'One connector']);
    $order=geonet_work_orders()[0];work_check($order['completed_at']!==null && !$order['overdue'],'completed work stores timestamp and clears overdue flag');
    work_denied(fn()=>geonet_update_work_order(['id'=>$id,'status'=>'in_progress']),'completed work cannot restart');
    $pdo->exec("INSERT INTO service_interruptions(id,subscriber_id,status,assigned_technician_id,reported_at,completed_at) VALUES(1,1,'resolved',1,'2026-11-02 08:00:00','2026-11-02 10:00:00'),(2,1,'resolved',1,'2026-11-03 08:00:00','2026-11-03 12:00:00'),(3,1,'resolved',1,'2026-12-01 00:00:00',NULL)");
    $pdo->exec("INSERT INTO service_interruption_events(interruption_id,event,created_at) VALUES(1,'accept','2026-11-02 08:30:00'),(2,'accept','2026-11-03 09:30:00')");
    $report=geonet_operational_analytics('2026-11-01','2026-11-30');
    work_check($report['summary']['reports']===2,'date range excludes next-month reports');
    work_check($report['summary']['avg_repair_hours']===3.0,'repair time average uses actual completion timestamps');
    work_check($report['summary']['avg_acceptance_hours']===1.0,'acceptance average uses actual accept events');
    work_check($report['summary']['repeat_connections']===1 && $report['summary']['affected_connections']===1,'repeat reports do not inflate unique connection count');
    work_check((int)$report['capacity'][0]['occupied']===1 && $report['capacity'][0]['available']===15,'NAP capacity uses assigned subscribers');
    $empty=geonet_operational_analytics('2025-01-01','2025-01-02');work_check($empty['summary']['avg_repair_hours']===null,'no timing data returns null rather than zero');
    work_denied(fn()=>geonet_operational_analytics('2026-11-30','2026-11-01'),'reversed range rejected');
    work_check(work_api(null,'GET')['ok']===false,'API rejects unauthenticated access');
    work_check(work_api('subscriber','GET')['ok']===false,'API rejects subscriber access');
    work_check(work_api('admin','POST')['ok']===false,'API rejects mutations without a CSRF token');
    work_check(work_api('admin','GET')['ok']===true,'admin API lists work orders');
    work_check(work_api('admin','GET','analytics')['ok']===true,'analytics API returns actual records');
} finally {
    if (!preg_match('/^geonet_work_test_[a-f0-9]{12}$/D',$testDb)) throw new RuntimeException('Unsafe test database name');
    $server->exec("DROP DATABASE `{$testDb}`"); echo "Temporary test database removed.\n";
}
