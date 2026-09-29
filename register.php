<?php
require_once __DIR__ . '/config/db.php';

$error = "";
$success = "";

$programs_result = $conn->query(
    "SELECT program_id, program_name, level FROM programs
     WHERE level IN ('program', 'office')
     ORDER BY level, program_name"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];
    $role     = $_POST['role'];
    $program_id = ($role === 'program' && !empty($_POST['program_id']))
        ? (int)$_POST['program_id']
        : null;

    if ($username === '' || $email === '' || $password === '') {
        $error = "Please fill out all required fields.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif ($role === 'program' && !$program_id) {
        $error = "Please select your program/office.";
    } else {

        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Username or email already taken.";
        } else {

            $role_stmt = $conn->prepare("SELECT role_id FROM roles WHERE role_name = ?");
            $role_stmt->bind_param("s", $role);
            $role_stmt->execute();
            $role_stmt->bind_result($role_id);
            $role_stmt->fetch();
            $role_stmt->close();

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $insert = $conn->prepare(
                "INSERT INTO users (username, email, password, role_id, program_id, account_status)
                 VALUES (?, ?, ?, ?, ?, 'pending')"
            );
            $insert->bind_param("sssii", $username, $email, $hashed_password, $role_id, $program_id);

            if ($insert->execute()) {
                $success = "Account created! Please wait for an Admin to approve your account before logging in.";
            } else {
                $error = "Something went wrong. Please try again.";
            }
            $insert->close();
        }
        $check->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Register - Document Management System</title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <div class="auth-container">
        <h2>Create an Account</h2>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" action="register.php" onsubmit="return validateRegisterForm()">
            <label>Username</label>
            <input type="text" name="username" required>

            <label>Email</label>
            <input type="email" name="email" required>

            <label>Password</label>
            <input type="password" name="password" id="password" required>

            <label>Confirm Password</label>
            <input type="password" name="confirm_password" id="confirm_password" required>

            <label>Account Type</label>
            <select name="role" id="role" onchange="toggleProgramField()" required>
                <option value="">-- Select --</option>
                <option value="qa">QA</option>
                <option value="program">Program / Office</option>
            </select>

            <div id="program-field" style="display:none;">
                <label>Program / Office</label>
                <select name="program_id">
                    <option value="">-- Select your program/office --</option>
                    <?php if (isset($programs_result)): ?>
                        <?php while ($row = $programs_result->fetch_assoc()): ?>
                            <option value="<?= $row['program_id'] ?>">
                                <?= htmlspecialchars($row['program_name']) ?>
                                (<?= htmlspecialchars($row['level']) ?>)
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
            </div>

            <button type="submit">Register</button>
        </form>

        <p>Already have an account? <a href="login.php">Login here</a></p>
    </div>

    <script src="js/main.js"></script>
</body>

</html>