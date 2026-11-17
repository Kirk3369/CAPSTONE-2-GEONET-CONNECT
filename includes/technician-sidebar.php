<?php
function geonet_technician_sidebar($active = 'jobs', $localViews = false)
{
    $items = [
        ['maintenance', 'maintenance-work.php', 'fa-calendar-check', 'Scheduled maintenance'],
        ['interruptions', 'interruptions.php', 'fa-wrench', 'Interruption repairs'],
        ['profile', 'technician.php#profile', 'fa-id-card', 'Profile'],
        ['jobs', 'technician.php#jobs', 'fa-clipboard-list', 'My jobs'],
        ['logs', 'technician.php#logs', 'fa-clock-rotate-left', 'Ticket logs'],
    ];
    ?>
    <aside class="sidebar">
        <div class="brand"><div class="brand-icon"><i class="fa-solid fa-helmet-safety" aria-hidden="true"></i></div><div><h1>Field portal</h1><p>Technician workspace</p></div></div>
        <nav class="nav" aria-label="Technician navigation">
            <?php foreach ($items as [$key, $href, $icon, $label]):
                $local = $localViews && in_array($key, ['profile','jobs','logs'], true);
            ?>
            <a class="nav-item<?php echo $active === $key ? ' active' : ''; ?>" href="<?php echo $local ? '#' . $key : $href; ?>"<?php if ($local): ?> data-tech-view="<?php echo $key; ?>"<?php endif; ?><?php if ($active === $key): ?> aria-current="page"<?php endif; ?>><i class="fa-solid <?php echo $icon; ?>" aria-hidden="true"></i> <?php echo $label; ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">San Miguel, Iloilo &middot; Field ops</div>
    </aside>
    <?php
}
