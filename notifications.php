<?php
// Shared by QA and Program users. Admin has no notifications.
require_once __DIR__ . '/includes/check.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

$role = $_SESSION['role_name'] ?? '';
if (!in_array($role, ['qa', 'program'], true)) {
    header("Location: admin/dashboard.php");
    exit();
}
$user_id = (int)$_SESSION['user_id'];
$active_page = 'notifications';
$dir = $role === 'qa' ? 'qa' : 'program';

// Newest first. Only THIS user's rows.
$stmt = $conn->prepare(
    "SELECT notification_id, file_id, message, is_read, created_at
     FROM notifications WHERE user_id = ? ORDER BY created_at DESC, notification_id DESC LIMIT 100"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Mark as read AFTER loading, so the page still shows which ones were new
$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Notifications</title>
<link rel="stylesheet" href="css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
</head>
<body class="with-sidebar">

<?php
include __DIR__ . '/includes/' . ($role === 'qa' ? 'qa_sidebar.php' : 'program_sidebar.php');
?>

<main class="main-content">
    <h2>Notifications</h2>
    <p class="muted">Latest 100 notifications.</p>

    <?php if (empty($items)): ?>
        <div class="empty-state">No notifications yet.</div>
    <?php else: ?>
        <ul class="notif-list">
            <?php foreach ($items as $n): ?>
                <li class="<?= $n['is_read'] ? '' : 'unread' ?>">
                    <span><?= htmlspecialchars($n['message']) ?></span>
                    <small><?= date('M d, Y h:i A', strtotime($n['created_at'])) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p><a href="<?= $dir ?>/dashboard.php">Back</a></p>
</main>
</body>
</html>