<?php
session_start();
require_once __DIR__ . '/config/db.php';

$error = "";

if (isset($_SESSION['role_name'])) {
    header("Location: " . $_SESSION['role_name'] . "/dashboard.php");
    exit();
}

if (isset($_GET['error']) && $_GET['error'] === 'unauthorized') {
    $error = "You don't have access to the page.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT u.user_id, u.username, u.password, u.account_status, u.program_id, r.role_name
    FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.username = ?");

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (!password_verify($password, $user['password'])) {
            $error = "Incorrect username or password.";
        } elseif ($user['account_status'] !== 'approved') {
            $error = "Your account is still " . $user['account_status'] . ". Please wait for the Admin approval.";
        } else {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role_name'] = $user['role_name'];
            $_SESSION['program_id'] = $user['program_id'];

            $session_id = session_id();
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $log = $conn->prepare("INSERT INTO sessions(session_id, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_activity = CURRENT_TIMESTAMP");
            $log->bind_param("siss", $session_id, $user['user_id'], $ip, $agent);
            $log->execute();
            $log->close();

            header("Location " . $user['role_name'] . "/dashboard.php");
            exit();
        }
    } else {
        $error = "Incorrect username or password";
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
    <div class="auth-container">
        <h2>Welcome back Busseng</h2>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <label>Username</label>
            <input type="text" name="username" required>

            <label>Password</label>
            <input type="password" name="password" required>

            <button type="submit">Login</button>
        </form>

        <p>Don't have an account? <a href="register.php">Register here</a></p>
    </div>

    <script src="js/main.js"></script>
</body>

</html>