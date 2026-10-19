function toggleProgramField()
{
    const roleField = document.getElementById('role');
    const programField = document.getElementById('program-field');

    if (!roleField || !programField) return;

    programField.style.display = (roleField.value === 'program') ? 'block' : 'none';
}

function validateRegisterForm()
{
    const password = document.getElementById('password');
    const confirm = document.getElementById('confirm_password');

    if (!password || !confirm) return;
    if(password.value !== confirm.value)
        {
          alert('Passwords do not match!');
          return false;
        }
        return true;
}

function togglePasswordVisibility()
{
    const password = document.getElementById('password');
    password.type = password.type === 'password' ? 'text' : "password";
}