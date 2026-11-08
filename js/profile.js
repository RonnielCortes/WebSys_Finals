// ============================================
// js/profile.js — My Profile page (account/my_profile.php)
// "Change photo": the moment a picture is chosen it is checked and sent.
// ============================================
(function () {
    var input = document.getElementById('picture');
    var form  = document.getElementById('photo-form');
    if (!input || !form) return;

    var MAX_BYTES = 2 * 1024 * 1024;   // same 2 MB limit as the server

    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) return;

        if (file.size > MAX_BYTES) {
            alert('That picture is too big. The limit is 2 MB.');
            input.value = '';
            return;
        }
        form.submit();
    });
})();