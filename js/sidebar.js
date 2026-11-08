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

(function(){
    var link = document.querySelector('.logout-btn');
    if (!link) return; 

    link.addEventListener('click', function(e){
        e.preventDefault();
        var href = link.getAttribute('href');

        var overlay = document.createElement('div');
        overlay.className = 'confirm-overlay';
        overlay.innerHTML = '<div class = "confirm-box" role="dialog" aria-model="true" aria-labelledby="logout-title">'+
        '<h3 id="logout-title">Log out?</h3>'+
        '<p>Are you sure you want to logout?</p>' + 
        '<div class="confirm-buttons">' + 
        '<button type="button" class="confirm-no">No</button>'+ 
        '<button type="button" class="confirm-yes">Yes</button>' +
        '</div></div>';

        function close(){
            document.removeEventListener('keydown', onKey);
            overlay.remove();
        }

        function onKey(ev){
            if(ev.key ==='Escape') close();
        }

        overlay.querySelector('.confirm-no').addEventListener('click', close);
        overlay.querySelector('.confirm-yes').addEventListener('click', function(){
            window.location.href=href;
        });
        overlay.addEventListener('click', function(ev){
            if(ev.target === overlay) close();
        });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('.confirm-no').focus();
    })
})();