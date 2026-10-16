<?php
// Expects $active_page to be set by the including page: 'files' or 'upload'
$active_page = $active_page ?? '';
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <h3>WebSys Program</h3>
    </div>
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="<?= $active_page === 'files' ? 'active' : '' ?>">
            My Files
        </a>
        <a href="upload.php" class="<?= $active_page === 'upload' ? 'active' : '' ?>">
            Upload File
        </a>
    </nav>
    <div class="sidebar-footer">
        <p><?= htmlspecialchars($_SESSION['username']) ?></p>
        <a href="../logout.php">Logout</a>
    </div>
</aside>