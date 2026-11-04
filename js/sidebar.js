// ============================================
// sidebar.js — minimize / expand the sidebar (admin, QA and Program pages)
// Loaded right after </aside> by includes/icons.php (sidebar_script()).
// Minimized = only the icons are shown. The choice is remembered in the browser.
// ============================================
(function () {
    var KEY = 'qadms_sidebar_minimized';
    var body = document.body;
    var btn = document.querySelector('.sidebar-toggle');

    function wasMinimized() {
        try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; }
    }
    function remember(minimized) {
        try { localStorage.setItem(KEY, minimized ? '1' : '0'); } catch (e) { /* private mode: just don't remember */ }
    }
    function apply(minimized) {
        body.classList.toggle('sb-minimized', minimized);
        if (btn) {
            btn.setAttribute('aria-expanded', minimized ? 'false' : 'true');
            btn.setAttribute('aria-label', minimized ? 'Expand sidebar' : 'Minimize sidebar');
        }
    }

    // Runs before the page is painted, so there is no flash when you move between pages
    apply(wasMinimized());

    if (btn) {
        btn.addEventListener('click', function () {
            var minimized = !body.classList.contains('sb-minimized');
            apply(minimized);
            remember(minimized);
        });
    }
})();
