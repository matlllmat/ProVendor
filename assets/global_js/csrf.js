// assets/global_js/csrf.js
// (1) Reads the CSRF token from <meta name="csrf-token"> and attaches it as the
//     X-CSRF-Token header to every same-origin fetch() call.
// (2) Exposes pvLogout() — a POST-based logout used by the navbar + landing page.
//     Logout has to be POST (not a GET link) so CSRF protection actually applies.
(function () {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return;

    var token     = meta.content;
    var origFetch = window.fetch.bind(window);

    window.fetch = function (input, init) {
        init = init || {};

        // Skip absolute URLs that point to a different origin so we never
        // leak the token cross-origin. Relative URLs (the common case) and
        // absolute same-origin URLs both fall through to the token-add path.
        var url = (typeof input === 'string') ? input : (input && input.url) || '';
        if (/^https?:\/\//i.test(url) && url.indexOf(window.location.origin) !== 0) {
            return origFetch(input, init);
        }

        var headers = new Headers(init.headers || {});
        if (!headers.has('X-CSRF-Token')) {
            headers.set('X-CSRF-Token', token);
        }
        init.headers = headers;

        return origFetch(input, init);
    };

    // Logout POSTs to api/logout.php (so the CSRF token is required) and then
    // redirects to the login page. Defined here so every page that loads csrf.js
    // already has it — no per-page wiring needed.
    window.pvLogout = function () {
        var base   = (meta.dataset && meta.dataset.base) || '';
        var logout = base + '/api/logout.php';
        var login  = base + '/pages/login.view.php';
        fetch(logout, { method: 'POST' })
            .then(function () { window.location = login; })
            .catch(function () { window.location = login; });
    };
}());
