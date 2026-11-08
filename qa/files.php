<?php
// ============================================
// qa/files.php — every file from every program/office, organized like folders.
// QA is view-only here: download, or ping a file back for revision.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('qa');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$active_page = 'files';

// ---------------------------------------------------------------
// Program tree (parent_id -> children), built once in PHP
// ---------------------------------------------------------------
$programs = load_programs($conn);
$children = [];
foreach ($programs as $id => $p) {
    $children[(int)$p['parent_id']][] = $id;
}
foreach ($children as $pid => $list) {
    sort($list);   // by program_id = the order they were set up (Elementary, High School, College, Offices...)
    $children[$pid] = $list;
}

function subtree_ids($children, $id) {
    $out = [$id];
    foreach ($children[$id] ?? [] as $c) {
        $out = array_merge($out, subtree_ids($children, $c));
    }
    return $out;
}

// Files per program (current versions), then summed up the tree
$direct = [];
$res = $conn->query("SELECT program_id, COUNT(*) AS c FROM files WHERE is_archived = 0 GROUP BY program_id");
while ($r = $res->fetch_assoc()) { $direct[(int)$r['program_id']] = (int)$r['c']; }

function folder_count($children, $direct, $id) {
    $n = 0;
    foreach (subtree_ids($children, $id) as $x) { $n += $direct[$x] ?? 0; }
    return $n;
}
$total_all = array_sum($direct);

// ---------------------------------------------------------------
// Filters (all from the URL)
// ---------------------------------------------------------------
$sel_program  = (int)($_GET['program'] ?? 0);
if (!isset($programs[$sel_program])) { $sel_program = 0; }
$sel_category = (int)($_GET['category'] ?? 0);
$sel_status   = $_GET['status'] ?? '';
if (!in_array($sel_status, file_statuses(), true)) { $sel_status = ''; }
$search       = trim($_GET['q'] ?? '');
$sort         = $_GET['sort'] ?? 'new';
if (!in_array($sort, ['new', 'old', 'name'], true)) { $sort = 'new'; }
$view         = ($_GET['view'] ?? 'list') === 'grid' ? 'grid' : 'list';
$show_old     = isset($_GET['archived']);
$flash        = $_GET['flash'] ?? '';

// Builds a link to this page with some filters changed
function qurl($changes = []) {
    $params = array_merge($_GET, $changes);
    unset($params['flash']);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === 0 || $v === '0' || $v === null) { unset($params[$k]); }
    }
    $qs = http_build_query($params);
    return 'files.php' . ($qs ? '?' . $qs : '');
}

// Categories (for the chips)
$categories = [];
$res = $conn->query("SELECT category_id, category_name FROM file_categories ORDER BY category_name");
while ($r = $res->fetch_assoc()) { $categories[(int)$r['category_id']] = $r['category_name']; }
if (!isset($categories[$sel_category])) { $sel_category = 0; }

// ---------------------------------------------------------------
// The file list is NOT shown by default. It appears only after QA opens a
// folder (or types something in the search box).
// ---------------------------------------------------------------
$show_files = ($sel_program > 0 || $search !== '');

// ---------------------------------------------------------------
// Files in the selected folder (and everything under it)
// ---------------------------------------------------------------
$files = [];
$sql = "SELECT f.file_id, f.file_path, f.status, f.created_at, f.program_id, f.is_archived,
               f.category_id, c.category_name, COALESCE(u.full_name, u.username) AS sent_by,
               (SELECT l.remarks FROM file_logs l
                 WHERE l.file_id = f.file_id AND l.action IN ('sent', 'resubmitted')
                 ORDER BY l.timestamp DESC, l.log_id DESC LIMIT 1) AS program_note
        FROM files f
        JOIN users u ON f.uploaded_by = u.user_id
        LEFT JOIN file_categories c ON f.category_id = c.category_id
        WHERE 1 = 1";
$types = ''; $params = [];

if (!$show_old) { $sql .= " AND f.is_archived = 0"; }
if ($sel_program) {
    $ids = array_map('intval', subtree_ids($children, $sel_program));   // ints only
    $sql .= " AND f.program_id IN (" . implode(',', $ids) . ")";
}
if ($sel_category) { $sql .= " AND f.category_id = ?"; $types .= 'i'; $params[] = $sel_category; }
if ($sel_status !== '') { $sql .= " AND f.status = ?"; $types .= 's'; $params[] = $sel_status; }
if ($search !== '') { $sql .= " AND f.file_path LIKE ?"; $types .= 's'; $params[] = '%' . addcslashes($search, '%_\\') . '%'; }

$order = ['new' => 'f.created_at DESC', 'old' => 'f.created_at ASC', 'name' => 'f.file_path ASC'];
$sql .= " ORDER BY " . $order[$sort] . " LIMIT 300";

if ($show_files) {
    $stmt = $conn->prepare($sql);
    if ($params) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ---------------------------------------------------------------
// Folder cards: sub-programs if there are any, otherwise categories
// ---------------------------------------------------------------
$cards = []; $cards_title = '';
if (!$sel_category) {
    $kids = $sel_program ? ($children[$sel_program] ?? []) : ($children[0] ?? []);
    if ($kids) {
        $cards_title = $sel_program ? 'Folders in ' . $programs[$sel_program]['program_name'] : 'Folders';
        foreach ($kids as $k) {
            $cards[] = ['name' => $programs[$k]['program_name'],
                        'count' => folder_count($children, $direct, $k),
                        'url' => qurl(['program' => $k])];
        }
    } elseif ($sel_program) {
        $cards_title = 'Folders in ' . $programs[$sel_program]['program_name'] . ' · by category';
        $ids = array_map('intval', subtree_ids($children, $sel_program));
        $res = $conn->query(
            "SELECT c.category_id, c.category_name, COUNT(*) AS n
             FROM files f JOIN file_categories c ON f.category_id = c.category_id
             WHERE f.is_archived = 0 AND f.program_id IN (" . implode(',', $ids) . ")
             GROUP BY c.category_id, c.category_name ORDER BY c.category_name"
        );
        while ($r = $res->fetch_assoc()) {
            $cards[] = ['name' => $r['category_name'], 'count' => (int)$r['n'],
                        'url' => qurl(['category' => (int)$r['category_id']])];
        }
    }
}

// Breadcrumb: Files > ... > current
$crumbs = [];
$cur = $sel_program; $guard = 0;
while ($cur && isset($programs[$cur]) && $guard++ < 20) {
    array_unshift($crumbs, $cur);
    $cur = (int)$programs[$cur]['parent_id'];
}

function file_kind($path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'pdf') { return ['pdf', 'PDF']; }
    if ($ext === 'docx' || $ext === 'doc') { return ['doc', 'DOC']; }
    if ($ext === 'xlsx' || $ext === 'xls') { return ['xls', 'XLS']; }
    return ['oth', strtoupper(substr($ext, 0, 3))];
}

$is_filtered = ($sel_category || $sel_status !== '' || $search !== '' || $show_old);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Files</title>
<link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
</head>
<body class="with-sidebar">

<?php include __DIR__ . '/../includes/qa_sidebar.php'; ?>

<main class="main-content">
    <div class="page-top">
        <div>
            <h2>Files</h2>
            <p class="muted">Open a folder to see its documents.</p>
        </div>
        <div class="top-date"><?= date('D, M d, Y') ?></div>
    </div>

    <?php if ($flash === 'pinged'): ?>
        <div class="alert alert-success">Sent back to the program for revision. They have been notified.</div>
    <?php elseif ($flash === 'ping_failed'): ?>
        <div class="alert alert-error">Could not ping that file. It may already be handled, or the note was empty.</div>
    <?php endif; ?>

    <div class="qf-page">
        <!-- content -->
        <section class="dash-panel qf-main">
            <div class="qf-crumb">
                <a href="<?= htmlspecialchars(qurl(['program' => 0, 'category' => 0])) ?>">Files</a>
                <?php foreach ($crumbs as $i => $cid): $last = ($i === count($crumbs) - 1); ?>
                    <span>&rsaquo;</span>
                    <?php if ($last && !$sel_category): ?>
                        <b><?= htmlspecialchars($programs[$cid]['program_name']) ?></b>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars(qurl(['program' => $cid, 'category' => 0])) ?>"><?= htmlspecialchars($programs[$cid]['program_name']) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($sel_category): ?><span>&rsaquo;</span><b><?= htmlspecialchars($categories[$sel_category]) ?></b><?php endif; ?>
            </div>

            <form method="GET" action="files.php" class="qf-tools">
                <input type="hidden" name="program" value="<?= $sel_program ?>">
                <input type="hidden" name="category" value="<?= $sel_category ?>">
                <input type="hidden" name="view" value="<?= $view ?>">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search file name...">
                <select name="status" aria-label="Status">
                    <option value="">Status: All</option>
                    <?php foreach (file_statuses() as $s): ?>
                        <option value="<?= $s ?>" <?= $sel_status === $s ? 'selected' : '' ?>><?= htmlspecialchars(status_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="sort" aria-label="Sort">
                    <option value="new"  <?= $sort === 'new' ? 'selected' : '' ?>>Newest first</option>
                    <option value="old"  <?= $sort === 'old' ? 'selected' : '' ?>>Oldest first</option>
                    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name A–Z</option>
                </select>
                <label class="qf-check"><input type="checkbox" name="archived" value="1" <?= $show_old ? 'checked' : '' ?>> Old versions</label>
                <button type="submit">Apply</button>
                <?php if ($is_filtered): ?><a class="btn-reset" href="<?= htmlspecialchars(qurl(['category' => 0, 'status' => '', 'q' => '', 'archived' => ''])) ?>">Reset</a><?php endif; ?>
                <div class="qf-seg">
                    <a class="<?= $view === 'list' ? 'on' : '' ?>" href="<?= htmlspecialchars(qurl(['view' => 'list'])) ?>">List</a>
                    <a class="<?= $view === 'grid' ? 'on' : '' ?>" href="<?= htmlspecialchars(qurl(['view' => 'grid'])) ?>">Grid</a>
                </div>
            </form>

            <div class="qf-chips">
                <a class="qf-chip<?= $sel_category === 0 ? ' on' : '' ?>" href="<?= htmlspecialchars(qurl(['category' => 0])) ?>">All categories</a>
                <?php foreach ($categories as $cid => $cname): ?>
                    <a class="qf-chip<?= $sel_category === $cid ? ' on' : '' ?>" href="<?= htmlspecialchars(qurl(['category' => $cid])) ?>"><?= htmlspecialchars($cname) ?></a>
                <?php endforeach; ?>
            </div>

            <?php if ($cards): ?>
                <div class="qf-sec"><?= htmlspecialchars($cards_title) ?></div>
                <div class="qf-folders">
                    <?php foreach ($cards as $c): ?>
                        <a class="qf-fold" href="<?= htmlspecialchars($c['url']) ?>">
                            <span class="qf-fi"><?= icon('folder') ?></span>
                            <span><b><?= htmlspecialchars($c['name']) ?></b><small><?= $c['count'] ?> file<?= $c['count'] === 1 ? '' : 's' ?></small></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$show_files): ?>
                <div class="qf-pick">
                    <?= icon('folder') ?>
                    <p>Choose a folder above to see its files.</p>
                </div>
            <?php else: ?>
            <div class="qf-sec">Files <small>(<?= count($files) ?><?= count($files) === 300 ? '+' : '' ?>)</small></div>

            <?php if (empty($files)): ?>
                <div class="dash-empty"><?= $is_filtered ? 'No files match your filters.' : 'No files in this folder yet.' ?></div>

            <?php elseif ($view === 'list'): ?>
                <table class="dash-table qf-table">
                    <thead><tr><th>Name</th><th>Category</th><th>Sent by</th><th>Status</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($files as $f): [$kc, $kt] = file_kind($f['file_path']); $can_ping = in_array($f['status'], ['sent','received','under_review','approved'], true) && !$f['is_archived']; ?>
                        <tr>
                            <td>
                                <span class="qf-ficon qf-<?= $kc ?>"><?= htmlspecialchars($kt) ?></span>
                                <b><?= htmlspecialchars(display_name($f['file_path'])) ?></b>
                                <?php if ($f['is_archived']): ?><span class="badge badge-archived">Archived</span><?php endif; ?>
                                <?php if (!$sel_program): ?><small class="qf-where"><?= htmlspecialchars(program_breadcrumb($programs, $f['program_id'])) ?></small><?php endif; ?>
                                <?php $pn = program_note($f['program_note'] ?? ''); if ($pn !== ''): ?>
                                    <div class="pnote qf-pnote"><b>Note:</b> <?= nl2br(htmlspecialchars($pn)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php if ($f['category_name']): ?><span class="qf-cat"><?= htmlspecialchars($f['category_name']) ?></span><?php else: ?>—<?php endif; ?></td>
                            <td><?= htmlspecialchars($f['sent_by']) ?></td>
                            <td><span class="badge badge-status-<?= htmlspecialchars($f['status']) ?>"><?= htmlspecialchars(status_label($f['status'])) ?></span></td>
                            <td class="qf-date"><?= date('M d, h:i A', strtotime($f['created_at'])) ?></td>
                            <td class="actions-cell">
                                <a href="../download.php?id=<?= (int)$f['file_id'] ?>" class="btn-small btn-download">Download</a>
                                <?php if ($can_ping): ?>
                                    <details class="ping-box">
                                        <summary class="btn-small btn-resubmit">Ping</summary>
                                        <form method="POST" action="ping.php">
                                            <input type="hidden" name="file_id" value="<?= (int)$f['file_id'] ?>">
                                            <input type="hidden" name="return_to" value="files">
                                            <input type="hidden" name="qs" value="<?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? '') ?>">
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

            <?php else: ?>
                <div class="qf-grid">
                    <?php foreach ($files as $f): [$kc, $kt] = file_kind($f['file_path']); ?>
                        <div class="qf-card">
                            <span class="qf-ficon qf-big qf-<?= $kc ?>"><?= htmlspecialchars($kt) ?></span>
                            <b title="<?= htmlspecialchars(display_name($f['file_path'])) ?>"><?= htmlspecialchars(display_name($f['file_path'])) ?></b>
                            <small><?= htmlspecialchars($f['category_name'] ?? '—') ?> &middot; <?= htmlspecialchars($f['sent_by']) ?></small>
                            <span class="badge badge-status-<?= htmlspecialchars($f['status']) ?>"><?= htmlspecialchars(status_label($f['status'])) ?></span>
                            <small><?= date('M d, h:i A', strtotime($f['created_at'])) ?></small>
                            <?php $pn = program_note($f['program_note'] ?? ''); if ($pn !== ''): ?>
                                <small class="pnote" title="<?= htmlspecialchars($pn) ?>"><b>Note:</b> <?= htmlspecialchars(mb_strimwidth($pn, 0, 60, '…')) ?></small>
                            <?php endif; ?>
                            <a href="../download.php?id=<?= (int)$f['file_id'] ?>" class="btn-small btn-download">Download</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php endif; /* show_files */ ?>
        </section>
    </div>
</main>
</body>
</html>