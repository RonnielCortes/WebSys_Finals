<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';

$active_page = 'accounts';

$flash = $_GET['flash'] ?? '';

$users = $conn->query(
    "SELECT u.user_id, u.username, u.email, u.account_status, u.is_active,
            r.role_name, p.program_name
     FROM users u
     JOIN roles r ON u.role_id = r.role_id
     LEFT JOIN programs p ON u.program_id = p.program_id
     ORDER BY u.account_status = 'pending' DESC, u.created_at DESC"
);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manage Accounts</title>
    <link rel="stylesheet" href="../css/style.css">
</head>

<body class="with-sidebar">

    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <main class="main-content">
        <h2>Manage Accounts</h2>

        <?php if ($flash === 'approved'): ?>
            <div class="alert alert-success">Account approved.</div>
        <?php elseif ($flash === 'rejected'): ?>
            <div class="alert alert-error">Account rejected.</div>
        <?php elseif ($flash === 'activated'): ?>
            <div class="alert alert-success">Account activated.</div>
        <?php elseif ($flash === 'deactivated'): ?>
            <div class="alert alert-error">Account deactivated.</div>
        <?php elseif ($flash === 'updated'): ?>
            <div class="alert alert-success">Account updated.</div>
        <?php elseif ($flash === 'cannot_self_deactivate'): ?>
            <div class="alert alert-error">You can't deactivate your own account.</div>
        <?php endif; ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Program/Office</th>
                    <th>Approval</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($u = $users->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><span class="badge badge-role"><?= htmlspecialchars($u['role_name']) ?></span></td>
                        <td><?= htmlspecialchars($u['program_name'] ?? '—') ?></td>
                        <td>
                            <span class="badge badge-<?= $u['account_status'] ?>">
                                <?= ucfirst($u['account_status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['account_status'] === 'approved'): ?>
                                <span class="badge <?= $u['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                                    <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="actions-cell">
                            <?php if ($u['account_status'] === 'pending'): ?>
                                <form method="POST" action="accounts_handler.php" style="display:inline;">
                                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="btn-small btn-approve">Approve</button>
                                </form>
                                <form method="POST" action="accounts_handler.php" style="display:inline;">
                                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="btn-small btn-reject">Reject</button>
                                </form>
                            <?php elseif ($u['account_status'] === 'approved'): ?>
                                <form method="POST" action="accounts_handler.php" style="display:inline;">
                                    <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                    <input type="hidden" name="action" value="<?= $u['is_active'] ? 'deactivate' : 'activate' ?>">
                                    <button type="submit" class="btn-small <?= $u['is_active'] ? 'btn-reject' : 'btn-approve' ?>">
                                        <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <a href="edit_account.php?id=<?= $u['user_id'] ?>" class="btn-small btn-edit">Edit</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </main>

</body>

</html>