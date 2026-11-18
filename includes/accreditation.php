<?php
// ============================================
// includes/accreditation.php
//
// Controls which Areas a Program user may access.
//
// PACUCOA example:
//
// PACUCOA
// ├── Area 1  -> BSIT, SHS, Engineering
// ├── Area 2  -> BSIT, SHS, Engineering
// ├── Area 3  -> BSIT, SHS, Engineering
// ├── ...
// └── Area 10 -> BSIT, SHS, Engineering
//
// Access is stored per AREA in area_units.
// ============================================


// --------------------------------------------------
// Return Area IDs accessible by the user's programs
// --------------------------------------------------
function accessible_area_ids($conn, $unit_ids)
{
    $unit_ids = array_values(
        array_unique(
            array_map('intval', (array)$unit_ids)
        )
    );

    if (!$unit_ids) {
        return [];
    }

    $id_list = implode(',', $unit_ids);

    /*
     * A program can access an Area when:
     *
     * area_units.area_id
     *      matches
     * area_units.program_id
     *
     * and that program belongs to the current user.
     *
     * include_children is also supported for compatibility
     * with the existing folder/program hierarchy.
     */

    $parent = [];

    $res = $conn->query(
        "SELECT program_id, parent_id FROM programs"
    );

    while ($r = $res->fetch_assoc()) {
        $parent[(int)$r['program_id']] =
            $r['parent_id'] === null
            ? 0
            : (int)$r['parent_id'];
    }

    // Programs directly assigned to the user
    $self = [];

    // Parent programs of the user's programs
    $above = [];

    foreach ($unit_ids as $id) {

        $self[$id] = true;

        $p = $parent[$id] ?? 0;

        $guard = 0;

        while ($p && $guard++ < 50) {

            $above[$p] = true;

            $p = $parent[$p] ?? 0;
        }
    }

    /*
     * Get all Area assignments.
     */
    $allowed_area_ids = [];

    $res = $conn->query(
        "SELECT area_id, program_id, include_children
         FROM area_units"
    );

    while ($r = $res->fetch_assoc()) {

        $area_id = (int)$r['area_id'];
        $program_id = (int)$r['program_id'];
        $include_children = (int)$r['include_children'];

        // Direct assignment
        if (isset($self[$program_id])) {

            $allowed_area_ids[$area_id] = true;

            continue;
        }

        // Parent assignment with sub-folders enabled
        if (
            $include_children === 1 &&
            isset($above[$program_id])
        ) {

            $allowed_area_ids[$area_id] = true;
        }
    }

    return array_map(
        'intval',
        array_keys($allowed_area_ids)
    );
}


// --------------------------------------------------
// Load Areas
//
// Returns:
//
// [
//     area_id => [
//         'area_id'     => ...,
//         'area_name'   => 'PACUCOA · Area 1',
//         'description' => ...
//     ]
// ]
//
// $only = list of Area IDs to include.
// $extra = Area IDs to include even if not in $only.
// --------------------------------------------------
function load_areas($conn, $only = null, $extra = [])
{
    $want = null;

    if ($only !== null) {

        $want = array_values(
            array_unique(
                array_map(
                    'intval',
                    array_merge(
                        (array)$only,
                        array_filter((array)$extra)
                    )
                )
            )
        );

        if (!$want) {
            return [];
        }
    }

    $sql =
        "SELECT
            a.area_id,
            CONCAT(
                COALESCE(b.accred_name, '-'),
                ' · ',
                a.area_name
            ) AS area_name,
            a.description

         FROM areas a

         LEFT JOIN accreditations b
            ON b.accred_id = a.accred_id"

        . (
            $want !== null
            ? " WHERE a.area_id IN (" . implode(',', $want) . ")"
            : ""
        )

        . "
         ORDER BY
            COALESCE(b.sort_order, 9999),
            b.accred_id,
            a.sort_order,
            a.area_id";

    $areas = [];

    $res = $conn->query($sql);

    while ($r = $res->fetch_assoc()) {

        $areas[(int)$r['area_id']] = $r;
    }

    return $areas;
}
