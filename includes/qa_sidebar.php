<?php
// Expects $active_page: 'dashboard' or 'notifications'. $conn is optional (unread bubble + user name).
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/helpers.php';
$active_page = $active_page ?? '';
$unread = isset($conn) ? unread_notifications($conn, (int)$_SESSION['user_id']) : 0;
?>
<aside class="sidebar">
    <?= sidebar_brand('QA Office') ?>
    <nav class="sidebar-nav">
        <a href="/websys/qa/dashboard.php" class="<?= $active_page === 'dashboard' ? 'active' : '' ?>">
            <?= icon('dashboard') ?>Dashboard
        </a>
        <a href="/websys/notifications.php" class="<?= $active_page === 'notifications' ? 'active' : '' ?>">
            <?= icon('notifications') ?>Notifications<?php if ($unread): ?><span class="nav-bubble"><?= $unread ?></span><?php endif; ?>
        </a>
    </nav>
    <?= sidebar_user($conn ?? null) ?>
</aside>