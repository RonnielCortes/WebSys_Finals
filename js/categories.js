// ============================================
// js/categories.js — QA Categories page (qa/categories.php)
// Asks "Are you sure?" before a category is deleted.
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