<?php
require_once __DIR__ . '/../includes/check.php';
require_role('admin');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/accreditation.php';

$active_page = 'files';

// --------------------------------------------
// Program list (for dropdown + breadcrumbs)
// --------------------------------------------
$programs = load_programs($conn);

// Files are tied to the most specific unit
// (BSIT, Accounting, etc.), so only programs
// without children appear in the dropdown.
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
// Filters
// --------------------------------------------
$filter_program = (int)($_GET['program'] ?? 0);
$filter_status  = $_GET['status'] ?? '';
$search         = trim($_GET['q'] ?? '');
$show_archived  = isset($_GET['archived']);

if (!in_array($filter_status, file_statuses(), true)) {
    $filter_status = '';
}

// --------------------------------------------
// Area filter
// '' = every file
// 'n' = normal files only
// number = one Area
// --------------------------------------------
$areas = [];

foreach (load_areas($conn) as $aid => $r) {
    $areas[$aid] = $r['area_name'];
}

$filter_area = $_GET['area'] ?? '';

if ($filter_area !== 'n') {
    $filter_area = isset($areas[(int)$filter_area])
        ? (int)$filter_area
        : '';
}

// --------------------------------------------
// Load files
// --------------------------------------------
// IMPORTANT:
// A file belongs to ONE Area only.
// There is NO file_area_links anymore.
$sql = "
    SELECT
        f.file_id,
        f.file_path,
        f.status,
        f.created_at,
        f.program_id,
        f.is_archived,
        COALESCE(u.full_name, u.username) AS uploaded_by,
        c.category_name,
        f.area_id,
        ar.area_name

    FROM files f

    JOIN users u
        ON f.uploaded_by = u.user_id

    LEFT JOIN file_categories c
        ON f.category_id = c.category_id

    LEFT JOIN areas ar
        ON f.area_id = ar.area_id
";

$where  = [];
$types  = '';
$params = [];

// Hide archived versions by default.
if (!$show_archived) {
    $where[] = 'f.is_archived = 0';
}

// Program filter.
if ($filter_program) {
    $where[] = 'f.program_id = ?';
    $types .= 'i';
    $params[] = $filter_program;
}

// Area filter.
if ($filter_area === 'n') {

    // Normal files = no Area assigned.
    $where[] = 'f.area_id IS NULL';

} elseif ($filter_area !== '') {

    // File belongs directly to this Area.
    $where[] = 'f.area_id = ?';
    $types .= 'i';
    $params[] = $filter_area;
}

// Status filter.
if ($filter_status !== '') {
    $where[] = 'f.status = ?';
    $types .= 's';
    $params[] = $filter_status;
}

// Search by file name/path.
if ($search !== '') {
    $where[] = 'f.file_path LIKE ?';
    $types .= 's';
    $params[] = '%' . addcslashes($search, '%_\\') . '%';
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY f.created_at DESC';

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Failed to prepare files query: ' . $conn->error);
}

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$result = $stmt->get_result();

// --------------------------------------------
// Group by Program breadcrumb
// --------------------------------------------
$groups = [];
$shown  = 0;

while ($row = $result->fetch_assoc()) {

    $crumb = program_breadcrumb(
        $programs,
        $row['program_id']
    );

    $groups[$crumb][] = $row;
    $shown++;
}

$stmt->close();

ksort($groups);

// --------------------------------------------
// Total active files
// --------------------------------------------
$total_result = $conn->query("
    SELECT COUNT(*) AS c
    FROM files
    WHERE is_archived = 0
");

$total_files = $total_result
    ? (int)$total_result->fetch_assoc()['c']
    : 0;

$is_filtered =
    $filter_program ||
    $filter_status !== '' ||
    $filter_area !== '' ||
    $search !== '' ||
    $show_archived;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <title>Files</title>

    <link
        rel="stylesheet"
        href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>"
    >
</head>

<body class="with-sidebar">

    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <main class="main-content">

        <h2>Files</h2>

        <p class="muted">
            Showing <?= $shown ?> of <?= $total_files ?> file(s),
            grouped by where they came from.
        </p>

        <!-- =========================================
             FILTERS
        ========================================== -->

        <form
            method="GET"
            action="files.php"
            class="filter-bar"
        >

            <!-- Program -->
            <div class="filter-field">

                <label for="program">
                    Program / Office
                </label>

                <select
                    name="program"
                    id="program"
                >

                    <option value="0">
                        All
                    </option>

                    <?php foreach ($program_options as $id => $label): ?>

                        <option
                            value="<?= (int)$id ?>"
                            <?= $filter_program === $id ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($label) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- Status -->
            <div class="filter-field">

                <label for="status">
                    Status
                </label>

                <select
                    name="status"
                    id="status"
                >

                    <option value="">
                        All
                    </option>

                    <?php foreach (file_statuses() as $s): ?>

                        <option
                            value="<?= htmlspecialchars($s) ?>"
                            <?= $filter_status === $s ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars(status_label($s)) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- Area -->
            <?php if ($areas): ?>

                <div class="filter-field">

                    <label for="area">
                        Area
                    </label>

                    <select
                        name="area"
                        id="area"
                    >

                        <option value="">
                            All
                        </option>

                        <option
                            value="n"
                            <?= $filter_area === 'n' ? 'selected' : '' ?>
                        >
                            Normal files (no area)
                        </option>

                        <?php foreach ($areas as $aid => $aname): ?>

                            <option
                                value="<?= (int)$aid ?>"
                                <?= $filter_area === $aid ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($aname) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            <?php endif; ?>

            <!-- Search -->
            <div class="filter-field">

                <label for="q">
                    File name
                </label>

                <input
                    type="text"
                    name="q"
                    id="q"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="Search..."
                >

            </div>

            <!-- Archived -->
            <div class="filter-field">

                <span
                    class="filter-spacer"
                    aria-hidden="true"
                >
                    &nbsp;
                </span>

                <label class="check-label">

                    <input
                        type="checkbox"
                        name="archived"
                        id="archived"
                        value="1"
                        <?= $show_archived ? 'checked' : '' ?>
                    >

                    Include archived versions

                </label>

            </div>

            <button type="submit">
                Filter
            </button>

            <?php if ($is_filtered): ?>

                <a
                    href="files.php"
                    class="btn-reset"
                >
                    Reset
                </a>

            <?php endif; ?>

        </form>

        <!-- =========================================
             EMPTY STATE
        ========================================== -->

        <?php if (empty($groups)): ?>

            <div class="empty-state">

                <?= $is_filtered
                    ? 'No files match your filters.'
                    : 'No files have been sent yet.'
                ?>

            </div>

        <?php endif; ?>

        <!-- =========================================
             FILE GROUPS
        ========================================== -->

        <?php foreach ($groups as $crumb => $rows): ?>

            <section class="file-group">

                <h3 class="group-heading">

                    <span>
                        <?= htmlspecialchars($crumb) ?>
                    </span>

                    <span class="group-count">
                        <?= count($rows) ?> file(s)
                    </span>

                </h3>

                <table class="data-table">

                    <thead>

                        <tr>
                            <th>File</th>
                            <th>Category</th>
                            <th>Area</th>
                            <th>Sent by</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($rows as $f): ?>

                            <tr>

                                <!-- File -->
                                <td>
                                    <?= htmlspecialchars(
                                        display_name($f['file_path'])
                                    ) ?>
                                </td>

                                <!-- Category -->
                                <td>
                                    <?= htmlspecialchars(
                                        $f['category_name'] ?? '—'
                                    ) ?>
                                </td>

                                <!-- Area -->
                                <td>

                                    <?php if ($f['area_name']): ?>

                                        <span class="badge-area">
                                            <?= htmlspecialchars(
                                                $f['area_name']
                                            ) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="muted">
                                            Normal
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <!-- Sent by -->
                                <td>
                                    <?= htmlspecialchars(
                                        $f['uploaded_by']
                                    ) ?>
                                </td>

                                <!-- Status -->
                                <td>

                                    <span
                                        class="badge badge-status-<?= htmlspecialchars(
                                            $f['status']
                                        ) ?>"
                                    >
                                        <?= htmlspecialchars(
                                            status_label($f['status'])
                                        ) ?>
                                    </span>

                                    <?php if ($f['is_archived']): ?>

                                        <span class="badge badge-archived">
                                            Archived
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <!-- Date -->
                                <td>
                                    <?= date(
                                        'M d, Y h:i A',
                                        strtotime($f['created_at'])
                                    ) ?>
                                </td>

                                <!-- Action -->
                                <td class="actions-cell">

                                    <?php if ($f['area_id'] === null): ?>

                                        <a
                                            href="../download.php?id=<?= (int)$f['file_id'] ?>"
                                            class="btn-small btn-download"
                                        >
                                            Download
                                        </a>

                                    <?php else: ?>

                                        <span
                                            class="lock-note"
                                            title="Files inside an Area are confidential"
                                        >
                                            Confidential
                                        </span>

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