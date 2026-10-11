<?php
$active_page = $active_page ?? '';
?>

<aside class="sidebar">
    <div class="side-bar">
        <h3>WebSys Admin</h3>
    </div>
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="<?= $active_page === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="manage_accounts.php" class="<?= $active_page === 'accounts' ? 'active' : '' ?>">Manage Accounts</a>
        <a href="files.php" class="<?= $active_page === 'files' ? 'active' : '' ?>">Files</a>
    </nav>
    <div class="sidebar-footer">
        <p><?= htmlspecialchars($_SESSION['username'])?></p>
        <a href="../logout.php">Logout</a>
    </div>
</aside>