<?php
// ============================================
// program/upload.php
//
// upload.php              -> send a NEW file to QA
// upload.php?replace=ID   -> replace a file
//
// A file belongs to ONE Area only.
// There is NO "link to other areas" feature.
// ============================================

require_once __DIR__ . '/../includes/check.php';
require_role('program');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/accreditation.php';

$active_page = 'upload';

$user_id = (int)$_SESSION['user_id'];


// ============================================
// PROGRAM / OFFICE
// ============================================

$unit_ids = session_program_ids();

if (!$unit_ids) {
    die(
        "Your account is not assigned to a program/office yet. " .
        "Please contact the Admin."
    );
}

$unit_list  = id_list($unit_ids);
$unit_names = program_names($conn, $unit_ids);

if (!$unit_names) {
    die(
        "Your program/office no longer exists. " .
        "Please contact the Admin."
    );
}


// ============================================
// CATEGORIES
// ============================================

$categories = [];

$cat_result = $conn->query(
    "SELECT category_id, category_name, area_id
     FROM file_categories
     ORDER BY category_name"
);

while ($row = $cat_result->fetch_assoc()) {

    $categories[(int)$row['category_id']] = [
        'name' => $row['category_name'],
        'area' => (int)$row['area_id']
    ];
}


// ============================================
// ACCESSIBLE AREAS
//
// Program can only select Areas assigned to
// its accreditation/program.
// ============================================

$my_area_ids = accessible_area_ids(
    $conn,
    $unit_ids
);

$areas = load_areas(
    $conn,
    $my_area_ids
);


// ============================================
// REPLACE / RE-SUBMIT MODE
// ============================================

$replace_id = (int)(
    $_GET['replace']
    ?? $_POST['replace']
    ?? 0
);

$old = null;

if ($replace_id) {

    $stmt = $conn->prepare(
        "SELECT
            file_id,
            file_path,
            status,
            category_id,
            area_id,
            is_archived,
            program_id

         FROM files

         WHERE file_id = ?
           AND program_id IN ($unit_list)"
    );

    $stmt->bind_param(
        "i",
        $replace_id
    );

    $stmt->execute();

    $old = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();


    if (
        !$old ||
        $old['is_archived'] ||
        !program_can_replace($old['status'])
    ) {

        header(
            "Location: dashboard.php?flash=cannot_replace"
        );

        exit();
    }
}


// ============================================
// WHICH PROGRAM / OFFICE
// ============================================

$unit_posted = (int)(
    $_POST['unit']
    ?? 0
);

if ($old) {

    $program_id = (int)$old['program_id'];

} elseif (
    in_array(
        $unit_posted,
        $unit_ids,
        true
    )
) {

    $program_id = $unit_posted;

} else {

    $program_id = $unit_ids[0];
}

$program_name =
    $unit_names[$program_id]
    ?? 'your program';


// ============================================
// FORM STATE
// ============================================

$error = "";

$is_resubmit =
    $old &&
    $old['status'] === 'needs_revision';

$selected_category =
    $old
        ? (int)$old['category_id']
        : 0;

$selected_area =
    $old
        ? (int)$old['area_id']
        : 0;


// If replacing an old file whose Area is no
// longer currently accessible, still show that
// Area so the replacement stays in the same Area.

if (
    $selected_area &&
    !isset($areas[$selected_area])
) {

    $old_area = load_areas(
        $conn,
        [$selected_area]
    );

    $areas = $areas + $old_area;
}


$choose_new = false;
$new_cat_text = "";
$note = "";


// ============================================
// FORM SUBMISSION
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $cat_choice =
        $_POST['category_id']
        ?? '';

    $note =
        trim(
            $_POST['note']
            ?? ''
        );


    // ----------------------------------------
    // New file can choose an Area.
    // Replacement keeps the old Area.
    // ----------------------------------------

    if (!$old) {

        $selected_area =
            (int)(
                $_POST['area_id']
                ?? 0
            );
    }


    // ----------------------------------------
    // Category
    // ----------------------------------------

    if ($cat_choice === 'new') {

        $choose_new = true;

        $selected_category = 0;

        $new_cat_text =
            clean_category_name(
                $_POST['new_category']
                ?? ''
            );

    } else {

        $selected_category =
            (int)$cat_choice;
    }


    // ----------------------------------------
    // Validation
    // ----------------------------------------

    if (
        empty($_POST) &&
        empty($_FILES) &&
        (int)(
            $_SERVER['CONTENT_LENGTH']
            ?? 0
        ) > 0
    ) {

        $error =
            "That file is too big for the server settings " .
            "(post_max_size is " .
            ini_get('post_max_size') .
            "). The system allows up to 10 MB.";

    } elseif (
        !$old &&
        count($unit_ids) > 1 &&
        !in_array(
            $unit_posted,
            $unit_ids,
            true
        )
    ) {

        $error =
            "Please choose which program/office " .
            "this file is for.";

    } elseif (
        $selected_area &&
        !isset($areas[$selected_area])
    ) {

        $error =
            "Please choose a valid area.";

    } elseif (
        $choose_new &&
        $selected_area
    ) {

        $error =
            "New categories can only be added " .
            "to normal files. Please pick one " .
            "from the list.";

    } elseif (
        $choose_new &&
        ($msg = category_name_error($new_cat_text)) !== ''
    ) {

        $error = $msg;

    } elseif (
        !$choose_new &&
        !isset(
            $categories[$selected_category]
        )
    ) {

        $error =
            "Please choose a category.";

    } elseif (
        !$choose_new &&
        $categories[$selected_category]['area']
            !== $selected_area
    ) {

        $error =
            "That category does not belong to " .
            "the Area you chose. Please pick again.";

    } else {

        $error =
            validate_upload(
                $_FILES['document']
                ?? null
            );
    }


    // ========================================
    // SAVE FILE
    // ========================================

    if ($error === '') {

        $path = store_uploaded_file(
            $_FILES['document']['tmp_name'],
            $_FILES['document']['name'],
            $program_name
        );


        if ($path === false) {

            $error =
                "The file could not be saved on the server. " .
                "Check that the uploads folder is writable.";

        } else {

            try {

                $conn->begin_transaction();


                // --------------------------------
                // Create / reuse category
                // --------------------------------

                if ($choose_new) {

                    $selected_category =
                        find_or_create_category(
                            $conn,
                            $new_cat_text,
                            0
                        );
                }


                // --------------------------------
                // Insert NEW file
                // --------------------------------

                $replaces =
                    $old
                        ? (int)$old['file_id']
                        : null;

                $area_value =
                    $selected_area
                        ? $selected_area
                        : null;


                $stmt = $conn->prepare(
                    "INSERT INTO files
                    (
                        file_path,
                        uploaded_by,
                        program_id,
                        category_id,
                        area_id,
                        status,
                        replaces_file_id
                    )

                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'sent',
                        ?
                    )"
                );


                /*
                 * IMPORTANT:
                 *
                 * mysqli bind_param does not
                 * accept NULL as an integer in
                 * the same way we need here.
                 *
                 * So use variables whose values
                 * may be NULL.
                 */

                $stmt->bind_param(
                    "siiiii",
                    $path,
                    $user_id,
                    $program_id,
                    $selected_category,
                    $area_value,
                    $replaces
                );

                $stmt->execute();

                $new_id =
                    (int)$conn->insert_id;

                $stmt->close();


                // --------------------------------
                // Archive OLD version
                // --------------------------------

                if ($old) {

                    $stmt = $conn->prepare(
                        "UPDATE files

                         SET is_archived = 1

                         WHERE file_id = ?
                           AND program_id = ?
                           AND is_archived = 0
                           AND status = ?"
                    );


                    $stmt->bind_param(
                        "iis",
                        $old['file_id'],
                        $program_id,
                        $old['status']
                    );


                    $stmt->execute();


                    if (
                        $stmt->affected_rows !== 1
                    ) {

                        throw new RuntimeException(
                            "QA already started working " .
                            "on this file, so it can't " .
                            "be replaced anymore."
                        );
                    }


                    $stmt->close();


                    // --------------------------------
                    // OLD file history
                    // --------------------------------

                    $action = 'replaced';

                    $remarks =
                        "Replaced by file #" .
                        $new_id;


                    $stmt = $conn->prepare(
                        "INSERT INTO file_logs
                        (
                            file_id,
                            action,
                            performed_by,
                            remarks
                        )

                        VALUES (?, ?, ?, ?)"
                    );


                    $stmt->bind_param(
                        "isis",
                        $old['file_id'],
                        $action,
                        $user_id,
                        $remarks
                    );


                    $stmt->execute();

                    $stmt->close();
                }


                // --------------------------------
                // NEW file history
                // --------------------------------

                $action =
                    $is_resubmit
                        ? 'resubmitted'
                        : 'sent';


                $remarks = $note;


                if ($old) {

                    $remarks =
                        "Replaces file #" .
                        $old['file_id'] .
                        (
                            $note !== ''
                                ? ". " . $note
                                : ""
                        );
                }


                $stmt = $conn->prepare(
                    "INSERT INTO file_logs
                    (
                        file_id,
                        action,
                        performed_by,
                        remarks
                    )

                    VALUES (?, ?, ?, ?)"
                );


                $stmt->bind_param(
                    "isis",
                    $new_id,
                    $action,
                    $user_id,
                    $remarks
                );


                $stmt->execute();

                $stmt->close();


                // --------------------------------
                // Notify QA
                // --------------------------------

                $prefix =
                    $is_resubmit
                        ? "Re-submitted file from "
                        : (
                            $old
                                ? "Replaced file from "
                                : "New file from "
                        );


                notify_active_qa(
                    $conn,
                    $new_id,
                    $prefix .
                    $program_name .
                    ": " .
                    display_name($path)
                );


                // --------------------------------
                // Commit
                // --------------------------------

                $conn->commit();


                header(
                    "Location: dashboard.php?flash=" .
                    (
                        $is_resubmit
                            ? 'resubmitted'
                            : (
                                $old
                                    ? 'replaced'
                                    : 'uploaded'
                            )
                    )
                );

                exit();


            } catch (Throwable $e) {

                $conn->rollback();


                $shown =
                    $e instanceof RuntimeException;


                // Remove uploaded file if DB failed
                $full =
                    dirname(__DIR__) .
                    '/' .
                    $path;


                if (is_file($full)) {
                    unlink($full);
                }


                $error =
                    $shown
                        ? $e->getMessage()
                        : "Something went wrong while saving. " .
                          "Please try again.";
            }
        }
    }
}


$page_title =
    $is_resubmit
        ? 'Re-submit File'
        : (
            $old
                ? 'Replace File'
                : 'Upload File'
        );

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <title>
        <?= htmlspecialchars($page_title) ?>
    </title>

    <link
        rel="stylesheet"
        href="../css/style.css?v=<?= @filemtime(
            __DIR__ . '/../css/style.css'
        ) ?>"
    >

    <link
        rel="stylesheet"
        href="../css/upload.css?v=<?= @filemtime(
            __DIR__ . '/../css/upload.css'
        ) ?>"
    >

</head>


<body class="with-sidebar">


<?php
include __DIR__ . '/../includes/program_sidebar.php';
?>


<main class="main-content upload-page">

    <div class="upload-wrap">


        <h2>
            <?= htmlspecialchars($page_title) ?>
        </h2>


        <p class="muted">

            Sending as

            <strong>

                <?=
                    (!$old && count($unit_ids) > 1)
                        ? 'one of your programs/offices'
                        : htmlspecialchars($program_name)
                ?>

            </strong>

        </p>


        <?php if ($old): ?>

            <div class="alert alert-info">

                You are replacing

                <strong>
                    <?= htmlspecialchars(
                        display_name(
                            $old['file_path']
                        )
                    ) ?>
                </strong>.

                The old version is kept in
                your archive, not erased.

            </div>

        <?php endif; ?>


        <?php if ($error): ?>

            <div class="alert alert-error">

                <?= htmlspecialchars($error) ?>

            </div>

        <?php endif; ?>


        <form
            method="POST"
            action="upload.php<?= $old
                ? '?replace=' . (int)$old['file_id']
                : '' ?>"
            enctype="multipart/form-data"
            class="upload-card"
        >


            <?php if ($old): ?>

                <input
                    type="hidden"
                    name="replace"
                    value="<?= (int)$old['file_id'] ?>"
                >

            <?php endif; ?>


            <?php if (
                !$old &&
                count($unit_ids) > 1
            ): ?>

                <div class="upload-field">

                    <label for="unit">
                        Send for
                    </label>


                    <select
                        name="unit"
                        id="unit"
                        required
                    >

                        <option value="">
                            Select program/office
                        </option>


                        <?php foreach (
                            $unit_names as $uid => $uname
                        ): ?>

                            <option
                                value="<?= (int)$uid ?>"
                                <?= $unit_posted === $uid
                                    ? 'selected'
                                    : '' ?>
                            >

                                <?= htmlspecialchars($uname) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            <?php endif; ?>


            <!-- =================================
                 AREA
            ================================== -->

            <?php if ($areas): ?>

                <div class="upload-field">

                    <label for="area_id">
                        Send to
                    </label>


                    <?php if ($old): ?>

                        <input
                            type="text"
                            id="area_id"
                            disabled
                            value="<?=
                                htmlspecialchars(
                                    isset(
                                        $areas[
                                            $selected_area
                                        ]
                                    )
                                        ? $areas[
                                            $selected_area
                                        ]['area_name'] .
                                        (
                                            $areas[
                                                $selected_area
                                            ]['description']
                                                ? ' - ' .
                                                $areas[
                                                    $selected_area
                                                ]['description']
                                                : ''
                                        )
                                        : 'Normal files (no area)'
                                )
                            ?>"
                        >


                        <input
                            type="hidden"
                            name="area_id"
                            value="<?= (int)$selected_area ?>"
                        >

                    <?php else: ?>

                        <select
                            name="area_id"
                            id="area_id"
                        >

                            <option value="0">
                                Normal files (no area)
                            </option>


                            <?php foreach (
                                $areas as $aid => $a
                            ): ?>

                                <option
                                    value="<?= (int)$aid ?>"
                                    <?= $selected_area === (int)$aid
                                        ? 'selected'
                                        : '' ?>
                                >

                                    <?= htmlspecialchars(
                                        $a['area_name'] .
                                        (
                                            $a['description']
                                                ? ' - ' .
                                                $a['description']
                                                : ''
                                        )
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    <?php endif; ?>

                </div>

            <?php else: ?>

                <!--
                    No accessible Areas.
                    Keep area_id = 0 so the file
                    can still be uploaded normally.
                -->

                <input
                    type="hidden"
                    name="area_id"
                    value="0"
                >

            <?php endif; ?>


            <!-- =================================
                 CATEGORY
            ================================== -->

            <div class="upload-field">

                <label for="category_id">
                    Category
                </label>


                <select
                    name="category_id"
                    id="category_id"
                    required
                >

                    <option value="">
                        Select a category
                    </option>


                    <?php foreach (
                        $categories as $id => $c
                    ): ?>

                        <option
                            value="<?= (int)$id ?>"
                            data-area="<?= (int)$c['area'] ?>"
                            <?= (
                                !$choose_new &&
                                $selected_category === (int)$id
                            )
                                ? 'selected'
                                : '' ?>
                        >

                            <?= htmlspecialchars(
                                $c['name']
                            ) ?>

                        </option>

                    <?php endforeach; ?>


                    <option
                        value="new"
                        data-area="0"
                        <?= $choose_new
                            ? 'selected'
                            : '' ?>
                    >
                        + Add new category&hellip;
                    </option>

                </select>


                <input
                    type="text"
                    name="new_category"
                    id="new_category"
                    maxlength="50"
                    placeholder="Type the new category name"
                    value="<?= htmlspecialchars(
                        $new_cat_text
                    ) ?>"
                    <?= $choose_new
                        ? ''
                        : 'hidden' ?>
                >

            </div>


            <!-- =================================
                 FILE
            ================================== -->

            <div class="upload-field">

                <span class="field-label">

                    <?= $old
                        ? 'New file'
                        : 'File' ?>

                </span>


                <label
                    for="document"
                    class="dropzone"
                    id="dropzone"
                >

                    <?= icon('upload') ?>


                    <span
                        class="dropzone-title"
                        id="dropzone-title"
                    >

                        <?= $old
                            ? 'Choose the new file'
                            : 'Choose a file' ?>

                    </span>


                    <span
                        class="dropzone-hint"
                        id="dropzone-hint"
                    >

                        PDF, DOCX or XLSX
                        &middot;
                        up to 10 MB

                    </span>


                    <input
                        type="file"
                        name="document"
                        id="document"
                        accept=".pdf,.docx,.xlsx"
                        required
                    >

                </label>

            </div>


            <!-- =================================
                 NOTE FOR QA
            ================================== -->

            <div class="upload-field">

                <label for="note">

                    Note for QA

                    <span class="optional">
                        (optional)
                    </span>

                </label>


                <textarea
                    name="note"
                    id="note"
                    rows="3"
                    maxlength="500"
                    placeholder="<?= $is_resubmit
                        ? 'What did you fix?'
                        : (
                            $old
                                ? 'Why are you replacing it?'
                                : 'Anything QA should know?'
                        ) ?>"
                ><?= htmlspecialchars($note) ?></textarea>

            </div>


            <!-- =================================
                 ACTIONS
            ================================== -->

            <div class="upload-actions">

                <a
                    href="dashboard.php"
                    class="upload-cancel"
                >
                    Cancel
                </a>


                <button
                    type="submit"
                    class="upload-submit"
                >

                    <?= $is_resubmit
                        ? 'Re-submit'
                        : (
                            $old
                                ? 'Replace file'
                                : 'Send to QA'
                        ) ?>

                </button>

            </div>


        </form>


    </div>

</main>


<script
    src="../js/upload.js?v=<?= @filemtime(
        __DIR__ . '/../js/upload.js'
    ) ?>"
></script>

<script
    src="../js/area_picker.js?v=<?= @filemtime(
        __DIR__ . '/../js/area_picker.js'
    ) ?>"
></script>


</body>

</html>