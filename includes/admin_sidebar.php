<?php
// Expects $active_page to be set by the including page, e.g. 'dashboard', 'accounts', 'files'
$active_page = $active_page ?? '';
$pending_bubble = 0;
if (isset($conn)) {
    $pending_bubble = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE account_status = 'pending'")->fetch_assoc()['c'];
}
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <h3>WebSys Admin</h3>
    </div>
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="<?= $active_page === 'dashboard' ? 'active' : '' ?>">
            Dashboard
        </a>
        <a href="manage_accounts.php" class="<?= $active_page === 'accounts' ? 'active' : '' ?>">
            Manage Accounts<?php if ($pending_bubble): ?> <span class="nav-bubble"><?= $pending_bubble ?></span><?php endif; ?>
        </a>
        <a href="files.php" class="<?= $active_page === 'files' ? 'active' : '' ?>">
            Files
        </a>
    </nav>
    <div class="sidebar-footer">
        <p><?= htmlspecialchars($_SESSION['username']) ?></p>
        <a href="../logout.php">Logout</a>
    </div>
</aside>