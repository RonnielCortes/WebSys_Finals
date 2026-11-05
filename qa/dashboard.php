<?php
// ============================================
// qa/dashboard.php
// Notification-style "needs attention" + simple charts.
// The weekly numbers/charts follow a Monday-Sunday week and start from zero
// every Monday (nothing is deleted: ?w=1 shows last week, etc.).
// The waiting list, longest wait and the "All files" donut never reset.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('qa');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

date_default_timezone_set('Asia/Manila');
$active_page = 'dashboard';
$flash = $_GET['flash'] ?? '';

$WAITING = ['sent', 'received', 'under_review'];
$HANDLED = ['needs_revision', 'approved', 'rejected', 'returned'];

function qd_in($list) { return "'" . implode("','", $list) . "'"; }
function qd_h($s)     { return htmlspecialchars((string)$s, ENT_QUOTES); }
function qd_rows($conn, $sql, $a, $b) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $a, $b);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
function qd_days($created) {
    return max(0, (int)floor((strtotime('today') - strtotime(date('Y-m-d', strtotime($created)))) / 86400));
}
function qd_days_text($d) { return $d === 0 ? 'Today' : $d . ($d === 1 ? ' day' : ' days'); }

// --------------------------------------------
// Week (Monday to Sunday). w=0 this week, w=1 last week ...
// --------------------------------------------
$w     = max(0, min(52, (int)($_GET['w'] ?? 0)));
$start = strtotime("-$w weeks", strtotime('monday this week'));
$end   = strtotime('+7 days', $start);
$ws    = date('Y-m-d H:i:s', $start);
$we    = date('Y-m-d H:i:s', $end);
$week_label = date('M d', $start) . ' – ' . date('M d', strtotime('-1 day', $end));
$week_word  = ($w === 0) ? 'this week' : 'that week';

// --------------------------------------------
// Filters
// --------------------------------------------
$view = $_GET['view'] ?? 'waiting';
if (!in_array($view, ['waiting', 'handled', 'all'], true)) $view = 'waiting';
$program_filter = (int)($_GET['program'] ?? 0);
$search = trim($_GET['q'] ?? '');

$state = ['view' => $view, 'program' => $program_filter, 'q' => $search, 'w' => $w];
function qd_url($over = []) {
    global $state;
    $q = array_filter(array_merge($state, $over), function ($v) { return $v !== '' && $v !== 0; });
    return 'dashboard.php' . ($q ? '?' . http_build_query($q) : '');
}
$back = http_build_query(array_filter($state, function ($v) { return $v !== '' && $v !== 0; }));

// --------------------------------------------
// Numbers that never reset
// --------------------------------------------
$row = $conn->query(
    "SELECT SUM(status IN (" . qd_in($WAITING) . ")) AS waiting,
            SUM(status IN (" . qd_in($HANDLED) . ")) AS handled
     FROM files WHERE is_archived = 0"
)->fetch_assoc();
$waiting_n = (int)$row['waiting'];
$handled_n = (int)$row['handled'];
$all_n     = $waiting_n + $handled_n;

$arrived_today = (int)$conn->query(
    "SELECT COUNT(*) AS c FROM files
     WHERE is_archived = 0 AND status IN (" . qd_in($WAITING) . ")
       AND created_at >= '" . date('Y-m-d 00:00:00') . "'"
)->fetch_assoc()['c'];

// oldest waiting files -> notifications + longest wait
$attention = $conn->query(
    "SELECT f.file_id, f.file_path, f.created_at, p.program_name, c.category_name,
            COALESCE(u.full_name, u.username) AS sent_by,
            (SELECT l.remarks FROM file_logs l
             WHERE l.file_id = f.file_id AND l.action IN ('sent', 'resubmitted')
             ORDER BY l.timestamp DESC, l.log_id DESC LIMIT 1) AS program_note
     FROM files f
     JOIN programs p ON f.program_id = p.program_id
     JOIN users u ON f.uploaded_by = u.user_id
     LEFT JOIN file_categories c ON f.category_id = c.category_id
     WHERE f.is_archived = 0 AND f.status IN (" . qd_in($WAITING) . ")
     ORDER BY f.created_at ASC LIMIT 3"
)->fetch_all(MYSQLI_ASSOC);
$longest = $attention ? $attention[0] : null;

// --------------------------------------------
// Numbers that follow the selected week
// --------------------------------------------
$received_week = (int)qd_rows($conn, "SELECT COUNT(*) AS c FROM files WHERE created_at >= ? AND created_at < ?", $ws, $we)[0]['c'];
$handled_week  = (int)qd_rows($conn,
    "SELECT COUNT(DISTINCT file_id) AS c FROM file_logs
     WHERE action IN ('approved','flagged_for_revision','rejected','returned')
       AND `timestamp` >= ? AND `timestamp` < ?", $ws, $we)[0]['c'];

$per_day = [];
foreach (qd_rows($conn, "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM files
                         WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)", $ws, $we) as $r) {
    $per_day[$r['d']] = (int)$r['c'];
}
$days = [];
for ($i = 0; $i < 7; $i++) {
    $t = strtotime("+$i days", $start);
    $days[] = ['label' => date('D', $t), 'n' => $per_day[date('Y-m-d', $t)] ?? 0];
}
$day_max = max(1, max(array_column($days, 'n')));

$per_program = qd_rows($conn,
    "SELECT p.program_name, COUNT(*) AS c FROM files f
     JOIN programs p ON f.program_id = p.program_id
     WHERE f.created_at >= ? AND f.created_at < ?
     GROUP BY p.program_id, p.program_name ORDER BY c DESC, p.program_name LIMIT 6", $ws, $we);
$prog_max = $per_program ? max(1, (int)$per_program[0]['c']) : 1;

$pct = $all_n ? round($waiting_n / $all_n * 100, 1) : 0;
$ring = $all_n
    ? "conic-gradient(#5b2fc9 0 {$pct}%, #d9cffa {$pct}% 100%)"
    : "#ece7f8";

// --------------------------------------------
// The list (tab + program chip + search)
// --------------------------------------------
$status_in = ($view === 'waiting') ? qd_in($WAITING)
           : (($view === 'handled') ? qd_in($HANDLED) : qd_in(array_merge($WAITING, $HANDLED)));

$chips = $conn->query(
    "SELECT f.program_id, p.program_name, COUNT(*) AS c FROM files f
     JOIN programs p ON f.program_id = p.program_id
     WHERE f.is_archived = 0 AND f.status IN ($status_in)
     GROUP BY f.program_id, p.program_name ORDER BY p.program_name"
)->fetch_all(MYSQLI_ASSOC);
$chip_total = array_sum(array_column($chips, 'c'));

$sql = "SELECT f.file_id, f.file_path, f.status, f.created_at, f.program_id,
               p.program_name, p.parent_id, c.category_name,
               COALESCE(u.full_name, u.username) AS sent_by,
               (SELECT l.remarks FROM file_logs l
                 WHERE l.file_id = f.file_id AND l.action = 'flagged_for_revision'
                 ORDER BY l.timestamp DESC, l.log_id DESC LIMIT 1) AS qa_remark,
               (SELECT l.remarks FROM file_logs l
                 WHERE l.file_id = f.file_id AND l.action IN ('sent', 'resubmitted')
                 ORDER BY l.timestamp DESC, l.log_id DESC LIMIT 1) AS program_note
        FROM files f
        JOIN users u ON f.uploaded_by = u.user_id
        JOIN programs p ON f.program_id = p.program_id
        LEFT JOIN file_categories c ON f.category_id = c.category_id
        WHERE f.is_archived = 0 AND f.status IN ($status_in)";
$types = '';
$params = [];
if ($program_filter) {
    $sql .= " AND f.program_id = ?";
    $types .= 'i';
    $params[] = $program_filter;
}
if ($search !== '') {
    $sql .= " AND f.file_path LIKE ?";
    $types .= 's';
    $params[] = '%' . addcslashes($search, '%_\\') . '%';
}
$sql .= ($view === 'waiting') ? " ORDER BY f.created_at ASC" : " ORDER BY f.created_at DESC";
$sql .= " LIMIT 200";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$programs = load_programs($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>QA Dashboard</title>
<link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
</head>
<body class="with-sidebar">

<?php include __DIR__ . '/../includes/qa_sidebar.php'; ?>

<main class="main-content qd-main">
    <div class="page-top">
        <div>
            <h2>QA Dashboard</h2>
            <p class="muted">Welcome, <?= qd_h(session_display_name($conn)) ?>. Here is what is new.</p>
        </div>
        <div class="top-date"><?= date('D, M d, Y') ?></div>
    </div>

    <?php if ($flash === 'approved'): ?>
        <div class="alert alert-success">File approved. The program was told.</div>
    <?php elseif ($flash === 'sent_back'): ?>
        <div class="alert alert-success">File sent back. The program was told what to fix.</div>
    <?php elseif ($flash === 'need_note'): ?>
        <div class="alert alert-error">Please write a short note so the program knows what to fix.</div>
    <?php elseif ($flash === 'cannot_review'): ?>
        <div class="alert alert-error">That file was already handled, so nothing was changed.</div>
    <?php endif; ?>
    &nbsp;&nbsp;

    <div class="qd-panel">
        <h3>Needs your attention</h3>
        <?php if (!$attention): ?>
            <p class="muted qd-empty">Nothing is waiting for you. Good job!</p>
        <?php endif; ?>
        <?php foreach ($attention as $a): ?>
            <?php $d = qd_days($a['created_at']); ?>
            <div class="qd-note <?= $d >= 1 ? 'urgent' : '' ?>">
                <div class="qd-ico" aria-hidden="true">
                    <?php if ($d >= 1): ?>
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <?php else: ?>
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/></svg>
                    <?php endif; ?>
                </div>
                <div class="qd-note-text">
                    <b><?= $d >= 1 ? 'Waiting for ' . qd_days_text($d) . ': ' : 'New file from ' ?><?= qd_h($a['program_name']) ?></b>
                    <span><?= qd_h(display_name($a['file_path'])) ?> &middot; <?= qd_h($a['category_name'] ?? 'No category') ?> &middot; sent by <?= qd_h($a['sent_by']) ?> &middot; <?= date('M d, h:i A', strtotime($a['created_at'])) ?></span>
                    <?php $pn = program_note($a['program_note'] ?? ''); if ($pn !== ''): ?>
                        <div class="pnote"><b>Note:</b> <?= nl2br(qd_h($pn)) ?></div>
                    <?php endif; ?>
                </div>
                <a class="qd-btn" href="../download.php?id=<?= (int)$a['file_id'] ?>">Open file</a>
            </div>
        <?php endforeach; ?>
        <?php if ($waiting_n > count($attention)): ?>
            <p class="muted qd-empty"><?= $waiting_n - count($attention) ?> more waiting in the list below.</p>
        <?php endif; ?>
    </div>

    <div class="qd-stats">
        <div class="qd-stat main">
            <span class="n"><?= $waiting_n ?></span>
            <span class="l">Files waiting for you<?= $arrived_today ? ' (' . $arrived_today . ' arrived today)' : '' ?></span>
        </div>
        <div class="qd-stat">
            <span class="n"><?= $longest ? qd_days_text(qd_days($longest['created_at'])) : '—' ?></span>
            <span class="l">Longest wait<?= $longest ? ': ' . qd_h(display_name($longest['file_path'])) . ' (' . qd_h($longest['program_name']) . ')' : '' ?></span>
        </div>
        <div class="qd-stat">
            <span class="n"><?= $received_week ?></span>
            <span class="l">Received <?= $week_word ?></span>
        </div>
        <div class="qd-stat">
            <span class="n"><?= $handled_week ?></span>
            <span class="l">Handled <?= $week_word ?></span>
        </div>
    </div>

    <div class="qd-charts">
        <div class="qd-panel">
            <h3>All files</h3>
            <div class="qd-donut">
                <div class="qd-ring" style="background: <?= $ring ?>"><div><b><?= $all_n ?></b>files</div></div>
                <div class="qd-key">
                    <span><i style="background:#5b2fc9"></i>Waiting: <b><?= $waiting_n ?></b></span>
                    <span><i style="background:#d9cffa"></i>Handled: <b><?= $handled_n ?></b></span>
                </div>
            </div>
        </div>

        <div class="qd-panel">
            <h3>Received, by program (<?= qd_h($week_label) ?>)</h3>
            <?php if (!$per_program): ?>
                <p class="muted qd-empty">No files received <?= $week_word ?>.</p>
            <?php endif; ?>
            <?php foreach ($per_program as $pp): ?>
                <div class="qd-hrow">
                    <span><?= qd_h($pp['program_name']) ?></span>
                    <div class="qd-track"><div class="qd-fill" style="width: <?= round($pp['c'] / $prog_max * 100) ?>%"></div></div>
                    <b><?= (int)$pp['c'] ?></b>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="qd-panel">
            <h3>Files received each day</h3>
            <div class="qd-cols">
                <?php foreach ($days as $dd): ?>
                    <div class="qd-col">
                        <b><?= $dd['n'] ?></b>
                        <div class="bar <?= $dd['n'] ? '' : 'zero' ?>" <?= $dd['n'] ? 'style="height:' . round($dd['n'] / $day_max * 110) . 'px"' : '' ?>></div>
                        <?= qd_h($dd['label']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="qd-panel">
        <h3>Files from Programs &amp; Offices</h3>

        <div class="tab-row">
            <a class="tab <?= $view === 'waiting' ? 'active' : '' ?>" href="<?= qd_url(['view' => 'waiting', 'program' => 0]) ?>">Waiting (<?= $waiting_n ?>)</a>
            <a class="tab <?= $view === 'handled' ? 'active' : '' ?>" href="<?= qd_url(['view' => 'handled', 'program' => 0]) ?>">Handled (<?= $handled_n ?>)</a>
            <a class="tab <?= $view === 'all' ? 'active' : '' ?>" href="<?= qd_url(['view' => 'all', 'program' => 0]) ?>">All (<?= $all_n ?>)</a>
        </div>

        <div class="qd-chips">
            <span>Show files from:</span>
            <a class="qd-chip <?= !$program_filter ? 'on' : '' ?>" href="<?= qd_url(['program' => 0]) ?>">All programs (<?= $chip_total ?>)</a>
            <?php foreach ($chips as $c): ?>
                <a class="qd-chip <?= $program_filter === (int)$c['program_id'] ? 'on' : '' ?>" href="<?= qd_url(['program' => (int)$c['program_id']]) ?>">
                    <?= qd_h($c['program_name']) ?> (<?= (int)$c['c'] ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <form method="GET" action="dashboard.php" class="qd-search">
            <input type="hidden" name="view" value="<?= qd_h($view) ?>">
            <?php if ($program_filter): ?><input type="hidden" name="program" value="<?= $program_filter ?>"><?php endif; ?>
            <?php if ($w): ?><input type="hidden" name="w" value="<?= $w ?>"><?php endif; ?>
            <input type="text" name="q" aria-label="Search by file name" value="<?= qd_h($search) ?>" placeholder="Search file name">
            <button type="submit" class="qd-btn">Search</button>
            <?php if ($search !== ''): ?><a href="<?= qd_url(['q' => '']) ?>" class="btn-reset">Clear</a><?php endif; ?>
        </form>

        <?php if (!$files): ?>
            <div class="empty-state"><?= $view === 'waiting' ? 'No files are waiting for you.' : 'No files found.' ?></div>
        <?php else: ?>
            <div class="qd-table-wrap">
            <table class="data-table qd-table">
                <thead>
                    <tr><th>File</th><th>Program</th><th>Category</th><th>Sent by</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($files as $f): ?>
                    <?php
                        $is_waiting = in_array($f['status'], $WAITING, true);
                        $d = qd_days($f['created_at']);
                        $parent = $f['parent_id'] ? program_breadcrumb($programs, (int)$f['parent_id']) : '';
                    ?>
                    <tr>
                        <td><strong><?= qd_h(display_name($f['file_path'])) ?></strong>
                            <small><?= date('M d, Y h:i A', strtotime($f['created_at'])) ?></small>
                            <?php $pn = program_note($f['program_note'] ?? ''); if ($pn !== ''): ?>
                                <div class="pnote"><b>Note from <?= qd_h($f['program_name']) ?>:</b> <?= nl2br(qd_h($pn)) ?></div>
                            <?php endif; ?></td>
                        <td><?= qd_h($f['program_name']) ?><?= $parent ? '<small>' . qd_h($parent) . '</small>' : '' ?></td>
                        <td><?= qd_h($f['category_name'] ?? '—') ?></td>
                        <td><?= qd_h($f['sent_by']) ?></td>
                        <td>
                            <?php if ($is_waiting): ?>
                                <span class="qd-pill <?= $d >= 1 ? 'old' : '' ?>"><?= qd_h(qd_days_text($d)) ?></span>
                            <?php else: ?>
                                <span class="badge badge-status-<?= qd_h($f['status']) ?>"><?= qd_h(status_label($f['status'])) ?></span>
                                <?php if ($f['status'] === 'needs_revision' && $f['qa_remark']): ?>
                                    <small title="<?= qd_h($f['qa_remark']) ?>">Your note: <?= qd_h(mb_strimwidth($f['qa_remark'], 0, 40, '…')) ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="qd-acts">
                                <a class="qd-btn" href="../download.php?id=<?= (int)$f['file_id'] ?>">Download</a>
                                <?php if ($is_waiting): ?>
                                    <form method="POST" action="review.php" class="js-confirm"
                                          data-confirm-title="Approve this file?"
                                          data-confirm-message="<?= qd_h(display_name($f['file_path'])) ?> from <?= qd_h($f['program_name']) ?> will be marked approved and the program will be told."
                                          data-confirm-yes="Yes, approve" data-confirm-tone="good">
                                        <input type="hidden" name="file_id" value="<?= (int)$f['file_id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="back" value="<?= qd_h($back) ?>">
                                        <button type="submit" class="qd-btn good">Approve</button>
                                    </form>
                                    <details class="qd-send">
                                        <summary class="qd-btn alt">Send back</summary>
                                        <form method="POST" action="review.php">
                                            <input type="hidden" name="file_id" value="<?= (int)$f['file_id'] ?>">
                                            <input type="hidden" name="action" value="send_back">
                                            <input type="hidden" name="back" value="<?= qd_h($back) ?>">
                                            <label for="n<?= (int)$f['file_id'] ?>">What should they fix?</label>
                                            <textarea id="n<?= (int)$f['file_id'] ?>" name="remarks" rows="3" maxlength="500" required placeholder="Write a short note for the program"></textarea>
                                            <button type="submit" class="qd-btn alt">Send back</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p class="qd-foot">The oldest files are at the top of the Waiting list. You can view and download files, but you can't change them.</p>
        <?php endif; ?>
    </div>
</main>

<script src="../js/main.js?v=<?= @filemtime(__DIR__ . '/../js/main.js') ?>"></script>
</body>
</html>