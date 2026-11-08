<?php
// Shared by QA and Program users. Admin has no notifications.
require_once __DIR__ . '/includes/check.php';
require_login();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';

date_default_timezone_set('Asia/Manila');   // so "Today" / "Yesterday" match the user's clock

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

// Group by day: "Today", "Yesterday", then "Mon, Nov 03, 2026"
$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$days = [];
foreach ($items as $n) {
    $d = date('Y-m-d', strtotime($n['created_at']));
    if ($d === $today) {
        $label = 'Today';
    } elseif ($d === $yesterday) {
        $label = 'Yesterday';
    } else {
        $label = date('D, M d, Y', strtotime($d));
    }
    $days[$d]['label']   = $label;
    $days[$d]['items'][] = $n;
}
$unread_total = 0;
foreach ($items as $n) {
    if (!$n['is_read']) {
        $unread_total++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>
    <link rel="stylesheet" href="css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
</head>

<body class="with-sidebar">

    <?php
    include __DIR__ . '/includes/' . ($role === 'qa' ? 'qa_sidebar.php' : 'program_sidebar.php');
    ?>

    <main class="main-content">
        <div class="nd-wrap">

            <div class="page-top">
                <div>
                    <h2>Notifications</h2>
                    <p class="muted">
                        <?php if ($unread_total): ?><?= $unread_total ?> new &middot; <?php endif; ?>Latest 100, grouped by day.
                    </p>
                </div>
                <a href="<?= $dir ?>/dashboard.php" class="nd-back"> Back to dashboard</a>
            </div>

            <?php if (empty($days)): ?>
                <div class="empty-state">No notifications yet.</div>
            <?php else: ?>
                <?php foreach ($days as $day): ?>
                    <section class="nd-day">
                        <h3><?= htmlspecialchars($day['label']) ?> <small><?= count($day['items']) ?></small></h3>
                        <ul class="nd-list">
                            <?php foreach ($day['items'] as $n): ?>
                                <li class="<?= $n['is_read'] ? '' : 'unread' ?>">
                                    <span class="nd-dot" aria-hidden="true"></span>
                                    <span class="nd-msg"><?= htmlspecialchars($n['message']) ?></span>
                                    <small class="nd-time"><?= date('h:i A', strtotime($n['created_at'])) ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </main>
</body>

</html>