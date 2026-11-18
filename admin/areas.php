<?php
// ============================================
// admin/areas.php — Admin manages ACCREDITATION BODIES.
// PACUCOA is for BSIT, SHS, and Engineering.
// These programs can access all PACUCOA Areas.
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
$open_body = (int)($_GET['body'] ?? 0);

$add_body_name = $add_name = $add_desc = "";
$add_order = '';

function clean_text($s)
{
    return trim(preg_replace('/\s+/u', ' ', (string)$s));
}

function body_name_error($name)
{
    if ($name === '')          return "Please type the accreditation name.";
    if (mb_strlen($name) < 2)  return "The accreditation name is too short.";
    if (mb_strlen($name) > 60) return "The accreditation name can be up to 60 characters.";
    return '';
}

function area_input_error($name, $desc)
{
    if ($name === '')            return "Please type the area name.";
    if (mb_strlen($name) > 100)  return "The area name can be up to 100 characters.";
    if (mb_strlen($desc) > 255)  return "The description can be up to 255 characters.";
    return '';
}

function body_name_taken($conn, $name, $except_id)
{
    $stmt = $conn->prepare(
        "SELECT accred_id
         FROM accreditations
         WHERE LOWER(accred_name) = LOWER(?)
           AND accred_id <> ?
         LIMIT 1"
    );

    $stmt->bind_param("si", $name, $except_id);
    $stmt->execute();

    $taken = $stmt->get_result()->num_rows > 0;

    $stmt->close();

    return $taken;
}

function area_name_taken($conn, $name, $body_id, $except_id)
{
    $stmt = $conn->prepare(
        "SELECT area_id
         FROM areas
         WHERE LOWER(area_name) = LOWER(?)
           AND accred_id <=> ?
           AND area_id <> ?
         LIMIT 1"
    );

    $stmt->bind_param("sii", $name, $body_id, $except_id);
    $stmt->execute();

    $taken = $stmt->get_result()->num_rows > 0;

    $stmt->close();

    return $taken;
}

function body_exists($conn, $id)
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM accreditations
         WHERE accred_id = ?"
    );

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $ok = $stmt->get_result()->num_rows > 0;

    $stmt->close();

    return $ok;
}

/*
|--------------------------------------------------------------------------
| PACUCOA allowed programs
|--------------------------------------------------------------------------
| Only these programs can be designated for PACUCOA.
*/
function is_pacucoa($name)
{
    return strtolower(trim($name)) === 'pacucoa';
}

function allowed_pacucoa_program($program_name)
{
    $name = strtolower(trim($program_name));

    return in_array(
        $name,
        [
            'bsit',
            'shs',
            'engineering'
        ],
        true
    );
}

$tables_ok = true;

try {
    $conn->query("SELECT accred_id FROM accreditations LIMIT 1");
    $conn->query("SELECT accred_id FROM accreditation_units LIMIT 1");
    $conn->query("SELECT accred_id FROM areas LIMIT 1");
} catch (Throwable $e) {
    $tables_ok = false;
}

$programs = $tables_ok ? load_programs($conn) : [];

if ($tables_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $action  = $_POST['action'] ?? '';
    $body_id = (int)($_POST['accred_id'] ?? 0);
    $id      = (int)($_POST['area_id'] ?? 0);

    $bname = clean_text($_POST['accred_name'] ?? '');
    $name  = clean_text($_POST['area_name'] ?? '');
    $desc  = clean_text($_POST['description'] ?? '');

    $order = max(
        0,
        min(9999, (int)($_POST['sort_order'] ?? 0))
    );

    $open_body = $body_id;

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {

        $error = "The page expired. Please try again.";

    /*
    |--------------------------------------------------------------------------
    | ADD ACCREDITATION
    |--------------------------------------------------------------------------
    */
    } elseif ($action === 'add_body') {

        $add_body_name = $bname;

        if (($msg = body_name_error($bname)) !== '') {

            $error = $msg;

        } elseif (body_name_taken($conn, $bname, 0)) {

            $error = "There is already an accreditation named \"" . $bname . "\".";

        } else {

            $next = (int)$conn
                ->query(
                    "SELECT COALESCE(MAX(sort_order), 0) + 1 AS n
                     FROM accreditations"
                )
                ->fetch_assoc()['n'];

            $stmt = $conn->prepare(
                "INSERT INTO accreditations
                    (accred_name, sort_order)
                 VALUES (?, ?)"
            );

            $stmt->bind_param("si", $bname, $next);
            $stmt->execute();

            $new_id = $stmt->insert_id;

            $stmt->close();

            header(
                "Location: areas.php?flash=body_added&body=" . $new_id
            );

            exit();
        }

    /*
    |--------------------------------------------------------------------------
    | SAVE ACCREDITATION
    |--------------------------------------------------------------------------
    */
    } elseif ($action === 'save_body') {

        if (!body_exists($conn, $body_id)) {

            $error = "That accreditation no longer exists.";

        } elseif (($msg = body_name_error($bname)) !== '') {

            $error = $msg;

        } elseif (body_name_taken($conn, $bname, $body_id)) {

            $error = "Another accreditation is already named \"" . $bname . "\".";

        } else {

            $stmt = $conn->prepare(
                "UPDATE accreditations
                 SET accred_name = ?, sort_order = ?
                 WHERE accred_id = ?"
            );

            $stmt->bind_param(
                "sii",
                $bname,
                $order,
                $body_id
            );

            $stmt->execute();
            $stmt->close();

            header(
                "Location: areas.php?flash=body_saved&body=" . $body_id
            );

            exit();
        }

    /*
    |--------------------------------------------------------------------------
    | DELETE ACCREDITATION
    |--------------------------------------------------------------------------
    */
    } elseif ($action === 'delete_body') {

        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS c
             FROM areas
             WHERE accred_id = ?"
        );

        $stmt->bind_param("i", $body_id);
        $stmt->execute();

        $n = (int)$stmt
            ->get_result()
            ->fetch_assoc()['c'];

        $stmt->close();

        if ($n > 0) {

            $error =
                "That accreditation still has " .
                $n .
                " area(s). Delete or move them first.";

        } else {

            $conn->begin_transaction();

            try {

                $stmt = $conn->prepare(
                    "DELETE FROM accreditation_units
                     WHERE accred_id = ?"
                );

                $stmt->bind_param("i", $body_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare(
                    "DELETE FROM accreditations
                     WHERE accred_id = ?"
                );

                $stmt->bind_param("i", $body_id);
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                header(
                    "Location: areas.php?flash=body_deleted"
                );

                exit();

            } catch (Throwable $e) {

                $conn->rollback();

                $error =
                    "Could not delete. Please try again.";
            }
        }

    /*
    |--------------------------------------------------------------------------
    | SAVE DESIGNATED UNITS
    |--------------------------------------------------------------------------
    */
    } elseif ($action === 'save_units') {

        if (!body_exists($conn, $body_id)) {

            $error =
                "That accreditation no longer exists.";

        } else {

            /*
             * Get accreditation name.
             */
            $stmt = $conn->prepare(
                "SELECT accred_name
                 FROM accreditations
                 WHERE accred_id = ?
                 LIMIT 1"
            );

            $stmt->bind_param("i", $body_id);
            $stmt->execute();

            $body_info = $stmt
                ->get_result()
                ->fetch_assoc();

            $stmt->close();

            $body_name = $body_info['accred_name'] ?? '';

            $conn->begin_transaction();

            try {

                /*
                 * Remove previous designations.
                 */
                $stmt = $conn->prepare(
                    "DELETE FROM accreditation_units
                     WHERE accred_id = ?"
                );

                $stmt->bind_param("i", $body_id);
                $stmt->execute();
                $stmt->close();

                /*
                 * Insert only allowed programs for PACUCOA.
                 */
                $ins = $conn->prepare(
                    "INSERT INTO accreditation_units
                        (accred_id, program_id, include_children)
                     VALUES (?, ?, ?)"
                );

                $sub = (array)($_POST['sub'] ?? []);

                foreach ((array)($_POST['unit'] ?? []) as $pid) {

                    $pid = (int)$pid;

                    if (!isset($programs[$pid])) {
                        continue;
                    }

                    /*
                     * PACUCOA:
                     * Only BSIT, SHS, and Engineering.
                     */
                    if (
                        is_pacucoa($body_name) &&
                        !allowed_pacucoa_program(
                            $programs[$pid]['program_name']
                        )
                    ) {
                        continue;
                    }

                    $inc = in_array(
                        (string)$pid,
                        array_map('strval', $sub),
                        true
                    ) ? 1 : 0;

                    /*
                     * PACUCOA does not need sub-folder access.
                     * The three allowed programs already have
                     * access to all PACUCOA areas.
                     */
                    if (is_pacucoa($body_name)) {
                        $inc = 0;
                    }

                    $ins->bind_param(
                        "iii",
                        $body_id,
                        $pid,
                        $inc
                    );

                    $ins->execute();
                }

                $ins->close();

                $conn->commit();

                header(
                    "Location: areas.php?flash=units_saved&body=" . $body_id
                );

                exit();

            } catch (Throwable $e) {

                $conn->rollback();

                $error =
                    "Could not save the units. Please try again.";
            }
        }

    /*
    |--------------------------------------------------------------------------
    | ADD AREA
    |--------------------------------------------------------------------------
    */
    } elseif ($action === 'add') {

        $add_name  = $name;
        $add_desc  = $desc;
        $add_order = $_POST['sort_order'] ?? '';

        if (!body_exists($conn, $body_id)) {

            $error =
                "Please choose a valid accreditation.";

        } elseif (($msg = area_input_error($name, $desc)) !== '') {

            $error = $msg;

        } elseif (area_name_taken($conn, $name, $body_id, 0)) {

            $error =
                "An area named \"" .
                $name .
                "\" already exists in this accreditation.";

        } else {

            if (($_POST['sort_order'] ?? '') === '') {

                $stmt = $conn->prepare(
                    "SELECT COALESCE(MAX(sort_order), 0) + 1 AS n
                     FROM areas
                     WHERE accred_id = ?"
                );

                $stmt->bind_param("i", $body_id);
                $stmt->execute();

                $order = (int)$stmt
                    ->get_result()
                    ->fetch_assoc()['n'];

                $stmt->close();
            }

            $stmt = $conn->prepare(
                "INSERT INTO areas
                    (area_name, description, sort_order, accred_id)
                 VALUES (?, ?, ?, ?)"
            );

            $stmt->bind_param(
                "ssii",
                $name,
                $desc,
                $order,
                $body_id
            );

            $stmt->execute();
            $stmt->close();

            header(
                "Location: areas.php?flash=added&body=" . $body_id
            );

            exit();
        }

    /*
    |--------------------------------------------------------------------------
    | SAVE AREA
    |--------------------------------------------------------------------------
    */
    } elseif ($action === 'save') {

        $new_body =
            (int)($_POST['move_to'] ?? $body_id);

        if (!body_exists($conn, $new_body)) {

            $error =
                "Please choose a valid accreditation.";

        } elseif (($msg = area_input_error($name, $desc)) !== '') {

            $error = $msg;

        } elseif (
            area_name_taken(
                $conn,
                $name,
                $new_body,
                $id
            )
        ) {

            $error =
                "Another area in that accreditation is already named \"" .
                $name .
                "\".";

        } else {

            $stmt = $conn->prepare(
                "UPDATE areas
                 SET area_name = ?,
                     description = ?,
                     sort_order = ?,
                     accred_id = ?
                 WHERE area_id = ?"
            );

            $stmt->bind_param(
                "ssiii",
                $name,
                $desc,
                $order,
                $new_body,
                $id
            );

            $stmt->execute();
            $stmt->close();

            header(
                "Location: areas.php?flash=saved&body=" . $new_body
            );

            exit();
        }

    /*
    |--------------------------------------------------------------------------
    | DELETE AREA
    |--------------------------------------------------------------------------
    */
    } elseif ($action === 'delete') {

        $stmt = $conn->prepare(
            "SELECT
                (SELECT COUNT(*)
                 FROM files
                 WHERE area_id = ?)
                +
                (SELECT COUNT(*)
                 FROM file_area_links
                 WHERE area_id = ?) AS c"
        );

        $stmt->bind_param("ii", $id, $id);
        $stmt->execute();

        $used = (int)$stmt
            ->get_result()
            ->fetch_assoc()['c'];

        $stmt->close();

        if ($used > 0) {

            $error =
                "That area is used by " .
                $used .
                " file(s), so it can't be deleted.";

        } else {

            $stmt = $conn->prepare(
                "DELETE FROM areas
                 WHERE area_id = ?"
            );

            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            header(
                "Location: areas.php?flash=deleted&body=" . $body_id
            );

            exit();
        }
    }
}

/*
|--------------------------------------------------------------------------
| DATA FOR PAGE
|--------------------------------------------------------------------------
*/

$bodies = $areas_by_body = $units_by_body = [];

if ($tables_ok) {

    $bodies = $conn
        ->query(
            "SELECT accred_id, accred_name, sort_order
             FROM accreditations
             ORDER BY sort_order, accred_id"
        )
        ->fetch_all(MYSQLI_ASSOC);

    $rows = $conn
        ->query(
            "SELECT
                a.area_id,
                a.accred_id,
                a.area_name,
                a.description,
                a.sort_order,

                (SELECT COUNT(*)
                 FROM files f
                 WHERE f.area_id = a.area_id)

                +

                (SELECT COUNT(*)
                 FROM file_area_links l
                 WHERE l.area_id = a.area_id) AS total

             FROM areas a
             ORDER BY a.sort_order, a.area_id"
        )
        ->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $r) {

        $areas_by_body[
            (int)$r['accred_id']
        ][] = $r;
    }

    foreach (
        $conn
            ->query(
                "SELECT
                    accred_id,
                    program_id,
                    include_children
                 FROM accreditation_units"
            )
            ->fetch_all(MYSQLI_ASSOC)
        as $r
    ) {

        $units_by_body[
            (int)$r['accred_id']
        ][
            (int)$r['program_id']
        ] = (int)$r['include_children'];
    }
}

/*
|--------------------------------------------------------------------------
| BUILD PROGRAM TREE
|--------------------------------------------------------------------------
*/

$tree = [];

if ($programs) {

    $kids = [];

    foreach ($programs as $pid => $p) {

        $kids[
            (int)($p['parent_id'] ?? 0)
        ][] = $pid;
    }

    foreach ($kids as &$list) {

        usort(
            $list,
            function ($a, $b) use ($programs) {

                return strcasecmp(
                    $programs[$a]['program_name'],
                    $programs[$b]['program_name']
                );
            }
        );
    }

    unset($list);

    $walk = function ($parent, $depth)
        use (&$walk, &$tree, $kids) {

        foreach ($kids[$parent] ?? [] as $pid) {

            $tree[] = [$pid, $depth];

            $walk(
                $pid,
                $depth + 1
            );
        }
    };

    $walk(0, 0);
}

$flash_text = [

    'added' =>
        "Area added.",

    'saved' =>
        "Area saved.",

    'deleted' =>
        "Area deleted.",

    'body_added' =>
        "Accreditation added. Now choose its units and add its areas.",

    'body_saved' =>
        "Accreditation saved.",

    'body_deleted' =>
        "Accreditation deleted.",

    'units_saved' =>
        "Designated units saved.",
];
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Accreditation</title>

    <link
        rel="stylesheet"
        href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>"
    >

    <link
        rel="stylesheet"
        href="../css/categories.css?v=<?= @filemtime(__DIR__ . '/../css/categories.css') ?>"
    >

</head>

<body class="with-sidebar">

<?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

<main class="main-content">

    <div class="page-top">

        <div>

            <h2>Accreditation</h2>

            <p class="muted">
                Each accreditation has its own Areas and designated units.
                PACUCOA is available only to BSIT, SHS, and Engineering.
            </p>

            &nbsp;

        </div>

    </div>

    <?php if (isset($flash_text[$flash])): ?>

        <div class="alert alert-success">
            <?= htmlspecialchars($flash_text[$flash]) ?>
        </div>

    <?php endif; ?>

    <?php if ($error): ?>

        <div class="alert alert-error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>

    <?php if (!$tables_ok): ?>

        <div class="alert alert-error">
            Run <strong>accreditation.sql</strong> in phpMyAdmin first,
            then reload this page.
        </div>

    <?php else: ?>

        <!-- ADD ACCREDITATION -->

        <section class="dash-panel cat-add">

            <h3>Add an accreditation</h3>

            <form method="POST" class="cat-row">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= htmlspecialchars($csrf) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="add_body"
                >

                <input
                    type="text"
                    name="accred_name"
                    maxlength="60"
                    required
                    placeholder="Name, e.g. PACUCOA"
                    value="<?= htmlspecialchars($add_body_name) ?>"
                >

                <button
                    type="submit"
                    class="cat-btn"
                >
                    Add
                </button>

            </form>

        </section>


        <!-- ACCREDITATIONS -->

        <?php foreach ($bodies as $b):

            $bid = (int)$b['accred_id'];

            $areas =
                $areas_by_body[$bid] ?? [];

            $units =
                $units_by_body[$bid] ?? [];

            $is_pacucoa =
                is_pacucoa($b['accred_name']);

        ?>

        <section class="dash-panel cat-list acc-body">

            <!-- ACCREDITATION TITLE -->

            <form
                method="POST"
                class="cat-edit acc-title"
            >

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= htmlspecialchars($csrf) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="save_body"
                >

                <input
                    type="hidden"
                    name="accred_id"
                    value="<?= $bid ?>"
                >

                <input
                    type="number"
                    name="sort_order"
                    min="0"
                    max="9999"
                    class="area-order"
                    value="<?= (int)$b['sort_order'] ?>"
                    aria-label="Order"
                >

                <input
                    type="text"
                    name="accred_name"
                    maxlength="60"
                    required
                    class="acc-name"
                    value="<?= htmlspecialchars($b['accred_name']) ?>"
                    aria-label="Accreditation name"
                >

                <span class="cat-count">

                    <?= count($areas) ?>

                    area<?= count($areas) === 1 ? '' : 's' ?>

                </span>

                <button
                    type="submit"
                    class="cat-btn alt"
                >
                    Save
                </button>

            </form>


            <!-- DELETE ACCREDITATION -->

            <?php if (!$areas): ?>

                <form
                    method="POST"
                    class="area-del acc-del"
                    data-name="<?= htmlspecialchars($b['accred_name']) ?>"
                >

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= htmlspecialchars($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="delete_body"
                    >

                    <input
                        type="hidden"
                        name="accred_id"
                        value="<?= $bid ?>"
                    >

                    <button
                        type="submit"
                        class="cat-btn danger"
                    >
                        Delete accreditation
                    </button>

                </form>

            <?php endif; ?>


            <!-- DESIGNATED UNITS -->

            <details
                class="acc-units"
                <?= ($open_body === $bid || !$units) ? 'open' : '' ?>
            >

                <summary>

                    Designated units

                    <small>
                        <?= count($units) ?>
                    </small>

                    <?php if (!$units): ?>

                        <span class="acc-warn">
                            no unit yet, so nobody can send files here
                        </span>

                    <?php endif; ?>

                </summary>


                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= htmlspecialchars($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="save_units"
                    >

                    <input
                        type="hidden"
                        name="accred_id"
                        value="<?= $bid ?>"
                    >


                    <?php if ($is_pacucoa): ?>

                        <!-- PACUCOA ONLY -->

                        <div class="acc-tree">

                            <?php foreach ($programs as $pid => $program):

                                if (
                                    !allowed_pacucoa_program(
                                        $program['program_name']
                                    )
                                ) {
                                    continue;
                                }

                                $on =
                                    isset($units[$pid]);

                            ?>

                            <div class="acc-row">

                                <label class="fold-check">

                                    <input
                                        type="checkbox"
                                        name="unit[]"
                                        value="<?= (int)$pid ?>"
                                        <?= $on ? 'checked' : '' ?>
                                    >

                                    <?= htmlspecialchars(
                                        $program['program_name']
                                    ) ?>

                                </label>

                            </div>

                            <?php endforeach; ?>

                        </div>

                        <p class="muted">
                            PACUCOA access:
                            BSIT, SHS, and Engineering can access
                            all PACUCOA Areas.
                        </p>


                    <?php else: ?>

                        <!-- OTHER ACCREDITATIONS -->

                        <div class="acc-tree">

                            <?php foreach ($tree as [$pid, $depth]):

                                $on =
                                    isset($units[$pid]);

                            ?>

                            <div
                                class="acc-row"
                                style="
                                    padding-left:
                                    <?= (int)$depth * 22 ?>px
                                "
                            >

                                <label class="fold-check">

                                    <input
                                        type="checkbox"
                                        name="unit[]"
                                        value="<?= (int)$pid ?>"
                                        <?= $on ? 'checked' : '' ?>
                                    >

                                    <?= htmlspecialchars(
                                        $programs[$pid]['program_name']
                                    ) ?>

                                </label>

                                <label class="fold-check acc-sub">

                                    <input
                                        type="checkbox"
                                        name="sub[]"
                                        value="<?= (int)$pid ?>"
                                        <?= (
                                            $on &&
                                            $units[$pid] === 1
                                        )
                                            ? 'checked'
                                            : '' ?>
                                    >

                                    with sub-folders

                                </label>

                            </div>

                            <?php endforeach; ?>

                            <?php if (!$tree): ?>

                                <div class="dash-empty">
                                    No departments, programs or offices yet.
                                </div>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>


                    <button
                        type="submit"
                        class="cat-btn"
                    >
                        Save units
                    </button>

                </form>

            </details>


            <!-- AREAS -->

            <h4 class="acc-sub-title">
                Areas
            </h4>


            <?php if ($areas): ?>

                <ul>

                    <?php foreach ($areas as $a): ?>

                    <li>

                        <form
                            method="POST"
                            class="cat-edit"
                        >

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= htmlspecialchars($csrf) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="save"
                            >

                            <input
                                type="hidden"
                                name="accred_id"
                                value="<?= $bid ?>"
                            >

                            <input
                                type="hidden"
                                name="area_id"
                                value="<?= (int)$a['area_id'] ?>"
                            >

                            <input
                                type="number"
                                name="sort_order"
                                min="0"
                                max="9999"
                                class="area-order"
                                value="<?= (int)$a['sort_order'] ?>"
                                aria-label="Order"
                            >

                            <input
                                type="text"
                                name="area_name"
                                maxlength="100"
                                required
                                class="area-name"
                                value="<?= htmlspecialchars($a['area_name']) ?>"
                                aria-label="Area name"
                            >

                            <input
                                type="text"
                                name="description"
                                maxlength="255"
                                class="area-desc"
                                value="<?= htmlspecialchars($a['description'] ?? '') ?>"
                                placeholder="Description"
                                aria-label="Description"
                            >

                            <select
                                name="move_to"
                                class="acc-move"
                                aria-label="Accreditation"
                            >

                                <?php foreach ($bodies as $ob): ?>

                                    <option
                                        value="<?= (int)$ob['accred_id'] ?>"
                                        <?= (
                                            (int)$ob['accred_id'] === $bid
                                        )
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= htmlspecialchars(
                                            $ob['accred_name']
                                        ) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <span class="cat-count">

                                <?= (int)$a['total'] ?>

                                file<?= (
                                    (int)$a['total'] === 1
                                )
                                    ? ''
                                    : 's' ?>

                            </span>

                            <button
                                type="submit"
                                class="cat-btn alt"
                            >
                                Save
                            </button>

                        </form>


                        <?php if ((int)$a['total'] === 0): ?>

                            <form
                                method="POST"
                                class="area-del"
                                data-name="<?= htmlspecialchars($a['area_name']) ?>"
                            >

                                <input
                                    type="hidden"
                                    name="csrf"
                                    value="<?= htmlspecialchars($csrf) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="delete"
                                >

                                <input
                                    type="hidden"
                                    name="accred_id"
                                    value="<?= $bid ?>"
                                >

                                <input
                                    type="hidden"
                                    name="area_id"
                                    value="<?= (int)$a['area_id'] ?>"
                                >

                                <button
                                    type="submit"
                                    class="cat-btn danger"
                                >
                                    Delete
                                </button>

                            </form>

                        <?php else: ?>

                            <span
                                class="cat-lock"
                                title="Used by files, so it can't be deleted"
                            >
                                In use
                            </span>

                        <?php endif; ?>

                    </li>

                    <?php endforeach; ?>

                </ul>

            <?php else: ?>

                <div class="dash-empty">
                    No areas yet.
                </div>

            <?php endif; ?>


            <!-- ADD AREA -->

            <form
                method="POST"
                class="cat-row acc-add-area"
            >

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= htmlspecialchars($csrf) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="add"
                >

                <input
                    type="hidden"
                    name="accred_id"
                    value="<?= $bid ?>"
                >

                <input
                    type="text"
                    name="area_name"
                    maxlength="100"
                    required
                    placeholder="New area, e.g. Area 11"
                    value="<?= (
                        $open_body === $bid
                    )
                        ? htmlspecialchars($add_name)
                        : '' ?>"
                >

                <input
                    type="text"
                    name="description"
                    maxlength="255"
                    class="area-desc"
                    placeholder="What belongs here (optional)"
                    value="<?= (
                        $open_body === $bid
                    )
                        ? htmlspecialchars($add_desc)
                        : '' ?>"
                >

                <input
                    type="number"
                    name="sort_order"
                    min="0"
                    max="9999"
                    class="area-order"
                    placeholder="Order"
                    title="Position in the list (leave blank to put it last)"
                >

                <button
                    type="submit"
                    class="cat-btn"
                >
                    Add area
                </button>

            </form>

        </section>

        <?php endforeach; ?>


        <?php if (!$bodies): ?>

            <div class="dash-empty">
                No accreditation yet. Add one above.
            </div>

        <?php endif; ?>


        <p class="fold-hint">

            An area or accreditation can only be deleted while nothing uses it.
            Taking a unit off the designated list does not delete its old files;
            it just can't send new ones there.

        </p>

    <?php endif; ?>

</main>

<script
    src="../js/areas.js?v=<?= @filemtime(__DIR__ . '/../js/areas.js') ?>"
></script>

</body>

</html>