<?php
// ============================================
// admin/archive.php — Admin sees every ARCHIVED file (old versions that were
// replaced, and files a Program withdrew) and can delete them completely.
//   "Delete completely" = the real file in uploads/ + its database row
//   + its history (file_logs) + its notifications are all removed. Cannot be undone.
// Only archived files can be deleted here — never a current file.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$active_page = 'archive';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf  = $_SESSION['csrf'];
$error = "";

$uploads_root = realpath(dirname(__DIR__) . '/uploads');

// Deletes the physical file, but only if it really sits inside uploads/
function remove_upload_file($uploads_root, $stored_path) {
    if (!$uploads_root || !$stored_path) { return; }
    $full = realpath(dirname(__DIR__) . '/' . $stored_path);
    if ($full && strpos($full, $uploads_root . DIRECTORY_SEPARATOR) === 0 && is_file($full)) {
        @unlink($full);
    }
}

// ============================================
// POST: delete the selected archived files completely
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = "The page expired. Please try again.";
    } elseif (!$ids) {
        $error = "Please select at least one file.";
    } else {
        $done = 0;
        $failed = 0;

        foreach ($ids as $id) {
            // must still be archived
            $stmt = $conn->prepare("SELECT file_path FROM files WHERE file_id = ? AND is_archived = 1");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $f = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$f) { $failed++; continue; }

            try {
                $conn->begin_transaction();

                $stmt = $conn->prepare("DELETE FROM notifications WHERE file_id = ?");
                $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

                $stmt = $conn->prepare("DELETE FROM file_logs WHERE file_id = ?");
                $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

                // a newer version that "replaces" this one keeps working
                $stmt = $conn->prepare("UPDATE files SET replaces_file_id = NULL WHERE replaces_file_id = ?");
                $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();

                $stmt = $conn->prepare("DELETE FROM files WHERE file_id = ? AND is_archived = 1");
                $stmt->bind_param("i", $id); $stmt->execute();
                $gone = $stmt->affected_rows === 1;
                $stmt->close();
                if (!$gone) { throw new RuntimeException("not deleted"); }

                $conn->commit();
                remove_upload_file($uploads_root, $f['file_path']);   // only after the database part succeeded
                $done++;
            } catch (Throwable $e) {
                $conn->rollback();
                $failed++;
            }
        }
        header("Location: archive.php?flash=deleted&n=" . $done . "&failed=" . $failed);
        exit();
    }
}

// ============================================
// Filters (GET)
// ============================================
$days = isset($_GET['days']) ? max(0, (int)$_GET['days']) : 0;   // 0 = every archived file
$q    = trim($_GET['q'] ?? '');

$programs = load_programs($conn);

$sql = "SELECT * FROM (
            SELECT f.file_id, f.file_path, f.program_id, f.created_at, c.category_name,
                   (SELECT l.action FROM file_logs l
                     WHERE l.file_id = f.file_id AND l.action IN ('replaced', 'deleted_by_program')
                     ORDER BY l.timestamp DESC, l.log_id DESC LIMIT 1) AS how,
                   COALESCE((SELECT MAX(l.timestamp) FROM file_logs l
                              WHERE l.file_id = f.file_id AND l.action IN ('replaced', 'deleted_by_program')),
                            f.created_at) AS archived_at
            FROM files f
            LEFT JOIN file_categories c ON c.category_id = f.category_id
            WHERE f.is_archived = 1
        ) t
        WHERE t.archived_at <= DATE_SUB(NOW(), INTERVAL ? DAY)";
$types  = "i";
$params = [$days];
if ($q !== '') {
    $sql .= " AND t.file_path LIKE ?";
    $types .= "s";
    $params[] = '%' . $q . '%';
}
$sql .= " ORDER BY t.archived_at ASC LIMIT 500";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_bytes = 0;
foreach ($rows as &$r) {
    $full = dirname(__DIR__) . '/' . $r['file_path'];
    $r['bytes'] = is_file($full) ? (int)filesize($full) : 0;
    $total_bytes += $r['bytes'];
    $r['age'] = max(0, (int)floor((time() - strtotime($r['archived_at'])) / 86400));
}
unset($r);

function pretty_bytes($b) {
    if ($b < 1024) { return $b . ' B'; }
    if ($b < 1024 * 1024) { return round($b / 1024) . ' KB'; }
    return number_format($b / (1024 * 1024), 1) . ' MB';
}

$msg = '';
if (($_GET['flash'] ?? '') === 'deleted') {
    $n = (int)($_GET['n'] ?? 0);
    $fl = (int)($_GET['failed'] ?? 0);
    $msg = $n . " file" . ($n === 1 ? '' : 's') . " deleted completely." . ($fl ? " " . $fl . " could not be deleted." : "");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Archived Files</title>
<link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
<link rel="stylesheet" href="../css/archive.css?v=<?= @filemtime(__DIR__ . '/../css/archive.css') ?>">
</head>
<body class="with-sidebar">

<?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

<main class="main-content">
  <div class="page-top">
      <div>
          <h2>Archived Files</h2>
          <p class="muted">Old versions and withdrawn files. Delete the ones you no longer need.</p>
          &nbsp;
      </div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <section class="dash-panel">
      <form method="GET" class="ar-filter">
          <label for="days">Archived at least</label>
          <input type="number" id="days" name="days" min="0" max="3650" value="<?= $days ?>">
          <span>days ago</span>
          <input type="text" name="q" placeholder="Search file name..." value="<?= htmlspecialchars($q) ?>">
          <button type="submit" class="ar-btn alt">Show</button>
          <?php if ($days || $q !== ''): ?><a href="archive.php" class="ar-reset">Reset</a><?php endif; ?>
      </form>
      <p class="dash-hint">0 days = every archived file. Tip: type 90, press Show, then select all to clear everything older than 90 days.</p>
  </section>

  <section class="dash-panel ar-panel">
      <form method="POST" id="ar-form">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

          <div class="ar-bar">
              <h3><?= count($rows) ?> file<?= count($rows) === 1 ? '' : 's' ?> <small><?= pretty_bytes($total_bytes) ?></small></h3>
              <button type="submit" class="ar-btn danger" id="ar-delete" disabled>Delete completely</button>
          </div>

          <?php if (!$rows): ?>
              <div class="dash-empty">No archived files<?= ($days || $q !== '') ? ' match this filter' : '' ?>.</div>
          <?php else: ?>
          <div class="ar-scroll">
          <table class="dash-table">
              <thead>
                  <tr>
                      <th class="ar-check"><input type="checkbox" id="ar-all" aria-label="Select all"></th>
                      <th>File</th>
                      <th>Program / Office</th>
                      <th>Category</th>
                      <th>Why archived</th>
                      <th>Archived</th>
                      <th>Size</th>
                  </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $r): ?>
                  <tr>
                      <td class="ar-check"><input type="checkbox" name="ids[]" value="<?= (int)$r['file_id'] ?>" class="ar-one"></td>
                      <td><b><?= htmlspecialchars(display_name($r['file_path'])) ?></b></td>
                      <td><?= htmlspecialchars(program_breadcrumb($programs, $r['program_id'])) ?></td>
                      <td><?= htmlspecialchars($r['category_name'] ?? '—') ?></td>
                      <td><?= $r['how'] === 'replaced' ? 'Replaced by a newer version' : ($r['how'] === 'deleted_by_program' ? 'Withdrawn by the program' : 'Archived') ?></td>
                      <td><?= date('M d, Y', strtotime($r['archived_at'])) ?> <small class="muted">(<?= $r['age'] ?> day<?= $r['age'] === 1 ? '' : 's' ?>)</small></td>
                      <td><?= $r['bytes'] ? pretty_bytes($r['bytes']) : '<small class="muted">missing</small>' ?></td>
                  </tr>
              <?php endforeach; ?>
              </tbody>
          </table>
          </div>
          <?php endif; ?>
      </form>
  </section>
</main>

<script src="../js/archive.js?v=<?= @filemtime(__DIR__ . '/../js/archive.js') ?>"></script>
</body>
</html>