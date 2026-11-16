<?php
// ============================================
// admin/areas.php — Admin manages the AREAS (Area I, Area II, ...).
// The list is the same for the whole system; each program/office keeps its
// own files inside every area. Add, rename, describe, reorder, and delete an
// area that no file uses.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$active_page = 'areas';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf  = $_SESSION['csrf'];
$error = "";
$flash = $_GET['flash'] ?? '';
$add_name = $add_desc = "";
$add_order = '';

// trims, collapses double spaces
function clean_text($s)
{
    return trim(preg_replace('/\s+/u', ' ', (string)$s));
}
// returns '' when OK, otherwise the error message
function area_input_error($name, $desc)
{
    if ($name === '') {
        return "Please type the area name.";
    }
    if (mb_strlen($name) > 100) {
        return "The area name can be up to 100 characters.";
    }
    if (mb_strlen($desc) > 255) {
        return "The description can be up to 255 characters.";
    }
    return '';
}
function area_name_taken($conn, $name, $except_id)
{
    $stmt = $conn->prepare("SELECT area_id FROM areas WHERE LOWER(area_name) = LOWER(?) AND area_id <> ? LIMIT 1");
    $stmt->bind_param("si", $name, $except_id);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $taken;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['area_id'] ?? 0);
    $name   = clean_text($_POST['area_name'] ?? '');
    $desc   = clean_text($_POST['description'] ?? '');
    $order  = max(0, min(9999, (int)($_POST['sort_order'] ?? 0)));

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = "The page expired. Please try again.";

    // ---- add ----
    } elseif ($action === 'add') {
        $add_name = $name;
        $add_desc = $desc;
        $add_order = $_POST['sort_order'] ?? '';
        if (($msg = area_input_error($name, $desc)) !== '') {
            $error = $msg;
        } elseif (area_name_taken($conn, $name, 0)) {
            $error = "An area named \"" . $name . "\" already exists.";
        } else {
            if (($_POST['sort_order'] ?? '') === '') {      // no number typed: put it last
                $order = (int)$conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM areas")->fetch_assoc()['n'];
            }
            $stmt = $conn->prepare("INSERT INTO areas (area_name, description, sort_order) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $name, $desc, $order);
            $stmt->execute();
            $stmt->close();
            header("Location: areas.php?flash=added");
            exit();
        }

    // ---- save (rename / description / order) ----
    } elseif ($action === 'save') {
        if (($msg = area_input_error($name, $desc)) !== '') {
            $error = $msg;
        } elseif (area_name_taken($conn, $name, $id)) {
            $error = "Another area is already named \"" . $name . "\".";
        } else {
            $stmt = $conn->prepare("UPDATE areas SET area_name = ?, description = ?, sort_order = ? WHERE area_id = ?");
            $stmt->bind_param("ssii", $name, $desc, $order, $id);
            $stmt->execute();
            $stmt->close();
            header("Location: areas.php?flash=saved");
            exit();
        }

    // ---- delete (only when no file uses it) ----
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM files WHERE area_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $used = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        if ($used > 0) {
            $error = "That area is used by " . $used . " file(s), so it can't be deleted.";
        } else {
            $stmt = $conn->prepare("DELETE FROM areas WHERE area_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            header("Location: areas.php?flash=deleted");
            exit();
        }
    }
}

// All areas + how many files use each one
$areas = $conn->query(
    "SELECT a.area_id, a.area_name, a.description, a.sort_order, COUNT(f.file_id) AS total
     FROM areas a
     LEFT JOIN files f ON f.area_id = a.area_id
     GROUP BY a.area_id, a.area_name, a.description, a.sort_order
     ORDER BY a.sort_order, a.area_id"
)->fetch_all(MYSQLI_ASSOC);

$flash_text = [
    'added'   => "Area added.",
    'saved'   => "Area saved.",
    'deleted' => "Area deleted.",
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Areas</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
    <link rel="stylesheet" href="../css/categories.css?v=<?= @filemtime(__DIR__ . '/../css/categories.css') ?>">
</head>

<body class="with-sidebar">

    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="page-top">
            <div>
                <h2>Areas</h2>
                <p class="muted">The areas (Area I, Area II, ...) are the same for everyone. Every program/office keeps its own files inside each area.</p>
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
            <h3>Add an area</h3>
            <form method="POST" class="cat-row">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="add">
                <input type="text" name="area_name" maxlength="100" required
                    placeholder="Name, e.g. Area XI" value="<?= htmlspecialchars($add_name) ?>">
                <input type="text" name="description" maxlength="255" class="area-desc"
                    placeholder="What belongs here (optional)" value="<?= htmlspecialchars($add_desc) ?>">
                <input type="number" name="sort_order" min="0" max="9999" class="area-order"
                    placeholder="Order" value="<?= htmlspecialchars((string)$add_order) ?>" title="Position in the list (leave blank to put it last)">
                <button type="submit" class="cat-btn">Add</button>
            </form>
        </section>

        <section class="dash-panel cat-list">
            <h3>All areas <small><?= count($areas) ?></small></h3>
            <?php if (!$areas): ?>
                <div class="dash-empty">No areas yet.</div>
            <?php else: ?>
                <ul>
                    <?php foreach ($areas as $a): ?>
                        <li>
                            <form method="POST" class="cat-edit">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="area_id" value="<?= (int)$a['area_id'] ?>">
                                <input type="number" name="sort_order" min="0" max="9999" class="area-order"
                                    value="<?= (int)$a['sort_order'] ?>" aria-label="Order">
                                <input type="text" name="area_name" maxlength="100" required class="area-name"
                                    value="<?= htmlspecialchars($a['area_name']) ?>" aria-label="Area name">
                                <input type="text" name="description" maxlength="255" class="area-desc"
                                    value="<?= htmlspecialchars($a['description'] ?? '') ?>" placeholder="Description" aria-label="Description">
                                <span class="cat-count"><?= (int)$a['total'] ?> file<?= (int)$a['total'] === 1 ? '' : 's' ?></span>
                                <button type="submit" class="cat-btn alt">Save</button>
                            </form>
                            <?php if ((int)$a['total'] === 0): ?>
                                <form method="POST" class="area-del" data-name="<?= htmlspecialchars($a['area_name']) ?>">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="area_id" value="<?= (int)$a['area_id'] ?>">
                                    <button type="submit" class="cat-btn danger">Delete</button>
                                </form>
                            <?php else: ?>
                                <span class="cat-lock" title="Used by files, so it can't be deleted">In use</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="fold-hint">An area can only be deleted while no file uses it. To hide an area people no longer need, rename it instead.</p>
        </section>
    </main>

    <script src="../js/areas.js?v=<?= @filemtime(__DIR__ . '/../js/areas.js') ?>"></script>
</body>

</html>