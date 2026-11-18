<?php
// ============================================
// includes/accreditation.php — which Areas a program/office may use.
// An accreditation body (PACUCOA...) has Areas and a list of designated units.
// A unit may use the Areas of a body when it is designated, or when one of its
// parent folders is designated WITH "include sub-folders".
// ============================================

// Area ids a user's units (program ids) may use
function accessible_area_ids($conn, $unit_ids)
{
    $unit_ids = array_values(array_unique(array_map('intval', (array)$unit_ids)));
    if (!$unit_ids) {
        return [];
    }
    $parent = [];
    $res = $conn->query("SELECT program_id, parent_id FROM programs");
    while ($r = $res->fetch_assoc()) {
        $parent[(int)$r['program_id']] = $r['parent_id'] === null ? 0 : (int)$r['parent_id'];
    }
    $self = $above = [];
    foreach ($unit_ids as $id) {
        $self[$id] = true;
        $p = $parent[$id] ?? 0;
        $guard = 0;
        while ($p && $guard++ < 50) {
            $above[$p] = true;
            $p = $parent[$p] ?? 0;
        }
    }
    $bodies = [];
    $res = $conn->query("SELECT accred_id, program_id, include_children FROM accreditation_units");
    while ($r = $res->fetch_assoc()) {
        $pid = (int)$r['program_id'];
        if (isset($self[$pid]) || ((int)$r['include_children'] === 1 && isset($above[$pid]))) {
            $bodies[(int)$r['accred_id']] = true;
        }
    }
    if (!$bodies) {
        return [];
    }
    $ids = [];
    $res = $conn->query("SELECT area_id FROM areas WHERE accred_id IN (" . implode(',', array_keys($bodies)) . ")");
    while ($r = $res->fetch_assoc()) {
        $ids[] = (int)$r['area_id'];
    }
    return $ids;
}

// [area_id => ['area_id','area_name' (with the body: "PACUCOA · Area 1"),'description']]
// $only = list of area ids to include (null = all). $extra = ids to include no matter what.
function load_areas($conn, $only = null, $extra = [])
{
    $want = null;
    if ($only !== null) {
        $want = array_values(array_unique(array_map('intval', array_merge((array)$only, array_filter((array)$extra)))));
        if (!$want) {
            return [];
        }
    }
    $sql = "SELECT a.area_id, CONCAT(COALESCE(b.accred_name, '-'), ' · ', a.area_name) AS area_name, a.description
            FROM areas a LEFT JOIN accreditations b ON b.accred_id = a.accred_id"
         . ($want !== null ? " WHERE a.area_id IN (" . implode(',', $want) . ")" : "")
         . " ORDER BY COALESCE(b.sort_order, 9999), b.accred_id, a.sort_order, a.area_id";
    $areas = [];
    $res = $conn->query($sql);
    while ($r = $res->fetch_assoc()) {
        $areas[(int)$r['area_id']] = $r;
    }
    return $areas;
}