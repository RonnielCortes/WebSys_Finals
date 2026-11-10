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

// ---------------------------------------------------------------
// Filters (GET): search text, type, period, unread only
// ---------------------------------------------------------------
$q      = trim($_GET['q'] ?? '');
$period = $_GET['period'] ?? 'all';      // all | today | 7 | 30
$type   = $_GET['type'] ?? 'all';
$status = ($_GET['status'] ?? 'all') === 'unread' ? 'unread' : 'all';

// "type" = the kind of notification, found by how the message starts
$all_types = [
    'new'       => ['New files',          'New file from%'],
    'replaced'  => ['Replaced files',     'Replaced file from%'],
    'resubmit'  => ['Re-submitted files', 'Re-submitted file from%'],
    'withdrawn' => ['Withdrawn files',    'File withdrawn%'],
    'approved'  => ['Approved',           'QA approved%'],
    'fix'       => ['Sent back / to fix', 'QA %'],     // refined below
];
$types_for_role = $role === 'qa'
    ? ['new', 'replaced', 'resubmit', 'withdrawn']
    : ['approved', 'fix'];
if (!in_array($type, $types_for_role, true)) { $type = 'all'; }
if (!in_array($period, ['all', 'today', '7', '30'], true)) { $period = 'all'; }

$where  = "user_id = ?";
$types  = "i";
$params = [$user_id];

if ($status === 'unread') {
    $where .= " AND is_read = 0";
}
if ($period === 'today') {
    $where .= " AND DATE(created_at) = CURDATE()";
} elseif ($period === '7') {
    $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($period === '30') {
    $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}
if ($type === 'fix') {
    $where .= " AND (message LIKE 'QA sent back%' OR message LIKE 'QA asked you to fix%')";
} elseif ($type !== 'all') {
    $where .= " AND message LIKE ?";
    $types .= "s";
    $params[] = $all_types[$type][1];
}
if ($q !== '') {
    $where .= " AND message LIKE ?";
    $types .= "s";
    $params[] = '%' . $q . '%';
}
$is_filtered = ($q !== '' || $period !== 'all' || $type !== 'all' || $status !== 'all');

// Link that keeps the other filters (used by the All / Unread chips)
function nurl($changes) {
    $now = array_filter([
        'q'      => $_GET['q'] ?? '',
        'period' => $_GET['period'] ?? '',
        'type'   => $_GET['type'] ?? '',
        'status' => $_GET['status'] ?? '',
    ], function ($v) { return $v !== '' && $v !== 'all'; });
    $now = array_merge($now, $changes);
    $now = array_filter($now, function ($v) { return $v !== '' && $v !== 'all'; });
    return 'notifications.php' . ($now ? '?' . http_build_query($now) : '');
}

// Newest first. Only THIS user's rows.
$stmt = $conn->prepare(
    "SELECT notification_id, file_id, message, is_read, created_at
     FROM notifications WHERE $where ORDER BY created_at DESC, notification_id DESC LIMIT 200"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Mark as read AFTER loading (so this page still shows which ones were new),
// and only the ones that were actually shown on screen.
$unread_ids = [];
foreach ($items as $n) {
    if (!$n['is_read']) { $unread_ids[] = (int)$n['notification_id']; }
}
if ($unread_ids) {
    $in = implode(',', $unread_ids);   // integers only
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = " . $user_id . " AND notification_id IN ($in)");
}

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
                        <?php if ($unread_total): ?><?= $unread_total ?> new &middot; <?php endif; ?><?= count($items) ?> shown<?= count($items) >= 200 ? ' (latest 200)' : '' ?>, grouped by day.
                    </p>
                </div>
                <a href="<?= $dir ?>/dashboard.php" class="nd-back"> Back to dashboard</a>
            </div>

            <form method="GET" class="qf-tools nd-filters">
                <?php if ($status === 'unread'): ?><input type="hidden" name="status" value="unread"><?php endif; ?>
                <input type="text" name="q" placeholder="Search notifications..." value="<?= htmlspecialchars($q) ?>">
                <select name="type" aria-label="Type">
                    <option value="all">All types</option>
                    <?php foreach ($types_for_role as $k): ?>
                        <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= htmlspecialchars($all_types[$k][0]) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="period" aria-label="Period">
                    <option value="all"   <?= $period === 'all'   ? 'selected' : '' ?>>Any time</option>
                    <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="7"     <?= $period === '7'     ? 'selected' : '' ?>>Last 7 days</option>
                    <option value="30"    <?= $period === '30'    ? 'selected' : '' ?>>Last 30 days</option>
                </select>
                <button type="submit">Apply</button>
                <?php if ($is_filtered): ?><a href="notifications.php" class="nd-back">Reset</a><?php endif; ?>
            </form>
            <div class="qf-chips">
                <a href="<?= htmlspecialchars(nurl(['status' => 'all'])) ?>" class="qf-chip <?= $status === 'all' ? 'on' : '' ?>">All</a>
                <a href="<?= htmlspecialchars(nurl(['status' => 'unread'])) ?>" class="qf-chip <?= $status === 'unread' ? 'on' : '' ?>">Unread</a>
            </div>

            <?php if (empty($days)): ?>
                <div class="empty-state"><?= $is_filtered ? 'No notifications match these filters.' : 'No notifications yet.' ?></div>
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