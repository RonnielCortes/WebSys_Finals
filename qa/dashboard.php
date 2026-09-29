<?php
require_once __DIR__ . '/../includes/check.php';
require_role('qa');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>QA Dashboard</title>
<link rel="stylesheet" href="../css/style.css">
</head>
<body>
<div class="dashboard-container">
    <h2>QA Dashboard</h2>
    <p>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</p>
    <p><em>(File in/out view across all programs will go here.)</em></p>
    <a href="../logout.php">Logout</a>
</div>
</body>
</html>