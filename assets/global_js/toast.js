// assets/global_js/toast.js
// Lightweight toast notifications. Mirrors confirm_modal.php's pattern:
// include includes/toast.php once on the page, then call showToast() anywhere.
//
//   showToast('Saved successfully', 'success');
//   showToast('Network error. Please try again.', 'error');
//   showToast('Heads up...');                     // defaults to 'info'
//
// Toasts auto-dismiss after 3.5s; the × button dismisses early. Multiple
// toasts stack vertically (newest at the bottom).
(function () {
    var DISMISS_MS = 3500;
    var LEAVE_MS   = 220;   // matches the toast-leaving transition in app.css

    window.showToast = function (message, type) {
        type = type || 'info';
        var container = document.getElementById('toast-container');
        if (!container) {
            // Page forgot to include the partial — fall back to alert() so the
            // message at least gets through. Log once for the dev.
            console.error('toast.js: missing #toast-container — include includes/toast.php');
            alert(message);
            return;
        }

        var toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.innerHTML =
            '<span class="toast-message"></span>' +
            '<button class="toast-close" aria-label="Dismiss">&times;</button>';
        toast.querySelector('.toast-message').textContent = message;
        toast.querySelector('.toast-close').onclick = function () { dismiss(toast); };

        container.appendChild(toast);

        // Trigger the slide-in transition by adding the visible class on the
        // next frame — gives the browser a tick to apply the initial styles.
        requestAnimationFrame(function () { toast.classList.add('toast-visible'); });

        setTimeout(function () { dismiss(toast); }, DISMISS_MS);
    };

    function dismiss(toast) {
        if (!toast || !toast.parentNode) return;
        toast.classList.remove('toast-visible');
        toast.classList.add('toast-leaving');
        setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, LEAVE_MS);
    }
}());
