<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: manage_accounts.php");
    exit();
}

$user_id = (int)($_POST['user_id'] ?? 0);
$action  = $_POST['action'] ?? '';
$admin_id = $_SESSION['user_id'];

if (!$user_id || !$action) {
    header("Location: manage_accounts.php");
    exit();
}


if ($user_id === $admin_id && $action === 'deactivate') {
    header("Location: manage_accounts.php?flash=cannot_self_deactivate");
    exit();
}

$flash = '';

switch ($action) {
    case 'approve':
        $stmt = $conn->prepare(
            "UPDATE users SET account_status = 'approved', approved_by = ? WHERE user_id = ?"
        );
        $stmt->bind_param("ii", $admin_id, $user_id);
        $stmt->execute();
        $stmt->close();
        $flash = 'approved';
        break;

    case 'reject':
        $stmt = $conn->prepare(
            "UPDATE users SET account_status = 'rejected', approved_by = ? WHERE user_id = ?"
        );
        $stmt->bind_param("ii", $admin_id, $user_id);
        $stmt->execute();
        $stmt->close();
        $flash = 'rejected';
        break;

    case 'activate':
        $stmt = $conn->prepare("UPDATE users SET is_active = 1 WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        $flash = 'activated';
        break;

    case 'deactivate':
        $stmt = $conn->prepare("UPDATE users SET is_active = 0 WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        $flash = 'deactivated';
        break;
}

header("Location: manage_accounts.php?flash=" . urlencode($flash));
exit();
