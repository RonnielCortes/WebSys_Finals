<?php
// ============================================
// qa/review.php  (POST only)
//   action=approve    -> file becomes 'approved'
//   action=send_back  -> file becomes 'needs_revision' (needs a note for Program)
// Both write a log row and notify the Program user who sent the file.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('qa');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

$qa_id   = (int)$_SESSION['user_id'];
$file_id = (int)($_POST['file_id'] ?? 0);
$action  = $_POST['action'] ?? '';
$remarks = mb_substr(trim($_POST['remarks'] ?? ''), 0, 500);

// go back to the same tab / filter / week
function review_redirect($flash)
{
    parse_str((string)($_POST['back'] ?? ''), $b);
    $keep = [];
    foreach (['view', 'program', 'q', 'w'] as $k) {
        if (isset($b[$k]) && is_string($b[$k]) && $b[$k] !== '') {
            $keep[$k] = $b[$k];
        }
    }
    $keep['flash'] = $flash;
    header("Location: dashboard.php?" . http_build_query($keep));
    exit();
}

if (!$file_id || !in_array($action, ['approve', 'send_back'], true)) {
    review_redirect('cannot_review');
}
if ($action === 'send_back' && $remarks === '') {
    review_redirect('need_note');
}

try {
    $stmt = $conn->prepare("SELECT file_path, uploaded_by, status, is_archived FROM files WHERE file_id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // only current files that are still waiting for QA
    if (!$file || $file['is_archived'] || !in_array($file['status'], ['sent', 'received', 'under_review'], true)) {
        review_redirect('cannot_review');
    }

    $new_status = ($action === 'approve') ? 'approved' : 'needs_revision';
    $log_action = ($action === 'approve') ? 'approved' : 'flagged_for_revision';
    $name       = display_name($file['file_path']);

    $conn->begin_transaction();

    // the status in the WHERE makes a double click / another QA user harmless
    $stmt = $conn->prepare(
        "UPDATE files SET status = ?
         WHERE file_id = ? AND is_archived = 0 AND status = ?"
    );
    $stmt->bind_param("sis", $new_status, $file_id, $file['status']);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException("Already handled");
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO file_logs (file_id, action, performed_by, remarks) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isis", $file_id, $log_action, $qa_id, $remarks);
    $stmt->execute();
    $stmt->close();

    $message = ($action === 'approve')
        ? "QA approved your file: " . $name
        : "QA asked you to fix: " . $name . " - " . $remarks;
    $message = mb_substr($message, 0, 250);
    $to = (int)$file['uploaded_by'];
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, file_id, message) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $to, $file_id, $message);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    review_redirect($action === 'approve' ? 'approved' : 'sent_back');
} catch (Throwable $e) {
    $conn->rollback();
    review_redirect('cannot_review');
}
