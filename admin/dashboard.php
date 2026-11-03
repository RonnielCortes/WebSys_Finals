<?php
require_once __DIR__ . '/../includes/check.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$active_page = 'dashboard';

// ---------------------------------------------------------------
// Stat cards
// ---------------------------------------------------------------
$pending_count  = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE account_status = 'pending'")->fetch_assoc()['c'];
$total_accounts = (int)$conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];
$inactive_count = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE account_status = 'approved' AND is_active = 0")->fetch_assoc()['c'];
$total_files    = (int)$conn->query("SELECT COUNT(*) AS c FROM files WHERE is_archived = 0")->fetch_assoc()['c'];

// Accounts by role
$role_counts = ['admin' => 0, 'qa' => 0, 'program' => 0];
$res = $conn->query("SELECT r.role_name, COUNT(*) AS c FROM users u JOIN roles r ON u.role_id = r.role_id GROUP BY r.role_name");
while ($r = $res->fetch_assoc()) { $role_counts[$r['role_name']] = (int)$r['c']; }

// ---------------------------------------------------------------
// Files by status (current versions only)
// ---------------------------------------------------------------
$status_counts = array_fill_keys(file_statuses(), 0);
$res = $conn->query("SELECT status, COUNT(*) AS c FROM files WHERE is_archived = 0 GROUP BY status");
while ($r = $res->fetch_assoc()) { $status_counts[$r['status']] = (int)$r['c']; }

$status_groups = [
    ['label' => 'Sent / Received', 'keys' => ['sent', 'received'],     'color' => '#6d28d9'],
    ['label' => 'Under review',    'keys' => ['under_review'],         'color' => '#a78bfa'],
    ['label' => 'Approved',        'keys' => ['approved'],             'color' => '#22c55e'],
    ['label' => 'Needs revision',  'keys' => ['needs_revision'],       'color' => '#fb923c'],
    ['label' => 'Rejected / Returned', 'keys' => ['rejected', 'returned'], 'color' => '#f0abfc'],
];
$donut_parts = []; $angle = 0;
foreach ($status_groups as $i => $g) {
    $n = 0;
    foreach ($g['keys'] as $k) { $n += $status_counts[$k]; }
    $status_groups[$i]['count'] = $n;
    if ($total_files > 0 && $n > 0) {
        $end = $angle + ($n / $total_files) * 100;
        $donut_parts[] = $g['color'] . ' ' . round($angle, 2) . '% ' . round($end, 2) . '%';
        $angle = $end;
    }
}
$donut_css = $donut_parts ? implode(', ', $donut_parts) : '#ede9fe 0 100%';

// ---------------------------------------------------------------
// Files by program / office (top 6, current versions only)
// ---------------------------------------------------------------
$programs = load_programs($conn);
$by_program = [];
$res = $conn->query("SELECT program_id, COUNT(*) AS c FROM files WHERE is_archived = 0 GROUP BY program_id ORDER BY c DESC LIMIT 6");
while ($r = $res->fetch_assoc()) {
    $pid = (int)$r['program_id'];
    $by_program[] = [
        'name'  => isset($programs[$pid]) ? $programs[$pid]['program_name'] : 'Unknown',
        'crumb' => isset($programs[$pid]) ? program_breadcrumb($programs, $pid) : '',
        'count' => (int)$r['c'],
    ];
}
$max_program = $by_program ? max(array_column($by_program, 'count')) : 1;

// ---------------------------------------------------------------
// Oldest file still waiting for QA
// ---------------------------------------------------------------
$oldest_days = null;
$row = $conn->query(
    "SELECT DATEDIFF(NOW(), MIN(created_at)) AS d FROM files
     WHERE is_archived = 0 AND status IN ('sent','received','under_review')"
)->fetch_assoc();
if ($row && $row['d'] !== null) { $oldest_days = (int)$row['d']; }

// ---------------------------------------------------------------
// Recent files (5)
// ---------------------------------------------------------------
$recent_files = $conn->query(
    "SELECT f.file_id, f.file_path, f.status, f.created_at, f.program_id
     FROM files f WHERE f.is_archived = 0
     ORDER BY f.created_at DESC LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------
// Pending accounts (5)
// ---------------------------------------------------------------
$pending_accounts = $conn->query(
    "SELECT u.user_id, u.username, u.full_name, r.role_name, p.program_name
     FROM users u
     JOIN roles r ON u.role_id = r.role_id
     LEFT JOIN programs p ON u.program_id = p.program_id
     WHERE u.account_status = 'pending'
     ORDER BY u.created_at ASC LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------
// Recent activity from file_logs (8)
// ---------------------------------------------------------------
$activity = $conn->query(
    "SELECT l.action, l.remarks, l.timestamp, f.file_path,
            COALESCE(u.full_name, u.username) AS actor, r.role_name
     FROM file_logs l
     JOIN files f ON l.file_id = f.file_id
     JOIN users u ON l.performed_by = u.user_id
     JOIN roles r ON u.role_id = r.role_id
     ORDER BY l.timestamp DESC, l.log_id DESC LIMIT 8"
)->fetch_all(MYSQLI_ASSOC);

function activity_text($action) {
    $map = [
        'sent'                 => 'sent',
        'resubmitted'          => 're-submitted',
        'replaced'             => 'replaced (old version archived)',
        'flagged_for_revision' => 'sent back for revision:',
        'received'             => 'received',
        'reviewed'             => 'reviewed',
        'approved'             => 'approved',
        'rejected'             => 'rejected',
        'returned'             => 'returned',
    ];
    return $map[$action] ?? $action;
}

function initials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $i = strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
    if (count($parts) > 1) { $i .= strtoupper(mb_substr(end($parts), 0, 1)); }
    return $i;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard</title>
<link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
</head>
<body class="with-sidebar">

<?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

<main class="main-content">
    <div class="page-top">
        <div>
            <h2>Admin Dashboard</h2>
            <p class="muted">Welcome back, <?= htmlspecialchars(session_display_name($conn)) ?>! Here's what's happening in the system.</p>
        </div>
        <div class="top-date"><?= date('D, M d, Y') ?></div>
    </div>

    <!-- stat cards -->
    <div class="dash-cards">
        <div class="dash-card dash-card-hot">
            <span class="dash-num"><?= $pending_count ?></span>
            <span class="dash-label">Pending Accounts</span>
            <span class="dash-note"><?= $pending_count ? 'Needs your approval' : 'All caught up' ?></span>
        </div>
        <div class="dash-card">
            <span class="dash-num"><?= $total_accounts ?></span>
            <span class="dash-label">Total Accounts</span>
            <span class="dash-note"><?= $role_counts['admin'] ?> admin &middot; <?= $role_counts['qa'] ?> QA &middot; <?= $role_counts['program'] ?> program<?= $inactive_count ? ' &middot; ' . $inactive_count . ' inactive' : '' ?></span>
        </div>
        <div class="dash-card">
            <span class="dash-num"><?= $total_files ?></span>
            <span class="dash-label">Total Files</span>
            <span class="dash-note">Current versions only</span>
        </div>
        <div class="dash-card">
            <span class="dash-num"><?= $oldest_days === null ? '&mdash;' : $oldest_days . 'd' ?></span>
            <span class="dash-label">Oldest Waiting for QA</span>
            <span class="dash-note"><?= $oldest_days === null ? 'Nothing waiting' : ($oldest_days === 0 ? 'Sent today' : 'Longest wait in the queue') ?></span>
        </div>
    </div>

    <?php if ($pending_count > 0): ?>
        <div class="dash-banner">
            <span><strong><?= $pending_count ?> account<?= $pending_count > 1 ? 's are' : ' is' ?></strong> waiting for approval. Review so they can start using the system.</span>
            <a href="manage_accounts.php" class="dash-btn">Review now</a>
        </div>
    <?php endif; ?>

    <!-- charts -->
    <div class="dash-grid dash-grid-a">
        <section class="dash-panel">
            <h3>Files by Program / Office</h3>
            <p class="dash-hint">Current versions only &middot; top 6</p>
            <?php if (empty($by_program)): ?>
                <div class="dash-empty">No files yet.</div>
            <?php else: ?>
                <?php foreach ($by_program as $p): ?>
                    <div class="dash-bar" title="<?= htmlspecialchars($p['crumb']) ?>">
                        <span><?= htmlspecialchars($p['name']) ?></span>
                        <div class="dash-track"><div class="dash-fill" style="width: <?= max(4, round($p['count'] / $max_program * 100)) ?>%"></div></div>
                        <b><?= $p['count'] ?></b>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="dash-panel">
            <h3>Files by Status</h3>
            <p class="dash-hint">Where everything stands right now</p>
            <div class="dash-donut-wrap">
                <div class="dash-donut" style="background: conic-gradient(<?= $donut_css ?>);">
                    <div><b><?= $total_files ?></b><small>files</small></div>
                </div>
                <div class="dash-legend">
                    <?php foreach ($status_groups as $g): ?>
                        <div><i style="background: <?= $g['color'] ?>"></i><?= htmlspecialchars($g['label']) ?><b><?= $g['count'] ?></b></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>

    <!-- tables -->
    <div class="dash-grid dash-grid-b">
        <section class="dash-panel">
            <h3>Recent Files</h3>
            <p class="dash-hint">Latest uploads from all programs</p>
            <?php if (empty($recent_files)): ?>
                <div class="dash-empty">No files have been sent yet.</div>
            <?php else: ?>
                <table class="dash-table">
                    <thead><tr><th>File</th><th>From</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent_files as $f): ?>
                        <tr>
                            <td><a href="../download.php?id=<?= (int)$f['file_id'] ?>"><?= htmlspecialchars(display_name($f['file_path'])) ?></a></td>
                            <td><?= htmlspecialchars(isset($programs[(int)$f['program_id']]) ? $programs[(int)$f['program_id']]['program_name'] : '—') ?></td>
                            <td><span class="badge badge-status-<?= htmlspecialchars($f['status']) ?>"><?= htmlspecialchars(status_label($f['status'])) ?></span></td>
                            <td><?= date('M d, h:i A', strtotime($f['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <a href="files.php" class="dash-link">View all files &rarr;</a>
            <?php endif; ?>
        </section>

        <section class="dash-panel">
            <h3>Waiting for Approval</h3>
            <p class="dash-hint">New accounts that registered</p>
            <?php if (empty($pending_accounts)): ?>
                <div class="dash-empty">No pending accounts.</div>
            <?php else: ?>
                <?php foreach ($pending_accounts as $u): $nm = $u['full_name'] ?: $u['username']; ?>
                    <div class="dash-who">
                        <div class="dash-avatar"><?= htmlspecialchars(initials($nm)) ?></div>
                        <div class="dash-who-text">
                            <b><?= htmlspecialchars($nm) ?></b>
                            <small><?= $u['role_name'] === 'program' ? 'Program &middot; ' . htmlspecialchars($u['program_name'] ?? '—') : strtoupper(htmlspecialchars($u['role_name'])) ?></small>
                        </div>
                        <div class="dash-actions">
                            <form method="POST" action="accounts_handler.php">
                                <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="return" value="dashboard">
                                <button type="submit" class="dash-mini dash-mini-ok">Approve</button>
                            </form>
                            <form method="POST" action="accounts_handler.php">
                                <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="return" value="dashboard">
                                <button type="submit" class="dash-mini dash-mini-no">Reject</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <a href="manage_accounts.php" class="dash-link">Manage all accounts &rarr;</a>
            <?php endif; ?>
        </section>
    </div>

    <!-- activity -->
    <section class="dash-panel">
        <h3>Recent Activity</h3>
        <p class="dash-hint">Latest actions on files, from the file history</p>
        <?php if (empty($activity)): ?>
            <div class="dash-empty">No activity yet.</div>
        <?php else: ?>
            <ul class="dash-activity">
                <?php foreach ($activity as $a): ?>
                    <li>
                        <span class="dash-dot"></span>
                        <div>
                            <b><?= htmlspecialchars($a['actor']) ?></b>
                            <span class="dash-role"><?= htmlspecialchars(strtoupper($a['role_name'])) ?></span>
                            <?= htmlspecialchars(activity_text($a['action'])) ?>
                            <?php if ($a['action'] === 'flagged_for_revision' && $a['remarks']): ?>
                                <em>&ldquo;<?= htmlspecialchars(mb_strimwidth($a['remarks'], 0, 70, '…')) ?>&rdquo;</em>
                            <?php endif; ?>
                            <strong><?= htmlspecialchars(display_name($a['file_path'])) ?></strong>
                        </div>
                        <time><?= date('M d, h:i A', strtotime($a['timestamp'])) ?></time>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</main>

</body>
</html>