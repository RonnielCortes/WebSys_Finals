<?php
// ============================================
// admin/edit_account.php
// Admin can change a user's name and email, move a Program user to another
// program/office, and reset the password.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';

$active_page = 'accounts';

$user_id = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);
if (!$user_id) {
    header("Location: manage_accounts.php");
    exit();
}

// small CSRF token (same one the My Profile page uses)
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

// The account being edited
$stmt = $conn->prepare(
    "SELECT u.username, u.full_name, u.email, u.program_id, r.role_name
     FROM users u
     JOIN roles r ON u.role_id = r.role_id
     WHERE u.user_id = ?"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header("Location: manage_accounts.php");
    exit();
}
$is_program_user = ($user['role_name'] === 'program');

// The units a Program user can belong to (same list as the register page)
$units = [];
$res = $conn->query(
    "SELECT program_id, program_name, level FROM programs
     WHERE level IN ('program', 'office') ORDER BY level, program_name"
);
while ($row = $res->fetch_assoc()) {
    $units[(int)$row['program_id']] = $row;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name    = trim($_POST['full_name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm      = $_POST['confirm_password'] ?? '';
    $program_id   = $is_program_user ? (int)($_POST['program_id'] ?? 0) : null;

    // keep what was typed if there is an error
    $user['full_name'] = $full_name;
    $user['email']     = $email;
    if ($is_program_user) { $user['program_id'] = $program_id; }

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = "The page expired. Please try again.";
    } elseif ($full_name === '' || mb_strlen($full_name) > 100) {
        $error = "Please enter a full name (up to 100 characters).";
    } elseif ($email === '') {
        $error = "Email cannot be blank.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
        $error = "Please enter a valid email address.";
    } elseif ($is_program_user && !isset($units[$program_id])) {
        $error = "Please choose a program/office.";
    } elseif ($new_password !== '' && strlen($new_password) < 8) {
        $error = "The new password must be at least 8 characters.";
    } elseif ($new_password !== $confirm) {
        $error = "The new password and the confirmation do not match.";
    } else {
        try {
            // Two accounts must not share one email
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1");
            $stmt->bind_param("si", $email, $user_id);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($dup) {
                $error = "That email is already used by another account.";
            } else {
                $sql    = "UPDATE users SET full_name = ?, email = ?";
                $types  = "ss";
                $params = [$full_name, $email];

                if ($is_program_user) {
                    $sql .= ", program_id = ?";
                    $types .= "i";
                    $params[] = $program_id;
                }
                if ($new_password !== '') {
                    $sql .= ", password = ?";
                    $types .= "s";
                    $params[] = password_hash($new_password, PASSWORD_DEFAULT);
                }
                $sql .= " WHERE user_id = ?";
                $types .= "i";
                $params[] = $user_id;

                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();

                header("Location: manage_accounts.php?flash=updated");
                exit();
            }
        } catch (Throwable $e) {
            $error = "The changes could not be saved. Please try again.";
        }
    }
}

$role_text = ($user['role_name'] === 'qa') ? 'QA' : ucfirst($user['role_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Account</title>
<link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
<link rel="stylesheet" href="../css/account.css?v=<?= @filemtime(__DIR__ . '/../css/account.css') ?>">
</head>
<body class="with-sidebar">

<?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

<main class="main-content account-page">
  <div class="acct-wrap">

    <div class="acct-title">
        <h2>Edit Account</h2>
        <p class="muted">@<?= htmlspecialchars($user['username']) ?> &middot; <?= htmlspecialchars($role_text) ?></p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <section class="dash-panel acct-card">
      <form method="POST" action="edit_account.php?id=<?= $user_id ?>" class="acct-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="user_id" value="<?= $user_id ?>">

        <label for="username">Username</label>
        <input type="text" id="username" value="<?= htmlspecialchars($user['username']) ?>" disabled>

        <label for="full_name">Full name</label>
        <input type="text" name="full_name" id="full_name" maxlength="100" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>

        <label for="email">Email</label>
        <input type="email" name="email" id="email" maxlength="100" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>

        <?php if ($is_program_user): ?>
            <label for="program_id">Program / Office</label>
            <select name="program_id" id="program_id" required>
                <option value="">-- Select program/office --</option>
                <?php foreach ($units as $id => $u): ?>
                    <option value="<?= $id ?>" <?= (int)$user['program_id'] === $id ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['program_name']) ?> (<?= htmlspecialchars($u['level']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="muted">If you change this, the user must log out and log in again for it to take effect.</small>
        <?php endif; ?>

        <label for="new_password">New password <span class="muted">(optional)</span></label>
        <input type="password" name="new_password" id="new_password" minlength="8" autocomplete="new-password"
               placeholder="Leave blank to keep the current password">

        <label for="confirm_password">Confirm new password</label>
        <input type="password" name="confirm_password" id="confirm_password" minlength="8" autocomplete="new-password">
        <small class="muted">Only fill these in to reset the password (at least 8 characters). Tell the user their new password in person.</small>

        <div class="acct-actions acct-actions-split">
            <a href="manage_accounts.php" class="acct-cancel">Cancel</a>
            <button type="submit" class="acct-btn">Save changes</button>
        </div>
      </form>
    </section>

  </div>
</main>

</body>
</html>