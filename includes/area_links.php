<?php
// ============================================
// includes/area_links.php — "Also link to" helpers
// A file has ONE main Area (files.area_id). It can also be LINKED to other Areas
// (table file_area_links). A link is only a mark: the file is not copied.
// ============================================

// The Area ids a file is linked to
function area_link_ids($conn, $file_id)
{
    $ids  = [];
    $stmt = $conn->prepare("SELECT area_id FROM file_area_links WHERE file_id = ? ORDER BY area_id");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $ids[] = (int)$r['area_id'];
    }
    $stmt->close();
    return $ids;
}

// Cleans what the form sent: whole numbers only, real Areas only, never the file's own main Area.
// $areas = [area_id => ...] (the list of valid Areas)
function clean_link_ids($posted, $areas, $main_area)
{
    $ids = array_values(array_unique(array_map('intval', (array)$posted)));
    $ok  = [];
    foreach ($ids as $id) {
        if (isset($areas[$id]) && $id !== (int)$main_area) {
            $ok[] = $id;
        }
    }
    return $ok;
}

// Replaces the links of a file. Call it inside the caller's transaction.
function save_area_links($conn, $file_id, $area_ids)
{
    $stmt = $conn->prepare("DELETE FROM file_area_links WHERE file_id = ?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $stmt->close();

    foreach ($area_ids as $aid) {
        $stmt = $conn->prepare("INSERT INTO file_area_links (file_id, area_id) VALUES (?, ?)");
        $aid  = (int)$aid;
        $stmt->bind_param("ii", $file_id, $aid);
        $stmt->execute();
        $stmt->close();
    }
}

// "AR1 - Administration" -> "AR1"
function short_area_name($name)
{
    return trim(explode(' - ', (string)$name, 2)[0]);
}

// $joined = the names glued with '||' by the SQL (GROUP_CONCAT). Returns the small "also: AR1, AR5" badge, or ''.
function linked_badge_html($joined)
{
    if ($joined === null || $joined === '') {
        return '';
    }
    $names = explode('||', $joined);
    $short = array_map('short_area_name', $names);
    return ' <span class="badge-area badge-linked" title="Also linked to: '
        . htmlspecialchars(implode(', ', $names)) . '">also '
        . htmlspecialchars(implode(', ', $short)) . '</span>';
}

// Plain-text version for the small grid cards: ", also AR1, AR5"
function linked_short_text($joined)
{
    if ($joined === null || $joined === '') {
        return '';
    }
    return '; also ' . implode(', ', array_map('short_area_name', explode('||', $joined)));
}

// SQL piece that gives the linked Area names of file alias $f (use with SELECT ... AS linked_areas)
function linked_areas_select($f = 'f')
{
    return "(SELECT GROUP_CONCAT(a2.area_name ORDER BY a2.sort_order, a2.area_id SEPARATOR '||')
               FROM file_area_links l2 JOIN areas a2 ON a2.area_id = l2.area_id
              WHERE l2.file_id = $f.file_id) AS linked_areas";
}