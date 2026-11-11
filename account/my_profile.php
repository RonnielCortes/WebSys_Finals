<?php
// ============================================
// account/my_profile.php — every role (admin, qa, program) manages their own account here:
//   1) name + email   2) profile picture   3) change password
// All the forms post back to this same page.
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_login();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$user_id = (int)$_SESSION['user_id'];

// ---- load the account (and make sure it is still allowed to be used) ----
$stmt = $conn->prepare(
    "SELECT user_id, username, full_name, email, password, profile_picture, program_id,
            account_status, is_active, created_at
     FROM users WHERE user_id = ? LIMIT 1"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user['account_status'] !== 'approved' || !$user['is_active']) {
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// ---- small CSRF token for the forms on this page ----
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$role        = $_SESSION['role_name'] ?? '';
$sidebars    = ['admin' => 'admin_sidebar.php', 'qa' => 'qa_sidebar.php', 'program' => 'program_sidebar.php'];
$role_labels = ['admin' => 'Administrator', 'qa' => 'Quality Assurance', 'program' => 'Program / Office'];
$active_page = 'profile';

$pic_dir = dirname(__DIR__) . '/profile_pics';   // pictures live here (NOT in uploads/)
$errors  = ['profile' => '', 'picture' => '', 'password' => ''];
$flash   = $_GET['flash'] ?? '';

// Deletes a stored picture, but only if it really is inside profile_pics/
function remove_picture_file($pic_dir, $stored_path)
{
    if (!$stored_path) {
        return;
    }
    $full = realpath(dirname($pic_dir) . '/' . $stored_path);
    $root = realpath($pic_dir);
    if ($full && $root && strpos($full, $root . DIRECTORY_SEPARATOR) === 0 && is_file($full)) {
        unlink($full);
    }
}

// ============================================
// POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        die("Invalid request. Please go back, refresh the page and try again.");
    }

    // ---------- 1) name + email ----------
    if ($action === 'save_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');

        if ($full_name === '' || mb_strlen($full_name) > 100) {
            $errors['profile'] = "Please enter your full name (up to 100 characters).";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
            $errors['profile'] = "Please enter a valid email address.";
        } else {
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id <> ?");
            $stmt->bind_param("si", $email, $user_id);
            $stmt->execute();
            $taken = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($taken) {
                $errors['profile'] = "That email is already used by another account.";
            } else {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = ?");
                $stmt->bind_param("ssi", $full_name, $email, $user_id);
                $stmt->execute();
                $stmt->close();
                $_SESSION['full_name'] = $full_name;
                header("Location: my_profile.php?flash=profile_saved");
                exit();
            }
        }
        // keep what the user typed
        $user['full_name'] = $full_name;
        $user['email']     = $email;
    }

    // ---------- 2) profile picture ----------
    if ($action === 'upload_picture') {
        $f = $_FILES['picture'] ?? null;
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        if (!$f || !isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
            $errors['picture'] = "Please choose a picture.";
        } elseif ($f['error'] !== UPLOAD_ERR_OK) {
            $errors['picture'] = ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE)
                ? "That picture is too big. The limit is 2 MB."
                : "The upload failed. Please try again.";
        } elseif ($f['size'] > 2 * 1024 * 1024) {
            $errors['picture'] = "That picture is too big. The limit is 2 MB.";
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            $info = @getimagesize($f['tmp_name']);
            if (!isset($allowed[$mime]) || $info === false) {
                $errors['picture'] = "Only JPG, PNG or WEBP pictures are allowed.";
            } elseif ($info[0] > 6000 || $info[1] > 6000) {
                $errors['picture'] = "That picture is too large in size (max 6000 × 6000 pixels).";
            } else {
                if (!is_dir($pic_dir) && !mkdir($pic_dir, 0775, true) && !is_dir($pic_dir)) {
                    $errors['picture'] = "Could not save the picture on the server.";
                } else {
                    // never run scripts from this folder
                    $ht = $pic_dir . '/.htaccess';
                    if (!is_file($ht)) {
                        @file_put_contents($ht, "<FilesMatch \"\\.(php|phtml|phar)$\">\n    Require all denied\n</FilesMatch>\n");
                    }
                    // the name is made by us, never taken from the user
                    $name = 'user_' . $user_id . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
                    if (!move_uploaded_file($f['tmp_name'], $pic_dir . '/' . $name)) {
                        $errors['picture'] = "Could not save the picture on the server.";
                    } else {
                        remove_picture_file($pic_dir, $user['profile_picture']);
                        $path = 'profile_pics/' . $name;
                        $stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?");
                        $stmt->bind_param("si", $path, $user_id);
                        $stmt->execute();
                        $stmt->close();
                        $_SESSION['profile_picture'] = $path;
                        header("Location: my_profile.php?flash=picture_saved");
                        exit();
                    }
                }
            }
        }
    }

    if ($action === 'remove_picture') {
        remove_picture_file($pic_dir, $user['profile_picture']);
        $stmt = $conn->prepare("UPDATE users SET profile_picture = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['profile_picture'] = '';
        header("Location: my_profile.php?flash=picture_removed");
        exit();
    }

    // ---------- 3) change password ----------
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password'])) {
            $errors['password'] = "Your current password is not correct.";
        } elseif (strlen($new) < 8) {
            $errors['password'] = "The new password must be at least 8 characters.";
        } elseif ($new !== $confirm) {
            $errors['password'] = "The new password and the confirmation do not match.";
        } elseif (password_verify($new, $user['password'])) {
            $errors['password'] = "The new password must be different from the current one.";
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->bind_param("si", $hash, $user_id);
            $stmt->execute();
            $stmt->close();
            session_regenerate_id(true);   // new session id after a password change
            header("Location: my_profile.php?flash=password_saved");
            exit();
        }
    }
}

// ---- the program/office name (Program users only) ----
$program_names = [];
if (!empty($user['program_id'])) {
    $program_names = array_values(program_names($conn, load_user_program_ids($conn, $user_id)));
}

$display = $user['full_name'] ?: $user['username'];
$pic_url = $user['profile_picture'] ? '../' . $user['profile_picture'] : '';

$flash_text = [
    'profile_saved'  => "Your name and email were updated.",
    'picture_saved'  => "Your profile picture was updated.",
    'picture_removed' => "Your profile picture was removed.",
    'password_saved' => "Your password was changed.",
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | QADMS</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
    <link rel="stylesheet" href="../css/account.css?v=<?= @filemtime(__DIR__ . '/../css/account.css') ?>">
</head>

<body class="with-sidebar">

    <?php
    if (isset($sidebars[$role])) {
        include __DIR__ . '/../includes/' . $sidebars[$role];
    }
    ?>

    <main class="main-content account-page">
        <div class="acct-wrap">

            <div class="acct-title">
                <h2>Profile Setup</h2>
                <p class="muted">Manage your name, picture and password.</p>
            </div>

            <?php if (isset($flash_text[$flash])): ?>
                <div class="alert alert-success"><?= htmlspecialchars($flash_text[$flash]) ?></div>
            <?php endif; ?>

            <!-- profile header: picture, name, email -->
            <section class="dash-panel acct-card acct-hero">
                <div class="acct-avatar">
                    <?php if ($pic_url): ?>
                        <img src="<?= htmlspecialchars($pic_url) ?>" alt="Your profile picture">
                    <?php else: ?>
                        <span><?= htmlspecialchars(name_initials($display)) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($errors['picture']): ?><div class="alert alert-error acct-hero-alert"><?= htmlspecialchars($errors['picture']) ?></div><?php endif; ?>

                <div class="acct-photo-actions">
                    <form method="POST" enctype="multipart/form-data" id="photo-form">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="upload_picture">
                        <input type="file" name="picture" id="picture" class="sr-only" accept="image/jpeg,image/png,image/webp" required>
                        <label for="picture" class="acct-outline">Change photo</label>
                        <noscript><button type="submit" class="acct-outline">Upload</button></noscript>
                    </form>
                    <?php if ($pic_url): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="remove_picture">
                            <button type="submit" class="acct-outline">Remove</button>
                        </form>
                    <?php endif; ?>
                </div>
                <p class="acct-photo-hint">JPG, PNG or WEBP, up to 2 MB.</p>

                <h3 class="acct-name"><?= htmlspecialchars($display) ?></h3>
                <p class="acct-email"><?= htmlspecialchars($user['email'] ?? '') ?></p>
                <div class="acct-chips">
                    <span class="acct-chip"><?= htmlspecialchars($role_labels[$role] ?? ucfirst($role)) ?></span>
                    <?php foreach ($program_names as $pn): ?><span class="acct-chip"><?= htmlspecialchars($pn) ?></span><?php endforeach; ?>
                    <span class="acct-chip good">Active</span>
                </div>
                <p class="acct-since">@<?= htmlspecialchars($user['username']) ?> &middot; Member since <?= date('M d, Y', strtotime($user['created_at'])) ?></p>
            </section>

            <!-- name + email -->
            <section class="dash-panel acct-card">
                <h3>Personal information</h3>
                <p class="dash-hint">Your username can't be changed.</p>
                <?php if ($errors['profile']): ?><div class="alert alert-error"><?= htmlspecialchars($errors['profile']) ?></div><?php endif; ?>
                <form method="POST" class="acct-form">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="save_profile">

                    <label for="username">Username</label>
                    <input type="text" id="username" value="<?= htmlspecialchars($user['username']) ?>" disabled>

                    <label for="full_name">Full name</label>
                    <input type="text" id="full_name" name="full_name" maxlength="100" value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>

                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" maxlength="100" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>

                    <div class="acct-actions"><button type="submit" class="acct-btn">Save changes</button></div>
                </form>
            </section>

            <!-- password -->
            <section class="dash-panel acct-card">
                <h3>Change password</h3>
                <p class="dash-hint">Use at least 8 characters.</p>
                <?php if ($errors['password']): ?><div class="alert alert-error"><?= htmlspecialchars($errors['password']) ?></div><?php endif; ?>
                <form method="POST" class="acct-form" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="change_password">

                    <label for="current_password">Current password</label>
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password">

                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">

                    <label for="confirm_password">Confirm new password</label>
                    <input type="password" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password">

                    <div class="acct-actions"><button type="submit" class="acct-btn">Change password</button></div>
                </form>
            </section>

        </div>
    </main>

    <script src="../js/profile.js?v=<?= @filemtime(__DIR__ . '/../js/profile.js') ?>"></script>
</body>

</html>