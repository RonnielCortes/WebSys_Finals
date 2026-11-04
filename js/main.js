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
function openConfirmBox(title, message, yesText, onYes) {
    const overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML =
        '<div class="confirm-box" role="dialog" aria-modal="true">' +
        '<h3></h3><p></p>' +
        '<div class="confirm-buttons">' +
        '<button type="button" class="confirm-no">No, keep it</button>' +
        '<button type="button" class="confirm-yes"></button>' +
        '</div></div>';

    overlay.querySelector('h3').textContent = title;
    overlay.querySelector('p').textContent = message;
    overlay.querySelector('.confirm-yes').textContent = yesText;

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
    if (!form.classList || !form.classList.contains('js-confirm-delete')) return;

    e.preventDefault();
    const name = form.dataset.filename || 'this file';
    openConfirmBox(
        'Delete this file?',
        '"' + name + '" will be removed from your list and QA will be told. ' +
        'This can only be done because QA has not started reviewing it yet.',
        'Yes, delete',
        function () { form.submit(); } // form.submit() does not re-trigger this listener
    );
});