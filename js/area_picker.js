// ============================================
// js/area_picker.js — Upload page (program/upload.php)
// ============================================
// The Category list follows the "Send to" choice:
//   Normal files -> general categories (and "+ Add new category")
//   an Area      -> only the categories made for that Area
(function () {
    var areaBox = document.getElementById('area_id');
    var cat = document.getElementById('category_id');
    if (!areaBox || !cat) return;
    var fixed = document.getElementById('area_value');   // set when replacing: area cannot change

    function currentArea() {
        return String(fixed ? fixed.value : areaBox.value);
    }
    function sync(fromUser) {
        var area = currentArea();
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
    if (!fixed) areaBox.addEventListener('change', sync);
    sync();
})();