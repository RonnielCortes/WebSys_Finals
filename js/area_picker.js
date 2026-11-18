// ============================================
// js/area_picker.js — Upload page (program/upload.php)
// "Send to" decides if the file needs a category:
//   Normal files -> the Category list is shown (and "+ Add new category")
//   an Area      -> NO category: the Area itself is the category, so the field is hidden
// ============================================
(function () {
    var areaBox = document.getElementById('area_id');
    var field = document.getElementById('category-field');
    var cat = document.getElementById('category_id');
    var newCat = document.getElementById('new_category');
    if (!areaBox || !field || !cat) return;
    var fixed = document.getElementById('area_value');   // set when replacing: area cannot change

    function currentArea() {
        return String(fixed ? fixed.value : areaBox.value);
    }

    function sync() {
        var isArea = currentArea() !== '0';
        field.hidden = isArea;
        // a disabled field is not validated and not sent
        cat.disabled = isArea;
        cat.required = !isArea;
        if (newCat) newCat.disabled = isArea;
        if (isArea) {
            cat.value = '';
            cat.dispatchEvent(new Event('change'));         // lets upload.js hide the "new category" box
        }
    }

    if (!fixed) areaBox.addEventListener('change', sync);
    sync();
})();