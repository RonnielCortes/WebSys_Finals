<?php
// ============================================
// qa/files.php — every file from every program/office, organized like folders.
//   Files                      -> the normal folders (departments, programs, offices) + one "Accreditation" folder
//   Files > Accreditation      -> PACUCOA, ... (accreditation bodies)
//   ... > PACUCOA              -> Area 1, Area 2, ...
//   ... > Area 1               -> the units designated for that body (IT, SHS, Engineering...)
//   ... > IT                   -> that unit's files for that Area
// QA is view-only here: download, or ping a file back for revision.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('qa');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/accreditation.php';

$active_page = 'files';

// true  = pagbukas ng Files, ISANG folder lang ang makikita: "Accreditation" (ang mga normal na folder ay nakatago)
// false = makikita ang Accreditation + ang mga normal na folder (CASE-IT, BSIT, ...)
const QA_ROOT_ONLY_ACCREDITATION = true;

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

// ---------------------------------------------------------------
// Accreditation: bodies > Areas. QA opens them like folders.
//   acc=1 (+ body, + area) = we are inside the Accreditation folder
//   without acc            = the normal folders (files that are NOT inside an Area)
// ---------------------------------------------------------------
$bodies = []; $areas_by_body = []; $area_info = []; $units_by_body = [];
try {
    $bodies = $conn->query("SELECT accred_id, accred_name FROM accreditations ORDER BY sort_order, accred_id")->fetch_all(MYSQLI_ASSOC);
    foreach ($conn->query("SELECT area_id, accred_id, area_name, description FROM areas ORDER BY sort_order, area_id")->fetch_all(MYSQLI_ASSOC) as $r) {
        $areas_by_body[(int)$r['accred_id']][] = $r;
        $area_info[(int)$r['area_id']] = $r;
    }
    foreach ($conn->query("SELECT accred_id, program_id FROM accreditation_units")->fetch_all(MYSQLI_ASSOC) as $r) {
        $units_by_body[(int)$r['accred_id']][] = (int)$r['program_id'];
    }
} catch (Throwable $e) {
    $bodies = [];   // accreditation.sql not run yet: the Accreditation folder just stays hidden
}
$body_name = [];
foreach ($bodies as $b) { $body_name[(int)$b['accred_id']] = $b['accred_name']; }

$in_acc   = isset($_GET['acc']) && $bodies;
$sel_body = $in_acc ? (int)($_GET['body'] ?? 0) : 0;
if (!isset($body_name[$sel_body])) { $sel_body = 0; }
$sel_area = $sel_body ? (int)($_GET['area'] ?? 0) : 0;
if (!isset($area_info[$sel_area]) || (int)$area_info[$sel_area]['accred_id'] !== $sel_body) { $sel_area = 0; }

// The search box at the very top searches EVERYTHING (area files too). Anywhere else the place decides.
$search_all = (!$in_acc && trim($_GET['q'] ?? '') !== '' && (int)($_GET['program'] ?? 0) === 0);

// SQL piece for "which files": $col = the area_id column (with or without alias)
function area_cond($col, $in_acc, $sel_body, $sel_area, $areas_by_body, $search_all) {
    if ($search_all) { return ''; }
    if (!$in_acc)    { return " AND $col IS NULL"; }                 // normal folders: normal files only
    if ($sel_area)   { return " AND $col = " . (int)$sel_area; }
    if ($sel_body) {
        $ids = array_map(function ($a) { return (int)$a['area_id']; }, $areas_by_body[$sel_body] ?? []);
        return $ids ? " AND $col IN (" . implode(',', $ids) . ")" : " AND 1 = 0";
    }
    return " AND $col IS NOT NULL";
}
$area_sql = area_cond('area_id', $in_acc, $sel_body, $sel_area, $areas_by_body, $search_all);

// how many current files sit in each Area (for the folder cards)
$area_count = [];
$res = $conn->query("SELECT area_id, COUNT(*) AS c FROM files WHERE is_archived = 0 AND area_id IS NOT NULL GROUP BY area_id");
while ($r = $res->fetch_assoc()) { $area_count[(int)$r['area_id']] = (int)$r['c']; }

// Files per program (current versions), then summed up the tree
$direct = [];
$res = $conn->query("SELECT program_id, COUNT(*) AS c FROM files WHERE is_archived = 0" . $area_sql . " GROUP BY program_id");
while ($r = $res->fetch_assoc()) { $direct[(int)$r['program_id']] = (int)$r['c']; }

function folder_count($children, $direct, $id) {
    $n = 0;
    foreach (subtree_ids($children, $id) as $x) { $n += $direct[$x] ?? 0; }
    return $n;
}

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

// Categories (for the dropdown): normal folders -> general categories; inside an Area -> that Area's categories
$categories = []; $chip_categories = [];
$res = $conn->query("SELECT category_id, category_name, area_id FROM file_categories ORDER BY category_name");
while ($r = $res->fetch_assoc()) {
    $cid = (int)$r['category_id'];
    $categories[$cid] = $r['category_name'];
    $in_area = ($r['area_id'] === null) ? 0 : (int)$r['area_id'];
    if ($search_all || !$in_acc)      { $show = ($in_area === 0) || $search_all; }
    elseif ($sel_area)                { $show = ($in_area === $sel_area); }
    elseif ($sel_body)                { $show = ($in_area && isset($area_info[$in_area]) && (int)$area_info[$in_area]['accred_id'] === $sel_body); }
    else                              { $show = ($in_area !== 0); }
    if ($show) {
        $chip_categories[$cid] = $r['category_name'] . (($in_area && !$sel_area && isset($area_info[$in_area])) ? ' · ' . $area_info[$in_area]['area_name'] : '');
    }
}
if (!isset($categories[$sel_category])) { $sel_category = 0; }
if ($sel_category && !isset($chip_categories[$sel_category])) { $sel_category = 0; }   // it belongs to another place

// ---------------------------------------------------------------
// The file list is NOT shown by default. It appears only after QA opens a
// folder (or types something in the search box).
// ---------------------------------------------------------------
$show_files = ($sel_program > 0 || $search !== '');
if ($in_acc && !$sel_area && $sel_program === 0 && $search === '') { $show_files = false; }
if ($in_acc && $sel_area) { $show_files = true; }   // inside an Area: its files are listed under the department cards

// ---------------------------------------------------------------
// Files in the selected folder (and everything under it)
// ---------------------------------------------------------------
$files = [];
$sql = "SELECT f.file_id, f.file_path, f.status, f.created_at, f.program_id, f.is_archived,
            f.category_id, c.category_name, f.area_id, ar.area_name, COALESCE(u.full_name, u.username) AS sent_by,
            (SELECT l.remarks FROM file_logs l
                WHERE l.file_id = f.file_id AND l.action IN ('sent', 'resubmitted')
                ORDER BY l.timestamp DESC, l.log_id DESC LIMIT 1) AS program_note
        FROM files f
        JOIN users u ON f.uploaded_by = u.user_id
        LEFT JOIN file_categories c ON f.category_id = c.category_id
        LEFT JOIN areas ar ON f.area_id = ar.area_id
        WHERE 1 = 1";
$sql .= area_cond('f.area_id', $in_acc, $sel_body, $sel_area, $areas_by_body, $search_all);
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
if ($in_acc && !$sel_body) {
    // Files > Accreditation: the accreditation bodies
    $cards_title = 'Accreditation';
    foreach ($bodies as $b) {
        $n = 0;
        foreach ($areas_by_body[(int)$b['accred_id']] ?? [] as $a) { $n += $area_count[(int)$a['area_id']] ?? 0; }
        $cards[] = ['name' => $b['accred_name'], 'count' => $n, 'url' => qurl(['body' => (int)$b['accred_id'], 'area' => 0, 'program' => 0, 'category' => 0])];
    }
} elseif ($in_acc && $sel_body && !$sel_area && !$sel_program) {
    // ... > PACUCOA: its Areas
    $cards_title = 'Areas in ' . $body_name[$sel_body];
    foreach ($areas_by_body[$sel_body] ?? [] as $a) {
        $label = $a['area_name'] . ($a['description'] ? ' - ' . $a['description'] : '');
        $cards[] = ['name' => $label, 'count' => $area_count[(int)$a['area_id']] ?? 0, 'url' => qurl(['area' => (int)$a['area_id'], 'program' => 0, 'category' => 0])];
    }
} elseif ($in_acc && $sel_area && !$sel_program) {
    // ... > Area 1: only the units designated for this accreditation
    $cards_title = 'Departments / offices for ' . $body_name[$sel_body];
    foreach ($units_by_body[$sel_body] ?? [] as $u) {
        if (!isset($programs[$u])) { continue; }
        $cards[] = ['name' => $programs[$u]['program_name'], 'count' => folder_count($children, $direct, $u), 'url' => qurl(['program' => $u, 'category' => 0])];
    }
} elseif (!$sel_category) {
    $kids = $sel_program ? ($children[$sel_program] ?? []) : ($children[0] ?? []);
    if (!$in_acc && !$sel_program && $bodies) {
        // the one "Accreditation" folder next to the normal folders
        $cards[] = ['name' => 'Accreditation', 'count' => array_sum($area_count), 'url' => qurl(['acc' => 1, 'body' => 0, 'area' => 0, 'program' => 0, 'category' => 0])];
    }
    if (QA_ROOT_ONLY_ACCREDITATION && $bodies && !$in_acc && !$sel_program) {
        $kids = [];   // root: Accreditation only
    }
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
            WHERE f.is_archived = 0" . area_cond('f.area_id', $in_acc, $sel_body, $sel_area, $areas_by_body, $search_all) . " AND f.program_id IN (" . implode(',', $ids) . ")
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
                <a href="<?= htmlspecialchars(qurl(['acc' => 0, 'body' => 0, 'area' => 0, 'program' => 0, 'category' => 0])) ?>">Files</a>
                <?php if ($in_acc): ?>
                    <span>&rsaquo;</span>
                    <?php if ($sel_body): ?><a href="<?= htmlspecialchars(qurl(['body' => 0, 'area' => 0, 'program' => 0, 'category' => 0])) ?>">Accreditation</a><?php else: ?><b>Accreditation</b><?php endif; ?>
                    <?php if ($sel_body): ?>
                        <span>&rsaquo;</span>
                        <?php if ($sel_area): ?><a href="<?= htmlspecialchars(qurl(['area' => 0, 'program' => 0, 'category' => 0])) ?>"><?= htmlspecialchars($body_name[$sel_body]) ?></a><?php else: ?><b><?= htmlspecialchars($body_name[$sel_body]) ?></b><?php endif; ?>
                    <?php endif; ?>
                    <?php if ($sel_area): ?>
                        <span>&rsaquo;</span>
                        <?php if ($sel_program): ?><a href="<?= htmlspecialchars(qurl(['program' => 0, 'category' => 0])) ?>"><?= htmlspecialchars($area_info[$sel_area]['area_name']) ?></a><?php else: ?><b><?= htmlspecialchars($area_info[$sel_area]['area_name']) ?></b><?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
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
                <?php if ($in_acc): ?>
                    <input type="hidden" name="acc" value="1">
                    <input type="hidden" name="body" value="<?= $sel_body ?>">
                    <input type="hidden" name="area" value="<?= $sel_area ?>">
                <?php endif; ?>
                <input type="hidden" name="view" value="<?= $view ?>">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search file name...">
                <?php if ($chip_categories || $sel_category): ?>
                <select name="category" aria-label="Category" data-autosubmit>
                    <option value="0">Category: All</option>
                    <?php foreach ($chip_categories as $cid => $cname): ?>
                        <option value="<?= $cid ?>" <?= $sel_category === $cid ? 'selected' : '' ?>><?= htmlspecialchars($cname) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
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
                    <p><?= $in_acc && !$sel_area ? 'Open an area to see which departments sent files.' : 'Choose a folder above to see its files.' ?></p>
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
                            <td><?php if ($f['category_name']): ?><span class="qf-cat"><?= htmlspecialchars($f['category_name']) ?></span><?php else: ?>—<?php endif; ?><?php if ($f['area_name']): ?> <span class="badge-area"><?= htmlspecialchars($f['area_name']) ?></span><?php endif; ?></td>
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
                            <small><?= htmlspecialchars($f['category_name'] ?? '—') ?><?= $f['area_name'] ? ' (' . htmlspecialchars($f['area_name']) . ')' : '' ?> &middot; <?= htmlspecialchars($f['sent_by']) ?></small>
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
    <script src="../js/qa_files.js?v=<?= @filemtime(__DIR__ . '/../js/qa_files.js') ?>"></script>
</body>
</html>