<?php
// ============================================
// qa/folders.php — QA sets up the folders the files are organized in
// (Education level > Department > Program, or an Office).
//   add a folder, rename one, delete one that is completely empty.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('qa');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$active_page = 'folders';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

// What can go inside what
$level_label = [
    'education_level' => 'Education level',
    'department'      => 'Department',
    'program'         => 'Program',
    'office'          => 'Office',
];
$allowed_children = [
    ''                => ['education_level', 'office'],   // top level
    'education_level' => ['department', 'program'],
    'department'      => ['program'],
    'program'         => [],
    'office'          => [],
];

function clean_folder_name($s)
{
    return trim(preg_replace('/\s+/', ' ', (string)$s));
}
function folder_name_error($name)
{
    if ($name === '')                  return "Please type a folder name.";
    if (mb_strlen($name) < 2)          return "The folder name is too short.";
    if (mb_strlen($name) > 60)         return "The folder name is too long (60 characters max).";
    if (!preg_match('/^[\p{L}\p{N} .,&()\/\'-]+$/u', $name)) {
        return "The folder name has a character that isn't allowed.";
    }
    return '';
}
// Is there already a folder with this name in the same place?
function folder_name_taken($conn, $name, $parent_id, $except_id = 0)
{
    $stmt = $conn->prepare(
        "SELECT program_id FROM programs
         WHERE LOWER(program_name) = LOWER(?) AND parent_id <=> ? AND program_id <> ? LIMIT 1"
    );
    $stmt->bind_param("sii", $name, $parent_id, $except_id);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $taken;
}

$programs = load_programs($conn);

$error    = "";
$flash    = $_GET['flash'] ?? '';
$add_name = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['program_id'] ?? 0);

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = "The page expired. Please try again.";

    // ---- add ----
    } elseif ($action === 'add') {
        $name      = clean_folder_name($_POST['folder_name'] ?? '');
        $add_name  = $name;
        $parent_id = (int)($_POST['parent_id'] ?? 0);
        $level     = $_POST['level'] ?? '';
        $parent_level = '';

        if ($parent_id !== 0) {
            if (!isset($programs[$parent_id])) {
                $error = "That parent folder no longer exists.";
            } else {
                $parent_level = $programs[$parent_id]['level'];
            }
        }

        if ($error === '') {
            if (($msg = folder_name_error($name)) !== '') {
                $error = $msg;
            } elseif (!in_array($level, $allowed_children[$parent_level] ?? [], true)) {
                $error = "That kind of folder can't be placed there.";
            } else {
                $parent_param = $parent_id === 0 ? null : $parent_id;
                if (folder_name_taken($conn, $name, $parent_param)) {
                    $error = "There is already a folder named \"" . $name . "\" in that place.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO programs (program_name, parent_id, level) VALUES (?, ?, ?)");
                    $stmt->bind_param("sis", $name, $parent_param, $level);
                    $stmt->execute();
                    $stmt->close();
                    header("Location: folders.php?flash=added");
                    exit();
                }
            }
        }

    // ---- rename ----
    } elseif ($action === 'rename') {
        $name = clean_folder_name($_POST['folder_name'] ?? '');
        if (!isset($programs[$id])) {
            $error = "That folder no longer exists.";
        } elseif (($msg = folder_name_error($name)) !== '') {
            $error = $msg;
        } else {
            $parent_param = $programs[$id]['parent_id'] === null ? null : (int)$programs[$id]['parent_id'];
            if (folder_name_taken($conn, $name, $parent_param, $id)) {
                $error = "There is already a folder named \"" . $name . "\" in that place.";
            } else {
                $stmt = $conn->prepare("UPDATE programs SET program_name = ? WHERE program_id = ?");
                $stmt->bind_param("si", $name, $id);
                $stmt->execute();
                $stmt->close();
                header("Location: folders.php?flash=renamed");
                exit();
            }
        }

    // ---- delete (only when completely empty) ----
    } elseif ($action === 'delete') {
        if (!isset($programs[$id])) {
            $error = "That folder no longer exists.";
        } else {
            $stmt = $conn->prepare(
                "SELECT (SELECT COUNT(*) FROM programs WHERE parent_id = ?) AS subs,
                        (SELECT COUNT(*) FROM files    WHERE program_id = ?) AS files,
                        (SELECT COUNT(*) FROM users    WHERE program_id = ?) AS users"
            );
            $stmt->bind_param("iii", $id, $id, $id);
            $stmt->execute();
            $c = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ((int)$c['subs'] > 0) {
                $error = "That folder has folders inside it, so it can't be deleted.";
            } elseif ((int)$c['files'] > 0) {
                $error = "That folder has files (including old versions), so it can't be deleted.";
            } elseif ((int)$c['users'] > 0) {
                $error = "That folder still has " . (int)$c['users'] . " account(s), so it can't be deleted.";
            } else {
                $stmt = $conn->prepare("DELETE FROM programs WHERE program_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                header("Location: folders.php?flash=deleted");
                exit();
            }
        }
    }
}

// ---------------------------------------------------------------
// Build the tree (fresh, because the lists above may have changed)
// ---------------------------------------------------------------
$programs = load_programs($conn);
$children = [];
foreach ($programs as $pid => $p) {
    $children[(int)$p['parent_id']][] = $pid;
}
foreach ($children as $k => $list) { sort($list); $children[$k] = $list; }

$file_n = [];
$res = $conn->query("SELECT program_id, COUNT(*) AS c FROM files GROUP BY program_id");
while ($r = $res->fetch_assoc()) { $file_n[(int)$r['program_id']] = (int)$r['c']; }
$user_n = [];
$res = $conn->query("SELECT program_id, COUNT(*) AS c FROM users WHERE program_id IS NOT NULL GROUP BY program_id");
while ($r = $res->fetch_assoc()) { $user_n[(int)$r['program_id']] = (int)$r['c']; }

// Flat, ordered rows with their depth
$rows = [];
function walk_tree($children, $programs, $parent, $depth, &$rows)
{
    foreach ($children[$parent] ?? [] as $id) {
        $rows[] = ['id' => $id, 'depth' => $depth, 'p' => $programs[$id]];
        walk_tree($children, $programs, $id, $depth + 1, $rows);
    }
}
walk_tree($children, $programs, 0, 0, $rows);

// Folders that can hold other folders (for the "Inside" dropdown)
$parents = array_filter($programs, function ($p) use ($allowed_children) {
    return !empty($allowed_children[$p['level']]);
});

$flash_text = [
    'added'   => "Folder added.",
    'renamed' => "Folder renamed.",
    'deleted' => "Folder deleted.",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Folders</title>
<link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
<link rel="stylesheet" href="../css/categories.css?v=<?= @filemtime(__DIR__ . '/../css/categories.css') ?>">
</head>
<body class="with-sidebar">

<?php include __DIR__ . '/../includes/qa_sidebar.php'; ?>

<main class="main-content">
  <div class="page-top">
      <div>
          <h2>Folders</h2>
          <p class="muted">How the files are organized: Education level, Department, Program or Office.</p>
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
      <h3>Add a folder</h3>
      <form method="POST" class="cat-row fold-add" id="foldAdd">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="action" value="add">
          <input type="text" name="folder_name" maxlength="60" required
                 placeholder="e.g. CASE-BA or Library" value="<?= htmlspecialchars($add_name) ?>">
          <select name="parent_id" id="foldParent" aria-label="Inside">
              <option value="0" data-allowed="education_level,office">Top level</option>
              <?php foreach ($parents as $id => $p): ?>
                  <option value="<?= (int)$id ?>" data-allowed="<?= htmlspecialchars(implode(',', $allowed_children[$p['level']])) ?>"
                      <?= ((int)($_POST['parent_id'] ?? 0) === (int)$id) ? 'selected' : '' ?>>
                      Inside: <?= htmlspecialchars(program_breadcrumb($programs, $id)) ?>
                  </option>
              <?php endforeach; ?>
          </select>
          <select name="level" id="foldLevel" aria-label="Type">
              <?php foreach ($level_label as $key => $label): ?>
                  <option value="<?= $key ?>" <?= (($_POST['level'] ?? '') === $key) ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
          </select>
          <button type="submit" class="cat-btn">Add</button>
      </form>
      <p class="fold-hint">Programs and Offices are the ones Program users can pick when they register.</p>
  </section>

  <section class="dash-panel cat-list">
      <h3>All folders <small><?= count($rows) ?></small></h3>
      <?php if (!$rows): ?>
          <div class="dash-empty">No folders yet.</div>
      <?php else: ?>
      <ul>
          <?php foreach ($rows as $r):
              $id    = $r['id'];
              $p     = $r['p'];
              $nf    = $file_n[$id] ?? 0;
              $nu    = $user_n[$id] ?? 0;
              $nsub  = count($children[$id] ?? []);
              $empty = ($nf === 0 && $nu === 0 && $nsub === 0);
          ?>
              <li style="padding-left: <?= (int)$r['depth'] * 26 ?>px">
                  <form method="POST" class="cat-edit">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="rename">
                      <input type="hidden" name="program_id" value="<?= (int)$id ?>">
                      <span class="fold-level fold-<?= htmlspecialchars($p['level']) ?>"><?= htmlspecialchars($level_label[$p['level']] ?? $p['level']) ?></span>
                      <input type="text" name="folder_name" maxlength="60" required
                             value="<?= htmlspecialchars($p['program_name']) ?>" aria-label="Folder name">
                      <span class="cat-count"><?= $nf ?> file<?= $nf === 1 ? '' : 's' ?></span>
                      <button type="submit" class="cat-btn alt">Save</button>
                  </form>
                  <?php if ($empty): ?>
                      <form method="POST" class="cat-del" data-name="<?= htmlspecialchars($p['program_name']) ?>">
                          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="program_id" value="<?= (int)$id ?>">
                          <button type="submit" class="cat-btn danger">Delete</button>
                      </form>
                  <?php else: ?>
                      <span class="cat-lock" title="<?= $nsub ?> folder(s), <?= $nf ?> file(s), <?= $nu ?> account(s) inside">In use</span>
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