<?php
// ============================================
// program/upload.php
//   upload.php              -> send a NEW file to QA
//   upload.php?replace=ID   -> replace a file. Allowed only while the file is
//                              'sent' / 'received' (QA has not started) or
//                              'needs_revision' (QA sent it back).
//                              The old version is archived, not overwritten.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('program');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/area_links.php';   // "Also link to"
require_once __DIR__ . '/../includes/accreditation.php';

$active_page = 'upload';

$user_id    = (int)$_SESSION['user_id'];

// A user can belong to several programs/offices
$unit_ids = session_program_ids();
if (!$unit_ids) {
    die("Your account is not assigned to a program/office yet. Please contact the Admin.");
}
$unit_list  = id_list($unit_ids);            // integers only, safe inside IN (...)
$unit_names = program_names($conn, $unit_ids);
if (!$unit_names) {
    die("Your program/office no longer exists. Please contact the Admin.");
}

// Categories for the dropdown
// (each one remembers its Area: 0 = general, otherwise the Area it belongs to)
$categories = [];
$cat_result = $conn->query("SELECT category_id, category_name, area_id FROM file_categories ORDER BY category_name");
while ($row = $cat_result->fetch_assoc()) {
    $categories[(int)$row['category_id']] = ['name' => $row['category_name'], 'area' => (int)$row['area_id']];
}

// Areas for the dropdown (Area I, Area II, ... set by the Admin)
// Only the Areas of the accreditation bodies this program/office is designated for
$my_area_ids = accessible_area_ids($conn, $unit_ids);
$areas = load_areas($conn, $my_area_ids);

// --------------------------------------------
// Re-submit mode: the file being replaced must belong to THIS program,
// still be the current version, and be waiting for revision.
// --------------------------------------------
$replace_id = (int)($_GET['replace'] ?? $_POST['replace'] ?? 0);
$old = null;
if ($replace_id) {
    $stmt = $conn->prepare(
        "SELECT file_id, file_path, status, category_id, area_id, is_archived, program_id
         FROM files WHERE file_id = ? AND program_id IN ($unit_list)"
    );
    $stmt->bind_param("i", $replace_id);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$old || $old['is_archived'] || !program_can_replace($old['status'])) {
        header("Location: dashboard.php?flash=cannot_replace");
        exit();
    }
}

// Which of the user's programs/offices this file is for:
//   re-submit/replace -> the same one as the old file
//   new file          -> the one picked in the form (or the only one the user has)
$unit_posted = (int)($_POST['unit'] ?? 0);
if ($old) {
    $program_id = (int)$old['program_id'];
} elseif (in_array($unit_posted, $unit_ids, true)) {
    $program_id = $unit_posted;
} else {
    $program_id = $unit_ids[0];
}
$program_name = $unit_names[$program_id] ?? 'your program';

$error = "";
// true  = QA sent it back and Program is fixing it (re-submit)
// false = QA has not started yet and Program just swaps the file (replace)
$is_resubmit = $old && $old['status'] === 'needs_revision';
$selected_category = $old ? (int)$old['category_id'] : 0;
$selected_area     = $old ? (int)$old['area_id'] : 0;     // 0 = normal file (no area); a replacement stays in the same area
if ($selected_area && !isset($areas[$selected_area])) {   // the old file's Area is shown even if the designation changed since
    $areas = $areas + load_areas($conn, [$selected_area]);
}
$selected_links    = $old ? area_link_ids($conn, (int)$old['file_id']) : [];   // other Areas the file is also linked to
$choose_new   = false;   // true = the Program user picked "+ Add new category"
$new_cat_text = "";
$note = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cat_choice = $_POST['category_id'] ?? '';
    $note = trim($_POST['note'] ?? '');
    if (!$old) {
        $selected_area = (int)($_POST['area_id'] ?? 0);
    }
    // the "Also link to" ticks: real Areas only, never the main one, and none for normal files
    $selected_links = $selected_area ? clean_link_ids($_POST['link_areas'] ?? [], $areas, $selected_area) : [];

    if ($cat_choice === 'new') {
        // a brand-new category typed by the Program user
        $choose_new        = true;
        $selected_category = 0;
        $new_cat_text      = clean_category_name($_POST['new_category'] ?? '');
    } else {
        $selected_category = (int)$cat_choice;
    }

    // If the whole request was bigger than PHP's post_max_size,
    // PHP throws away both $_POST and $_FILES.
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $error = "That file is too big for the server settings (post_max_size is "
            . ini_get('post_max_size') . "). The system allows up to 10 MB.";
    } elseif (!$old && count($unit_ids) > 1 && !in_array($unit_posted, $unit_ids, true)) {
        $error = "Please choose which program/office this file is for.";
    } elseif ($selected_area && !isset($areas[$selected_area])) {
        $error = "Please choose a valid area.";
    } elseif ($choose_new && $selected_area) {
        $error = "New categories can only be added to normal files. Please pick one from the list.";
    } elseif ($choose_new && ($msg = category_name_error($new_cat_text)) !== '') {
        $error = $msg;
    } elseif (!$choose_new && !isset($categories[$selected_category])) {
        $error = "Please choose a category.";
    } elseif (!$choose_new && $categories[$selected_category]['area'] !== $selected_area) {
        $error = "That category does not belong to the place you chose. Please pick again.";
    } else {
        $error = validate_upload($_FILES['document'] ?? null);
    }

    if ($error === '') {
        $path = store_uploaded_file(
            $_FILES['document']['tmp_name'],
            $_FILES['document']['name'],
            $program_name
        );

        if ($path === false) {
            $error = "The file could not be saved on the server. Check that the uploads folder is writable.";
        } else {
            try {
                $conn->begin_transaction();

                // 0) a new category? reuse an existing one with the same name, otherwise create it
                if ($choose_new) {
                    $selected_category = find_or_create_category($conn, $new_cat_text, 0);
                }

                // 1) the new file (status always starts as 'sent')
                $replaces = $old ? (int)$old['file_id'] : null;
                $stmt = $conn->prepare(
                    "INSERT INTO files (file_path, uploaded_by, program_id, category_id, area_id, status, replaces_file_id)
                     VALUES (?, ?, ?, ?, ?, 'sent', ?)"
                );
                $area_value = $selected_area ?: null;   // NULL when the old file had no area
                $stmt->bind_param("siiiii", $path, $user_id, $program_id, $selected_category, $area_value, $replaces);
                $stmt->execute();
                $new_id = (int)$conn->insert_id;
                $stmt->close();

                // 1b) the extra Areas this file is also linked to (not a copy, just a mark)
                save_area_links($conn, $new_id, $selected_links);

                // 2) archive the old version (only if it is still the current one
                //    AND its status has not changed since the page was opened)
                if ($old) {
                    $stmt = $conn->prepare(
                        "UPDATE files SET is_archived = 1
                         WHERE file_id = ? AND program_id = ? AND is_archived = 0 AND status = ?"
                    );
                    $stmt->bind_param("iis", $old['file_id'], $program_id, $old['status']);
                    $stmt->execute();
                    if ($stmt->affected_rows !== 1) {
                        throw new RuntimeException("QA already started working on this file, so it can't be replaced anymore.");
                    }
                    $stmt->close();

                    // history on the OLD file too
                    $action  = 'replaced';
                    $remarks = "Replaced by file #" . $new_id;
                    $stmt = $conn->prepare(
                        "INSERT INTO file_logs (file_id, action, performed_by, remarks) VALUES (?, ?, ?, ?)"
                    );
                    $stmt->bind_param("isis", $old['file_id'], $action, $user_id, $remarks);
                    $stmt->execute();
                    $stmt->close();
                }

                // 3) history on the NEW file
                $action  = $is_resubmit ? 'resubmitted' : 'sent';
                $remarks = $note;
                if ($old) {
                    $remarks = "Replaces file #" . $old['file_id'] . ($note !== '' ? ". " . $note : "");
                }
                $stmt = $conn->prepare(
                    "INSERT INTO file_logs (file_id, action, performed_by, remarks) VALUES (?, ?, ?, ?)"
                );
                $stmt->bind_param("isis", $new_id, $action, $user_id, $remarks);
                $stmt->execute();
                $stmt->close();

                // 4) tell every active QA user
                $prefix = $is_resubmit ? "Re-submitted file from "
                    : ($old ? "Replaced file from " : "New file from ");
                notify_active_qa($conn, $new_id, $prefix . $program_name . ": " . display_name($path));

                $conn->commit();

                header("Location: dashboard.php?flash=" . ($is_resubmit ? 'resubmitted' : ($old ? 'replaced' : 'uploaded')));
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                $shown = ($e instanceof RuntimeException);
                // don't leave an orphan file on disk if the database part failed
                $full = dirname(__DIR__) . '/' . $path;
                if (is_file($full)) {
                    unlink($full);
                }
                $error = $shown ? $e->getMessage() : "Something went wrong while saving. Please try again.";
            }
        }
    }
}

$page_title = $is_resubmit ? 'Re-submit File' : ($old ? 'Replace File' : 'Upload File');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?= $page_title ?></title>
    <link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
    <link rel="stylesheet" href="../css/upload.css?v=<?= @filemtime(__DIR__ . '/../css/upload.css') ?>">
</head>

<body class="with-sidebar">

    <?php include __DIR__ . '/../includes/program_sidebar.php'; ?>

    <main class="main-content upload-page">
        <div class="upload-wrap">

            <h2><?= $page_title ?></h2>
            <p class="muted">Sending as <strong><?= (!$old && count($unit_ids) > 1) ? 'one of your programs/offices' : htmlspecialchars($program_name) ?></strong></p>

            <?php if ($old): ?>
                <div class="alert alert-info">
                    You are replacing <strong><?= htmlspecialchars(display_name($old['file_path'])) ?></strong>.
                    The old version is kept in your archive, not erased.
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="upload.php<?= $old ? '?replace=' . (int)$old['file_id'] : '' ?>"
                enctype="multipart/form-data" class="upload-card">
                <?php if ($old): ?>
                    <input type="hidden" name="replace" value="<?= (int)$old['file_id'] ?>">
                <?php endif; ?>

                <?php if (!$old && count($unit_ids) > 1): ?>
                    <div class="upload-field">
                        <label for="unit">Send for</label>
                        <select name="unit" id="unit" required>
                            <option value="">Select program/office</option>
                            <?php foreach ($unit_names as $uid => $uname): ?>
                                <option value="<?= (int)$uid ?>" <?= $unit_posted === $uid ? 'selected' : '' ?>><?= htmlspecialchars($uname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if ($areas): ?>
                    <div class="upload-field">
                        <label for="area_id">Send to</label>
                        <?php if ($old): ?>
                            <input type="text" id="area_id" disabled
                                value="<?= htmlspecialchars(isset($areas[$selected_area]) ? $areas[$selected_area]['area_name'] . ($areas[$selected_area]['description'] ? ' - ' . $areas[$selected_area]['description'] : '') : 'Normal files (no area)') ?>">
                            <input type="hidden" id="area_value" value="<?= (int)$selected_area ?>">
                        <?php else: ?>
                            <select name="area_id" id="area_id">
                                <option value="0">Normal files (no area)</option>
                                <?php foreach ($areas as $aid => $a): ?>
                                    <option value="<?= $aid ?>" <?= $selected_area === $aid ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($a['area_name'] . ($a['description'] ? ' - ' . $a['description'] : '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($areas): ?>
                    <div class="upload-field" id="link-field" <?= $selected_area ? '' : 'hidden' ?>>
                        <span class="field-label">Also link to <span class="optional">(optional)</span></span>
                        <div class="link-checks">
                            <?php foreach ($areas as $aid => $a): ?>
                                <label class="link-check" data-area="<?= $aid ?>">
                                    <input type="checkbox" name="link_areas[]" value="<?= $aid ?>" <?= in_array($aid, $selected_links, true) ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($a['area_name'] . ($a['description'] ? ' - ' . $a['description'] : '')) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small class="muted">The same file will also show up in these areas. It is not copied.</small>
                    </div>
                <?php endif; ?>

                <div class="upload-field">
                    <label for="category_id">Category</label>
                    <select name="category_id" id="category_id" required>
                        <option value="">Select a category</option>
                        <?php foreach ($categories as $id => $c): ?>
                            <option value="<?= $id ?>" data-area="<?= (int)$c['area'] ?>" <?= (!$choose_new && $selected_category === $id) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="new" data-area="0" <?= $choose_new ? 'selected' : '' ?>>+ Add new category&hellip;</option>
                    </select>
                    <input type="text" name="new_category" id="new_category" maxlength="50"
                           placeholder="Type the new category name" value="<?= htmlspecialchars($new_cat_text) ?>"
                           <?= $choose_new ? '' : 'hidden' ?>>
                </div>

                <div class="upload-field">
                    <span class="field-label"><?= $old ? 'New file' : 'File' ?></span>
                    <label for="document" class="dropzone" id="dropzone">
                        <?= icon('upload') ?>
                        <span class="dropzone-title" id="dropzone-title"><?= $old ? 'Choose the new file' : 'Choose a file' ?></span>
                        <span class="dropzone-hint" id="dropzone-hint">PDF, DOCX or XLSX &middot; up to 10 MB</span>
                        <input type="file" name="document" id="document" accept=".pdf,.docx,.xlsx" required>
                    </label>
                </div>

                <div class="upload-field">
                    <label for="note">Note for QA <span class="optional">(optional)</span></label>
                    <textarea name="note" id="note" rows="3" maxlength="500"
                        placeholder="<?= $is_resubmit ? 'What did you fix?' : ($old ? 'Why are you replacing it?' : 'Anything QA should know?') ?>"><?= htmlspecialchars($note) ?></textarea>
                </div>

                <div class="upload-actions">
                    <a href="dashboard.php" class="upload-cancel">Cancel</a>
                    <button type="submit" class="upload-submit"><?= $is_resubmit ? 'Re-submit' : ($old ? 'Replace file' : 'Send to QA') ?></button>
                </div>
            </form>

        </div>
    </main>

    <script src="../js/upload.js?v=<?= @filemtime(__DIR__ . '/../js/upload.js') ?>"></script>
    <script src="../js/area_picker.js?v=<?= @filemtime(__DIR__ . '/../js/area_picker.js') ?>"></script>
</body>

</html>