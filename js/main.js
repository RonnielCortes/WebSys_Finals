// ============================================
// main.js — shared vanilla JS for the whole system
// ============================================

// Shows/hides the Program/Office dropdown on the register page,
// depending on which Account Type was selected.
function toggleProgramField() {
    const roleField = document.getElementById('role');
    const programField = document.getElementById('program-field');
    if (!roleField || !programField) return;

    programField.style.display = (roleField.value === 'program') ? 'block' : 'none';
}


// Simple client-side check before the register form is allowed to submit.
// (The server still re-checks all of this — this is just for a faster user experience.)
function validateRegisterForm() {
    // A Program / Office account must add at least one program/office
    const role = document.getElementById('role');
    if (role && role.value === 'program' &&
        !document.querySelector('#program-field input[name="units[]"]')) {
        alert('Please choose your program/office and press Add.');
        return false;
    }

    // Password and confirm-password must match
    const password = document.getElementById('password');
    const confirm = document.getElementById('confirm_password');
    if (!password || !confirm) return true;

    if (password.value !== confirm.value) {
        alert('Passwords do not match.');
        return false;
    }
    return true;
}

// Show / hide a password field. The button sits next to the input inside .pw-wrap.
function togglePassword(btn) {
    const input = btn.parentElement.querySelector('input');
    if (!input) return;

    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.classList.toggle('showing', show);
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}

// ============================================
// Delete confirmation (Program > My Files)
// Any <form class="js-confirm-delete" data-filename="..."> shows a big,
// clear box first. The form only submits if the user presses "Yes, delete".
// ============================================
function openConfirmBox(title, message, yesText, onYes, tone) {
    const overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML =
        '<div class="confirm-box" role="dialog" aria-modal="true">' +
        '<h3></h3><p></p>' +
        '<div class="confirm-buttons">' +
        '<button type="button" class="confirm-no">No, go back</button>' +
        '<button type="button" class="confirm-yes"></button>' +
        '</div></div>';

    overlay.querySelector('h3').textContent = title;
    overlay.querySelector('p').textContent = message;
    overlay.querySelector('.confirm-yes').textContent = yesText;
    if (tone === 'good') overlay.querySelector('.confirm-yes').classList.add('good');

    function close() {
        document.removeEventListener('keydown', onKey);
        overlay.remove();
    }
    function onKey(e) {
        if (e.key === 'Escape') close();
    }

    overlay.querySelector('.confirm-no').addEventListener('click', close);
    overlay.querySelector('.confirm-yes').addEventListener('click', function () {
        close();
        onYes();
    });
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) close();
    });
    document.addEventListener('keydown', onKey);

    document.body.appendChild(overlay);
    overlay.querySelector('.confirm-no').focus(); // safest choice is the default
}

document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!form.classList) return;

    // Program > My Files: delete a file
    if (form.classList.contains('js-confirm-delete')) {
        e.preventDefault();
        const name = form.dataset.filename || 'this file';
        openConfirmBox(
            'Delete this file?',
            '"' + name + '" will be removed from your list and QA will be told. ' +
            'This can only be done because QA has not started reviewing it yet.',
            'Yes, delete',
            function () { form.submit(); } // form.submit() does not re-trigger this listener
        );
        return;
    }

    // Anywhere else: <form class="js-confirm" data-confirm-title data-confirm-message data-confirm-yes>
    if (form.classList.contains('js-confirm')) {
        e.preventDefault();
        openConfirmBox(
            form.dataset.confirmTitle || 'Are you sure?',
            form.dataset.confirmMessage || '',
            form.dataset.confirmYes || 'Yes',
            function () { form.submit(); },
            form.dataset.confirmTone
        );
    }
});

// ============================================
// Program / Office check-list (Register, Admin > Edit Account)
//   Register : choose where you belong first -> only what is under it is listed
//   Edit     : everything is listed
//   Both     : a search box (searching looks at everything)
// ============================================
(function () {
    document.querySelectorAll('[data-unit-picker]').forEach(function (box) {
        var cascade = box.hasAttribute('data-cascade');
        var root    = box.querySelector('.unit-root');
        var search  = box.querySelector('.unit-search');
        var list    = box.querySelector('.unit-list');
        var items   = box.querySelectorAll('.unit-item');
        var groups  = box.querySelectorAll('.unit-group');
        var empty   = box.querySelector('.unit-empty');
        var count   = box.querySelector('[data-unit-count]');
        var chosen  = box.querySelector('[data-unit-chosen]');

        function updateCount() {
            var on = box.querySelectorAll('input[type="checkbox"]:checked');
            if (count) count.textContent = on.length;
            if (chosen) {
                var names = [];
                on.forEach(function (c) { names.push(c.parentNode.querySelector('.unit-name').textContent); });
                chosen.textContent = names.length ? ': ' + names.join(', ') : '';
            }
        }

        function apply() {
            var q = search.value.trim().toLowerCase();
            var r = root ? root.value : '';
            var waiting = cascade && q === '' && r === '';   // nothing chosen yet
            var shown = 0;

            items.forEach(function (it) {
                var ok;
                if (q !== '')      ok = it.getAttribute('data-text').indexOf(q) !== -1;
                else if (cascade)  ok = r !== '' && it.getAttribute('data-root') === r;
                else               ok = true;
                it.hidden = !ok;
                if (ok) shown++;
            });
            groups.forEach(function (g) { g.hidden = !g.querySelector('.unit-item:not([hidden])'); });

            list.hidden = waiting;
            if (empty) empty.hidden = waiting || shown !== 0;
        }

        search.addEventListener('input', apply);
        // Enter in the search box must not submit the form
        search.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
        if (root) root.addEventListener('change', function () { search.value = ''; apply(); });
        box.addEventListener('change', updateCount);
        apply();
        updateCount();
    });
})();

// ============================================
// Register: cascading Program / Office picker
// Shows one dropdown at a time. Each choice with sub-folders opens the next
// dropdown; the last choice (no children) becomes the hidden program_id.
// ============================================
function initProgramPicker() {
    const box    = document.getElementById('program-levels');
    const hidden = document.getElementById('program_id');
    if (!box || !hidden || typeof PROGRAMS === 'undefined') return;

    function childrenOf(parentId) {
        return PROGRAMS.filter(function (p) { return p.parent === parentId; });
    }

    function addLevel(parentId) {
        const kids = childrenOf(parentId);
        if (!kids.length) return;

        const sel = document.createElement('select');
        sel.className = 'pl-select';
        sel.innerHTML = '<option value="">Choose your designated Office or Program</option>';
        kids.forEach(function (k) {
            const o = document.createElement('option');
            o.value = k.id;
            o.textContent = k.name;
            sel.appendChild(o);
        });

        sel.addEventListener('change', function () {
            // remove every dropdown below this one
            while (sel.nextElementSibling) sel.nextElementSibling.remove();
            hidden.value = '';
            if (!sel.value) return;

            const id = parseInt(sel.value, 10);

            // show the meaning (description) of the chosen program under its dropdown
            const chosen = PROGRAMS.find(function (p) { return p.id === id; });
            if (chosen && chosen.desc) {
                const note = document.createElement('div');
                note.className = 'pl-desc';
                note.textContent = chosen.desc;   // textContent = safe, no HTML injection
                box.appendChild(note);
            }

            if (childrenOf(id).length) {
                addLevel(id);        // has sub-folders: show the next dropdown
            } else {
                hidden.value = id;   // last level: this is the program/office
            }
        });

        box.appendChild(sel);
    }

    addLevel(0);   // top level (parent_id = 0)
}
document.addEventListener('DOMContentLoaded', initProgramPicker);

// ============================================
// Register: Program / Office picker, step by step, more than one allowed
//   1. choose where you belong (College, SMS...)  ->  the next dropdown opens
//   2. keep choosing until you reach your department/office/program
//   3. press "Add"  -> it goes to the list below (one hidden units[] input each)
//   4. repeat if you handle another one; X removes a mistake
// ============================================
function initUnitCascade() {
    const root = document.getElementById('unit-cascade');
    if (!root || typeof UNITS === 'undefined') return;

    const levels = root.querySelector('.uc-levels');
    const descEl = root.querySelector('.uc-desc');
    const addBtn = root.querySelector('.uc-add');
    const chips  = root.querySelector('.uc-chips');

    const byId = {};
    UNITS.forEach(function (u) { byId[u.id] = u; });

    let picked  = [];   // ids already added
    let current = 0;    // the deepest dropdown that has a value (0 = nothing yet)

    function kidsOf(parentId) {
        return UNITS.filter(function (u) { return u.parent === parentId; })
                    .sort(function (a, b) { return a.name.localeCompare(b.name); });
    }
    function pathOf(id) {   // "College > CASE-IT > BSIT"
        const names = [];
        let cur = byId[id], guard = 0;
        while (cur && guard++ < 10) {
            names.unshift(cur.name);
            cur = cur.parent ? byId[cur.parent] : null;
        }
        return names.join(' > ');
    }

    function refreshAddArea() {
        const u = current ? byId[current] : null;
        if (u && u.desc) {
            descEl.textContent = u.desc;     // textContent = safe, no HTML injection
            descEl.hidden = false;
        } else {
            descEl.hidden = true;
        }
        const already = picked.indexOf(current) !== -1;
        addBtn.disabled = !u || already;
        addBtn.textContent = !u ? '+ Add'
                            : already ? u.name + ' is already added'
                            : '+ Add ' + u.name;
    }

    function addLevel(parentId) {
        const kids = kidsOf(parentId);
        if (!kids.length) return;

        const sel = document.createElement('select');
        sel.className = 'uc-select';
        sel.innerHTML = '<option value="">' +
            (parentId === 0 ? 'Choose where you belong' : 'Choose what is under it (optional)') + '</option>';
        kids.forEach(function (k) {
            const o = document.createElement('option');
            o.value = k.id;
            o.textContent = k.name;
            sel.appendChild(o);
        });

        sel.addEventListener('change', function () {
            while (sel.nextElementSibling) sel.nextElementSibling.remove();   // close the dropdowns below
            if (!sel.value) {
                current = parentId;           // went back: the parent is the choice again
            } else {
                current = parseInt(sel.value, 10);
                addLevel(current);            // opens the next dropdown only if it has something under it
            }
            refreshAddArea();
        });
        levels.appendChild(sel);
    }

    function resetLevels() {
        levels.innerHTML = '';
        current = 0;
        addLevel(0);
        refreshAddArea();
    }

    function renderChips() {
        chips.innerHTML = '';
        picked.forEach(function (id) {
            const chip = document.createElement('div');
            chip.className = 'uc-chip';

            const text = document.createElement('span');
            const strong = document.createElement('strong');
            strong.textContent = byId[id].name;
            const small = document.createElement('small');
            small.textContent = pathOf(id);
            text.appendChild(strong);
            text.appendChild(small);

            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'uc-x';
            x.setAttribute('aria-label', 'Remove ' + byId[id].name);
            x.textContent = '\u00d7';
            x.addEventListener('click', function () {
                picked = picked.filter(function (p) { return p !== id; });
                renderChips();
                refreshAddArea();
            });

            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'units[]';
            hidden.value = id;

            chip.appendChild(text);
            chip.appendChild(x);
            chip.appendChild(hidden);
            chips.appendChild(chip);
        });
    }

    addBtn.addEventListener('click', function () {
        if (!current || picked.indexOf(current) !== -1) return;
        picked.push(current);
        renderChips();
        resetLevels();   // ready for the next one
    });

    // keep what was chosen before if the page came back with an error
    if (typeof UNITS_PICKED !== 'undefined') {
        picked = UNITS_PICKED.filter(function (id) { return byId[id]; });
        renderChips();
    }
    resetLevels();
}
document.addEventListener('DOMContentLoaded', initUnitCascade);