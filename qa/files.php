<?php
// ============================================================
// qa/files.php
// QA folder structure:
//
// Files
//   └── Accreditation
//       └── PACUCOA
//           └── Area 1
//               ├── BSIT
//               ├── SHS
//               └── Engineering
//
// QA only sees the accreditation hierarchy here.
// Other programs/offices are NOT shown at the root.
// ============================================================

require_once __DIR__ . '/../includes/check.php';
require_role('qa');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/accreditation.php';


$active_page = 'files';

$folder = $_GET['folder'] ?? '';
$accred_id = (int)($_GET['accreditation'] ?? 0);
$area_id   = (int)($_GET['area'] ?? 0);
$program_id = (int)($_GET['program'] ?? 0);

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$sort   = $_GET['sort'] ?? 'new';
$view   = ($_GET['view'] ?? 'list') === 'grid' ? 'grid' : 'list';
$show_old = isset($_GET['archived']);
$flash = $_GET['flash'] ?? '';

if (!in_array($status, file_statuses(), true)) {
    $status = '';
}

if (!in_array($sort, ['new', 'old', 'name'], true)) {
    $sort = 'new';
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function qurl($changes = [])
{
    $params = array_merge($_GET, $changes);
    unset($params['flash']);

    foreach ($params as $key => $value) {
        if ($value === '' || $value === 0 || $value === '0' || $value === null) {
            unset($params[$key]);
        }
    }

    $query = http_build_query($params);
    return 'files.php' . ($query ? '?' . $query : '');
}

function area_file_condition($area_id, $file_alias = 'f')
{
    $area_id = (int)$area_id;

    if ($area_id <= 0) {
        return '';
    }

    return " AND (
        {$file_alias}.area_id = {$area_id}
        OR {$file_alias}.file_id IN (
            SELECT l.file_id
            FROM file_area_links l
            WHERE l.area_id = {$area_id}
        )
    )";
}

function file_kind($path)
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($ext === 'pdf') {
        return ['pdf', 'PDF'];
    }

    if ($ext === 'docx' || $ext === 'doc') {
        return ['doc', 'DOC'];
    }

    if ($ext === 'xlsx' || $ext === 'xls') {
        return ['xls', 'XLS'];
    }

    return ['oth', strtoupper(substr($ext, 0, 3))];
}

/*
|--------------------------------------------------------------------------
| ACCREDITATIONS
|--------------------------------------------------------------------------
*/

$accreditations = [];

$res = $conn->query(
    "SELECT accred_id, accred_name, sort_order
     FROM accreditations
     ORDER BY sort_order, accred_id"
);

if ($res) {
    $accreditations = $res->fetch_all(MYSQLI_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Selected accreditation
|--------------------------------------------------------------------------
*/

$selected_accreditation = null;

if ($accred_id > 0) {
    $folder = 'accreditation';
    $stmt = $conn->prepare(
        "SELECT accred_id, accred_name, sort_order
         FROM accreditations
         WHERE accred_id = ?
         LIMIT 1"
    );

    $stmt->bind_param('i', $accred_id);
    $stmt->execute();
    $selected_accreditation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$selected_accreditation) {
        $accred_id = 0;
    }
}

/*
|--------------------------------------------------------------------------
| Areas belonging ONLY to the selected accreditation
|--------------------------------------------------------------------------
*/

$areas = [];

if ($accred_id > 0) {
    $stmt = $conn->prepare(
        "SELECT
            a.area_id,
            a.area_name,
            a.description,
            a.sort_order,
            (
                SELECT COUNT(*)
                FROM files f
                WHERE f.is_archived = 0
                  AND (
                    f.area_id = a.area_id
                    OR f.file_id IN (
                        SELECT l.file_id
                        FROM file_area_links l
                        WHERE l.area_id = a.area_id
                    )
                  )
            ) AS total
         FROM areas a
         WHERE a.accred_id = ?
         ORDER BY a.sort_order, a.area_id"
    );

    $stmt->bind_param('i', $accred_id);
    $stmt->execute();
    $areas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Validate selected area
|--------------------------------------------------------------------------
*/

$selected_area = null;

if ($area_id > 0 && $accred_id > 0) {
    foreach ($areas as $area) {
        if ((int)$area['area_id'] === $area_id) {
            $selected_area = $area;
            break;
        }
    }
}

if (!$selected_area) {
    $area_id = 0;
    $program_id = 0;
}

/*
|--------------------------------------------------------------------------
| Programs designated for the selected accreditation
|
| For PACUCOA this should be:
|   BSIT
|   SHS
|   Engineering
|
| We use accreditation_units so the Admin designation remains the source
| of truth instead of exposing every program/office in the system.
|--------------------------------------------------------------------------
*/

$designated_programs = [];

if ($accred_id > 0) {
    $stmt = $conn->prepare(
        "SELECT
            p.program_id,
            p.program_name,
            p.parent_id,
            au.include_children
         FROM accreditation_units au
         INNER JOIN programs p
             ON p.program_id = au.program_id
         WHERE au.accred_id = ?
         ORDER BY p.program_name"
    );

    $stmt->bind_param('i', $accred_id);
    $stmt->execute();
    $designated_programs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Validate selected program
|--------------------------------------------------------------------------
*/

$selected_program = null;

if ($program_id > 0 && $area_id > 0) {
    foreach ($designated_programs as $program) {
        if ((int)$program['program_id'] === $program_id) {
            $selected_program = $program;
            break;
        }
    }
}

if (!$selected_program) {
    $program_id = 0;
}

/*
|--------------------------------------------------------------------------
| Folder counts
|--------------------------------------------------------------------------
*/

function program_file_count($conn, $area_id, $program_id)
{
    $area_id = (int)$area_id;
    $program_id = (int)$program_id;

    $sql = "
        SELECT COUNT(*) AS c
        FROM files f
        WHERE f.program_id = {$program_id}
          AND f.is_archived = 0
          AND (
              f.area_id = {$area_id}
              OR f.file_id IN (
                  SELECT l.file_id
                  FROM file_area_links l
                  WHERE l.area_id = {$area_id}
              )
          )
    ";

    $res = $conn->query($sql);

    if (!$res) {
        return 0;
    }

    $row = $res->fetch_assoc();
    return (int)($row['c'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| Files are shown ONLY after QA opens:
| Accreditation -> Area -> Program
|--------------------------------------------------------------------------
*/

$show_files = (
    $accred_id > 0 &&
    $area_id > 0 &&
    $program_id > 0
);

$files = [];

if ($show_files) {
    $sql = "
        SELECT
            f.file_id,
            f.file_path,
            f.status,
            f.created_at,
            f.program_id,
            f.is_archived,
            f.category_id,
            c.category_name,
            f.area_id,
            ar.area_name,
            " . ",
            COALESCE(u.full_name, u.username) AS sent_by,

            (
                SELECT l.remarks
                FROM file_logs l
                WHERE l.file_id = f.file_id
                  AND l.action IN ('sent', 'resubmitted')
                ORDER BY l.timestamp DESC, l.log_id DESC
                LIMIT 1
            ) AS program_note

        FROM files f

        INNER JOIN users u
            ON f.uploaded_by = u.user_id

        LEFT JOIN file_categories c
            ON f.category_id = c.category_id

        LEFT JOIN areas ar
            ON f.area_id = ar.area_id

        WHERE f.program_id = ?
    ";

    $sql .= area_file_condition($area_id);

    if (!$show_old) {
        $sql .= " AND f.is_archived = 0";
    }

    $types = 'i';
    $params = [$program_id];

    if ($status !== '') {
        $sql .= " AND f.status = ?";
        $types .= 's';
        $params[] = $status;
    }

    if ($search !== '') {
        $sql .= " AND f.file_path LIKE ?";
        $types .= 's';
        $params[] = '%' . addcslashes($search, '%_\\') . '%';
    }

    $order = [
        'new'  => 'f.created_at DESC',
        'old'  => 'f.created_at ASC',
        'name' => 'f.file_path ASC',
    ];

    $sql .= " ORDER BY {$order[$sort]} LIMIT 300";

    $stmt = $conn->prepare($sql);

    if ($params) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Categories for file filter
|--------------------------------------------------------------------------
*/

$categories = [];

$res = $conn->query(
    "SELECT category_id, category_name
     FROM file_categories
     ORDER BY category_name"
);

if ($res) {
    $categories = $res->fetch_all(MYSQLI_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Breadcrumb
|--------------------------------------------------------------------------
*/

$breadcrumbs = [];

if ($folder === 'accreditation' || $accred_id > 0) {
    $breadcrumbs[] = [
        'name' => 'Accreditation',
        'url'  => qurl([
            'folder' => 'accreditation',
            'accreditation' => 0,
            'area' => 0,
            'program' => 0,
            'category' => 0
        ])
    ];
}

if ($accred_id > 0 && $selected_accreditation) {
    $breadcrumbs[] = [
        'name' => $selected_accreditation['accred_name'],
        'url'  => qurl([
            'accreditation' => $accred_id,
            'area' => 0,
            'program' => 0,
            'category' => 0
        ])
    ];
}

if ($area_id > 0 && $selected_area) {
    $breadcrumbs[] = [
        'name' => $selected_area['area_name'],
        'url'  => qurl([
            'accreditation' => $accred_id,
            'area' => $area_id,
            'program' => 0,
            'category' => 0
        ])
    ];
}

if ($program_id > 0 && $selected_program) {
    $breadcrumbs[] = [
        'name' => $selected_program['program_name'],
        'url'  => qurl([
            'accreditation' => $accred_id,
            'area' => $area_id,
            'program' => $program_id
        ])
    ];
}

$is_filtered = (
    $search !== '' ||
    $status !== '' ||
    $show_old
);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Files</title>

    <link
        rel="stylesheet"
        href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>"
    >
</head>

<body class="with-sidebar">

<?php include __DIR__ . '/../includes/qa_sidebar.php'; ?>

<main class="main-content">

    <div class="page-top">

        <div>
            <h2>Files</h2>

            <p class="muted">
                Open Accreditation, then PACUCOA, then an Area and department.
            </p>
        </div>

        <div class="top-date">
            <?= date('D, M d, Y') ?>
        </div>

    </div>

    <?php if ($flash === 'pinged'): ?>

        <div class="alert alert-success">
            Sent back to the program for revision. They have been notified.
        </div>

    <?php elseif ($flash === 'ping_failed'): ?>

        <div class="alert alert-error">
            Could not ping that file.
        </div>

    <?php endif; ?>


    <div class="qf-page">

        <section class="dash-panel qf-main">

            <!-- =====================================================
                 BREADCRUMB
            ====================================================== -->

            <div class="qf-crumb">

                <a href="<?= htmlspecialchars(qurl([
                    'accreditation' => 0,
                    'area' => 0,
                    'program' => 0,
                    'category' => 0,
                    'q' => '',
                    'status' => '',
                    'archived' => ''
                ])) ?>">
                    Files
                </a>

                <?php foreach ($breadcrumbs as $i => $crumb): ?>

                    <span>&rsaquo;</span>

                    <?php if ($i === count($breadcrumbs) - 1): ?>

                        <b><?= htmlspecialchars($crumb['name']) ?></b>

                    <?php else: ?>

                        <a href="<?= htmlspecialchars($crumb['url']) ?>">
                            <?= htmlspecialchars($crumb['name']) ?>
                        </a>

                    <?php endif; ?>

                <?php endforeach; ?>

            </div>


            <!-- =====================================================
                 FILTERS
                 Only show file filters when viewing a program.
            ====================================================== -->

            <?php if ($show_files): ?>

                <form method="GET" action="files.php" class="qf-tools">

                    <input
                        type="hidden"
                        name="accreditation"
                        value="<?= $accred_id ?>"
                    >

                    <input
                        type="hidden"
                        name="area"
                        value="<?= $area_id ?>"
                    >

                    <input
                        type="hidden"
                        name="program"
                        value="<?= $program_id ?>"
                    >

                    <input
                        type="hidden"
                        name="view"
                        value="<?= htmlspecialchars($view) ?>"
                    >

                    <input
                        type="text"
                        name="q"
                        value="<?= htmlspecialchars($search) ?>"
                        placeholder="Search file name..."
                    >

                    <select name="status" aria-label="Status">

                        <option value="">
                            Status: All
                        </option>

                        <?php foreach (file_statuses() as $s): ?>

                            <option
                                value="<?= htmlspecialchars($s) ?>"
                                <?= $status === $s ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars(status_label($s)) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <select name="sort" aria-label="Sort">

                        <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>
                            Newest first
                        </option>

                        <option value="old" <?= $sort === 'old' ? 'selected' : '' ?>>
                            Oldest first
                        </option>

                        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>
                            Name A–Z
                        </option>

                    </select>

                    <label class="qf-check">

                        <input
                            type="checkbox"
                            name="archived"
                            value="1"
                            <?= $show_old ? 'checked' : '' ?>
                        >

                        Old versions

                    </label>

                    <button type="submit">
                        Apply
                    </button>

                    <?php if ($is_filtered): ?>

                        <a
                            class="btn-reset"
                            href="<?= htmlspecialchars(qurl([
                                'q' => '',
                                'status' => '',
                                'archived' => ''
                            ])) ?>"
                        >
                            Reset
                        </a>

                    <?php endif; ?>

                    <div class="qf-seg">

                        <a
                            class="<?= $view === 'list' ? 'on' : '' ?>"
                            href="<?= htmlspecialchars(qurl(['view' => 'list'])) ?>"
                        >
                            List
                        </a>

                        <a
                            class="<?= $view === 'grid' ? 'on' : '' ?>"
                            href="<?= htmlspecialchars(qurl(['view' => 'grid'])) ?>"
                        >
                            Grid
                        </a>

                    </div>

                </form>

            <?php endif; ?>


            <!-- =====================================================
                 LEVEL 1: ROOT
                 ONLY ONE FOLDER APPEARS HERE: Accreditation.
            ====================================================== -->

            <?php if ($folder !== 'accreditation'): ?>

                <div class="qf-sec">
                    Folders
                </div>

                <div class="qf-folders">

                    <a
                        class="qf-fold"
                        href="<?= htmlspecialchars(qurl([
                            'folder' => 'accreditation',
                            'accreditation' => 0,
                            'area' => 0,
                            'program' => 0,
                            'category' => 0
                        ])) ?>"
                    >

                        <span class="qf-fi">
                            <?= icon('folder') ?>
                        </span>

                        <span>
                            <b>Accreditation</b>
                            <small>Quality accreditation files</small>
                        </span>

                    </a>

                </div>

            <!-- =====================================================
                 LEVEL 2: ACCREDITATION
                 Show only Areas under this accreditation.
            ====================================================== -->

            <?php elseif ($folder === 'accreditation' && !$accred_id): ?>

                <div class="qf-sec">
                    Accreditation
                </div>

                <div class="qf-folders">

                    <?php foreach ($accreditations as $accred): ?>

                        <a
                            class="qf-fold"
                            href="<?= htmlspecialchars(qurl([
                                'folder' => 'accreditation',
                                'accreditation' => (int)$accred['accred_id'],
                                'area' => 0,
                                'program' => 0,
                                'category' => 0
                            ])) ?>"
                        >

                            <span class="qf-fi">
                                <?= icon('folder') ?>
                            </span>

                            <span>
                                <b><?= htmlspecialchars($accred['accred_name']) ?></b>
                                <small>Accreditation body</small>
                            </span>

                        </a>

                    <?php endforeach; ?>

                </div>

                <?php if (!$accreditations): ?>
                    <div class="dash-empty">
                        No accreditation bodies have been created yet.
                    </div>
                <?php endif; ?>

            <!-- =====================================================
                 LEVEL 3: ACCREDITATION BODY
                 Show only Areas under this accreditation.
            ====================================================== -->

            <?php elseif ($accred_id > 0 && !$area_id): ?>

                <div class="qf-sec">
                    <?= htmlspecialchars($selected_accreditation['accred_name']) ?>
                    · Areas
                </div>

                <div class="qf-folders">

                    <?php foreach ($areas as $area): ?>

                        <a
                            class="qf-fold"
                            href="<?= htmlspecialchars(qurl([
                                'accreditation' => $accred_id,
                                'area' => (int)$area['area_id'],
                                'program' => 0,
                                'category' => 0
                            ])) ?>"
                        >

                            <span class="qf-fi">
                                <?= icon('folder') ?>
                            </span>

                            <span>

                                <b>
                                    <?= htmlspecialchars($area['area_name']) ?>
                                </b>

                                <small>
                                    <?= (int)$area['total'] ?>
                                    file<?= (int)$area['total'] === 1 ? '' : 's' ?>
                                </small>

                            </span>

                        </a>

                    <?php endforeach; ?>

                </div>

                <?php if (!$areas): ?>

                    <div class="dash-empty">
                        No Areas have been created for this accreditation yet.
                    </div>

                <?php endif; ?>


            <!-- =====================================================
                 LEVEL 4: AREA
                 Show ONLY designated programs.
                 For PACUCOA this is BSIT / SHS / Engineering.
            ====================================================== -->

            <?php elseif (!$program_id): ?>

                <div class="qf-sec">
                    <?= htmlspecialchars($selected_area['area_name']) ?>
                    · Departments
                </div>

                <div class="qf-folders">

                    <?php foreach ($designated_programs as $program): ?>

                        <?php
                        $count = program_file_count(
                            $conn,
                            $area_id,
                            (int)$program['program_id']
                        );
                        ?>

                        <a
                            class="qf-fold"
                            href="<?= htmlspecialchars(qurl([
                                'accreditation' => $accred_id,
                                'area' => $area_id,
                                'program' => (int)$program['program_id'],
                                'category' => 0
                            ])) ?>"
                        >

                            <span class="qf-fi">
                                <?= icon('folder') ?>
                            </span>

                            <span>

                                <b>
                                    <?= htmlspecialchars($program['program_name']) ?>
                                </b>

                                <small>
                                    <?= $count ?>
                                    file<?= $count === 1 ? '' : 's' ?>
                                </small>

                            </span>

                        </a>

                    <?php endforeach; ?>

                </div>

                <?php if (!$designated_programs): ?>

                    <div class="dash-empty">
                        No departments are designated for this accreditation.
                    </div>

                <?php endif; ?>


            <!-- =====================================================
                 LEVEL 5: PROGRAM / DEPARTMENT
                 Finally show the documents.
            ====================================================== -->

            <?php else: ?>

                <div class="qf-sec">

                    Files
                    <small>
                        (<?= count($files) ?><?= count($files) === 300 ? '+' : '' ?>)
                    </small>

                </div>


                <?php if (empty($files)): ?>

                    <div class="dash-empty">

                        <?= $is_filtered
                            ? 'No files match your filters.'
                            : 'No files in this folder yet.'
                        ?>

                    </div>


                <?php elseif ($view === 'list'): ?>

                    <table class="dash-table qf-table">

                        <thead>

                            <tr>

                                <th>Name</th>
                                <th>Category</th>
                                <th>Sent by</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th></th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($files as $f): ?>

                            <?php
                            [$kc, $kt] = file_kind($f['file_path']);

                            $can_ping = in_array(
                                $f['status'],
                                ['sent', 'received', 'under_review', 'approved'],
                                true
                            ) && !$f['is_archived'];
                            ?>

                            <tr>

                                <td>

                                    <span class="qf-ficon qf-<?= htmlspecialchars($kc) ?>">
                                        <?= htmlspecialchars($kt) ?>
                                    </span>

                                    <b>
                                        <?= htmlspecialchars(
                                            display_name($f['file_path'])
                                        ) ?>
                                    </b>

                                    <?php if ($f['is_archived']): ?>

                                        <span class="badge badge-archived">
                                            Archived
                                        </span>

                                    <?php endif; ?>

                                    <?php
                                    $pn = program_note(
                                        $f['program_note'] ?? ''
                                    );
                                    ?>

                                    <?php if ($pn !== ''): ?>

                                        <div class="pnote qf-pnote">
                                            <b>Note:</b>
                                            <?= nl2br(htmlspecialchars($pn)) ?>
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?php if ($f['category_name']): ?>

                                        <span class="qf-cat">
                                            <?= htmlspecialchars(
                                                $f['category_name']
                                            ) ?>
                                        </span>

                                    <?php else: ?>

                                        —

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <?= htmlspecialchars($f['sent_by']) ?>
                                </td>

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

                                </td>

                                <td class="qf-date">

                                    <?= date(
                                        'M d, h:i A',
                                        strtotime($f['created_at'])
                                    ) ?>

                                </td>

                                <td class="actions-cell">

                                    <a
                                        href="../download.php?id=<?= (int)$f['file_id'] ?>"
                                        class="btn-small btn-download"
                                    >
                                        Download
                                    </a>

                                    <?php if ($can_ping): ?>

                                        <details class="ping-box">

                                            <summary class="btn-small btn-resubmit">
                                                Ping
                                            </summary>

                                            <form
                                                method="POST"
                                                action="ping.php"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="file_id"
                                                    value="<?= (int)$f['file_id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="return_to"
                                                    value="files"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="qs"
                                                    value="<?= htmlspecialchars(
                                                        $_SERVER['QUERY_STRING'] ?? ''
                                                    ) ?>"
                                                >

                                                <textarea
                                                    name="remark"
                                                    rows="3"
                                                    maxlength="500"
                                                    required
                                                    placeholder="What needs to be revised?"
                                                ></textarea>

                                                <button
                                                    type="submit"
                                                    class="btn-small"
                                                >
                                                    Send back
                                                </button>

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

                        <?php foreach ($files as $f): ?>

                            <?php [$kc, $kt] = file_kind($f['file_path']); ?>

                            <div class="qf-card">

                                <span class="qf-ficon qf-big qf-<?= htmlspecialchars($kc) ?>">
                                    <?= htmlspecialchars($kt) ?>
                                </span>

                                <b
                                    title="<?= htmlspecialchars(
                                        display_name($f['file_path'])
                                    ) ?>"
                                >
                                    <?= htmlspecialchars(
                                        display_name($f['file_path'])
                                    ) ?>
                                </b>

                                <small>
                                    <?= htmlspecialchars(
                                        $f['category_name'] ?? '—'
                                    ) ?>

                                    &middot;

                                    <?= htmlspecialchars($f['sent_by']) ?>
                                </small>

                                <span
                                    class="badge badge-status-<?= htmlspecialchars(
                                        $f['status']
                                    ) ?>"
                                >
                                    <?= htmlspecialchars(
                                        status_label($f['status'])
                                    ) ?>
                                </span>

                                <small>
                                    <?= date(
                                        'M d, h:i A',
                                        strtotime($f['created_at'])
                                    ) ?>
                                </small>

                                <a
                                    href="../download.php?id=<?= (int)$f['file_id'] ?>"
                                    class="btn-small btn-download"
                                >
                                    Download
                                </a>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </section>

    </div>

</main>

<script src="../js/qa_files.js?v=<?= @filemtime(__DIR__ . '/../js/qa_files.js') ?>"></script>

</body>
</html>
