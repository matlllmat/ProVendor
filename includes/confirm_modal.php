<?php
// includes/confirm_modal.php
// Reusable confirmation modal. Include once before </body> on any page that needs it.
// Trigger from JS: showConfirm({ title, message, confirmText, confirmStyle, onConfirm })
//   confirmStyle: 'danger' (red) | 'warning' (orange) | 'primary' (dark) — default: 'primary'
?>

<!-- ════════════════════════════════════════════
     CONFIRM MODAL
════════════════════════════════════════════ -->
<div id="confirm-modal"
    class="fixed inset-0 z-[2000] flex items-center justify-center hidden"
    role="dialog" aria-modal="true" aria-labelledby="confirm-title">

    <!-- Backdrop -->
    <div id="confirm-backdrop"
        class="absolute inset-0 transition-opacity"
        style="background:rgba(38,31,14,0.55)"
        onclick="hideConfirm()"></div>

    <!-- Card -->
    <div class="relative bg-[#F0E8D0] rounded-2xl border border-[#D2C8AE] w-full max-w-sm mx-4 p-8"
        style="box-shadow:0 24px 64px rgba(38,31,14,0.3)">

        <!-- Icon -->
        <div id="confirm-icon-wrap"
            class="w-11 h-11 rounded-xl flex items-center justify-center mb-5">
            <!-- Filled by JS -->
        </div>

        <h3 id="confirm-title-text" class="text-lg font-semibold text-[#261F0E] mb-2 leading-snug"></h3>
        <p id="confirm-message-text" class="text-sm text-[#261F0E] leading-relaxed mb-8" style="opacity:0.55"></p>

        <!-- Buttons -->
        <div class="flex gap-3">
            <button type="button" onclick="hideConfirm()"
                class="flex-1 border border-[#D2C8AE] rounded-xl py-2.5 text-sm font-semibold text-[#261F0E] hover:bg-[#D2C8AE] transition-colors">
                Cancel
            </button>
            <button type="button" id="confirm-action-btn" onclick="_executeConfirm()"
                class="flex-1 rounded-xl py-2.5 text-sm font-semibold transition-opacity hover:opacity-90">
                <!-- Label set by JS -->
            </button>
        </div>

    </div>
</div>

<script>
(function () {
    let _callback       = null;
    let _cancelCallback = null;

    const ICONS = {
        danger: {
            bg:     'rgba(255,26,26,0.1)',
            border: 'rgba(255,26,26,0.2)',
            svg: '<svg style="width:1.1rem;height:1.1rem;color:#FF1A1A" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        },
        warning: {
            bg:     'rgba(255,87,34,0.1)',
            border: 'rgba(255,87,34,0.2)',
            svg: '<svg style="width:1.1rem;height:1.1rem;color:#FF5722" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        },
        primary: {
            bg:     'rgba(38,31,14,0.07)',
            border: 'rgba(38,31,14,0.14)',
            svg: '<svg style="width:1.1rem;height:1.1rem;color:#261F0E;opacity:0.7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        },
    };

    const BTN_STYLES = {
        danger:  { bg: '#FF1A1A', color: '#F0E8D0' },
        warning: { bg: '#FF5722', color: '#F0E8D0' },
        primary: { bg: '#261F0E', color: '#F0E8D0' },
    };

    window.showConfirm = function (opts) {
        const style = opts.confirmStyle || 'primary';
        const icon  = ICONS[style] || ICONS.primary;
        const btn   = BTN_STYLES[style] || BTN_STYLES.primary;

        // Icon
        const iconWrap = document.getElementById('confirm-icon-wrap');
        iconWrap.style.background = icon.bg;
        iconWrap.style.border     = '1px solid ' + icon.border;
        iconWrap.innerHTML        = icon.svg;

        // Text
        document.getElementById('confirm-title-text').textContent   = opts.title   || 'Are you sure?';
        document.getElementById('confirm-message-text').textContent = opts.message || '';

        // Confirm button
        const actionBtn = document.getElementById('confirm-action-btn');
        actionBtn.textContent       = opts.confirmText || 'Confirm';
        actionBtn.style.background  = btn.bg;
        actionBtn.style.color       = btn.color;

        _callback       = opts.onConfirm || null;
        _cancelCallback = opts.onCancel  || null;

        document.getElementById('confirm-modal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    };

    window.hideConfirm = function () {
        const cb = _cancelCallback;
        document.getElementById('confirm-modal').classList.add('hidden');
        document.body.style.overflow = '';
        _callback       = null;
        _cancelCallback = null;
        if (cb) cb(); // fire cancel callback after cleanup
    };

    window._executeConfirm = function () {
        const cb = _callback;
        document.getElementById('confirm-modal').classList.add('hidden');
        document.body.style.overflow = '';
        _callback       = null;
        _cancelCallback = null; // don't fire cancel when confirming
        if (cb) cb();
    };

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') hideConfirm();
    });
}());
</script>
