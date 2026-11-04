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
        <?= nav_link('/websys/admin/dashboard.php', 'dashboard', 'Dashboard', $active_page === 'dashboard') ?>
        <?= nav_link('/websys/admin/manage_accounts.php', 'accounts', 'Manage Accounts', $active_page === 'accounts', $pending_bubble) ?>
        <?= nav_link('/websys/admin/files.php', 'files', 'Files', $active_page === 'files') ?>
    </nav>
    <?= sidebar_user($conn ?? null) ?>
</aside>
<?= sidebar_script() ?>