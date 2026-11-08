<?php
require_once __DIR__ . '/../includes/check.php';
require_role('program');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$active_page = 'files';

$program_id = (int)($_SESSION['program_id'] ?? 0);
if (!$program_id) {
    die("Your account is not assigned to a program/office yet. Please contact the Admin.");
}

// This program's name (for the heading)
$stmt = $conn->prepare("SELECT program_name FROM programs WHERE program_id = ?");
$stmt->bind_param("i", $program_id);
$stmt->execute();
$program = $stmt->get_result()->fetch_assoc();
$stmt->close();
$program_name = $program['program_name'] ?? 'Your program';

$flash = $_GET['flash'] ?? '';

// --------------------------------------------
// Stat cards (current versions only, THIS program only)
// --------------------------------------------
$counts = array_fill_keys(file_statuses(), 0);
$stmt = $conn->prepare(
    "SELECT status, COUNT(*) AS c FROM files
     WHERE program_id = ? AND is_archived = 0 GROUP BY status"
);
$stmt->bind_param("i", $program_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $counts[$r['status']] = (int)$r['c'];
}
$stmt->close();

$total    = array_sum($counts);
$waiting  = $counts['sent'] + $counts['received'] + $counts['under_review'];
$revision = $counts['needs_revision'];
$approved = $counts['approved'];

// --------------------------------------------
// Filters
// --------------------------------------------
$filter_status = $_GET['status'] ?? '';
$search        = trim($_GET['q'] ?? '');
$show_archived = isset($_GET['archived']);

if (!in_array($filter_status, file_statuses(), true)) {
    $filter_status = '';
}

// program_id is ALWAYS part of the query: a Program user can never see another program's files
$sql = "SELECT f.file_id, f.file_path, f.status, f.created_at, f.is_archived,
               c.category_name,
               COALESCE(u.full_name, u.username) AS sent_by,
               (SELECT l.remarks FROM file_logs l
                 WHERE l.file_id = f.file_id AND l.action = 'flagged_for_revision'
                 ORDER BY l.timestamp DESC, l.log_id DESC LIMIT 1) AS qa_remark
        FROM files f
        JOIN users u ON f.uploaded_by = u.user_id
        LEFT JOIN file_categories c ON f.category_id = c.category_id
        WHERE f.program_id = ?";
$types  = 'i';
$params = [$program_id];

if (!$show_archived) {
    $sql .= " AND f.is_archived = 0";
}
if ($filter_status !== '') {
    $sql    .= " AND f.status = ?";
    $types  .= 's';
    $params[] = $filter_status;
}
if ($search !== '') {
    $sql    .= " AND f.file_path LIKE ?";
    $types  .= 's';
    $params[] = '%' . addcslashes($search, '%_\\') . '%';
}
$sql .= " ORDER BY f.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$files = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$is_filtered = ($filter_status !== '' || $search !== '' || $show_archived);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Files</title>
<link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
</head>
<body class="with-sidebar">

<?php include __DIR__ . '/../includes/program_sidebar.php'; ?>

<main class="main-content">
    <div class="page-top">
        <div>
            <h2>My Files</h2>
           <p class="muted"><?= htmlspecialchars($program_name) ?> &middot; welcome, <strong class="welcome-name"><?= htmlspecialchars(session_display_name($conn)) ?></strong>!</p>
        </div>
        <div class="top-date"><?= date('D, M d, Y') ?></div>
    </div>

    <?php if ($flash === 'uploaded'): ?>
        <div class="alert alert-success">File sent to QA.</div>
    <?php elseif ($flash === 'resubmitted'): ?>
        <div class="alert alert-success">New version sent to QA. The old version was moved to your archive.</div>
    <?php elseif ($flash === 'replaced'): ?>
        <div class="alert alert-success">File replaced. The old one was moved to your archive and QA was told.</div>
    <?php elseif ($flash === 'deleted'): ?>
        <div class="alert alert-success">File deleted. QA was told.</div>
    <?php elseif ($flash === 'cannot_replace'): ?>
        <div class="alert alert-error">That file can't be replaced anymore. QA may have already started on it, or it was already replaced.</div>
    <?php elseif ($flash === 'cannot_delete'): ?>
        <div class="alert alert-error">That file can't be deleted anymore. QA may have already started on it.</div>
    <?php endif; ?>

    <div class="stats-row">
        <div class="stat-card">
            <span class="stat-number"><?= $total ?></span>
            <span class="stat-label">Files sent</span>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?= $waiting ?></span>
            <span class="stat-label">Waiting for QA</span>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?= $revision ?></span>
            <span class="stat-label">Needs revision</span>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?= $approved ?></span>
            <span class="stat-label">Approved</span>
        </div>
    </div>

    <?php if ($revision > 0): ?>
        <div class="alert alert-error">
            QA asked you to fix <?= $revision ?> file(s). Use <strong>Re-submit</strong> on the file below.
        </div>
    <?php endif; ?>

    <form method="GET" action="dashboard.php" class="filter-bar">
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

        <div class="filter-field">
            <label for="q">File name</label>
            <input type="text" name="q" id="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search...">
        </div>

        <div class="filter-field">
            <span class="filter-spacer" aria-hidden="true">&nbsp;</span>
            <label class="check-label">
                <input type="checkbox" name="archived" value="1" <?= $show_archived ? 'checked' : '' ?>>
                Include archived versions
            </label>
        </div>

        <button type="submit">Filter</button>
        <?php if ($is_filtered): ?>
            <a href="dashboard.php" class="btn-reset">Reset</a>
        <?php endif; ?>
        <a href="upload.php" class="btn-small btn-download btn-right">+ Upload File</a>
    </form>

    <?php if (empty($files)): ?>
        <div class="empty-state">
            <?= $is_filtered ? 'No files match your filters.' : 'You have not sent any files yet.' ?>
            <?php if (!$is_filtered): ?>
                <br><a href="upload.php">Upload your first file</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Category</th>
                    <th>Sent by</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($files as $f): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars(display_name($f['file_path'])) ?>
                            <?php if ($f['status'] === 'needs_revision' && !$f['is_archived'] && $f['qa_remark']): ?>
                                <div class="remark-box">
                                    <strong>QA note:</strong> <?= htmlspecialchars($f['qa_remark']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($f['category_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($f['sent_by']) ?></td>
                        <td>
                            <span class="badge badge-status-<?= htmlspecialchars($f['status']) ?>">
                                <?= htmlspecialchars(status_label($f['status'])) ?>
                            </span>
                            <?php if ($f['is_archived']): ?>
                                <span class="badge badge-archived">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('M d, Y h:i A', strtotime($f['created_at'])) ?></td>
                        <td class="actions-cell program-actions">
                            <a href="../download.php?id=<?= (int)$f['file_id'] ?>" class="btn-small btn-download">Download</a>

                            <?php if (!$f['is_archived']): ?>
                                <?php if ($f['status'] === 'needs_revision'): ?>
                                    <a href="upload.php?replace=<?= (int)$f['file_id'] ?>" class="btn-small btn-resubmit">Re-submit</a>
                                <?php elseif (program_can_replace($f['status'])): ?>
                                    <a href="upload.php?replace=<?= (int)$f['file_id'] ?>" class="btn-small btn-replace">Replace</a>
                                <?php endif; ?>

                                <?php if (program_can_delete($f['status'])): ?>
                                    <form method="POST" action="delete.php" class="js-confirm-delete"
                                          data-filename="<?= htmlspecialchars(display_name($f['file_path'])) ?>">
                                        <input type="hidden" name="file_id" value="<?= (int)$f['file_id'] ?>">
                                        <button type="submit" class="btn-small btn-delete">Delete</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!program_can_replace($f['status'])): ?>
                                    <span class="lock-note">Locked</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>

<script src="../js/main.js?v=<?= @filemtime(__DIR__ . '/../js/main.js') ?>"></script>
</body>
</html>