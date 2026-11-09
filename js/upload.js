// ============================================
// js/upload.js — Upload / Replace / Re-submit page (program/upload.php)
// Shows the chosen file's name and size inside the drop zone,
// and blocks files over 10 MB before they are sent.
// (Dragging a file onto the zone works by itself: the real file input covers it.)
// Also: choosing "+ Add new category" in the Category list shows a box to type the name.
// ============================================
(function () {
    var input = document.getElementById('document');
    var zone  = document.getElementById('dropzone');
    var title = document.getElementById('dropzone-title');
    var hint  = document.getElementById('dropzone-hint');
    if (!input || !zone || !title || !hint) return;

    var MAX_BYTES = 10 * 1024 * 1024;           // same limit as the server
    var defaultTitle = title.textContent;
    var defaultHint  = hint.textContent;

    function prettySize(bytes) {
        if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function refresh() {
        var file = input.files && input.files[0];

        if (!file) {
            zone.classList.remove('has-file', 'too-big');
            title.textContent = defaultTitle;
            hint.textContent  = defaultHint;
            input.setCustomValidity('');
            return;
        }

        var tooBig = file.size > MAX_BYTES;
        zone.classList.add('has-file');
        zone.classList.toggle('too-big', tooBig);
        title.textContent = file.name;
        hint.textContent  = prettySize(file.size) + (tooBig ? ' — too big, the limit is 10 MB' : ' · click to change');
        input.setCustomValidity(tooBig ? 'This file is larger than 10 MB.' : '');
    }

    input.addEventListener('change', refresh);

    // highlight the zone while a file is dragged over it
    zone.addEventListener('dragenter', function () { zone.classList.add('dragging'); });
    zone.addEventListener('dragover',  function () { zone.classList.add('dragging'); });
    zone.addEventListener('dragleave', function () { zone.classList.remove('dragging'); });
    zone.addEventListener('drop',      function () { zone.classList.remove('dragging'); });

    refresh();
})();

// ---- "+ Add new category" ----
(function () {
    var select = document.getElementById('category_id');
    var box    = document.getElementById('new_category');
    if (!select || !box) return;

    function refresh() {
        var isNew = select.value === 'new';
        box.hidden   = !isNew;
        box.required = isNew;
        if (isNew) box.focus();
    }

    select.addEventListener('change', refresh);
    // when the page reloads with an error, keep the box open if "new" was chosen
    box.hidden   = select.value !== 'new';
    box.required = select.value === 'new';
})();