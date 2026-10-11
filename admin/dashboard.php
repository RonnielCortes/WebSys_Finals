<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';

$active_page = 'dashboard';

$pending_count = $conn->query("SELECT COUNT(*) AS c FROM users WHERE account_status = 'pending'")->fetch_assoc()['c'];
$total_accounts = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
$total_files = $conn->query("SELECT COUNT(*) AS c FROM files")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard</title>
<link rel="stylesheet" href="../css/style.css">
</head>
<body class="with-sidebar">

<?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

<main class="main-content">
    <h2>Admin Dashboard</h2>
    <p>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</p>

    <div class="stats-row">
        <div class="stat-card">
            <span class="stat-number"><?= $pending_count ?></span>
            <span class="stat-label">Pending Accounts</span>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?= $total_accounts ?></span>
            <span class="stat-label">Total Accounts</span>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?= $total_files ?></span>
            <span class="stat-label">Total Files</span>
        </div>
    </div>

    <?php if ($pending_count > 0): ?>
        <div class="alert alert-error">
            You have <?= $pending_count ?> account(s) waiting for approval.
            <a href="manage_accounts.php">Review now</a>
        </div>
    <?php endif; ?>
</main>

</body>
</html>