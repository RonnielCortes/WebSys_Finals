<?php
// Expects $active_page to be set by the including page, e.g. 'dashboard', 'accounts', 'files'

require_once __DIR__ . '/icons.php';
$active_page = $active_page ?? '';
$pending_bubble = 0;
if (isset($conn)) {
    $pending_bubble = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE account_status = 'pending'")->fetch_assoc()['c'];
}
?>
<aside class="sidebar">
    <?= sidebar_brand('Admin Panel') ?>
    <nav class="sidebar-nav">
        <a href="/websys/admin/dashboard.php" class="<?= $active_page === 'dashboard' ? 'active' : '' ?>">
            <?= icon('dashboard') ?>Dashboard
        </a>
        <a href="/websys/admin/manage_accounts.php" class="<?= $active_page === 'accounts' ? 'active' : '' ?>">
            <?= icon('accounts') ?>Manage Accounts<?php if ($pending_bubble): ?><span class="nav-bubble"><?= $pending_bubble ?></span><?php endif; ?>
        </a>
        <a href="/websys/admin/files.php" class="<?= $active_page === 'files' ? 'active' : '' ?>">
            <?= icon('files') ?>Files
        </a>
    </nav>
    <?= sidebar_user($conn ?? null) ?>
</aside>