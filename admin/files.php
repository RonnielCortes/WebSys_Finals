<?php
require_once __DIR__ . '/../includes/check.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/accreditation.php';
require_once __DIR__ . '/../includes/area_links.php';

$active_page = 'files';

// --------------------------------------------
// Program list (for the dropdown + breadcrumbs)
// --------------------------------------------
$programs = load_programs($conn);

// Files are always tied to the most specific unit (BSIT, Accounting, ...),
// so the dropdown only lists programs that have no children.
$parent_ids = [];
foreach ($programs as $p) {
    if ($p['parent_id']) {
        $parent_ids[(int)$p['parent_id']] = true;
    }
}
$program_options = [];
foreach ($programs as $id => $p) {
    if (!isset($parent_ids[$id])) {
        $program_options[$id] = program_breadcrumb($programs, $id);
    }
}
asort($program_options);

// --------------------------------------------
// Filters (all optional, all from the URL)
// --------------------------------------------
$filter_program = (int)($_GET['program'] ?? 0);
$filter_status  = $_GET['status'] ?? '';
$search         = trim($_GET['q'] ?? '');
$show_archived  = isset($_GET['archived']);   // old, replaced versions are hidden unless ticked

if (!in_array($filter_status, file_statuses(), true)) {
    $filter_status = '';
}

// Area filter: '' = every file, 'n' = normal files only, a number = one Area
$areas = [];
foreach (load_areas($conn) as $aid => $r) {
    $areas[$aid] = $r['area_name'];   // "PACUCOA · Area 1"
}
$filter_area = $_GET['area'] ?? '';
if ($filter_area !== 'n') {
    $filter_area = isset($areas[(int)$filter_area]) ? (int)$filter_area : '';
}

$sql = "SELECT f.file_id, f.file_path, f.status, f.created_at, f.program_id, f.is_archived,
               COALESCE(u.full_name, u.username) AS uploaded_by, c.category_name, f.area_id, ar.area_name, " . linked_areas_select('f') . "
        FROM files f
        JOIN users u ON f.uploaded_by = u.user_id
        LEFT JOIN file_categories c ON f.category_id = c.category_id
        LEFT JOIN areas ar ON f.area_id = ar.area_id";

$where  = [];
$types  = '';
$params = [];

if (!$show_archived) {
    $where[] = 'f.is_archived = 0';
}
if ($filter_program) {
    $where[]  = 'f.program_id = ?';
    $types   .= 'i';
    $params[] = $filter_program;
}
if ($filter_area === 'n') {
    $where[] = 'f.area_id IS NULL';
} elseif ($filter_area !== '') {
    // files whose main Area is this one, plus files that are linked to it
    $where[]  = '(f.area_id = ? OR f.file_id IN (SELECT l.file_id FROM file_area_links l WHERE l.area_id = ?))';
    $types   .= 'ii';
    $params[] = $filter_area;
    $params[] = $filter_area;
}
if ($filter_status !== '') {
    $where[]  = 'f.status = ?';
    $types   .= 's';
    $params[] = $filter_status;
}
if ($search !== '') {
    $where[]  = 'f.file_path LIKE ?';
    $types   .= 's';
    $params[] = '%' . addcslashes($search, '%_\\') . '%';
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY f.created_at DESC';

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Group by "College > CASE-IT > BSIT" so it reads like folders
$groups = [];
$shown  = 0;
while ($row = $result->fetch_assoc()) {
    $crumb = program_breadcrumb($programs, $row['program_id']);
    $groups[$crumb][] = $row;
    $shown++;
}
$stmt->close();
ksort($groups);

$total_files = $conn->query("SELECT COUNT(*) AS c FROM files WHERE is_archived = 0")->fetch_assoc()['c'];
$is_filtered = ($filter_program || $filter_status !== '' || $filter_area !== '' || $search !== '' || $show_archived);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Files</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
</head>

<body class="with-sidebar">

    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <main class="main-content">
        <h2>Files</h2>
        <p class="muted">
            Showing <?= $shown ?> of <?= $total_files ?> file(s), grouped by where they came from.
        </p>

        <form method="GET" action="files.php" class="filter-bar">
            <div class="filter-field">
                <label for="program">Program / Office</label>
                <select name="program" id="program">
                    <option value="0">All</option>
                    <?php foreach ($program_options as $id => $label): ?>
                        <option value="<?= $id ?>" <?= $filter_program === $id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label for="status">Status</label>
                <select name="status" id="status">
                    <option value="">All</option>
                    <?php foreach (file_statuses() as $s): ?>
                        <option value="<?= $s ?>" <?= $filter_status === $s ? 'selected' : '' ?>>
                            <?= htmlspecialchars(status_label($s)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($areas): ?>
            <div class="filter-field">
                <label for="area">Area</label>
                <select name="area" id="area">
                    <option value="">All</option>
                    <option value="n" <?= $filter_area === 'n' ? 'selected' : '' ?>>Normal files (no area)</option>
                    <?php foreach ($areas as $aid => $aname): ?>
                        <option value="<?= $aid ?>" <?= $filter_area === $aid ? 'selected' : '' ?>><?= htmlspecialchars($aname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="filter-field">
                <label for="q">File name</label>
                <input type="text" name="q" id="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search...">
            </div>

            <div class="filter-field">
                <span class="filter-spacer" aria-hidden="true">&nbsp;</span>
                <label class="check-label">
                    <input type="checkbox" name="archived" id="archived" value="1" <?= $show_archived ? 'checked' : '' ?>>
                    Include archived versions
                </label>
            </div>

            <button type="submit">Filter</button>
            <?php if ($is_filtered): ?>
                <a href="files.php" class="btn-reset">Reset</a>
            <?php endif; ?>
        </form>

        <?php if (empty($groups)): ?>
            <div class="empty-state">
                <?= $is_filtered ? 'No files match your filters.' : 'No files have been sent yet.' ?>
            </div>
        <?php endif; ?>

        <?php foreach ($groups as $crumb => $rows): ?>
            <section class="file-group">
                <h3 class="group-heading">
                    <span><?= htmlspecialchars($crumb) ?></span>
                    <span class="group-count"><?= count($rows) ?> file(s)</span>
                </h3>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Category</th>
                            <th>Sent by</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $f): ?>
                            <tr>
                                <td><?= htmlspecialchars(display_name($f['file_path'])) ?></td>
                                <td><?= htmlspecialchars($f['category_name'] ?? '—') ?><?php if ($f['area_name']): ?> <span class="badge-area"><?= htmlspecialchars($f['area_name']) ?></span><?php endif; ?><?= linked_badge_html($f['linked_areas']) ?></td>
                                <td><?= htmlspecialchars($f['uploaded_by']) ?></td>
                                <td>
                                    <span class="badge badge-status-<?= htmlspecialchars($f['status']) ?>">
                                        <?= htmlspecialchars(status_label($f['status'])) ?>
                                    </span>
                                    <?php if ($f['is_archived']): ?>
                                        <span class="badge badge-archived">Archived</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('M d, Y h:i A', strtotime($f['created_at'])) ?></td>
                                <td class="actions-cell">
                                    <?php if ($f['area_id'] === null): ?>
                                        <a href="../download.php?id=<?= (int)$f['file_id'] ?>" class="btn-small btn-download">Download</a>
                                    <?php else: ?>
                                        <span class="lock-note" title="Files inside an Area are confidential">Confidential</span>
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