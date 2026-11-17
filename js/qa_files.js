// ============================================
// js/qa_files.js — QA > Files page (qa/files.php)
// The Area and Category dropdowns apply as soon as you choose one.
// ============================================
document.querySelectorAll('.qf-tools select[data-autosubmit]').forEach(function (sel) {
    sel.addEventListener('change', function () {
        // a new Area means the old Category may not exist there: go back to "All"
        if (sel.name === 'area' && sel.form.elements['category']) {
            sel.form.elements['category'].value = '0';
        }
        sel.form.submit();
    });
});