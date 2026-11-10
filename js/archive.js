// ============================================
// js/archive.js — Admin "Archived Files" page (admin/archive.php)
// Select all, keeps the Delete button disabled until something is ticked,
// and asks "Are you sure?" before deleting.
// ============================================
(function () {
    var form = document.getElementById('ar-form');
    if (!form) return;

    var all  = document.getElementById('ar-all');
    var btn  = document.getElementById('ar-delete');
    var ones = Array.prototype.slice.call(form.querySelectorAll('.ar-one'));

    function selected() {
        return ones.filter(function (c) { return c.checked; }).length;
    }
    function refresh() {
        var n = selected();
        btn.disabled = n === 0;
        btn.textContent = n ? 'Delete completely (' + n + ')' : 'Delete completely';
        if (all) {
            all.checked = n > 0 && n === ones.length;
            all.indeterminate = n > 0 && n < ones.length;
        }
    }

    if (all) {
        all.addEventListener('change', function () {
            ones.forEach(function (c) { c.checked = all.checked; });
            refresh();
        });
    }
    ones.forEach(function (c) { c.addEventListener('change', refresh); });

    form.addEventListener('submit', function (e) {
        var n = selected();
        if (!n) { e.preventDefault(); return; }
        var ok = window.confirm(
            'Delete ' + n + ' file' + (n === 1 ? '' : 's') + ' completely?\n\n' +
            'The files and their history will be removed. This cannot be undone.'
        );
        if (!ok) e.preventDefault();
    });

    refresh();
})();