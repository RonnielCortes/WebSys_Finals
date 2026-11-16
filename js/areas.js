// ============================================
// js/areas.js — Admin Areas page (admin/areas.php)
// ============================================
// "Are you sure?" before an area is deleted
document.querySelectorAll('form.area-del').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var name = form.getAttribute('data-name') || 'this area';
        if (!window.confirm('Delete "' + name + '"? This cannot be undone.')) {
            e.preventDefault();
        }
    });
});