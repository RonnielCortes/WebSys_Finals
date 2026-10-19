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