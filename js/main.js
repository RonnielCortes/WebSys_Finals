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


// Simple client-side check: password and confirm-password must match
// before the register form is allowed to submit.
// (The server still re-checks this — this is just for a faster user experience.)
function validateRegisterForm() {
    const password = document.getElementById('password');
    const confirm = document.getElementById('confirm_password');
    if (!password || !confirm) return true;

    if (password.value !== confirm.value) {
        alert('Passwords do not match.');
        return false;
    }

    // a Program user must tick at least one program/office
    const role = document.getElementById('role');
    if (role && role.value === 'program' && !document.querySelector('[data-unit-picker] input[type="checkbox"]:checked')) {
        alert('Please tick at least one program/office.');
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