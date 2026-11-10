<?php
require_once __DIR__ . '/config/db.php';

$error = "";
$success = "";

// Fetch only the "leaf" programs/offices — the specific units a Program user
// can actually be assigned to (e.g. BSIT, BEED, Accounting, Registrar)
$programs_result = $conn->query(
    "SELECT program_id, program_name, level FROM programs
     WHERE level IN ('program', 'office')
     ORDER BY level, program_name"
);
$unit_rows = $programs_result ? $programs_result->fetch_all(MYSQLI_ASSOC) : [];
$unit_ids  = array_map('intval', array_column($unit_rows, 'program_id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];
    $role     = $_POST['role'] ?? ''; // 'qa' or 'program'

    // Program / Office: one from the list, or "other" + what the user typed
    $program_raw    = $_POST['program_id'] ?? '';
    $other_text     = trim(preg_replace('/\s+/', ' ', (string)($_POST['other_unit'] ?? '')));
    $program_id     = null;
    $requested_unit = null;
    if ($role === 'program') {
        if ($program_raw === 'other') {
            $requested_unit = $other_text;
        } elseif (in_array((int)$program_raw, $unit_ids, true)) {
            $program_id = (int)$program_raw;
        }
    }

    if ($full_name === '' || $username === '' || $email === '' || $password === '') {
        $error = "Please fill out all required fields.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (!in_array($role, ['qa', 'program'], true)) {
        $error = "Please choose an account type.";
    } elseif ($role === 'program' && $program_raw === 'other' && mb_strlen($requested_unit) < 2) {
        $error = "Please type your program, office or position.";
    } elseif ($role === 'program' && $program_raw === 'other'
            && (mb_strlen($requested_unit) > 100 || !preg_match('/^[\p{L}\p{N} .,&()\/\'-]+$/u', $requested_unit))) {
        $error = "Your program/office/position is too long (100 max) or has a character that isn't allowed.";
    } elseif ($role === 'program' && $program_raw !== 'other' && !$program_id) {
        $error = "Please select your program/office.";
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
                "INSERT INTO users (username, full_name, email, password, role_id, program_id, requested_unit, account_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $insert->bind_param("ssssiis", $username, $full_name, $email, $hashed_password, $role_id, $program_id, $requested_unit);

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
                    <select name="program_id" id="program_id" onchange="toggleOtherField()">
                        <option value="">-- Select your program/office --</option>
                        <?php foreach ($unit_rows as $row): ?>
                            <option value="<?= (int)$row['program_id'] ?>">
                                <?= htmlspecialchars($row['program_name']) ?>
                                (<?= htmlspecialchars($row['level']) ?>)
                            </option>
                        <?php endforeach; ?>
                        <option value="other">Other (not in the list)</option>
                    </select>

                    <div id="other-field" style="display:none;">
                        <label>Your program, office or position</label>
                        <input type="text" name="other_unit" id="other_unit" maxlength="100" placeholder="e.g. Library, Guidance Office">
                        <small class="muted">An admin will place you in the right folder when approving your account.</small>
                    </div>
                </div>

                <button type="submit">Register</button>
            </form>

            <p>Already have an account? <a href="login.php">Login here</a></p>
        </div>

    </div>

    <script src="js/main.js?v=<?= @filemtime(__DIR__ . '/js/main.js') ?>"></script>
</body>

</html>