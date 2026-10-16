<?php
// Expects $active_page: 'files', 'upload' or 'notifications'. $conn is optional (for the unread bubble).
$active_page = $active_page ?? '';
$unread = isset($conn) ? unread_notifications($conn, (int)$_SESSION['user_id']) : 0;
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <h3>WebSys Program</h3>
    </div>
    <nav class="sidebar-nav">
        <a href="/websys/program/dashboard.php" class="<?= $active_page === 'files' ? 'active' : '' ?>">
            My Files
        </a>
        <a href="/websys/program/upload.php" class="<?= $active_page === 'upload' ? 'active' : '' ?>">
            Upload File
        </a>
        <a href="/websys/notifications.php" class="<?= $active_page === 'notifications' ? 'active' : '' ?>">
            Notifications<?php if ($unread): ?> <span class="nav-bubble"><?= $unread ?></span><?php endif; ?>
        </a>
    </nav>
    <div class="sidebar-footer">
        <p><?= htmlspecialchars($_SESSION['username']) ?></p>
        <a href="/websys/logout.php">Logout</a>
    </div>
</aside>