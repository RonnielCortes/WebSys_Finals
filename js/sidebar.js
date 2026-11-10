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

// ============================================
// Success / error messages ("Category added.", "File deleted.", ...) pop up in the
// top-right corner for a few seconds, then disappear by themselves.
// Works on every page that has a sidebar; no change needed in the PHP pages.
// (The blue .alert-info notes, like "You are replacing ...", stay where they are.)
// ============================================
(function () {
    var alerts = document.querySelectorAll('.main-content .alert-success, .main-content .alert-error');
    if (!alerts.length) return;

    var stack = document.createElement('div');
    stack.className = 'toast-stack';
    document.body.appendChild(stack);

    Array.prototype.forEach.call(alerts, function (el) {
        var isError = el.classList.contains('alert-error');
        var ms = isError ? 6000 : 4000;           // errors stay a little longer
        var timer = null;

        el.classList.add('toast');
        el.setAttribute('role', isError ? 'alert' : 'status');
        el.style.setProperty('--toast-ms', ms + 'ms');

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', 'Close');
        close.innerHTML = '&times;';
        el.appendChild(close);

        function hide() {
            clearTimeout(timer);
            el.classList.add('toast-hide');
            setTimeout(function () { el.remove(); }, 300);
        }
        function start(wait) { timer = setTimeout(hide, wait); }

        close.addEventListener('click', hide);
        // hover = pause, so the message can be read in peace
        el.addEventListener('mouseenter', function () { clearTimeout(timer); el.classList.add('toast-paused'); });
        el.addEventListener('mouseleave', function () { el.classList.remove('toast-paused'); start(1500); });

        stack.appendChild(el);                    // moves it out of the page into the corner
        start(ms);
    });
})();