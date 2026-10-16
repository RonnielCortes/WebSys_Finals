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
<link rel="stylesheet" href="../css/style.css">
</head>
<body class="with-sidebar">

<?php include __DIR__ . '/../includes/qa_sidebar.php'; ?>

<main class="main-content">
    <h2>QA Dashboard</h2>
    <p class="muted">Welcome, <?= htmlspecialchars($_SESSION['username']) ?>! Files from every program, grouped by where they came from.</p>

    <?php if ($flash === 'pinged'): ?>
        <div class="alert alert-success">Sent back to the program for revision. They have been notified.</div>
    <?php elseif ($flash === 'ping_failed'): ?>
        <div class="alert alert-error">Could not ping that file. It may already be handled, or the note was empty.</div>
    <?php endif; ?>

    <div class="stats-row">
        <div class="stat-card"><span class="stat-number"><?= $n_in ?></span><span class="stat-label">In (waiting for QA)</span></div>
        <div class="stat-card"><span class="stat-number"><?= $counts['needs_revision'] ?></span><span class="stat-label">Sent back for revision</span></div>
        <div class="stat-card"><span class="stat-number"><?= $counts['approved'] ?></span><span class="stat-label">Approved</span></div>
        <div class="stat-card"><span class="stat-number"><?= $n_in + $n_out ?></span><span class="stat-label">All current files</span></div>
    </div>

    <div class="tab-row">
        <?php
        $tabs = ['in' => "In ($n_in)", 'out' => "Out ($n_out)", 'all' => 'All'];
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
                            <?php if ($f['status'] === 'needs_revision' && $f['qa_remark']): ?>
                                <div class="remark-box"><strong>Your note:</strong> <?= htmlspecialchars($f['qa_remark']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($f['category_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($f['sent_by']) ?></td>
                        <td><span class="badge badge-status-<?= htmlspecialchars($f['status']) ?>"><?= htmlspecialchars(status_label($f['status'])) ?></span></td>
                        <td><?= date('M d, Y h:i A', strtotime($f['created_at'])) ?></td>
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
</main>
</body>
</html>