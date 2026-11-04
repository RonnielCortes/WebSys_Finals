<?php
// Expects $active_page: 'files', 'upload' or 'notifications'. $conn is optional (unread bubble + user name).
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/helpers.php';
$active_page = $active_page ?? '';
$unread = isset($conn) ? unread_notifications($conn, (int)$_SESSION['user_id']) : 0;
?>
<aside class="sidebar">
    <?= sidebar_brand('Program / Office') ?>
    <nav class="sidebar-nav">
        <?= nav_link('/websys/program/dashboard.php', 'files', 'My Files', $active_page === 'files') ?>
        <?= nav_link('/websys/program/upload.php', 'upload', 'Upload File', $active_page === 'upload') ?>
        <?= nav_link('/websys/notifications.php', 'notifications', 'Notifications', $active_page === 'notifications', $unread) ?>
    </nav>
    <?= sidebar_user($conn ?? null) ?>
</aside>
<?= sidebar_script() ?>