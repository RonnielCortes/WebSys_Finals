<?php
// Expects $active_page: 'dashboard', 'files', 'categories' or 'notifications'. $conn is optional (unread bubble + user name).
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/helpers.php';
$active_page = $active_page ?? '';
$unread = isset($conn) ? unread_notifications($conn, (int)$_SESSION['user_id']) : 0;
?>
<aside class="sidebar">
    <?= sidebar_brand('QA Office') ?>
    <nav class="sidebar-nav">
        <?= nav_link('/websys/qa/dashboard.php', 'dashboard', 'Dashboard', $active_page === 'dashboard') ?>
        <?= nav_link('/websys/qa/files.php', 'folder', 'Files', $active_page === 'files') ?>
        <?= nav_link('/websys/qa/categories.php', 'tag', 'Categories', $active_page === 'categories') ?>
        <?= nav_link('/websys/notifications.php', 'notifications', 'Notifications', $active_page === 'notifications', $unread) ?>
    </nav>
    <?= sidebar_user($conn ?? null) ?>
</aside>
<?= sidebar_script() ?>