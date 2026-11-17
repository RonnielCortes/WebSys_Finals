<?php
// ============================================
// program/edit_links.php?id=ID — a Program user chooses which OTHER Areas
// one of their files is also linked to. The file is not copied: it just shows up
// in those Areas too. Works only for files inside an Area, and only for your own files.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('program');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/area_links.php';

$active_page = 'files';

$unit_ids = session_program_ids();
if (!$unit_ids) {
    die("Your account is not assigned to a program/office yet. Please contact the Admin.");
}
$unit_list = id_list($unit_ids);          // integers only, safe inside IN (...)

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$file_id = (int)($_GET['id'] ?? $_POST['file_id'] ?? 0);

// The file must be YOURS (one of your programs/offices), still current, and inside an Area
$stmt = $conn->prepare(
    "SELECT file_id, file_path, area_id, is_archived
     FROM files WHERE file_id = ? AND program_id IN ($unit_list)"
);
$stmt->bind_param("i", $file_id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file || $file['is_archived'] || $file['area_id'] === null) {
    header("Location: dashboard.php?flash=cannot_link");
    exit();
}
$main_area = (int)$file['area_id'];

$areas = [];
$res = $conn->query("SELECT area_id, area_name, description FROM areas ORDER BY sort_order, area_id");
while ($r = $res->fetch_assoc()) {
    $areas[(int)$r['area_id']] = $r;
}

$selected = area_link_ids($conn, $file_id);
$error    = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = clean_link_ids($_POST['link_areas'] ?? [], $areas, $main_area);

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = "The page expired. Please try again.";
    } else {
        try {
            $conn->begin_transaction();
            save_area_links($conn, $file_id, $selected);
            $conn->commit();
            header("Location: dashboard.php?flash=links_saved");
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            $error = "The links could not be saved. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link to other areas</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
    <link rel="stylesheet" href="../css/upload.css?v=<?= @filemtime(__DIR__ . '/../css/upload.css') ?>">
</head>

<body class="with-sidebar">

    <?php include __DIR__ . '/../includes/program_sidebar.php'; ?>

    <main class="main-content upload-page">
        <div class="upload-wrap">

            <h2>Link to other areas</h2>
            <p class="muted">
                <strong><?= htmlspecialchars(display_name($file['file_path'])) ?></strong><br>
                Main area: <strong><?= htmlspecialchars($areas[$main_area]['area_name'] ?? '') ?></strong>
            </p>

            <div class="alert alert-info">
                The file is <strong>not copied</strong>. It stays one file, and it also shows up in the areas you tick.
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="edit_links.php?id=<?= $file_id ?>" class="upload-card">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="file_id" value="<?= $file_id ?>">

                <div class="upload-field">
                    <span class="field-label">Also show this file in</span>
                    <div class="link-checks">
                        <?php foreach ($areas as $aid => $a): ?>
                            <?php if ($aid === $main_area) { continue; } ?>
                            <label class="link-check">
                                <input type="checkbox" name="link_areas[]" value="<?= $aid ?>" <?= in_array($aid, $selected, true) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($a['area_name'] . ($a['description'] ? ' - ' . $a['description'] : '')) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="upload-actions">
                    <a href="dashboard.php" class="upload-cancel">Cancel</a>
                    <button type="submit" class="upload-submit">Save links</button>
                </div>
            </form>

        </div>
    </main>

</body>

</html>