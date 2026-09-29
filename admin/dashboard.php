<?php
require_once __DIR__ . '/../includes/check.php';
require_role('admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard</title>
<link rel="stylesheet" href="../css/style.css">
</head>
<body>
<div class="dashboard-container">
    <h2>Admin Dashboard</h2>
    <p>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</p>
    <p><em>(Account approvals and full file access will go here.)</em></p>
    <a href="../logout.php">Logout</a>
</div>
</body>
</html>