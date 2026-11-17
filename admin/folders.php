<?php
// ============================================
// admin/folders.php — Admin sets up the departments, programs and offices
// (the folders the files are organized in)
// (Education level > Department > Program, or an Office).
//   add a folder, rename one, delete one that is completely empty,
//   MOVE a folder to another parent, and TRANSFER the files/accounts of one folder to another.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('admin');
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
    'office'          => ['office'],   // an office can have sub-offices (e.g. Accounting > Cashier)
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
function clean_description($s)
{
    return trim(preg_replace('/\s+/', ' ', (string)$s));
}
function description_error($d)
{
    if (mb_strlen($d) > 255) return "The description is too long (255 characters max).";
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

// Every folder inside $id (children, grandchildren, ...), not counting $id itself
function folder_descendants($programs, $id)
{
    $out   = [];
    $stack = [(int)$id];
    while ($stack) {
        $cur = array_pop($stack);
        foreach ($programs as $pid => $p) {
            if ((int)$p['parent_id'] === $cur) {
                $out[]   = (int)$pid;
                $stack[] = (int)$pid;
            }
        }
    }
    return $out;
}

$programs = load_programs($conn);

$error    = "";
$flash    = $_GET['flash'] ?? '';
$add_name = "";
$add_desc = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['program_id'] ?? 0);

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = "The page expired. Please try again.";

        // ---- add ----
    } elseif ($action === 'add') {
        $name      = clean_folder_name($_POST['folder_name'] ?? '');
        $add_name  = $name;
        $desc      = clean_description($_POST['description'] ?? '');
        $add_desc  = $desc;
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
            } elseif (($msg = description_error($desc)) !== '') {
                $error = $msg;
            } elseif (!in_array($level, $allowed_children[$parent_level] ?? [], true)) {
                $error = "That kind of folder can't be placed there.";
            } else {
                $parent_param = $parent_id === 0 ? null : $parent_id;
                if (folder_name_taken($conn, $name, $parent_param)) {
                    $error = "There is already a folder named \"" . $name . "\" in that place.";
                } else {
                    $desc_param = ($desc === '') ? null : $desc;   // blank = NULL
                    $stmt = $conn->prepare("INSERT INTO programs (program_name, description, parent_id, level) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssis", $name, $desc_param, $parent_param, $level);
                    $stmt->execute();
                    $stmt->close();
                    header("Location: folders.php?flash=added&kind=" . urlencode($level));
                    exit();
                }
            }
        }

        // ---- rename ----
    } elseif ($action === 'rename') {
        $name = clean_folder_name($_POST['folder_name'] ?? '');
        $desc = clean_description($_POST['description'] ?? '');
        if (!isset($programs[$id])) {
            $error = "That folder no longer exists.";
        } elseif (($msg = folder_name_error($name)) !== '') {
            $error = $msg;
        } elseif (($msg = description_error($desc)) !== '') {
            $error = $msg;
        } else {
            $parent_param = $programs[$id]['parent_id'] === null ? null : (int)$programs[$id]['parent_id'];
            if (folder_name_taken($conn, $name, $parent_param, $id)) {
                $error = "There is already a folder named \"" . $name . "\" in that place.";
            } else {
                $desc_param = ($desc === '') ? null : $desc;   // blank = NULL
                $stmt = $conn->prepare("UPDATE programs SET program_name = ?, description = ? WHERE program_id = ?");
                $stmt->bind_param("ssi", $name, $desc_param, $id);
                $stmt->execute();
                $stmt->close();
                header("Location: folders.php?flash=renamed&kind=" . urlencode($programs[$id]['level'] ?? ''));
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
                        (SELECT COUNT(*) FROM user_programs WHERE program_id = ?) AS users"
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
                $kind = $programs[$id]['level'];
                $stmt = $conn->prepare("DELETE FROM programs WHERE program_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                header("Location: folders.php?flash=deleted&kind=" . urlencode($kind));
                exit();
            }
        }

        // ---- move a folder (with everything inside it) to another parent ----
    } elseif ($action === 'move') {
        $dest_raw = $_POST['new_parent'] ?? '';
        $dest     = (int)$dest_raw;

        if (!isset($programs[$id])) {
            $error = "That folder no longer exists.";
        } elseif ($dest_raw === '') {
            $error = "Please choose where to move it.";
        } elseif ($dest_raw === 'independent') {
            // No parent at all: the folder stands on its own at the top level.
            //   new_type = keep   -> it stays what it is (Department / Program ...) and keeps its own sub-folders
            //   new_type = office -> it becomes an Office; the folders inside it become offices too
            //                        (an office can only hold offices)
            $new_type = (($_POST['new_type'] ?? 'office') === 'keep') ? 'keep' : 'office';
            $is_top   = ((int)$programs[$id]['parent_id'] === 0);
            if ($is_top && ($new_type === 'keep' || $programs[$id]['level'] === 'office')) {
                $error = "That folder is already independent.";
            } elseif (folder_name_taken($conn, $programs[$id]['program_name'], null, $id)) {
                $error = "There is already a top-level folder named \"" . $programs[$id]['program_name'] . "\".";
            } else {
                $ids = array_merge([$id], folder_descendants($programs, $id));   // whole group, all ints
                try {
                    $conn->begin_transaction();
                    $stmt = $conn->prepare("UPDATE programs SET parent_id = NULL WHERE program_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $stmt->close();
                    if ($new_type === 'office') {
                        $conn->query("UPDATE programs SET level = 'office' WHERE program_id IN (" . implode(',', array_map('intval', $ids)) . ")");
                    }
                    $conn->commit();
                    header("Location: folders.php?flash=independent&id=" . $id);
                    exit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = "It could not be changed. Nothing was changed.";
                }
            }
        } elseif ($dest !== 0 && !isset($programs[$dest])) {
            $error = "The place you chose no longer exists.";
        } elseif ($dest === $id || in_array($dest, folder_descendants($programs, $id), true)) {
            $error = "A folder can't be moved inside itself.";
        } elseif ($dest === (int)$programs[$id]['parent_id']) {
            $error = "That folder is already there.";
        } else {
            $dest_level = ($dest === 0) ? '' : $programs[$dest]['level'];
            $my_level   = $programs[$id]['level'];
            $dest_param = ($dest === 0) ? null : $dest;
            if (!in_array($my_level, $allowed_children[$dest_level] ?? [], true)) {
                $error = "A " . strtolower($level_label[$my_level] ?? 'folder') . " can't be placed there.";
            } elseif (folder_name_taken($conn, $programs[$id]['program_name'], $dest_param, $id)) {
                $error = "There is already a folder named \"" . $programs[$id]['program_name'] . "\" in that place.";
            } else {
                // Only parent_id changes. The files and accounts are tied to this folder's own id,
                // so they all go along automatically.
                $stmt = $conn->prepare("UPDATE programs SET parent_id = ? WHERE program_id = ?");
                $stmt->bind_param("ii", $dest_param, $id);
                $stmt->execute();
                $stmt->close();
                header("Location: folders.php?flash=moved&kind=" . urlencode($my_level));
                exit();
            }
        }

        // ---- transfer the files and/or accounts of one folder to another folder ----
    } elseif ($action === 'transfer') {
        $from     = (int)($_POST['from_id'] ?? 0);
        $to       = (int)($_POST['to_id'] ?? 0);
        $do_files = !empty($_POST['move_files']);
        $do_users = !empty($_POST['move_users']);

        if (!isset($programs[$from]) || !isset($programs[$to])) {
            $error = "Please choose both folders.";
        } elseif ($from === $to) {
            $error = "Please choose two different folders.";
        } elseif (!$do_files && !$do_users) {
            $error = "Tick what to transfer: files, accounts, or both.";
        } else {
            $nf = 0;
            $nu = 0;
            try {
                // all or nothing: if anything fails, nothing is changed
                $conn->begin_transaction();

                if ($do_files) {
                    // includes old (archived) versions, so a Replace chain never gets split
                    $stmt = $conn->prepare("UPDATE files SET program_id = ? WHERE program_id = ?");
                    $stmt->bind_param("ii", $to, $from);
                    $stmt->execute();
                    $nf = $stmt->affected_rows;
                    $stmt->close();
                }

                if ($do_users) {
                    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM user_programs WHERE program_id = ?");
                    $stmt->bind_param("i", $from);
                    $stmt->execute();
                    $nu = (int)$stmt->get_result()->fetch_assoc()['c'];
                    $stmt->close();

                    // give the accounts the new folder (skip anyone who already has it) ...
                    $stmt = $conn->prepare(
                        "INSERT INTO user_programs (user_id, program_id)
                         SELECT up.user_id, ? FROM user_programs up
                         WHERE up.program_id = ?
                           AND NOT EXISTS (SELECT 1 FROM user_programs x WHERE x.user_id = up.user_id AND x.program_id = ?)"
                    );
                    $stmt->bind_param("iii", $to, $from, $to);
                    $stmt->execute();
                    $stmt->close();

                    // ... then take the old one away
                    $stmt = $conn->prepare("DELETE FROM user_programs WHERE program_id = ?");
                    $stmt->bind_param("i", $from);
                    $stmt->execute();
                    $stmt->close();

                    // users.program_id keeps each account's first folder
                    $stmt = $conn->prepare("UPDATE users SET program_id = ? WHERE program_id = ?");
                    $stmt->bind_param("ii", $to, $from);
                    $stmt->execute();
                    $stmt->close();
                }

                if ($nf === 0 && $nu === 0) {
                    $conn->rollback();
                    $error = "That folder has nothing to transfer.";
                } else {
                    $conn->commit();
                    header("Location: folders.php?flash=transferred&from=" . $from . "&to=" . $to . "&f=" . $nf . "&u=" . $nu);
                    exit();
                }
            } catch (Throwable $e) {
                $conn->rollback();
                $error = "The transfer could not be completed. Nothing was changed.";
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
foreach ($children as $k => $list) {
    sort($list);
    $children[$k] = $list;
}

$file_n = [];
$res = $conn->query("SELECT program_id, COUNT(*) AS c FROM files GROUP BY program_id");
while ($r = $res->fetch_assoc()) {
    $file_n[(int)$r['program_id']] = (int)$r['c'];
}
$user_n = [];
$res = $conn->query("SELECT program_id, COUNT(*) AS c FROM user_programs GROUP BY program_id");
while ($r = $res->fetch_assoc()) {
    $user_n[(int)$r['program_id']] = (int)$r['c'];
}

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

// All folders inside (and including) $id, used for the counts shown when moving
function subtree_of($children, $id)
{
    $out = [$id];
    foreach ($children[$id] ?? [] as $c) {
        $out = array_merge($out, subtree_of($children, $c));
    }
    return $out;
}

// Flash messages say what was added/saved/deleted/moved (Department, Program, Office, ...)
$kind_name = $level_label[$_GET['kind'] ?? ''] ?? 'Folder';
$flash_text = [
    'added'   => $kind_name . " added.",
    'renamed' => $kind_name . " saved.",
    'deleted' => $kind_name . " deleted.",
    'moved'   => $kind_name . " moved. Everything inside it went along.",
];
if ($flash === 'independent') {
    $ind = $programs[(int)($_GET['id'] ?? 0)] ?? null;
    $flash_text['independent'] = $ind
        ? $ind['program_name'] . " is now an independent " . ($level_label[$ind['level']] ?? 'folder') . ". Its files and accounts stayed with it."
        : "The folder is now independent.";
}
if ($flash === 'transferred') {
    $tf = (int)($_GET['f'] ?? 0);
    $tu = (int)($_GET['u'] ?? 0);
    $parts = [];
    if ($tf) { $parts[] = $tf . " file" . ($tf === 1 ? '' : 's'); }
    if ($tu) { $parts[] = $tu . " account" . ($tu === 1 ? '' : 's'); }
    $from_name = $programs[(int)($_GET['from'] ?? 0)]['program_name'] ?? 'the folder';
    $to_name   = $programs[(int)($_GET['to'] ?? 0)]['program_name'] ?? 'the folder';
    $flash_text['transferred'] = "Transferred " . implode(' and ', $parts) . " from " . $from_name . " to " . $to_name
                               . ". Tell those users to log out and log in again.";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments &amp; Programs</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
    <link rel="stylesheet" href="../css/categories.css?v=<?= @filemtime(__DIR__ . '/../css/categories.css') ?>">
</head>

<body class="with-sidebar">

    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="page-top">
            <div>
                <h2>Departments &amp; Programs</h2>
                <p class="muted">Add, rename or delete the education levels, departments, programs and offices. Users pick from this list when they register.</p>
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
            <h3>Add new</h3>
            <form method="POST" class="cat-row fold-add" id="foldAdd">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="add">
                <input type="text" name="folder_name" maxlength="60" required
                    placeholder="e.g. CASE-BA or Library" value="<?= htmlspecialchars($add_name) ?>">
                <input type="text" name="description" maxlength="255" class="fold-desc"
                    placeholder="Meaning, e.g. College of Arts, Sciences, Education and Technology (optional)"
                    value="<?= htmlspecialchars($add_desc) ?>" aria-label="Description">
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
            <p class="fold-hint">A folder can only be deleted while it is empty (no folders, files or accounts inside).</p>
        </section>

        <div class="fold-tools">
            <!-- Move: put a folder (and everything inside it) under another parent -->
            <section class="dash-panel cat-add fold-tool">
                <h3>Move a folder</h3>
                <form method="POST" class="cat-row" id="foldMove">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="move">
                    <select name="program_id" id="moveWho" required aria-label="Folder to move">
                        <option value="">Folder to move...</option>
                        <?php foreach ($rows as $r):
                            $mid  = $r['id'];
                            $sub  = subtree_of($children, $mid);
                            $sf = 0; $su = 0;
                            foreach ($sub as $sid) { $sf += $file_n[$sid] ?? 0; $su += $user_n[$sid] ?? 0; }
                        ?>
                            <option value="<?= (int)$mid ?>"
                                data-level="<?= htmlspecialchars($r['p']['level']) ?>"
                                data-label="<?= htmlspecialchars($level_label[$r['p']['level']] ?? '') ?>"
                                data-parent="<?= (int)$r['p']['parent_id'] ?>"
                                data-desc="<?= htmlspecialchars(implode(',', array_slice($sub, 1))) ?>"
                                data-subs="<?= count($sub) - 1 ?>" data-files="<?= $sf ?>" data-users="<?= $su ?>">
                                <?= htmlspecialchars(program_breadcrumb($programs, $mid)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="new_parent" id="moveTo" required aria-label="Move into">
                        <option value="">Move into...</option>
                        <option value="0" data-allowed="<?= htmlspecialchars(implode(',', $allowed_children[''])) ?>">Top level</option>
                        <option value="independent">Make it independent (no parent)</option>
                        <?php foreach ($parents as $pid => $p): ?>
                            <option value="<?= (int)$pid ?>" data-allowed="<?= htmlspecialchars(implode(',', $allowed_children[$p['level']])) ?>">
                                <?= htmlspecialchars(program_breadcrumb($programs, $pid)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="new_type" id="moveType" aria-label="Becomes" hidden>
                        <option value="keep">Keep its type</option>
                        <option value="office">Become an Office</option>
                    </select>
                    <button type="submit" class="cat-btn">Move</button>
                </form>
                <p class="fold-hint">Only the place changes. The files and accounts of the folder (and of everything inside it) go along. If a folder has no place to go, choose "independent": it loses its parent and stands on its own, either keeping its type (and its own sub-folders) or becoming an Office.</p>
            </section>

            <!-- Transfer: give the files / accounts of one folder to another folder -->
            <section class="dash-panel cat-add fold-tool">
                <h3>Transfer files / accounts</h3>
                <form method="POST" class="cat-row" id="foldTransfer">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="transfer">
                    <select name="from_id" id="trFrom" required aria-label="Transfer from">
                        <option value="">From...</option>
                        <?php foreach ($rows as $r):
                            $tid = $r['id'];
                            $tf  = $file_n[$tid] ?? 0;
                            $tu  = $user_n[$tid] ?? 0;
                            if (!$tf && !$tu) { continue; }   // nothing to give away
                        ?>
                            <option value="<?= (int)$tid ?>" data-files="<?= $tf ?>" data-users="<?= $tu ?>">
                                <?= htmlspecialchars(program_breadcrumb($programs, $tid)) ?> (<?= $tf ?> file<?= $tf === 1 ? '' : 's' ?>, <?= $tu ?> account<?= $tu === 1 ? '' : 's' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="to_id" id="trTo" required aria-label="Transfer to">
                        <option value="">To...</option>
                        <?php foreach ($rows as $r): ?>
                            <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars(program_breadcrumb($programs, $r['id'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="fold-check"><input type="checkbox" name="move_files" value="1" checked> Files</label>
                    <label class="fold-check"><input type="checkbox" name="move_users" value="1" checked> Accounts</label>
                    <button type="submit" class="cat-btn">Transfer</button>
                </form>
                <p class="fold-hint">Use this when a department is split or two offices are merged. Only what sits directly in the folder is moved (not what is in its sub-folders). The empty folder stays, and you can delete it afterwards.</p>
            </section>
        </div>

        <section class="dash-panel cat-list">
            <h3>All <small><?= count($rows) ?></small></h3>
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
                                <input type="text" name="description" maxlength="255" class="fold-desc"
                                    placeholder="Description (optional)"
                                    value="<?= htmlspecialchars($p['description'] ?? '') ?>" aria-label="Description">
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
    <script src="../js/folders.js?v=<?= @filemtime(__DIR__ . '/../js/folders.js') ?>"></script>
</body>

</html>