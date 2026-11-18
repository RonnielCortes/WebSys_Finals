<?php
// ============================================
// includes/accreditation.php
//
// Controls which Areas a Program user may access.
//
// PACUCOA:
//   BSIT
//   SHS
//   Engineering
//
// All three programs can access ALL PACUCOA Areas.
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


    /*
    |--------------------------------------------------------------------------
    | Get program hierarchy
    |--------------------------------------------------------------------------
    */

    $parent = [];

    $res = $conn->query(
        "SELECT program_id, parent_id
         FROM programs"
    );

    while ($r = $res->fetch_assoc()) {

        $parent[(int)$r['program_id']] =
            $r['parent_id'] === null
            ? 0
            : (int)$r['parent_id'];
    }


    /*
    |--------------------------------------------------------------------------
    | Programs directly assigned to the logged-in user
    |--------------------------------------------------------------------------
    */

    $self = [];

    /*
    |--------------------------------------------------------------------------
    | Parent folders of the user's programs
    |--------------------------------------------------------------------------
    */

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
    |--------------------------------------------------------------------------
    | Find accreditation bodies assigned to the user's programs
    |--------------------------------------------------------------------------
    |
    | admin/areas.php saves designations in:
    |
    | accreditation_units
    |
    | Therefore this function must also use:
    |
    | accreditation_units
    |
    */

    $accreditation_ids = [];

    $res = $conn->query(
        "SELECT accred_id, program_id, include_children
         FROM accreditation_units"
    );

    while ($r = $res->fetch_assoc()) {

        $accred_id = (int)$r['accred_id'];
        $program_id = (int)$r['program_id'];
        $include_children = (int)$r['include_children'];


        /*
        |--------------------------------------------------------------------------
        | Direct designation
        |--------------------------------------------------------------------------
        */

        if (isset($self[$program_id])) {

            $accreditation_ids[$accred_id] = true;

            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | Parent designation
        |--------------------------------------------------------------------------
        |
        | This remains for compatibility with the existing
        | program/folder hierarchy.
        |
        */

        if (
            $include_children === 1 &&
            isset($above[$program_id])
        ) {

            $accreditation_ids[$accred_id] = true;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | No accreditation assigned
    |--------------------------------------------------------------------------
    */

    if (!$accreditation_ids) {
        return [];
    }


    /*
    |--------------------------------------------------------------------------
    | Get ALL Areas belonging to the user's accreditation bodies
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | PACUCOA
    |   Area 1
    |   Area 2
    |   Area 3
    |   ...
    |   Area 10
    |
    | If the user's program is:
    |
    |   BSIT
    |   SHS
    |   Engineering
    |
    | and they are designated for PACUCOA,
    | they can access ALL PACUCOA Areas.
    |
    */

    $accred_ids = array_keys($accreditation_ids);

    $accred_list = implode(
        ',',
        array_map('intval', $accred_ids)
    );

    $allowed_area_ids = [];

    $res = $conn->query(
        "SELECT area_id
         FROM areas
         WHERE accred_id IN ($accred_list)
         ORDER BY sort_order, area_id"
    );

    while ($r = $res->fetch_assoc()) {

        $allowed_area_ids[] = (int)$r['area_id'];
    }


    return $allowed_area_ids;
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


    /*
    |--------------------------------------------------------------------------
    | Limit the Areas when $only is supplied
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | Load Areas together with their accreditation body
    |--------------------------------------------------------------------------
    */

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
            ON b.accred_id = a.accred_id";


    /*
    |--------------------------------------------------------------------------
    | Apply Area filter
    |--------------------------------------------------------------------------
    */

    if ($want !== null) {

        $sql .=
            " WHERE a.area_id IN (" .
            implode(',', $want) .
            ")";
    }


    /*
    |--------------------------------------------------------------------------
    | Sort by accreditation first,
    | then Area order.
    |--------------------------------------------------------------------------
    */

    $sql .=
        " ORDER BY
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
