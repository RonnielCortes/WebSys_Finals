<?php
require_once __DIR__ . '/../includes/check.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';

$active_page = 'accounts';

$user_id = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);
if (!$user_id) {
    header("Location: manage_accounts.php");
    exit();
}

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email']);
    $new_password = $_POST['new_password'];

    if ($full_name === '') {
        $error = "Full name cannot be blank.";
    } elseif ($email === '') {
        $error = "Email cannot be blank.";
    } else {
        if ($new_password !== '') {
            // Admin is also resetting the password
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, password = ? WHERE user_id = ?");
            $stmt->bind_param("sssi", $full_name, $email, $hashed, $user_id);
        } else {
            // Name + email only, leave password untouched
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = ?");
            $stmt->bind_param("ssi", $full_name, $email, $user_id);
        }

        if ($stmt->execute()) {
            $stmt->close();
            header("Location: manage_accounts.php?flash=updated");
            exit();
        } else {
            $error = "Update failed: " . $conn->error;
        }
    }
}

// Fetch current user info to pre-fill the form
$stmt = $conn->prepare(
    "SELECT u.username, u.full_name, u.email, r.role_name, p.program_name
     FROM users u
     JOIN roles r ON u.role_id = r.role_id
     LEFT JOIN programs p ON u.program_id = p.program_id
     WHERE u.user_id = ?"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    header("Location: manage_accounts.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Account</title>
<link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
</head>
<body class="with-sidebar">

<?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

<main class="main-content">
    <h2>Edit Account</h2>
    <p class="muted">
        <?= htmlspecialchars($user['username']) ?>
        — <?= htmlspecialchars($user['role_name']) ?>
        <?php if ($user['program_name']): ?> (<?= htmlspecialchars($user['program_name']) ?>)<?php endif; ?>
    </p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="edit_account.php?id=<?= $user_id ?>" class="edit-form">
        <input type="hidden" name="user_id" value="<?= $user_id ?>">

        <label>Username</label>
        <input type="text" value="<?= htmlspecialchars($user['username']) ?>" disabled>
        <small class="muted">Username cannot be changed.</small>

        <label>Full Name</label>
        <input type="text" name="full_name" maxlength="100" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>

        <label>Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>

        <label>New Password</label>
        <input type="password" name="new_password" placeholder="Leave blank to keep current password">
        <small class="muted">Only fill this in if you want to reset the password.</small>

        <button type="submit">Save Changes</button>
    </form>

    <p><a href="manage_accounts.php">&larr; Back to Manage Accounts</a></p>
</main>

</body>
</html>