<?php
require_once __DIR__ . '/config/db.php';

// A valid choice must exist and have no sub-programs under it
function is_leaf_program($conn, $program_id) {
    $stmt = $conn->prepare(
        "SELECT 1 FROM programs p
         WHERE p.program_id = ?
           AND NOT EXISTS (SELECT 1 FROM programs c WHERE c.parent_id = p.program_id)"
    );
    $stmt->bind_param("i", $program_id);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $ok;
}

$error = "";
$success = "";

// Whole program tree, sent to the browser so the dropdowns can cascade
$all_programs = [];
$res = $conn->query("SELECT program_id, program_name, parent_id FROM programs ORDER BY program_id");
while ($r = $res->fetch_assoc()) {
    $all_programs[] = [
        'id'     => (int)$r['program_id'],
        'name'   => $r['program_name'],
        'parent' => (int)$r['parent_id'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];
    $role     = $_POST['role']; // 'qa' or 'program'
    $program_id = ($role === 'program' && !empty($_POST['program_id']))
        ? (int)$_POST['program_id']
        : null;

    if ($full_name === '' || $username === '' || $email === '' || $password === '') {
        $error = "Please fill out all required fields.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif ($role === 'program' && !$program_id) {
        $error = "Please select your program/office.";
    } elseif ($role === 'program' && !is_leaf_program($conn, $program_id)) {
        $error = "Please pick your specific program/office (keep selecting until there are no more choices).";
    } else {
        // Check if username or email already exists
        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Username or email already taken.";
        } else {
            // Look up role_id from role name
            $role_stmt = $conn->prepare("SELECT role_id FROM roles WHERE role_name = ?");
            $role_stmt->bind_param("s", $role);
            $role_stmt->execute();
            $role_stmt->bind_result($role_id);
            $role_stmt->fetch();
            $role_stmt->close();

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $insert = $conn->prepare(
                "INSERT INTO users (username, full_name, email, password, role_id, program_id, account_status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
            );
            $insert->bind_param("ssssii", $username, $full_name, $email, $hashed_password, $role_id, $program_id);

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
    <link rel="stylesheet" href="css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?>">
</head>

<body>
    <div class="auth-split">
        <div class="auth-form-panel">
            <h2>Create an account</h2>
            <p class="form-sub">An admin will approve it before you can sign in.</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <form method="POST" action="register.php" onsubmit="return validateRegisterForm()">
                <label>Full Name</label>
                <input type="text" name="full_name" maxlength="100" placeholder="e.g. Juan Dela Cruz" required>

                <label>Username</label>
                <input type="text" name="username" required>

                <label>Email</label>
                <input type="email" name="email" required>

                <label>Password</label>
                <div class="pw-wrap">
                    <input type="password" name="password" id="password" required>
                    <button type="button" class="pw-toggle" onclick="togglePassword(this)" aria-label="Show password" title="Show / hide password">
                        <svg class="eye-open" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                        <svg class="eye-closed" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a19.8 19.8 0 0 1 5.06-5.94M9.9 4.24A10.9 10.9 0 0 1 12 4c7 0 11 7 11 7a19.7 19.7 0 0 1-3.17 4.19M14.12 14.12A3 3 0 1 1 9.88 9.88" />
                            <line x1="1" y1="1" x2="23" y2="23" />
                        </svg>
                    </button>
                </div>

                <label>Confirm Password</label>
                <div class="pw-wrap">
                    <input type="password" name="confirm_password" id="confirm_password" required>
                    <button type="button" class="pw-toggle" onclick="togglePassword(this)" aria-label="Show password" title="Show / hide password">
                        <svg class="eye-open" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                        <svg class="eye-closed" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a19.8 19.8 0 0 1 5.06-5.94M9.9 4.24A10.9 10.9 0 0 1 12 4c7 0 11 7 11 7a19.7 19.7 0 0 1-3.17 4.19M14.12 14.12A3 3 0 1 1 9.88 9.88" />
                            <line x1="1" y1="1" x2="23" y2="23" />
                        </svg>
                    </button>
                </div>

                <label>Account Type</label>
                <select name="role" id="role" onchange="toggleProgramField()" required>
                    <option value="">-- Select --</option>
                    <option value="qa">QA</option>
                    <option value="program">Program / Office</option>
                </select>

                <div id="program-field" style="display:none;">
                    <label>Program / Office</label>
                    <div id="program-levels"></div>
                    <input type="hidden" name="program_id" id="program_id" value="">
                </div>

                <button type="submit">Register</button>
            </form>

            <p>Already have an account? <a href="login.php">Login here</a></p>
        </div>
       

    <script>
        // The whole program tree, used by js/main.js to build the cascading dropdowns
        const PROGRAMS = <?= json_encode($all_programs, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    </script>
    <script src="js/main.js?v=<?= @filemtime(__DIR__ . '/js/main.js') ?>"></script>
</body>

</html>