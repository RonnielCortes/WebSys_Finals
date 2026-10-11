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
function display_name($file_path) {
    $name = basename($file_path);
    return preg_replace('/^\d{8}_\d{6}_/', '', $name);
}

/**
 * Loads every program/office into an array keyed by program_id,
 * so breadcrumbs can be built without extra queries.
 */
function load_programs($conn) {
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
function program_breadcrumb($programs, $program_id) {
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
function can_access_file($file_program_id) {
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
function file_statuses() {
    return ['sent', 'received', 'under_review', 'needs_revision', 'approved', 'rejected', 'returned'];
}

/**
 * "under_review" -> "Under Review"
 */
function status_label($status) {
    return ucwords(str_replace('_', ' ', $status));
}