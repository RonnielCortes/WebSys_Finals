<?php
// ============================================
// includes/unit_types.php — the folder TYPES (Education level, Office, Facility...)
// and which type can go inside which. Admin edits them on admin/unit_types.php;
// admin/folders.php and qa/folders.php only read them from here.
//   $level_label      [type_key => 'Label']
//   $allowed_children [parent_key => [child_key, ...]]   ('' = top level)
// ============================================

function default_unit_types()
{
    return [
        'label' => [
            'education_level' => 'Education level',
            'department'      => 'Department',
            'program'         => 'Program',
            'office'          => 'Office',
        ],
        'children' => [
            ''                => ['education_level', 'office'],
            'education_level' => ['department', 'program'],
            'department'      => ['program'],
            'program'         => [],
            'office'          => ['office'],
        ],
    ];
}

// Reads the types from the database. If the tables aren't there yet (unit_types.sql not run),
// it falls back to the old fixed list so nothing breaks.
function load_unit_types($conn)
{
    try {
        $types = $conn->query("SELECT type_key, type_label, at_top, is_system FROM unit_types ORDER BY sort_order, type_key")->fetch_all(MYSQLI_ASSOC);
        if (!$types) {
            return default_unit_types();
        }
        $label = [];
        $children = ['' => []];
        foreach ($types as $t) {
            $label[$t['type_key']] = $t['type_label'];
            $children[$t['type_key']] = [];
        }
        foreach ($types as $t) {
            if ((int)$t['at_top'] === 1) {
                $children[''][] = $t['type_key'];
            }
        }
        $rows = $conn->query("SELECT parent_key, child_key FROM unit_type_children")->fetch_all(MYSQLI_ASSOC);
        $pairs = [];
        foreach ($rows as $r) {
            $pairs[$r['parent_key']][$r['child_key']] = true;
        }
        // keep the same order as the type list
        foreach ($types as $t) {
            foreach ($types as $c) {
                if (isset($pairs[$t['type_key']][$c['type_key']])) {
                    $children[$t['type_key']][] = $c['type_key'];
                }
            }
        }
        return ['label' => $label, 'children' => $children];
    } catch (Throwable $e) {
        return default_unit_types();
    }
}