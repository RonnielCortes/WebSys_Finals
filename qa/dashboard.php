<?php
require_once __DIR__ . '/../includes/check.php';
require_role('qa');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$active_page = 'dashboard';
$flash = $_GET['flash'] ?? '';

$programs = load_programs($conn);
$parent_ids = [];
foreach ($programs as $p) {
    if ($p['parent_id']) { $parent_ids[(int)$p['parent_id']] = true; }
}
$program_options = [];
foreach ($programs as $id => $p) {
    if (!isset($parent_ids[$id])) { $program_options[$id] = program_breadcrumb($programs, $id); }
}
asort($program_options);

// IN  = files that came in and are still waiting for QA
// OUT = files QA already answered (sent back / approved / rejected / returned)
$in_statuses  = ['sent', 'received', 'under_review'];
$out_statuses = ['needs_revision', 'approved', 'rejected', 'returned'];

// --- stat cards (current versions only) ---
$counts = array_fill_keys(file_statuses(), 0);
$res = $conn->query("SELECT status, COUNT(*) AS c FROM files WHERE is_archived = 0 GROUP BY status");
while ($r = $res->fetch_assoc()) { $counts[$r['status']] = (int)$r['c']; }
$n_in  = array_sum(array_intersect_key($counts, array_flip($in_statuses)));
$n_out = array_sum(array_intersect_key($counts, array_flip($out_statuses)));

// --- extra dashboard numbers (fixed whitelists only, no user input in these queries) ---
$in_list  = "'" . implode("','", $in_statuses)  . "'";
$out_list = "'" . implode("','", $out_statuses) . "'";

// arrived today (still waiting)
$arrived_today = (int)$conn->query(
    "SELECT COUNT(*) FROM files
     WHERE is_archived = 0 AND status IN ($in_list) AND DATE(created_at) = CURDATE()"
)->fetch_row()[0];

// oldest file still waiting for QA
$oldest = $conn->query(
    "SELECT file_path, program_id, TIMESTAMPDIFF(DAY, created_at, NOW()) AS days_waiting
     FROM files WHERE is_archived = 0 AND status IN ($in_list)
     ORDER BY created_at ASC, file_id ASC LIMIT 1"
)->fetch_assoc();

// how many files are waiting for QA, per program (only programs that have something waiting)
$by_program = [];
$res = $conn->query(
    "SELECT program_id, COUNT(*) AS n_in FROM files
     WHERE is_archived = 0 AND status IN ($in_list) GROUP BY program_id"
);
while ($r = $res->fetch_assoc()) {
    $pid = (int)$r['program_id'];
    $by_program[] = [
        'id'   => $pid,
        'name' => $programs[$pid]['program_name'] ?? 'Unknown',
        'in'   => (int)$r['n_in'],
    ];
}
usort($by_program, function ($a, $b) { return $b['in'] <=> $a['in']; });
$by_program = array_slice($by_program, 0, 6);
$max_in = $by_program ? max(1, $by_program[0]['in']) : 1;

// --- filters ---
$view           = $_GET['view'] ?? 'in';
if (!in_array($view, ['in', 'out', 'all'], true)) { $view = 'in'; }
$filter_program = (int)($_GET['program'] ?? 0);
$search         = trim($_GET['q'] ?? '');

$sql = "SELECT f.file_id, f.file_path, f.status, f.created_at, f.program_id,
               c.category_name, COALESCE(u.full_name, u.username) AS sent_by,
               (SELECT l.remarks FROM file_logs l
                 WHERE l.file_id = f.file_id AND l.action = 'flagged_for_revision'
                 ORDER BY l.timestamp DESC, l.log_id DESC LIMIT 1) AS qa_remark
        FROM files f
        JOIN users u ON f.uploaded_by = u.user_id
        LEFT JOIN file_categories c ON f.category_id = c.category_id
        WHERE f.is_archived = 0";
$types = ''; $params = [];

if ($view === 'in' || $view === 'out') {
    $list = $view === 'in' ? $in_statuses : $out_statuses;
    $sql .= " AND f.status IN ('" . implode("','", $list) . "')";   // fixed whitelist, no user input
}
if ($filter_program) { $sql .= " AND f.program_id = ?"; $types .= 'i'; $params[] = $filter_program; }
if ($search !== '')  { $sql .= " AND f.file_path LIKE ?"; $types .= 's'; $params[] = '%' . addcslashes($search, '%_\\') . '%'; }
$sql .= " ORDER BY f.created_at DESC";

$stmt = $conn->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();

$groups = []; $shown = 0;
while ($row = $result->fetch_assoc()) {
    $groups[program_breadcrumb($programs, $row['program_id'])][] = $row;
    $shown++;
}
$stmt->close();
ksort($groups);

$is_filtered = ($filter_program || $search !== '');
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

<main class="main-content">
    <div class="page-top">
        <div>
            <h2>QA Dashboard</h2>
            <p class="muted">Welcome, <?= htmlspecialchars(session_display_name($conn)) ?>! Here are the files waiting for you.</p>
        </div>
        <div class="top-date"><?= date('D, M d, Y') ?></div>
    </div>

    <?php if ($flash === 'pinged'): ?>
        <div class="alert alert-success">Sent back to the program for revision. They have been notified.</div>
    <?php elseif ($flash === 'ping_failed'): ?>
        <div class="alert alert-error">Could not ping that file. It may already be handled, or the note was empty.</div>
    <?php endif; ?>

    <div class="dash-cards dash-cards-3">
        <div class="dash-card dash-card-hot">
            <span class="dash-num"><?= $n_in ?></span>
            <span class="dash-label">Waiting for you</span>
            <span class="dash-note"><?= $arrived_today ?> arrived today</span>
        </div>
        <div class="dash-card">
            <?php if ($oldest): ?>
                <span class="dash-num"><?= (int)$oldest['days_waiting'] ?> <small class="qa-unit">days</small></span>
                <span class="dash-label">Longest wait</span>
                <span class="dash-note"><?= htmlspecialchars(display_name($oldest['file_path'])) ?> &middot; <?= htmlspecialchars($programs[(int)$oldest['program_id']]['program_name'] ?? '') ?></span>
            <?php else: ?>
                <span class="dash-num">0</span>
                <span class="dash-label">Longest wait</span>
                <span class="dash-note">Nothing waiting. All clear!</span>
            <?php endif; ?>
        </div>
        <a class="dash-card qa-card-link" href="dashboard.php?view=out">
            <span class="dash-num"><?= $n_out ?></span>
            <span class="dash-label">Already handled</span>
            <span class="dash-note">Sent back, approved or closed &rarr;</span>
        </a>
    </div>

    <div class="qa-grid">
    <div class="dash-panel qa-main">
    <h3>Files from Programs &amp; Offices</h3>
    <p class="dash-hint">Grouped by where they came from &middot; view only, you can download or ping</p>

    <div class="tab-row">
        <?php
        $tabs = ['in' => "Waiting ($n_in)", 'out' => "Handled ($n_out)", 'all' => 'All'];
        foreach ($tabs as $key => $label):
            $qs = http_build_query(array_filter(['view' => $key, 'program' => $filter_program, 'q' => $search]));
        ?>
            <a href="dashboard.php?<?= $qs ?>" class="tab <?= $view === $key ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>

    <form method="GET" action="dashboard.php" class="filter-bar">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
        <div class="filter-field">
            <label for="program">Program / Office</label>
            <select name="program" id="program">
                <option value="0">All</option>
                <?php foreach ($program_options as $id => $label): ?>
                    <option value="<?= $id ?>" <?= $filter_program === $id ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="q">File name</label>
            <input type="text" name="q" id="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search...">
        </div>
        <button type="submit">Filter</button>
        <?php if ($is_filtered): ?><a href="dashboard.php?view=<?= $view ?>" class="btn-reset">Reset</a><?php endif; ?>
    </form>

    <?php if (empty($groups)): ?>
        <div class="empty-state"><?= $is_filtered ? 'No files match your filters.' : 'Nothing here yet.' ?></div>
    <?php endif; ?>

    <?php foreach ($groups as $crumb => $rows): ?>
        <section class="file-group">
            <h3 class="group-heading">
                <span><?= htmlspecialchars($crumb) ?></span>
                <span class="group-count"><?= count($rows) ?> file(s)</span>
            </h3>
            <table class="data-table">
                <thead>
                    <tr><th>File</th><th>Category</th><th>Sent by</th><th>Status</th><th>Date</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $f): $can_ping = in_array($f['status'], $in_statuses, true); ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars(display_name($f['file_path'])) ?>
                            <?php if ($view === 'out' && $f['status'] === 'needs_revision' && $f['qa_remark']): ?>
                                <div class="remark-box" title="<?= htmlspecialchars($f['qa_remark']) ?>"><strong>Your note:</strong> <?= htmlspecialchars($f['qa_remark']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($f['category_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($f['sent_by']) ?></td>
                        <td><span class="badge badge-status-<?= htmlspecialchars($f['status']) ?>"><?= htmlspecialchars(status_label($f['status'])) ?></span></td>
                        <td class="qa-date"><?= date('M d, Y', strtotime($f['created_at'])) ?><small><?= date('h:i A', strtotime($f['created_at'])) ?></small></td>
                        <td class="actions-cell">
                            <a href="../download.php?id=<?= (int)$f['file_id'] ?>" class="btn-small btn-download">Download</a>
                            <?php if ($can_ping): ?>
                                <details class="ping-box">
                                    <summary class="btn-small btn-resubmit">Ping</summary>
                                    <form method="POST" action="ping.php">
                                        <input type="hidden" name="file_id" value="<?= (int)$f['file_id'] ?>">
                                        <textarea name="remark" rows="3" maxlength="500" required placeholder="What needs to be revised?"></textarea>
                                        <button type="submit" class="btn-small">Send back</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>
    </div><!-- /qa-main -->

    <aside class="qa-side">
        <div class="dash-panel">
            <h3>Waiting by Program</h3>
            <p class="dash-hint">Tap a program to see only its files</p>
            <?php if (empty($by_program)): ?>
                <div class="dash-empty">Nothing is waiting for you.</div>
            <?php endif; ?>
            <?php foreach ($by_program as $bp): ?>
                <a class="qa-bar" href="dashboard.php?view=in&amp;program=<?= (int)$bp['id'] ?>" title="<?= htmlspecialchars($bp['name']) ?>">
                    <span><?= htmlspecialchars($bp['name']) ?></span>
                    <div class="qa-track"><i class="seg-in" style="width:<?= round($bp['in'] / $max_in * 100) ?>%"></i></div>
                    <b><?= $bp['in'] ?></b>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>
    </div><!-- /qa-grid -->
</main>
</body>
</html> 