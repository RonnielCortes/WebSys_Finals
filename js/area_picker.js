// ============================================
// js/area_picker.js — Upload page (program/upload.php)
// ============================================
// 1) The Category list follows the "Send to" choice:
//      Normal files -> general categories (and "+ Add new category")
//      an Area      -> only the categories made for that Area
// 2) The "Also link to" tick-boxes only appear for an Area, and never offer
//    the Area the file is already in.
(function () {
    var areaBox = document.getElementById('area_id');
    var cat = document.getElementById('category_id');
    if (!areaBox || !cat) return;
    var fixed = document.getElementById('area_value');   // set when replacing: area cannot change
    var linkField = document.getElementById('link-field');

    function currentArea() {
        return String(fixed ? fixed.value : areaBox.value);
    }

    function syncCategories(area) {
        var keepChoice = true;
        Array.prototype.forEach.call(cat.options, function (o) {
            if (o.value === '') return;                     // "Select a category"
            var ok = o.getAttribute('data-area') === area;
            o.hidden = !ok;
            o.disabled = !ok;
            if (o.selected && !ok) keepChoice = false;
        });
        if (!keepChoice) {                                  // the chosen category is not in this place
            cat.value = '';
            cat.dispatchEvent(new Event('change'));         // lets upload.js hide the "new category" box
        }
    }

    function syncLinks(area) {
        if (!linkField) return;
        linkField.hidden = (area === '0');                  // normal files have no Area to link
        Array.prototype.forEach.call(linkField.querySelectorAll('.link-check'), function (label) {
            var same = label.getAttribute('data-area') === area;   // its own Area cannot be linked
            var box = label.querySelector('input');
            label.hidden = same;
            if (same) box.checked = false;
            box.disabled = same || area === '0';            // a disabled box is not sent
        });
    }

    function sync() {
        var area = currentArea();
        syncCategories(area);
        syncLinks(area);
    }

    if (!fixed) areaBox.addEventListener('change', sync);
    sync();
})();