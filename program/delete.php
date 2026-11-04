<?php
// ============================================
// program/delete.php
//   POST file_id -> Program withdraws one of ITS OWN files.
//   Soft delete only: is_archived = 1. The real file stays in uploads/
//   and the row stays in the database, so it can be restored if needed.
//   Allowed only while status is 'sent' or 'received' (QA has not started).
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('program');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// Deleting must never happen from a plain link / URL typed in the browser
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

$user_id    = (int)$_SESSION['user_id'];
$program_id = (int)($_SESSION['program_id'] ?? 0);
$file_id    = (int)($_POST['file_id'] ?? 0);

if (!$program_id || !$file_id) {
    header("Location: dashboard.php?flash=cannot_delete");
    exit();
}

// The file must belong to THIS program
$stmt = $conn->prepare(
    "SELECT file_id, file_path, status, is_archived
     FROM files WHERE file_id = ? AND program_id = ?"
);
$stmt->bind_param("ii", $file_id, $program_id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file || $file['is_archived'] || !program_can_delete($file['status'])) {
    header("Location: dashboard.php?flash=cannot_delete");
    exit();
}

try {
    $conn->begin_transaction();

    // Only archive if it is STILL current and STILL in the same status
    // (QA may have opened it a second ago).
    $stmt = $conn->prepare(
        "UPDATE files SET is_archived = 1
         WHERE file_id = ? AND program_id = ? AND is_archived = 0 AND status = ?"
    );
    $stmt->bind_param("iis", $file_id, $program_id, $file['status']);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException("File changed before it could be deleted.");
    }
    $stmt->close();

    $action  = 'deleted_by_program';
    $remarks = "Withdrawn by the program before QA review";
    $stmt = $conn->prepare(
        "INSERT INTO file_logs (file_id, action, performed_by, remarks) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("isis", $file_id, $action, $user_id, $remarks);
    $stmt->execute();
    $stmt->close();

    notify_active_qa($conn, $file_id, "File withdrawn by the program: " . display_name($file['file_path']));

    $conn->commit();
    header("Location: dashboard.php?flash=deleted");
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    header("Location: dashboard.php?flash=cannot_delete");
    exit();
}