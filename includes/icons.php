<?php
// ============================================
// Small shared bits for the sidebars: icons + the logged-in user's display name
// ============================================

// Inline SVG icons (stroke = currentColor, so they follow the text color)
function icon($name) {
    $paths = [
        'dashboard'     => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'accounts'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        'files'         => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/>',
        'folder'        => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'upload'        => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5M12 3v12"/>',
        'notifications' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'logout'        => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true">' . $p . '</svg>';
}

// "Niel" -> full name if we have it, otherwise the username
function session_display_name($conn = null) {
    if (!empty($_SESSION['full_name'])) {
        return $_SESSION['full_name'];
    }
    // Sessions created before full_name was stored in the session
    if ($conn && !empty($_SESSION['user_id'])) {
        $stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
        $uid = (int)$_SESSION['user_id'];
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && $row['full_name']) {
            $_SESSION['full_name'] = $row['full_name'];
            return $row['full_name'];
        }
    }
    return $_SESSION['username'] ?? 'User';
}

function name_initials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $i = strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
    if (count($parts) > 1) { $i .= strtoupper(mb_substr(end($parts), 0, 1)); }
    return $i;
}

// Brand block at the top of every sidebar
function sidebar_brand($subtitle) {
    return '<div class="sidebar-header"><div class="brand-logo">QA</div><div><b>QADMS</b><small>'
         . htmlspecialchars($subtitle) . '</small></div></div>';
}

// User block at the bottom of every sidebar (avatar + name + role + logout)
function sidebar_user($conn = null) {
    $name = session_display_name($conn);
    $role = $_SESSION['role_name'] ?? '';
    $roles = ['admin' => 'Administrator', 'qa' => 'Quality Assurance', 'program' => 'Program / Office'];
    return '<div class="sidebar-footer">'
         . '<div class="user-avatar">' . htmlspecialchars(name_initials($name)) . '</div>'
         . '<div class="user-info"><b>' . htmlspecialchars($name) . '</b>'
         . '<small>' . htmlspecialchars($roles[$role] ?? ucfirst($role)) . '</small></div>'
         . '<a href="/websys/logout.php" class="logout-btn" title="Logout" aria-label="Logout">' . icon('logout') . '</a>'
         . '</div>';
}