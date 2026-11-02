<?php
session_start();

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT user_id, username, full_name, email, profile_picture, role_id, program_id, account_status, is_active, created_at FROM users WHERE user_id = ? LIMIT 1");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();

if(!$user){
    session_destroy();
    header("Location: ../login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | QADMS</title>
</head>
<body>
    
    <h2>My Profile</h2>

    <p>User ID: <?= htmlspecialchars($user['user_id'])?></p>
    <p>Username: <?= htmlspecialchars($user['username'])?></p>
    <p>Full Name: <?= htmlspecialchars($user['full_name'])?></p>
    <p>Email: <?= htmlspecialchars($user['email'])?></p>
    <p>Profile Picture:<?= htmlspecialchars($user['profile_picture'] ?? 'No profile picture')?></p>
    <p>Role ID: <?= htmlspecialchars($user['role_id'])?></p>
    <p>Program ID:<?= htmlspecialchars($user['program_id'] ?? 'N/A')?></p>
    <p>Account Status:<?= htmlspecialchars($user['account_status'])?></p>
    <p>Account Created:<?= htmlspecialchars($user['created_at'])?></p>
</body>
</html>