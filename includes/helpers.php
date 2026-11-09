<?php
// ============================================
// Shared helper functions (used by admin, qa, program, download)
// ============================================

/**
 * Turns a stored path into the name the user should see.
 * Uploaded files are saved as "20261011_134500_Grading_Sheet.pdf"
 * (timestamp prefix so two uploads never overwrite each other),
 * so we strip that prefix when displaying or downloading.
 */
function display_name($file_path)
{
    $name = basename($file_path);
    return preg_replace('/^\d{8}_\d{6}_/', '', $name);
}

/**
 * Loads every program/office into an array keyed by program_id,
 * so breadcrumbs can be built without extra queries.
 */
function load_programs($conn)
{
    $programs = [];
    $result = $conn->query("SELECT program_id, program_name, parent_id, level FROM programs");
    while ($row = $result->fetch_assoc()) {
        $programs[(int)$row['program_id']] = $row;
    }
    return $programs;
}

/**
 * Builds "College > CASE-IT > BSIT" by walking up parent_id.
 */
function program_breadcrumb($programs, $program_id)
{
    $parts = [];
    $seen = [];
    $current = (int)$program_id;

    while ($current && isset($programs[$current]) && !isset($seen[$current])) {
        $seen[$current] = true; // guards against an accidental parent_id loop
        array_unshift($parts, $programs[$current]['program_name']);
        $current = (int)$programs[$current]['parent_id'];
    }
    return implode(' > ', $parts);
}

/**
 * Whether the logged-in user is allowed to see/download a file
 * that belongs to $file_program_id.
 *   admin, qa -> every file
 *   program   -> only their own program's files
 */
function can_access_file($file_program_id)
{
    $role = $_SESSION['role_name'] ?? '';

    if ($role === 'admin' || $role === 'qa') {
        return true;
    }
    if ($role === 'program') {
        return isset($_SESSION['program_id'])
            && (int)$_SESSION['program_id'] === (int)$file_program_id;
    }
    return false;
}

/**
 * The 7 possible values of files.status, in the order of the file's journey.
 */
function file_statuses()
{
    return ['sent', 'received', 'under_review', 'needs_revision', 'approved', 'rejected', 'returned'];
}

/**
 * "under_review" -> "Under Review"
 */
function status_label($status)
{
    return ucwords(str_replace('_', ' ', $status));
}

// ============================================
// Upload helpers (used by program/upload.php)
// ============================================

function upload_allowed_extensions()
{
    return ['pdf', 'docx', 'xlsx'];
}

function upload_max_bytes()
{
    return 10 * 1024 * 1024; // 10 MB
}

/**
 * "College > CASE-IT > BSIT" style names -> a safe folder name like "BSIT".
 */
function safe_folder_name($name)
{
    $folder = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($name));
    $folder = trim($folder, '_');
    return $folder === '' ? 'program' : $folder;
}

/**
 * Keeps the file readable but removes anything risky
 * ("Grading Sheet (final).PDF" -> "Grading_Sheet_final.pdf").
 */
function safe_file_name($original)
{
    $ext  = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $base = pathinfo($original, PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9 _-]+/', '', $base);
    $base = preg_replace('/\s+/', '_', trim($base));
    $base = substr(trim($base, '_-'), 0, 80);
    if ($base === '') {
        $base = 'file';
    }
    return $base . '.' . $ext;
}

/**
 * Checks one entry of $_FILES. Returns '' when it is fine,
 * otherwise a message that can be shown to the user.
 */
function validate_upload($file)
{
    if (!is_array($file) || !isset($file['error'])) {
        return "Please choose a file.";
    }

    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return "Please choose a file.";
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "That file is too big for the server settings (upload_max_filesize is "
                . ini_get('upload_max_filesize') . "). The system allows up to 10 MB.";
        default:
            return "The upload failed (error code " . (int)$file['error'] . "). Please try again.";
    }

    if ($file['size'] <= 0) {
        return "That file is empty.";
    }
    if ($file['size'] > upload_max_bytes()) {
        return "That file is larger than 10 MB.";
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, upload_allowed_extensions(), true)) {
        return "Only PDF, DOCX and XLSX files are allowed.";
    }
    return '';
}

/**
 * Moves the uploaded file into uploads/<ProgramFolder>/ with a timestamp prefix
 * (so two uploads with the same name never overwrite each other).
 * Returns the path to store in the database, or false on failure.
 * $mover exists only so this can be tested outside a real upload.
 */
function store_uploaded_file($tmp_path, $original_name, $program_name, $mover = 'move_uploaded_file')
{
    $folder = safe_folder_name($program_name);
    $dir    = dirname(__DIR__) . '/uploads/' . $folder;

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $safe = safe_file_name($original_name);
    $time = time();
    do {
        $name = date('Ymd_His', $time) . '_' . $safe;
        $full = $dir . '/' . $name;
        $time++;
    } while (file_exists($full));

    if (!$mover($tmp_path, $full)) {
        return false;
    }
    return 'uploads/' . $folder . '/' . $name;
}

// Number of unread notifications for a user (shown as a bubble in the sidebars)
function unread_notifications($conn, $user_id)
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $c = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $c;
}

// ============================================
// Program: replace / delete rules (used by program/upload.php, delete.php, dashboard.php)
// ============================================

// Program may swap the file while QA has not started (sent/received),
// or when QA sent it back (needs_revision).
function program_can_replace($status)
{
    return in_array($status, ['sent', 'received', 'needs_revision'], true);
}

// Program may withdraw (delete) a file only while QA has not started on it.
// Once it is under_review / approved / rejected it is locked.
function program_can_delete($status)
{
    return in_array($status, ['sent', 'received', 'needs_revision'], true);
}

// Sends one notification to every active, approved QA user.
function notify_active_qa($conn, $file_id, $message)
{
    $message = mb_substr($message, 0, 250);
    $qa = $conn->query(
        "SELECT u.user_id FROM users u
         JOIN roles r ON u.role_id = r.role_id
         WHERE r.role_name = 'qa' AND u.account_status = 'approved' AND u.is_active = 1"
    );
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, file_id, message) VALUES (?, ?, ?)");
    while ($q = $qa->fetch_assoc()) {
        $qa_id = (int)$q['user_id'];
        $stmt->bind_param("iis", $qa_id, $file_id, $message);
        $stmt->execute();
    }
    $stmt->close();
}

// The optional note a Program user typed when sending a file.
// It is saved in file_logs.remarks (action 'sent' or 'resubmitted'). When a file was
// replaced, the log starts with "Replaces file #12." -- that part is removed here.
function program_note($remarks)
{
    $note = preg_replace('/^Replaces file #\d+\.?\s*/', '', trim((string)$remarks));
    return trim($note);
}

// ============================================================
// Paste at the END of includes/helpers.php  (after program_note()).
// Categories: used by program/upload.php and qa/categories.php
// ============================================================

// "  Lesson   plan " -> "Lesson plan"
function clean_category_name($name)
{
    return trim(preg_replace('/\s+/u', ' ', (string)$name));
}

// '' = fine, otherwise a message that can be shown to the user
function category_name_error($name)
{
    $len = mb_strlen($name);
    if ($len < 2) {
        return "The category name must be at least 2 characters.";
    }
    if ($len > 50) {
        return "The category name can be at most 50 characters.";
    }
    if (!preg_match('/^[\p{L}\p{N} &\/().,\'-]+$/u', $name)) {
        return "The category name can only have letters, numbers, spaces and & / ( ) . , ' -";
    }
    return '';
}

// Case-insensitive: "reports" and "Reports" are the same category.
// Returns the id of the existing category, or creates it and returns the new id.
function find_or_create_category($conn, $name)
{
    $stmt = $conn->prepare("SELECT category_id FROM file_categories WHERE LOWER(category_name) = LOWER(?) LIMIT 1");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return (int)$row['category_id'];
    }

    $stmt = $conn->prepare("INSERT INTO file_categories (category_name) VALUES (?)");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}