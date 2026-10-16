<?php
// ============================================
// program/upload.php
//   upload.php              -> send a NEW file to QA
//   upload.php?replace=ID   -> re-submit a file QA flagged (needs_revision).
//                              The old version is archived, not overwritten.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('program');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$active_page = 'upload';

$user_id    = (int)$_SESSION['user_id'];
$program_id = (int)($_SESSION['program_id'] ?? 0);

if (!$program_id) {
    die("Your account is not assigned to a program/office yet. Please contact the Admin.");
}

// This user's program/office name (used for the folder name and messages)
$stmt = $conn->prepare("SELECT program_name FROM programs WHERE program_id = ?");
$stmt->bind_param("i", $program_id);
$stmt->execute();
$program = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$program) {
    die("Your program/office no longer exists. Please contact the Admin.");
}
$program_name = $program['program_name'];

// Categories for the dropdown
$categories = [];
$cat_result = $conn->query("SELECT category_id, category_name FROM file_categories ORDER BY category_name");
while ($row = $cat_result->fetch_assoc()) {
    $categories[(int)$row['category_id']] = $row['category_name'];
}

// --------------------------------------------
// Re-submit mode: the file being replaced must belong to THIS program,
// still be the current version, and be waiting for revision.
// --------------------------------------------
$replace_id = (int)($_GET['replace'] ?? $_POST['replace'] ?? 0);
$old = null;
if ($replace_id) {
    $stmt = $conn->prepare(
        "SELECT file_id, file_path, status, category_id, is_archived
         FROM files WHERE file_id = ? AND program_id = ?"
    );
    $stmt->bind_param("ii", $replace_id, $program_id);
    $stmt->execute();
    $old = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$old || $old['is_archived'] || $old['status'] !== 'needs_revision') {
        header("Location: dashboard.php?flash=cannot_replace");
        exit();
    }
}

$error = "";
$selected_category = $old ? (int)$old['category_id'] : 0;
$note = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_category = (int)($_POST['category_id'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    // If the whole request was bigger than PHP's post_max_size,
    // PHP throws away both $_POST and $_FILES.
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $error = "That file is too big for the server settings (post_max_size is "
               . ini_get('post_max_size') . "). The system allows up to 10 MB.";
    } elseif (!isset($categories[$selected_category])) {
        $error = "Please choose a category.";
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

                // 1) the new file (status always starts as 'sent')
                $replaces = $old ? (int)$old['file_id'] : null;
                $stmt = $conn->prepare(
                    "INSERT INTO files (file_path, uploaded_by, program_id, category_id, status, replaces_file_id)
                     VALUES (?, ?, ?, ?, 'sent', ?)"
                );
                $stmt->bind_param("siiii", $path, $user_id, $program_id, $selected_category, $replaces);
                $stmt->execute();
                $new_id = (int)$conn->insert_id;
                $stmt->close();

                // 2) re-submit: archive the old version (only if it is still the current one)
                if ($old) {
                    $stmt = $conn->prepare(
                        "UPDATE files SET is_archived = 1
                         WHERE file_id = ? AND is_archived = 0 AND status = 'needs_revision'"
                    );
                    $stmt->bind_param("i", $old['file_id']);
                    $stmt->execute();
                    if ($stmt->affected_rows !== 1) {
                        throw new RuntimeException("The file was already replaced or changed.");
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
                $action  = $old ? 'resubmitted' : 'sent';
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
                $qa = $conn->query(
                    "SELECT u.user_id FROM users u
                     JOIN roles r ON u.role_id = r.role_id
                     WHERE r.role_name = 'qa' AND u.account_status = 'approved' AND u.is_active = 1"
                );
                $message = ($old ? "Re-submitted file from " : "New file from ")
                         . $program_name . ": " . display_name($path);
                $message = mb_substr($message, 0, 250);

                $stmt = $conn->prepare("INSERT INTO notifications (user_id, file_id, message) VALUES (?, ?, ?)");
                while ($q = $qa->fetch_assoc()) {
                    $qa_id = (int)$q['user_id'];
                    $stmt->bind_param("iis", $qa_id, $new_id, $message);
                    $stmt->execute();
                }
                $stmt->close();

                $conn->commit();

                header("Location: dashboard.php?flash=" . ($old ? 'resubmitted' : 'uploaded'));
                exit();

            } catch (Throwable $e) {
                $conn->rollback();
                // don't leave an orphan file on disk if the database part failed
                $full = dirname(__DIR__) . '/' . $path;
                if (is_file($full)) {
                    unlink($full);
                }
                $error = "Something went wrong while saving. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $old ? 'Re-submit File' : 'Upload File' ?></title>
<link rel="stylesheet" href="../css/style.css">
</head>
<body class="with-sidebar">

<?php include __DIR__ . '/../includes/program_sidebar.php'; ?>

<main class="main-content">
    <h2><?= $old ? 'Re-submit File' : 'Upload File' ?></h2>
    <p class="muted">
        Sending as <strong><?= htmlspecialchars($program_name) ?></strong>
        &middot; PDF, DOCX or XLSX &middot; up to 10 MB
    </p>

    <?php if ($old): ?>
        <div class="alert alert-info">
            You are replacing <strong><?= htmlspecialchars(display_name($old['file_path'])) ?></strong>.
            The old version is kept in your history, not deleted.
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="upload.php<?= $old ? '?replace=' . (int)$old['file_id'] : '' ?>"
          enctype="multipart/form-data" class="edit-form">
        <?php if ($old): ?>
            <input type="hidden" name="replace" value="<?= (int)$old['file_id'] ?>">
        <?php endif; ?>

        <label for="category_id">Category</label>
        <select name="category_id" id="category_id" required>
            <option value="">-- Select a category --</option>
            <?php foreach ($categories as $id => $name): ?>
                <option value="<?= $id ?>" <?= $selected_category === $id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($name) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="document"><?= $old ? 'New version of the file' : 'File' ?></label>
        <input type="file" name="document" id="document" accept=".pdf,.docx,.xlsx" required>

        <label for="note">Note for QA (optional)</label>
        <textarea name="note" id="note" rows="3" maxlength="500"
                  placeholder="<?= $old ? 'What did you fix?' : 'Anything QA should know?' ?>"><?= htmlspecialchars($note) ?></textarea>

        <button type="submit"><?= $old ? 'Re-submit' : 'Send to QA' ?></button>
    </form>

    <p><a href="dashboard.php">&larr; Back to My Files</a></p>
</main>

</body>
</html>