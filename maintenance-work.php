<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/maintenance.php';
geonet_require_login('technician');
header('Cache-Control: no-store');
$_SESSION['profile_csrf_token'] = $_SESSION['profile_csrf_token'] ?? bin2hex(random_bytes(32));
geonet_ensure_work_orders();
$q=geonet_pdo()->prepare('SELECT id FROM technicians WHERE user_id=?');$q->execute([$_SESSION['user_id']]);$technicianId=$q->fetchColumn();
$orders=$technicianId ? geonet_work_orders($technicianId) : [];
function work_escape($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>My scheduled maintenance | GeoNet</title><link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css"><link rel="stylesheet" href="assets/app.css">    <link rel="stylesheet" href="assets/design-system.css?v=<?php echo filemtime(__DIR__ . "/assets/design-system.css"); ?>">
</head>
<body><div class="app">
<?php require_once __DIR__ . '/includes/technician-sidebar.php'; geonet_technician_sidebar('maintenance'); ?>
<div class="main"><header class="topbar"><div><h2>Scheduled maintenance</h2><p class="topbar-sub">Assigned inspections and preventive maintenance</p></div><div class="topbar-right"><a class="user-chip" href="technician.php#profile"><?php echo work_escape($_SESSION['display_name'] ?? 'Technician'); ?></a><a class="btn btn-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Logout</a></div></header>
<main class="content"><p class="side-meta">Record the work performed and materials used. Times are in Manila time.</p><p id="workError" role="status" aria-live="polite"></p>
<?php if (!$orders): ?><p>No maintenance work is assigned to you.</p><?php endif; ?>
<?php foreach($orders as $order): ?>
<article class="card panel work-order <?php echo $order['overdue']?'work-overdue':''; ?>" style="margin-bottom:16px"><h2>#<?php echo (int)$order['id']; ?> <?php echo work_escape($order['title']); ?></h2><p><?php echo work_escape($order['barangay'].' / '.($order['nap_box']??'All areas').' / '.$order['priority'].' / '.$order['status']); ?></p><p>Due: <?php echo work_escape($order['due_at']); ?><?php echo $order['overdue']?' / Overdue':''; ?></p>
<?php if($order['interruption_id']): ?><a href="interruptions.php">Linked interruption #<?php echo (int)$order['interruption_id']; ?></a><?php endif; ?>
<?php if($order['status']==='completed'): ?><p class="work-note"><?php echo work_escape($order['findings']); ?></p><p class="work-note">Materials: <?php echo work_escape($order['materials']); ?></p>
<?php else: ?><form class="work-update" data-id="<?php echo (int)$order['id']; ?>" data-status="<?php echo $order['status']==='scheduled'?'in_progress':'completed'; ?>"><label>Findings / work performed<textarea name="findings" maxlength="4000" <?php echo $order['status']==='in_progress'?'required':''; ?>><?php echo work_escape($order['findings']); ?></textarea></label><label>Materials used<textarea name="materials" maxlength="4000"><?php echo work_escape($order['materials']); ?></textarea></label><button class="btn btn-primary" type="submit"><?php echo $order['status']==='scheduled'?'Start work':'Complete work'; ?></button></form><?php endif; ?></article>
<?php endforeach; ?>
</main></div></div><script>
const csrfToken=<?php echo json_encode($_SESSION['profile_csrf_token']); ?>;
document.querySelectorAll('.work-update').forEach(form=>form.addEventListener('submit',async event=>{
event.preventDefault();const button=form.querySelector('button');button.disabled=true;
try{const response=await fetch('api/maintenance.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'update',id:form.dataset.id,status:form.dataset.status,...Object.fromEntries(new FormData(form)),csrfToken})});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Could not save work.');location.reload();}
catch(error){document.getElementById('workError').textContent=error.message;button.disabled=false;}
}));
</script><script src="assets/accessibility.js?v=<?php echo filemtime(__DIR__ . "/assets/accessibility.js"); ?>"></script>
</body></html>
