// ============================================
// js/categories.js — QA Categories page (qa/categories.php)
// Asks "Are you sure?" before a category (or folder) is deleted.
// Also: on the Folders page, only shows the folder types that fit the chosen place.
// ============================================
(function () {
    var forms = document.querySelectorAll('form.cat-del');

    forms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var name = form.getAttribute('data-name') || 'this category';
            if (!window.confirm('Delete the category "' + name + '"? This cannot be undone.')) {
                e.preventDefault();
            }
        });
    });
})();

// ---- qa/folders.php: the "Type" list follows the "Inside" choice ----
(function () {
    var parent = document.getElementById('foldParent');
    var level  = document.getElementById('foldLevel');
    if (!parent || !level) return;

    function sync() {
        var allowed = (parent.options[parent.selectedIndex].getAttribute('data-allowed') || '').split(',');
        var firstOk = null;
        Array.prototype.forEach.call(level.options, function (o) {
            var ok = allowed.indexOf(o.value) !== -1;
            o.disabled = !ok;
            o.hidden = !ok;
            if (ok && firstOk === null) firstOk = o;
        });
        if (level.options[level.selectedIndex].disabled && firstOk) level.value = firstOk.value;
    }
    parent.addEventListener('change', sync);
    sync();
})();