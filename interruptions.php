<?php
require_once __DIR__ . '/includes/auth.php';
geonet_require_login(['admin','technician','subscriber']);
header('Cache-Control: no-store');
$_SESSION['profile_csrf_token'] = $_SESSION['profile_csrf_token'] ?? bin2hex(random_bytes(32));
$role = $_SESSION['role'];
$home = geonet_home($role);
$display = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Account';
$portal = [
    'admin' => ['GeoNet Connect', 'R-Link Data and Solutions', 'fa-location-dot', 'GIS ops'],
    'technician' => ['Field portal', 'Technician workspace', 'fa-helmet-safety', 'Field ops'],
    'subscriber' => ['My connection', 'Subscriber portal', 'fa-wifi', 'R-Link'],
][$role];
$navigation = [
    'admin' => [['dashboard.php#profile','fa-id-card','Profile'], ['dashboard.php#dashboard','fa-map-location-dot','Network Map'],
        ['dashboard.php#subscribers','fa-users','Subscribers'], ['dashboard.php#technicians','fa-helmet-safety','Technicians'],
        ['dashboard.php#maintenance','fa-screwdriver-wrench','Maintenance Support'], ['interruptions.php','fa-wrench','Interruption reports'],
        ['dashboard.php#analytics','fa-chart-line','Analytics'], ['dashboard.php#sms','fa-comment-sms','SMS messages'],
        ['dashboard.php#settings','fa-gear','Settings']],
    'technician' => [['interruptions.php','fa-wrench','Interruption repairs'], ['technician.php#profile','fa-id-card','Profile'],
        ['technician.php#jobs','fa-clipboard-list','My jobs'], ['technician.php#logs','fa-clock-rotate-left','Ticket logs']],
    'subscriber' => [['subscriber.php#profile','fa-id-card','Profile'],
        ['subscriber.php#account','fa-house','My account'], ['interruptions.php','fa-wrench','My interruption reports']],
][$role];
$description = $role === 'admin' ? 'Assign technicians and follow subscriber repair progress.'
    : ($role === 'technician' ? 'Manage assigned repairs and request subscriber confirmation.' : 'Track repairs and confirm when your connection is restored.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Interruption reports - GeoNet Connect</title>
    <link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/app.css'); ?>">
    <link rel="stylesheet" href="assets/reports.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/reports.css'); ?>">
    <link rel="stylesheet" href="assets/design-system.css?v=<?php echo filemtime(__DIR__ . "/assets/design-system.css"); ?>">
</head>
<body>
<div class="app reports-portal">
    <?php if ($role === 'technician'): require_once __DIR__ . '/includes/technician-sidebar.php'; geonet_technician_sidebar('interruptions'); else: ?>
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon"><i class="fa-solid <?php echo $portal[2]; ?>" aria-hidden="true"></i></div>
            <div><h1><?php echo htmlspecialchars($portal[0]); ?></h1><p><?php echo htmlspecialchars($portal[1]); ?></p></div>
        </div>
        <nav class="nav" aria-label="Portal navigation">
            <?php foreach ($navigation as [$href, $icon, $label]): ?>
            <a class="nav-item<?php echo $href === 'interruptions.php' ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($href); ?>"<?php echo $href === 'interruptions.php' ? ' aria-current="page"' : ''; ?>>
                <i class="fa-solid <?php echo $icon; ?>" aria-hidden="true"></i> <?php echo htmlspecialchars($label); ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">San Miguel, Iloilo &middot; <?php echo htmlspecialchars($portal[3]); ?></div>
    </aside>
    <?php endif; ?>
    <div class="main">
        <header class="topbar">
            <div><h2>Interruption reports</h2><p class="topbar-sub"><?php echo htmlspecialchars($description); ?></p></div>
            <div class="topbar-right">
                <div class="notification-center" data-notification-center data-csrf-token="<?php echo htmlspecialchars($_SESSION['profile_csrf_token']); ?>">
                    <button class="notification-trigger" type="button" aria-expanded="false">
                        <i class="fa-regular fa-bell" aria-hidden="true"></i><span class="sr-only">Notifications</span>
                        <span class="notification-count" data-notification-count aria-live="polite" hidden></span>
                    </button>
                    <section class="notification-panel" data-notification-panel hidden aria-label="Notifications">
                        <div class="notification-panel-head"><h3>Notifications</h3><div class="notification-panel-actions">
                            <button type="button" class="notification-mark-all" data-notification-mark-all>Mark all read</button>
                            <button type="button" class="notification-clear-all" data-notification-clear-all>Clear</button>
                        </div></div>
                        <div class="notification-list" data-notification-list></div>
                    </section>
                </div>
                <a class="user-chip" href="<?php echo htmlspecialchars($home . '#profile'); ?>"><?php echo htmlspecialchars($display); ?></a>
                <a class="btn btn-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Logout</a>
            </div>
        </header>
        <main class="content reports-page">
            <a class="reports-mobile-back" href="<?php echo htmlspecialchars($home); ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to portal</a>
            <div class="stats" aria-label="Summary of displayed reports">
                <?php foreach ([['Open reports','reportsOpen','accent-red'], ['In progress','reportsProgress','accent-blue'], ['Awaiting confirmation','reportsWaiting',''], ['Resolved','reportsResolved','']] as [$label, $id, $accent]): ?>
                <div class="card stat"><div class="stat-label"><?php echo $label; ?></div>
                    <div class="stat-value <?php echo $accent; ?>" id="<?php echo $id; ?>">&mdash;</div>
                    <div class="stat-hint">In displayed reports</div>
                </div>
                <?php endforeach; ?>
            </div>
            <section class="card panel reports-overview">
                <div class="panel-head"><div><h3>Repair activity</h3><p class="side-meta"><?php echo htmlspecialchars($description); ?></p></div>
                    <button id="reportsRefresh" type="button" class="btn btn-outline"><i class="fa-solid fa-rotate" aria-hidden="true"></i> Refresh reports</button>
                </div>
                <details class="reports-policy"><summary><i class="fa-regular fa-clock" aria-hidden="true"></i> How restoration confirmation works</summary>
                    <p>After a technician marks a repair complete, the connection stays offline until the subscriber confirms restoration or the five-hour response window expires. Reporting that it is still down cancels the countdown.</p>
                </details>
                <p id="reportsWorker" class="side-meta" role="status"></p>
            </section>
            <p id="reportsMessage" class="availability-message" role="status" aria-live="polite"></p>
            <div id="reportsList" class="reports-list" aria-label="Interruption reports"><p class="side-meta">Loading interruption reports&hellip;</p></div>
        </main>
    </div>
</div>
<script>
window.geonetReports = <?php echo json_encode(['role' => $role, 'csrfToken' => $_SESSION['profile_csrf_token']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="assets/interruptions.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/interruptions.js'); ?>"></script>
<script src="assets/notifications.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/notifications.js'); ?>"></script>
<script src="assets/accessibility.js?v=<?php echo filemtime(__DIR__ . "/assets/accessibility.js"); ?>"></script>
</body>
</html>
