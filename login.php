<?php
session_start();
require_once __DIR__ . '/config/db.php';

$error = "";

// If already logged in, send them straight to their dashboard
if (isset($_SESSION['role_name'])) {
    header("Location: " . $_SESSION['role_name'] . "/dashboard.php");
    exit();
}

if (isset($_GET['error']) && $_GET['error'] === 'unauthorized') {
    $error = "You don't have access to that page.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare(
        "SELECT u.user_id, u.username, u.password, u.account_status, u.is_active,
                u.program_id, r.role_name
         FROM users u
         JOIN roles r ON u.role_id = r.role_id
         WHERE u.username = ?"
    );
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (!password_verify($password, $user['password'])) {
            $error = "Incorrect username or password.";
        } elseif ($user['account_status'] !== 'approved') {
            $error = "Your account is still " . $user['account_status'] . ". Please wait for Admin approval.";
        } elseif (!$user['is_active']) {
            $error = "Your account has been deactivated. Please contact the Admin.";
        } else {
            // Success — set up the session
            $_SESSION['user_id']    = $user['user_id'];
            $_SESSION['username']   = $user['username'];
            $_SESSION['role_name']  = $user['role_name'];
            $_SESSION['program_id'] = $user['program_id'];

            // Log this session in the sessions table
            $session_id = session_id();
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $log = $conn->prepare(
                "INSERT INTO sessions (session_id, user_id, ip_address, user_agent)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE last_activity = CURRENT_TIMESTAMP"
            );
            $log->bind_param("siss", $session_id, $user['user_id'], $ip, $agent);
            $log->execute();
            $log->close();

            // Redirect based on role
            header("Location: " . $user['role_name'] . "/dashboard.php");
            exit();
        }
    } else {
        $error = "Incorrect username or password.";
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login - Document Management System</title>
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="auth-split">
<div class="auth-form-panel">
    <h2>Welcome back</h2>
    <p class="form-sub">Please enter your account details.</p>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <label>Username</label>
        <input type="text" name="username" autocomplete="username" required autofocus>

        <label>Password</label>
        <div class="pw-wrap">
            <input type="password" name="password" id="password" required>
            <button type="button" class="pw-toggle" onclick="togglePassword(this)" aria-label="Show password" title="Show / hide password">
                <svg class="eye-open" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="eye-closed" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a19.8 19.8 0 0 1 5.06-5.94M9.9 4.24A10.9 10.9 0 0 1 12 4c7 0 11 7 11 7a19.7 19.7 0 0 1-3.17 4.19M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
        </div>

        <button type="submit">Sign in</button>
    </form>

    <p>Don't have an account? <a href="register.php">Register here</a></p>
</div>
    <aside class="auth-info-panel">
        <div class="info-badge">QADMS</div>
        <h2>Quality Assurance Document Management System</h2>
        <p class="info-lead">One place for programs and offices to send their approved documents to QA, and for QA to review them.</p>

        <ul class="info-steps">
            <li>
                <span class="step-num">1</span>
                <div><strong>Programs &amp; Offices send</strong><small>Upload approved files (PDF, DOCX, XLSX) straight to QA.</small></div>
            </li>
            <li>
                <span class="step-num">2</span>
                <div><strong>QA reviews</strong><small>See every file by program, download it, or ping it back for revision.</small></div>
            </li>
            <li>
                <span class="step-num">3</span>
                <div><strong>Re-submit &amp; track</strong><small>Fix flagged files and re-send. Older versions are kept in the history.</small></div>
            </li>
        </ul>

        <p class="info-foot">Access is by approved accounts only.</p>
    </aside>
</div>

<script src="js/main.js"></script>
</body>
</html>