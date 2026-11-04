<?php
// ============================================
// admin/manage_accounts.php
// Tabs (Pending / Approved / Rejected / All) + search + role filter.
// Actions go to accounts_handler.php (POST only).
// ============================================
require_once __DIR__ . '/../includes/check.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';

$active_page = 'accounts';

$flash = $_GET['flash'] ?? '';
$me    = (int)$_SESSION['user_id'];

// --------------------------------------------
// Counts for the tabs
// --------------------------------------------
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$res = $conn->query("SELECT account_status, COUNT(*) AS c FROM users GROUP BY account_status");
while ($r = $res->fetch_assoc()) {
    if (isset($counts[$r['account_status']])) {
        $counts[$r['account_status']] = (int)$r['c'];
    }
}
$all_count = array_sum($counts);

// --------------------------------------------
// Filters
// --------------------------------------------
$tabs = ['pending', 'approved', 'rejected', 'all'];
$tab  = $_GET['tab'] ?? '';
if (!in_array($tab, $tabs, true)) {
    // first visit: go straight to the accounts that need the Admin
    $tab = $counts['pending'] > 0 ? 'pending' : 'all';
}

$roles = [];
$res = $conn->query("SELECT role_name FROM roles ORDER BY role_name");
while ($r = $res->fetch_assoc()) {
    $roles[] = $r['role_name'];
}

$role_filter = $_GET['role'] ?? '';
if (!in_array($role_filter, $roles, true)) {
    $role_filter = '';
}
$search = trim($_GET['q'] ?? '');

$sql = "SELECT u.user_id, u.username, u.full_name, u.email, u.account_status, u.is_active,
               r.role_name, p.program_name
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        LEFT JOIN programs p ON u.program_id = p.program_id
        WHERE 1 = 1";
$types  = '';
$params = [];

if ($tab !== 'all') {
    $sql    .= " AND u.account_status = ?";
    $types  .= 's';
    $params[] = $tab;
}
if ($role_filter !== '') {
    $sql    .= " AND r.role_name = ?";
    $types  .= 's';
    $params[] = $role_filter;
}
if ($search !== '') {
    $sql .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
$sql .= " ORDER BY u.account_status = 'pending' DESC, u.created_at DESC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$is_filtered = ($search !== '' || $role_filter !== '');

// Used so the Admin lands back on the same tab/search after an action
$back = http_build_query(array_filter(
    ['tab' => $tab, 'q' => $search, 'role' => $role_filter],
    function ($v) { return $v !== ''; }
));

function account_role_label($role) {
    return $role === 'qa' ? 'QA' : ucfirst($role);
}

function tab_url($tab, $search, $role_filter) {
    $q = array_filter(['tab' => $tab, 'q' => $search, 'role' => $role_filter],
        function ($v) { return $v !== ''; });
    return 'manage_accounts.php?' . http_build_query($q);
}

// One small POST form per action button
function account_action_form($user_id, $action, $label, $class, $back, $confirm = null) {
    $attrs = '';
    if ($confirm) {
        $attrs = ' class="js-confirm"'
               . ' data-confirm-title="'   . htmlspecialchars($confirm['title'])   . '"'
               . ' data-confirm-message="' . htmlspecialchars($confirm['message']) . '"'
               . ' data-confirm-yes="'     . htmlspecialchars($confirm['yes'])     . '"';
    }
    echo '<form method="POST" action="accounts_handler.php"' . $attrs . '>'
       . '<input type="hidden" name="user_id" value="' . (int)$user_id . '">'
       . '<input type="hidden" name="action" value="' . htmlspecialchars($action) . '">'
       . '<input type="hidden" name="back" value="' . htmlspecialchars($back) . '">'
       . '<button type="submit" class="btn-small ' . htmlspecialchars($class) . '">' . htmlspecialchars($label) . '</button>'
       . '</form>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manage Accounts</title>
<link rel="stylesheet" href="../css/style.css?v=<?= @filemtime(__DIR__ . '/../css/style.css') ?>">
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
        <div class="alert alert-error">You can't reject or deactivate your own account.</div>
    <?php elseif ($flash === 'invalid'): ?>
        <div class="alert alert-error">That action isn't possible for this account anymore. The page was refreshed.</div>
    <?php endif; ?>

    <div class="tab-row">
        <a class="tab <?= $tab === 'pending'  ? 'active' : '' ?>" href="<?= tab_url('pending',  $search, $role_filter) ?>">Pending (<?= $counts['pending'] ?>)</a>
        <a class="tab <?= $tab === 'approved' ? 'active' : '' ?>" href="<?= tab_url('approved', $search, $role_filter) ?>">Approved (<?= $counts['approved'] ?>)</a>
        <a class="tab <?= $tab === 'rejected' ? 'active' : '' ?>" href="<?= tab_url('rejected', $search, $role_filter) ?>">Rejected (<?= $counts['rejected'] ?>)</a>
        <a class="tab <?= $tab === 'all'      ? 'active' : '' ?>" href="<?= tab_url('all',      $search, $role_filter) ?>">All (<?= $all_count ?>)</a>
    </div>

    <form method="GET" action="manage_accounts.php" class="filter-bar">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">

        <div class="filter-field">
            <label for="q">Search</label>
            <input type="text" name="q" id="q" value="<?= htmlspecialchars($search) ?>" placeholder="Name, username or email">
        </div>

        <div class="filter-field">
            <label for="role">Account type</label>
            <select name="role" id="role">
                <option value="">All</option>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= htmlspecialchars($r) ?>" <?= $role_filter === $r ? 'selected' : '' ?>>
                        <?= htmlspecialchars(account_role_label($r)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit">Search</button>
        <?php if ($is_filtered): ?>
            <a href="manage_accounts.php?tab=<?= htmlspecialchars($tab) ?>" class="btn-reset">Reset</a>
        <?php endif; ?>
    </form>

    <?php if (empty($users)): ?>
        <div class="empty-state">
            <?= $is_filtered ? 'No accounts match your search.' : 'No accounts here.' ?>
        </div>
    <?php else: ?>
        <table class="data-table accounts-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Program/Office</th>
                    <th>Approval</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <?php $is_me = ((int)$u['user_id'] === $me); ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>
                            <?php if ($is_me): ?><span class="lock-note">(you)</span><?php endif; ?>
                            <?php if ($u['full_name']): ?>
                                <br><small class="muted">@<?= htmlspecialchars($u['username']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><span class="badge badge-role"><?= htmlspecialchars(account_role_label($u['role_name'])) ?></span></td>
                        <td><?= htmlspecialchars($u['program_name'] ?? '—') ?></td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($u['account_status']) ?>">
                                <?= htmlspecialchars(ucfirst($u['account_status'])) ?>
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
                        <td class="actions-cell manage-actions">
                            <?php if ($u['account_status'] === 'pending'): ?>
                                <?php account_action_form($u['user_id'], 'approve', 'Approve', 'btn-approve', $back); ?>
                                <?php account_action_form($u['user_id'], 'reject', 'Reject', 'btn-reject', $back, [
                                    'title'   => 'Reject this account?',
                                    'message' => ($u['full_name'] ?: $u['username']) . ' will not be able to log in.',
                                    'yes'     => 'Yes, reject',
                                ]); ?>
                            <?php elseif ($u['account_status'] === 'rejected'): ?>
                                <?php account_action_form($u['user_id'], 'approve', 'Approve', 'btn-approve', $back); ?>
                            <?php elseif ($u['account_status'] === 'approved' && !$is_me): ?>
                                <?php if ($u['is_active']): ?>
                                    <?php account_action_form($u['user_id'], 'deactivate', 'Deactivate', 'btn-reject', $back, [
                                        'title'   => 'Deactivate this account?',
                                        'message' => ($u['full_name'] ?: $u['username']) . ' will not be able to log in until you activate the account again.',
                                        'yes'     => 'Yes, deactivate',
                                    ]); ?>
                                <?php else: ?>
                                    <?php account_action_form($u['user_id'], 'activate', 'Activate', 'btn-approve', $back); ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            <a href="edit_account.php?id=<?= (int)$u['user_id'] ?>" class="btn-small btn-edit">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</main>

<script src="../js/main.js?v=<?= @filemtime(__DIR__ . '/../js/main.js') ?>"></script>
</body>
</html>