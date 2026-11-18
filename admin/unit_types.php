<?php
// ============================================
// admin/unit_types.php — Admin manages the folder TYPES
// (Education level, Department, Program, Office, Facility, Laboratory, Cafeteria...).
// For every type Admin decides:
//   - can it stand at the top level (no parent)?
//   - which types can be put INSIDE it?
// Add a type, rename it, change those rules, and delete a type that no folder uses
// (the four built-in types can't be deleted).
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$active_page = 'unit_types';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf  = $_SESSION['csrf'];
$error = "";
$flash = $_GET['flash'] ?? '';
$add_label = '';

function clean_text($s)
{
    return trim(preg_replace('/\s+/u', ' ', (string)$s));
}
function type_label_error($label)
{
    if ($label === '')            return "Please type the type name.";
    if (mb_strlen($label) < 2)    return "The type name is too short.";
    if (mb_strlen($label) > 40)   return "The type name can be up to 40 characters.";
    if (!preg_match('/^[\p{L}\p{N} .,&()\/\'-]+$/u', $label)) {
        return "The type name has a character that isn't allowed.";
    }
    return '';
}
function type_label_taken($conn, $label, $except_key)
{
    $stmt = $conn->prepare("SELECT type_key FROM unit_types WHERE LOWER(type_label) = LOWER(?) AND type_key <> ? LIMIT 1");
    $stmt->bind_param("ss", $label, $except_key);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $taken;
}
// "Cold Storage" -> cold_storage (unique)
function make_type_key($conn, $label)
{
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label));
    $base = trim($base, '_');
    if ($base === '') {
        $base = 'type';
    }
    $base = substr($base, 0, 24);
    $key = $base;
    $n = 2;
    while (true) {
        $stmt = $conn->prepare("SELECT 1 FROM unit_types WHERE type_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$exists) {
            return $key;
        }
        $key = $base . '_' . $n++;
    }
}

$tables_ok = true;
try {
    $conn->query("SELECT 1 FROM unit_types LIMIT 1");
    $conn->query("SELECT 1 FROM unit_type_children LIMIT 1");
} catch (Throwable $e) {
    $tables_ok = false;
}

if ($tables_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $key    = (string)($_POST['type_key'] ?? '');
    $label  = clean_text($_POST['type_label'] ?? '');
    $order  = max(0, min(9999, (int)($_POST['sort_order'] ?? 0)));
    $at_top = isset($_POST['at_top']) ? 1 : 0;

    // the types that exist now (to check the ticked boxes)
    $existing = [];
    foreach ($conn->query("SELECT type_key, is_system FROM unit_types")->fetch_all(MYSQLI_ASSOC) as $r) {
        $existing[$r['type_key']] = (int)$r['is_system'];
    }
    $picked_children = [];
    foreach ((array)($_POST['children'] ?? []) as $c) {
        $c = (string)$c;
        if (isset($existing[$c]) || $c === '__self') {
            $picked_children[$c] = true;
        }
    }

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = "The page expired. Please try again.";

    // ---- add ----
    } elseif ($action === 'add') {
        $add_label = $label;
        $error = type_label_error($label);
        if (!$error && type_label_taken($conn, $label, '')) {
            $error = "There is already a type named \"" . $label . "\".";
        }
        if (!$error) {
            $picked_parents = [];
            foreach ((array)($_POST['parents'] ?? []) as $p) {
                if (isset($existing[(string)$p])) {
                    $picked_parents[(string)$p] = true;
                }
            }
            $newkey = make_type_key($conn, $label);
            $next   = (int)$conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM unit_types")->fetch_assoc()['n'];
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("INSERT INTO unit_types (type_key, type_label, sort_order, at_top, is_system) VALUES (?, ?, ?, ?, 0)");
                $stmt->bind_param("ssii", $newkey, $label, $next, $at_top);
                $stmt->execute();
                $stmt->close();
                $ins = $conn->prepare("INSERT IGNORE INTO unit_type_children (parent_key, child_key) VALUES (?, ?)");
                foreach (array_keys($picked_parents) as $p) {      // it can go INSIDE these
                    $ins->bind_param("ss", $p, $newkey);
                    $ins->execute();
                }
                foreach (array_keys($picked_children) as $c) {     // and these can go inside IT
                    $child = ($c === '__self') ? $newkey : $c;
                    $ins->bind_param("ss", $newkey, $child);
                    $ins->execute();
                }
                $ins->close();
                $conn->commit();
                header("Location: unit_types.php?flash=added");
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                $error = "Could not add the type. Please try again.";
            }
        }

    // ---- save (rename + rules) ----
    } elseif ($action === 'save') {
        if (!isset($existing[$key])) {
            $error = "That type no longer exists.";
        } else {
            $error = type_label_error($label);
            if (!$error && type_label_taken($conn, $label, $key)) {
                $error = "There is already a type named \"" . $label . "\".";
            }
        }
        if (!$error) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE unit_types SET type_label = ?, sort_order = ?, at_top = ? WHERE type_key = ?");
                $stmt->bind_param("siis", $label, $order, $at_top, $key);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare("DELETE FROM unit_type_children WHERE parent_key = ?");
                $stmt->bind_param("s", $key);
                $stmt->execute();
                $stmt->close();
                $ins = $conn->prepare("INSERT IGNORE INTO unit_type_children (parent_key, child_key) VALUES (?, ?)");
                foreach (array_keys($picked_children) as $c) {
                    $child = ($c === '__self') ? $key : $c;
                    $ins->bind_param("ss", $key, $child);
                    $ins->execute();
                }
                $ins->close();
                $conn->commit();
                header("Location: unit_types.php?flash=saved");
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                $error = "Could not save. Please try again.";
            }
        }

    // ---- delete (only a custom type that no folder uses) ----
    } elseif ($action === 'delete') {
        if (!isset($existing[$key])) {
            $error = "That type no longer exists.";
        } elseif ($existing[$key] === 1) {
            $error = "The built-in types can't be deleted.";
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM programs WHERE level = ?");
            $stmt->bind_param("s", $key);
            $stmt->execute();
            $used = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();
            if ($used > 0) {
                $error = "That type is used by " . $used . " folder(s), so it can't be deleted.";
            } else {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("DELETE FROM unit_type_children WHERE parent_key = ? OR child_key = ?");
                    $stmt->bind_param("ss", $key, $key);
                    $stmt->execute();
                    $stmt->close();
                    $stmt = $conn->prepare("DELETE FROM unit_types WHERE type_key = ?");
                    $stmt->bind_param("s", $key);
                    $stmt->execute();
                    $stmt->close();
                    $conn->commit();
                    header("Location: unit_types.php?flash=deleted");
                    exit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = "Could not delete. Please try again.";
                }
            }
        }
    }
}

$types = $used_by = $children = [];
if ($tables_ok) {
    $types = $conn->query("SELECT type_key, type_label, sort_order, at_top, is_system FROM unit_types ORDER BY sort_order, type_key")->fetch_all(MYSQLI_ASSOC);
    foreach ($conn->query("SELECT level, COUNT(*) AS c FROM programs GROUP BY level")->fetch_all(MYSQLI_ASSOC) as $r) {
        $used_by[$r['level']] = (int)$r['c'];
    }
    foreach ($conn->query("SELECT parent_key, child_key FROM unit_type_children")->fetch_all(MYSQLI_ASSOC) as $r) {
        $children[$r['parent_key']][$r['child_key']] = true;
    }
}
$names = [];
foreach ($types as $t) {
    $names[$t['type_key']] = $t['type_label'];
}
// "goes inside": the reverse of "can contain"
function goes_inside_text($key, $t_at_top, $children, $names)
{
    $parts = [];
    if ($t_at_top) {
        $parts[] = 'Top level';
    }
    foreach ($names as $pk => $pn) {
        if (isset($children[$pk][$key])) {
            $parts[] = $pn;
        }
    }
    return $parts ? implode(', ', $parts) : 'nowhere yet';
}

$flash_text = [
    'added'   => "Folder type added.",
    'saved'   => "Folder type saved.",
    'deleted' => "Folder type deleted.",
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Folder Types</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
    <link rel="stylesheet" href="../css/categories.css?v=<?= @filemtime(__DIR__ . '/../css/categories.css') ?>">
</head>

<body class="with-sidebar">

    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="page-top">
            <div>
                <h2>Folder Types</h2>
                <p class="muted">The kinds of folders you can create on the Depts &amp; Programs page (Education level, Office, Facility, Laboratory, ...). For each type, choose where it can stand and what can go inside it.</p>
                &nbsp;
            </div>
        </div>

        <?php if (isset($flash_text[$flash])): ?>
            <div class="alert alert-success"><?= htmlspecialchars($flash_text[$flash]) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$tables_ok): ?>
            <div class="alert alert-error">Run <strong>unit_types.sql</strong> in phpMyAdmin first, then reload this page.</div>
        <?php else: ?>

            <section class="dash-panel cat-add">
                <h3>Add a folder type</h3>
                <form method="POST" class="ut-form">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="text" name="type_label" maxlength="40" required
                        placeholder="Name, e.g. Laboratory or Cafeteria" value="<?= htmlspecialchars($add_label) ?>">
                    <div class="ut-group">
                        <span class="ut-title">It can be placed:</span>
                        <label class="fold-check"><input type="checkbox" name="at_top" value="1" checked> At the top level</label>
                        <?php foreach ($types as $t): ?>
                            <label class="fold-check"><input type="checkbox" name="parents[]" value="<?= htmlspecialchars($t['type_key']) ?>"> Inside a <?= htmlspecialchars($t['type_label']) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="ut-group">
                        <span class="ut-title">These can go inside it:</span>
                        <label class="fold-check"><input type="checkbox" name="children[]" value="__self"> Same type (e.g. a lab inside a lab)</label>
                        <?php foreach ($types as $t): ?>
                            <label class="fold-check"><input type="checkbox" name="children[]" value="<?= htmlspecialchars($t['type_key']) ?>"> <?= htmlspecialchars($t['type_label']) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="cat-btn">Add type</button>
                </form>
            </section>

            <section class="dash-panel cat-list">
                <h3>All folder types <small><?= count($types) ?></small></h3>
                <ul class="ut-list">
                    <?php foreach ($types as $t): $k = $t['type_key'];
                        $n = $used_by[$k] ?? 0; ?>
                        <li>
                            <form method="POST" class="ut-form ut-edit">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="type_key" value="<?= htmlspecialchars($k) ?>">
                                <div class="ut-head">
                                    <input type="number" name="sort_order" min="0" max="9999" class="area-order"
                                        value="<?= (int)$t['sort_order'] ?>" aria-label="Order">
                                    <input type="text" name="type_label" maxlength="40" required class="ut-name"
                                        value="<?= htmlspecialchars($t['type_label']) ?>" aria-label="Type name">
                                    <span class="cat-count"><?= $n ?> folder<?= $n === 1 ? '' : 's' ?></span>
                                    <?php if ((int)$t['is_system'] === 1): ?><span class="cat-lock" title="Built-in type">Built-in</span><?php endif; ?>
                                </div>
                                <div class="ut-group">
                                    <span class="ut-title">Can stand:</span>
                                    <label class="fold-check"><input type="checkbox" name="at_top" value="1" <?= (int)$t['at_top'] ? 'checked' : '' ?>> At the top level</label>
                                    <span class="ut-note">Goes inside: <?= htmlspecialchars(goes_inside_text($k, (int)$t['at_top'], $children, $names)) ?></span>
                                </div>
                                <div class="ut-group">
                                    <span class="ut-title">Can contain:</span>
                                    <?php foreach ($types as $c): ?>
                                        <label class="fold-check"><input type="checkbox" name="children[]" value="<?= htmlspecialchars($c['type_key']) ?>"
                                                <?= isset($children[$k][$c['type_key']]) ? 'checked' : '' ?>> <?= htmlspecialchars($c['type_label']) ?></label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="ut-actions">
                                    <button type="submit" class="cat-btn alt">Save</button>
                                </div>
                            </form>
                            <?php if ((int)$t['is_system'] === 0 && $n === 0): ?>
                                <form method="POST" class="area-del ut-del" data-name="<?= htmlspecialchars($t['type_label']) ?>">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="type_key" value="<?= htmlspecialchars($k) ?>">
                                    <button type="submit" class="cat-btn danger">Delete</button>
                                </form>
                            <?php elseif ((int)$t['is_system'] === 0): ?>
                                <span class="cat-lock" title="Folders use this type, so it can't be deleted">In use</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="fold-hint">A type can only be deleted while no folder uses it, and the built-in types can't be deleted. Changing the rules does not move or change folders that already exist.</p>
            </section>
        <?php endif; ?>
    </main>

    <script src="../js/areas.js?v=<?= @filemtime(__DIR__ . '/../js/areas.js') ?>"></script>
</body>

</html>