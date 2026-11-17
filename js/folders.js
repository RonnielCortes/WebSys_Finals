// ============================================
// js/folders.js — Admin > Depts & Programs (admin/folders.php)
// Helps the "Move a folder" and "Transfer files / accounts" forms:
//   - only shows the places that are allowed
//   - asks "Are you sure?" with the real numbers before anything changes
// (The server checks everything again, this is only for a clearer screen.)
// ============================================
(function () {
    function chosen(select) {
        return select.options[select.selectedIndex];
    }
    function plural(n, word) {
        return n + ' ' + word + (n === 1 ? '' : 's');
    }

    // ---------- Move ----------
    var moveForm = document.getElementById('foldMove');
    var who = document.getElementById('moveWho');
    var to = document.getElementById('moveTo');
    var typeBox = document.getElementById('moveType');   // "Keep its type" / "Become an Office"

    if (moveForm && who && to) {
        function syncMove() {
            var opt = chosen(who);
            var id = who.value;
            var level = opt.getAttribute('data-level') || '';
            var parent = opt.getAttribute('data-parent') || '0';
            var inside = (opt.getAttribute('data-desc') || '').split(',').filter(Boolean);

            Array.prototype.forEach.call(to.options, function (o) {
                if (o.value === '') return;
                if (o.value === 'independent') {                 // always possible, unless it is already on its own
                    var already = (parent === '0');
                    o.disabled = (id === '') || already;
                    o.hidden = o.disabled;
                    return;
                }
                var allowed = (o.getAttribute('data-allowed') || '').split(',');
                var ok = id !== '' &&
                    o.value !== id &&                        // not into itself
                    inside.indexOf(o.value) === -1 &&        // not into something that is inside it
                    o.value !== parent &&                    // not where it already is
                    allowed.indexOf(level) !== -1;           // this kind of folder is allowed there
                o.disabled = !ok;
                o.hidden = !ok;
            });
            if (to.value !== '' && chosen(to).disabled) to.value = '';
            syncType();
        }
        // the "Keep / Office" choice only shows up for "independent"
        function syncType() {
            if (!typeBox) return;
            var label = chosen(who).getAttribute('data-label') || 'type';
            typeBox.options[0].text = 'Keep as ' + label;
            typeBox.hidden = (to.value !== 'independent');
        }
        who.addEventListener('change', syncMove);
        to.addEventListener('change', syncType);
        syncMove();

        moveForm.addEventListener('submit', function (e) {
            var opt = chosen(who);
            if (to.value === 'independent') {
                var subs = +opt.getAttribute('data-subs') || 0;
                var asOffice = typeBox && typeBox.value === 'office';
                var text = 'Make "' + opt.text.trim() + '" independent?\n\nIt loses its parent and ' +
                    (asOffice ? 'becomes its own Office' + (subs ? ' (its ' + plural(subs, 'folder') + ' inside will become Offices too)' : '')
                              : 'stays a ' + (opt.getAttribute('data-label') || 'folder') + ' on its own, keeping what is inside it') + '.\n' +
                    plural(+opt.getAttribute('data-files') || 0, 'file') + ' and ' +
                    plural(+opt.getAttribute('data-users') || 0, 'account') + ' stay with it.';
                if (!window.confirm(text)) e.preventDefault();
                return;
            }
            var msg = 'Move "' + opt.text.trim() + '"\ninto "' + chosen(to).text.trim() + '"?\n\n' +
                'It takes along ' + plural(+opt.getAttribute('data-subs') || 0, 'folder') + ', ' +
                plural(+opt.getAttribute('data-files') || 0, 'file') + ' and ' +
                plural(+opt.getAttribute('data-users') || 0, 'account') + '.';
            if (!window.confirm(msg)) e.preventDefault();
        });
    }

    // ---------- Transfer ----------
    var trForm = document.getElementById('foldTransfer');
    var from = document.getElementById('trFrom');
    var dest = document.getElementById('trTo');

    if (trForm && from && dest) {
        function syncTransfer() {
            Array.prototype.forEach.call(dest.options, function (o) {
                if (o.value === '') return;
                var same = o.value === from.value;           // cannot transfer to itself
                o.disabled = same;
                o.hidden = same;
            });
            if (dest.value !== '' && chosen(dest).disabled) dest.value = '';
        }
        from.addEventListener('change', syncTransfer);
        syncTransfer();

        trForm.addEventListener('submit', function (e) {
            var opt = chosen(from);
            var parts = [];
            if (trForm.elements['move_files'].checked) parts.push(plural(+opt.getAttribute('data-files') || 0, 'file'));
            if (trForm.elements['move_users'].checked) parts.push(plural(+opt.getAttribute('data-users') || 0, 'account'));
            var fromName = opt.text.replace(/\s*\(.*\)\s*$/, '').trim();
            var msg = 'Transfer ' + (parts.join(' and ') || 'nothing') + '\nfrom "' + fromName + '"\nto "' + chosen(dest).text.trim() + '"?';
            if (!window.confirm(msg)) e.preventDefault();
        });
    }
})();