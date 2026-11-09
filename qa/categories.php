<?php
// ============================================
// qa/categories.php — QA manages the file categories:
//   add a new one, rename one, delete one that no file uses.
// (Program users can also add a category from the upload page.)
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('qa');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$active_page = 'categories';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$error   = "";
$flash   = $_GET['flash'] ?? '';
$add_val = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['category_id'] ?? 0);

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = "The page expired. Please try again.";

    // ---- add ----
    } elseif ($action === 'add') {
        $name    = clean_category_name($_POST['category_name'] ?? '');
        $add_val = $name;
        if (($msg = category_name_error($name)) !== '') {
            $error = $msg;
        } else {
            $stmt = $conn->prepare("SELECT category_id FROM file_categories WHERE LOWER(category_name) = LOWER(?) LIMIT 1");
            $stmt->bind_param("s", $name);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($exists) {
                $error = "A category named \"" . $name . "\" already exists.";
            } else {
                find_or_create_category($conn, $name);
                header("Location: categories.php?flash=added");
                exit();
            }
        }

    // ---- rename ----
    } elseif ($action === 'rename') {
        $name = clean_category_name($_POST['category_name'] ?? '');
        if (($msg = category_name_error($name)) !== '') {
            $error = $msg;
        } else {
            $stmt = $conn->prepare("SELECT category_id FROM file_categories WHERE LOWER(category_name) = LOWER(?) AND category_id <> ? LIMIT 1");
            $stmt->bind_param("si", $name, $id);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($exists) {
                $error = "Another category is already named \"" . $name . "\".";
            } else {
                $stmt = $conn->prepare("UPDATE file_categories SET category_name = ? WHERE category_id = ?");
                $stmt->bind_param("si", $name, $id);
                $stmt->execute();
                $stmt->close();
                header("Location: categories.php?flash=renamed");
                exit();
            }
        }

    // ---- delete (only if no file uses it) ----
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM files WHERE category_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $used = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        if ($used > 0) {
            $error = "That category is used by " . $used . " file(s), so it can't be deleted.";
        } else {
            $stmt = $conn->prepare("DELETE FROM file_categories WHERE category_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            header("Location: categories.php?flash=deleted");
            exit();
        }
    }
}

// All categories + how many files use each one
$cats = $conn->query(
    "SELECT c.category_id, c.category_name, COUNT(f.file_id) AS total
     FROM file_categories c
     LEFT JOIN files f ON f.category_id = c.category_id
     GROUP BY c.category_id, c.category_name
     ORDER BY c.category_name"
)->fetch_all(MYSQLI_ASSOC);

$flash_text = [
    'added'   => "Category added.",
    'renamed' => "Category renamed.",
    'deleted' => "Category deleted.",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Categories</title>
<link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
<link rel="stylesheet" href="../css/categories.css?v=<?= @filemtime(__DIR__ . '/../css/categories.css') ?>">
</head>
<body class="with-sidebar">

<?php include __DIR__ . '/../includes/qa_sidebar.php'; ?>

<main class="main-content">
  <div class="page-top">
      <div>
          <h2>Categories</h2>
          <p class="muted">The types of documents Programs can choose when they upload.</p>
          &nbsp;
      </div>
  </div>

  <?php if (isset($flash_text[$flash])): ?>
      <div class="alert alert-success"><?= htmlspecialchars($flash_text[$flash]) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <section class="dash-panel cat-add">
      <h3>Add a category</h3>
      <form method="POST" class="cat-row">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="add">
          <input type="text" name="category_name" maxlength="50" required
                 placeholder="e.g. Lesson Plan" value="<?= htmlspecialchars($add_val) ?>">
          <button type="submit" class="cat-btn">Add</button>
      </form>
  </section>

  <section class="dash-panel cat-list">
      <h3>All categories <small><?= count($cats) ?></small></h3>
      <?php if (!$cats): ?>
          <div class="dash-empty">No categories yet.</div>
      <?php else: ?>
      <ul>
          <?php foreach ($cats as $c): ?>
              <li>
                  <form method="POST" class="cat-edit">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="rename">
                      <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                      <input type="text" name="category_name" maxlength="50" required
                             value="<?= htmlspecialchars($c['category_name']) ?>" aria-label="Category name">
                      <span class="cat-count"><?= (int)$c['total'] ?> file<?= (int)$c['total'] === 1 ? '' : 's' ?></span>
                      <button type="submit" class="cat-btn alt">Save</button>
                  </form>
                  <?php if ((int)$c['total'] === 0): ?>
                      <form method="POST" class="cat-del" data-name="<?= htmlspecialchars($c['category_name']) ?>">
                          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                          <button type="submit" class="cat-btn danger">Delete</button>
                      </form>
                  <?php else: ?>
                      <span class="cat-lock" title="Used by files, so it can't be deleted">In use</span>
                  <?php endif; ?>
              </li>
          <?php endforeach; ?>
      </ul>
      <?php endif; ?>
  </section>
</main>

<script src="../js/categories.js?v=<?= @filemtime(__DIR__ . '/../js/categories.js') ?>"></script>
</body>
</html>