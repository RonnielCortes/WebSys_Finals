<?php
// QA "ping": send a file back to its program for revision (status -> needs_revision).
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
$remark  = trim($_POST['remark'] ?? '');

// Where to go afterwards: the Files page (keeping its folder/filters) or the dashboard
$back = 'dashboard.php?view=out&';
if (($_POST['return_to'] ?? '') === 'files') {
    parse_str((string)($_POST['qs'] ?? ''), $keep);
    unset($keep['flash']);
    $back = 'files.php?' . http_build_query($keep) . '&';
}

if (!$file_id || $remark === '') {
    header("Location: " . $back . "flash=ping_failed");
    exit();
}
$remark = mb_substr($remark, 0, 500);

try {
    $conn->begin_transaction();

    // Only a current (non-archived) file that is still waiting for QA can be pinged
    $stmt = $conn->prepare(
        "UPDATE files SET status = 'needs_revision'
         WHERE file_id = ? AND is_archived = 0 AND status IN ('sent','received','under_review')"
    );
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException("File not pingable");
    }
    $stmt->close();

    $action = 'flagged_for_revision';
    $stmt = $conn->prepare("INSERT INTO file_logs (file_id, action, performed_by, remarks) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isis", $file_id, $action, $qa_id, $remark);
    $stmt->execute();
    $stmt->close();

    // Tell the person who uploaded it
    $stmt = $conn->prepare("SELECT uploaded_by, file_path FROM files WHERE file_id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $message = mb_substr("QA sent back " . display_name($file['file_path']) . " for revision: " . $remark, 0, 250);
    $to = (int)$file['uploaded_by'];
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, file_id, message) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $to, $file_id, $message);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    header("Location: " . $back . "flash=pinged");
} catch (Throwable $e) {
    $conn->rollback();
    header("Location: " . $back . "flash=ping_failed");
}
exit();