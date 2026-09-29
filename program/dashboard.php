<?php
require_once __DIR__ . '/../includes/check.php';
require_role('program');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Program Dashboard</title>
<link rel="stylesheet" href="../css/style.css">
</head>
<body>
<div class="dashboard-container">
    <h2>Program Dashboard</h2>
    <p>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</p>
    <p><em>(Your program's own files will go here.)</em></p>
    <a href="../logout.php">Logout</a>
</div>
</body>
</html>