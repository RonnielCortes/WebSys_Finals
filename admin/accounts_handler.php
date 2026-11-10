<?php
// ============================================
// admin/accounts_handler.php  (POST only)
// approve / reject / activate / deactivate one account.
// Each action is only allowed from the right starting state, so a double
// click or an old open tab can't undo something by accident.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: manage_accounts.php");
    exit();
}

$user_id  = (int)($_POST['user_id'] ?? 0);
$action   = $_POST['action'] ?? '';
$admin_id = (int)$_SESSION['user_id'];

// Where to go afterwards: the admin dashboard, or back to the same tab/search
function accounts_redirect($flash)
{
    if (($_POST['return'] ?? '') === 'dashboard') {
        $url = 'dashboard.php';
        $qs  = '';
    } else {
        $url = 'manage_accounts.php';
        parse_str((string)($_POST['back'] ?? ''), $b);
        $keep = [];
        foreach (['tab', 'q', 'role'] as $k) {
            if (isset($b[$k]) && is_string($b[$k]) && $b[$k] !== '') {
                $keep[$k] = $b[$k];
            }
        }
        $qs = http_build_query($keep);
    }
    $sep = ($qs === '') ? '' : '&';
    header("Location: " . $url . "?" . $qs . $sep . "flash=" . urlencode($flash));
    exit();
}

if (!$user_id || !in_array($action, ['approve', 'reject', 'activate', 'deactivate'], true)) {
    accounts_redirect('invalid');
}

// An Admin must never lock themselves out
if ($user_id === $admin_id && in_array($action, ['reject', 'deactivate'], true)) {
    accounts_redirect('cannot_self_deactivate');
}

try {
    // current state of the target account
    $stmt = $conn->prepare(
        "SELECT u.account_status, u.is_active, u.program_id, r.role_name
         FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        accounts_redirect('invalid');
    }
    $status = $target['account_status'];

    switch ($action) {
        case 'approve':   // pending -> approved, or a rejected account the Admin changed their mind about
            if (!in_array($status, ['pending', 'rejected'], true)) accounts_redirect('invalid');
            // A Program user who picked "Other" has no folder yet: the Admin must assign one first
            if ($target['role_name'] === 'program' && empty($target['program_id'])) {
                header("Location: manage_accounts.php?tab=pending&flash=needs_program");
                exit();
            }
            $stmt = $conn->prepare("UPDATE users SET account_status = 'approved', approved_by = ? WHERE user_id = ?");
            $stmt->bind_param("ii", $admin_id, $user_id);
            $flash = 'approved';
            break;

        case 'reject':    // only while still pending
            if ($status !== 'pending') accounts_redirect('invalid');
            $stmt = $conn->prepare("UPDATE users SET account_status = 'rejected', approved_by = ? WHERE user_id = ?");
            $stmt->bind_param("ii", $admin_id, $user_id);
            $flash = 'rejected';
            break;

        case 'activate':
            if ($status !== 'approved') accounts_redirect('invalid');
            $stmt = $conn->prepare("UPDATE users SET is_active = 1 WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $flash = 'activated';
            break;

        case 'deactivate':
            if ($status !== 'approved') accounts_redirect('invalid');
            $stmt = $conn->prepare("UPDATE users SET is_active = 0 WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $flash = 'deactivated';
            break;
    }

    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    accounts_redirect('invalid');
}

accounts_redirect($flash);
