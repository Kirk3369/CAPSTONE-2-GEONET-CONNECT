<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sms-inbox.php';
geonet_require_login(['admin']);
if (!headers_sent()) header('Cache-Control: no-store');
$_SESSION['profile_csrf_token'] = $_SESSION['profile_csrf_token'] ?? bin2hex(random_bytes(32));
$simulationAllowed = geonet_sms_simulation_allowed();
$smsConfig = geonet_sms_config();
$sendSubscribers = !$smsConfig["mock_mode"] ? geonet_pdo()->query("SELECT public_id, full_name, phone FROM subscribers ORDER BY full_name, public_id")->fetchAll() : [];
?>
<?php if (empty($smsEmbedded)): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SMS inbox - GeoNet Connect</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/sms-inbox.css?v=<?php echo filemtime(__DIR__ . "/assets/sms-inbox.css"); ?>">
    <link rel="stylesheet" href="assets/design-system.css?v=<?php echo filemtime(__DIR__ . "/assets/design-system.css"); ?>">
</head>
<body>
<?php endif; ?>
<main class="inbox-page">
    <?php if (empty($smsEmbedded)): ?><a href="dashboard.php#sms">&larr; Open SMS in dashboard</a><?php endif; ?>
    <header class="inbox-heading">
        <div><span class="inbox-eyebrow">GEONET CONNECT / COMMUNICATIONS</span><h1>SMS inbox</h1><p>Keep subscribers informed. Track every incoming report.</p></div>
        <div class="inbox-heading-actions"><span class="mode-pill <?php echo $smsConfig['mock_mode'] ? 'mode-mock' : 'mode-live'; ?>"><?php echo $smsConfig['mock_mode'] ? 'Mock mode' : 'Live SMS'; ?></span><a class="btn btn-outline" href="interruptions.php">View interruptions &rarr;</a></div>
    </header>
    <div class="inbox-workspace">
    <?php if (!$smsConfig['mock_mode']): ?>
    <section class="card panel">
        <span class="inbox-eyebrow">COMPOSE</span><h2>Message subscribers</h2>
        <p>Send an update through your gateway SIM. Your SMS allowance applies.</p>
        <form id="subscriberSmsForm" class="inbox-form">
            <fieldset class="recipient-field"><legend>Recipients</legend>
                <input id="recipientSearch" type="search" placeholder="Search name, connection ID or phone" aria-label="Search subscribers">
                <div class="recipient-tools"><span>Choose who receives this message</span><button id="clearRecipients" type="button">Clear selection</button></div>
                <div id="sendSubscribers" class="recipient-list">
                <?php foreach ($sendSubscribers as $subscriber): ?>
                    <label class="recipient-row" data-search="<?php echo htmlspecialchars(strtolower(implode(' ', $subscriber)), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="checkbox" value="<?php echo htmlspecialchars($subscriber['public_id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <span><strong><?php echo htmlspecialchars($subscriber['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars($subscriber['public_id'] . ' / ' . $subscriber['phone'], ENT_QUOTES, 'UTF-8'); ?></small></span>
                    </label>
                <?php endforeach; ?>
                </div>
                <p id="recipientEmpty" hidden>No subscribers match your search.</p>
            </fieldset>
            <label>Message template
                <select id="subscriberSmsTemplate"><option value="custom">Custom message</option><option value="restoration">Service restoration</option><option value="outage">Service interruption</option></select>
            </label>
            <label>Message<textarea id="subscriberSmsMessage" rows="5" maxlength="1000" placeholder="Write an update for your subscribers..." required></textarea></label><span id="composeCharacterCount" class="compose-counter">0 / 1,000 characters</span>
            <p id="subscriberSmsCount">0 subscribers selected</p>
            <button id="subscriberSmsSend" type="submit" class="btn btn-primary" <?php echo !$smsConfig['enabled'] ? 'disabled' : ''; ?>>Send SMS to selected subscribers</button>
            <p id="subscriberSmsStatus" role="status" aria-live="polite"><?php echo !$smsConfig['enabled'] ? 'Enable SMS notifications in Settings before sending.' : ''; ?></p>
        </form>
    </section>
    <?php endif; ?>
    <?php if ($simulationAllowed): ?>
    <section class="card panel">
        <h2>Simulate an incoming SMS</h2>
        <p>This changes your local subscriber status. No text message is sent to a carrier.</p>
        <form id="inboxForm" class="inbox-form">
            <label>Sender's registered mobile number
                <input id="inboxSender" type="tel" maxlength="32" placeholder="09XXXXXXXXX" required>
            </label>
            <label>Message
                <textarea id="inboxMessage" rows="3" maxlength="2000" placeholder="DOWN SUB-1008" required></textarea>
            </label>
            <p>Use DOWN followed by a connection ID to report an interruption, or RESTORED followed by the ID to report restoration.</p>
            <label>Message ID
                <input id="inboxMessageId" maxlength="100" pattern="[A-Za-z0-9._:\-]+" required>
            </label>
            <p>Keep the same ID to test duplicate delivery. Choose a new ID for each different message.</p>
            <div><button type="button" id="inboxNewId" class="btn btn-outline">New message ID</button>
            <button type="submit" id="inboxSubmit" class="btn btn-primary">Process simulated SMS</button></div>
        </form>
    </section>
    <?php else: ?>
    <?php if ($smsConfig["mock_mode"]): ?><p class="inbox-hint">The simulator is available only through local XAMPP access.</p><?php endif; ?>
    <?php endif; ?>
    <section class="card panel history-panel">
        <div class="panel-head"><div><span class="inbox-eyebrow">INCOMING REPORTS</span><h2>Recent messages <span id="inboxTotal" class="count-pill">0</span></h2></div><button id="inboxRefresh" class="btn btn-outline" type="button">Refresh</button></div>
        <p class="inbox-hint">Latest 100 reports. DOWN and RESTORED update the matching registered connection.</p>
        <p id="inboxStatus" role="status" aria-live="polite"></p>
        <div id="inboxHistory" class="inbox-history"></div>
    </section>
    </div>
</main>
<script>
window.geonetInboxCsrf = <?php echo json_encode($_SESSION['profile_csrf_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="assets/sms-inbox.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/sms-inbox.js'); ?>"></script>
<?php if (empty($smsEmbedded)): ?>
<script src="assets/accessibility.js?v=<?php echo filemtime(__DIR__ . "/assets/accessibility.js"); ?>"></script>
</body>
</html>
<?php endif; ?>
