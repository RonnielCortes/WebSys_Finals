<?php
// ============================================
// download.php?id=<file_id>
// The ONLY way to get a file out of uploads/.
// Checks login + role/program before sending anything.
// ============================================
require_once __DIR__ . '/includes/check.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/db.php';

require_login();

$file_id = (int)($_GET['id'] ?? 0);
if (!$file_id) {
    http_response_code(400);
    die("Invalid file.");
}

$stmt = $conn->prepare("SELECT file_path, program_id FROM files WHERE file_id = ?");
$stmt->bind_param("i", $file_id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    http_response_code(404);
    die("File not found.");
}

// Role / program rule (Admin & QA: all files, Program: own program only)
if (!can_access_file($file['program_id'])) {
    http_response_code(403);
    die("You don't have access to this file.");
}

// Resolve the real location and make sure it really lives inside uploads/
// (stops a tampered path like "../config/db.php" from leaking other files)
$uploads_root = realpath(__DIR__ . '/uploads');
$full_path    = realpath(__DIR__ . '/' . $file['file_path']);

if (
    $uploads_root === false || $full_path === false ||
    strpos($full_path, $uploads_root . DIRECTORY_SEPARATOR) !== 0 ||
    !is_file($full_path)
) {
    http_response_code(404);
    die("The file is missing on the server.");
}

$download_name = display_name($file['file_path']);
$ascii_name    = str_replace(['"', '\\', "\r", "\n"], '', $download_name);

// Let other pages keep working while a big file is being sent
session_write_close();

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $ascii_name . '"; filename*=UTF-8\'\'' . rawurlencode($download_name));
header('Content-Length: ' . filesize($full_path));
header('X-Content-Type-Options: nosniff');

readfile($full_path);
exit();