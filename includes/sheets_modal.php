<?php
// includes/sheets_modal.php
// The "Import from Google Sheets" dialog, shared by the onboarding page
// (landing.view.php) and the Sales Data tab (import.view.php).
//
// Include once before </body>, then trigger with openSheetsDialog(). The page
// must define window.pvSheetsOnValidated(data) — it receives the same payload
// shape api/detect.php returns, so each page can hand it to its own existing
// column-mapping UI and continue into the normal preview → import flow.
//
// Requires includes/confirm_modal.php (the second confirmation uses showConfirm).

// The address owners must share their sheet with. Read straight from the
// service-account key so it can never drift out of sync with the credentials
// the server actually authenticates as — and so the dialog still explains the
// setup correctly even when the Python server is down.
$_saEmail = null;
$_saPath  = __DIR__ . '/../creds/service-account.json';
if (is_readable($_saPath)) {
    $_saJson  = json_decode((string) file_get_contents($_saPath), true);
    $_saEmail = $_saJson['client_email'] ?? null;
}
?>

<!-- ════════════════════════════════════════════
     GOOGLE SHEETS IMPORT DIALOG
════════════════════════════════════════════ -->
<div id="sheets-dialog" class="gs-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="gs-title">

    <div class="gs-backdrop" onclick="closeSheetsDialog()"></div>

    <div class="gs-card">

        <!-- Header -->
        <div class="gs-header">
            <div class="gs-header-text">
                <p class="gs-eyebrow">Import</p>
                <h2 class="gs-title" id="gs-title">Connect a Google Sheet</h2>
                <p class="gs-sub">
                    Keep your daily sales in a spreadsheet and ProVendor will read it directly —
                    no more exporting CSVs.
                </p>
            </div>
            <button type="button" class="gs-close" onclick="closeSheetsDialog()" title="Cancel" aria-label="Cancel">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="gs-body">

            <?php if ($_saEmail === null): ?>
            <!-- Without the key there is nothing for the owner to share with, and
                 no point letting them paste a link that can never be opened. -->
            <div class="gs-error" style="display:block">
                ProVendor's Google service account credentials are missing on this server
                (<code>creds/service-account.json</code>), so sheets cannot be read yet.
                Ask whoever set up this installation to add the key file.
            </div>
            <?php else: ?>

            <!-- ── Setup steps ── -->
            <ol class="gs-steps">

                <li class="gs-step">
                    <span class="gs-step-num">1</span>
                    <div class="gs-step-body">
                        <p class="gs-step-title">Lay your sheet out in columns</p>
                        <p class="gs-step-desc">
                            Row&nbsp;1 holds the column names. You need a date, a product, and a
                            quantity column — anything else (category, cost, price) is optional and
                            can be mapped in the next step.
                        </p>
                        <div class="gs-example">
                            <table class="gs-example-table">
                                <thead><tr><th>Date</th><th>Product</th><th>Quantity</th></tr></thead>
                                <tbody>
                                    <tr><td>2026-08-01</td><td>Pandesal</td><td>120</td></tr>
                                    <tr><td>2026-08-01</td><td>Ensaymada</td><td>45</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </li>

                <li class="gs-step">
                    <span class="gs-step-num">2</span>
                    <div class="gs-step-body">
                        <p class="gs-step-title">Share it with ProVendor</p>
                        <p class="gs-step-desc">
                            In your sheet click <strong>Share</strong>, paste the address below,
                            set it to <strong>Viewer</strong>, and send. You can untick
                            &ldquo;Notify people&rdquo; — this is a robot account, not a person.
                        </p>
                        <div class="gs-email-row">
                            <code class="gs-email" id="gs-sa-email"><?php echo htmlspecialchars($_saEmail); ?></code>
                            <button type="button" class="gs-copy-btn" id="gs-copy-btn" onclick="copyServiceAccount()">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                </svg>
                                Copy
                            </button>
                        </div>
                        <p class="gs-step-note">
                            ProVendor only ever reads this sheet — it is opened with read-only
                            access and never writes back.
                        </p>
                    </div>
                </li>

                <li class="gs-step">
                    <span class="gs-step-num">3</span>
                    <div class="gs-step-body">
                        <p class="gs-step-title">Paste the link</p>
                        <p class="gs-step-desc">
                            Copy the address from your browser's address bar while the sheet is open.
                            If your sales are on a specific tab, open that tab first.
                        </p>
                        <input type="text" id="gs-url-input" class="gs-input" autocomplete="off" spellcheck="false"
                               placeholder="https://docs.google.com/spreadsheets/d/..."
                               oninput="clearSheetsError()"
                               onkeydown="if (event.key === 'Enter') confirmSheetsLink()">
                    </div>
                </li>

            </ol>

            <div id="gs-error" class="gs-error"></div>

            <?php endif; ?>
        </div>

        <!-- Footer actions -->
        <div class="gs-footer">
            <button type="button" class="gs-btn-cancel" onclick="closeSheetsDialog()">Cancel</button>
            <button type="button" class="gs-btn-confirm" id="gs-confirm-btn"
                    onclick="confirmSheetsLink()"<?php echo $_saEmail === null ? ' disabled style="opacity:0.4;cursor:not-allowed"' : ''; ?>>
                Confirm
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </button>
        </div>

    </div>
</div>

<script>
(function () {
    var BASE            = '<?php echo BASE_URL; ?>';
    var CONFIRM_BTN_TXT = 'Confirm <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';

    window.openSheetsDialog = function () {
        clearSheetsError();
        document.getElementById('sheets-dialog').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        var input = document.getElementById('gs-url-input');
        if (input) setTimeout(function () { input.focus(); }, 50);
    };

    window.closeSheetsDialog = function () {
        document.getElementById('sheets-dialog').classList.add('hidden');
        document.body.style.overflow = '';
        setBusy(false);
    };

    window.copyServiceAccount = function () {
        var email = document.getElementById('gs-sa-email').textContent.trim();
        var btn   = document.getElementById('gs-copy-btn');

        var done = function () {
            btn.classList.add('gs-copy-btn-done');
            btn.textContent = 'Copied';
            setTimeout(function () {
                btn.classList.remove('gs-copy-btn-done');
                btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copy';
            }, 1600);
        };

        // clipboard API needs a secure context; plain http://localhost counts,
        // but a LAN address (http://192.168.x.x) does not — hence the fallback.
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(email).then(done, selectEmail);
        } else {
            selectEmail();
        }
    };

    // Last resort: select the address so the owner can press Ctrl+C themselves.
    function selectEmail() {
        var node  = document.getElementById('gs-sa-email');
        var range = document.createRange();
        range.selectNodeContents(node);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        showSheetsError('Press Ctrl+C to copy the highlighted address.');
    }

    // ── Step 3 → the "this changes how your data works" confirmation ──────────
    window.confirmSheetsLink = function () {
        var url = (document.getElementById('gs-url-input') || {}).value || '';
        url = url.trim();

        if (!url) {
            showSheetsError('Paste the link to your Google Sheet first.');
            return;
        }
        if (url.indexOf('docs.google.com/spreadsheets') === -1) {
            showSheetsError('That does not look like a Google Sheets link. It should start with https://docs.google.com/spreadsheets/…');
            return;
        }

        clearSheetsError();

        showConfirm({
            title:       'Make this sheet your sales data?',
            message:     'ProVendor will re-read this sheet every 5 minutes and update any day whose '
                       + 'quantity changed there. While the sheet stays connected, importing a CSV is '
                       + 'turned off — otherwise the next refresh would undo it. Rows you delete in the '
                       + 'sheet are never deleted here, and you can disconnect at any time.',
            confirmText: 'Yes, connect it',
            confirmStyle: 'primary',
            onConfirm:   function () { validateSheetsLink(url); },
        });
    };

    // ── Step 4 → does it exist, and can we actually open it? ──────────────────
    function validateSheetsLink(url) {
        setBusy(true);

        var body = new FormData();
        body.append('url', url);

        fetch(BASE + '/api/sheets_link.php', { method: 'POST', body: body })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                setBusy(false);

                if (data.error) {
                    showSheetsError(data.error);
                    return;
                }

                // Step 5 — hand the sheet's columns to the page's mapping UI.
                closeSheetsDialog();
                if (typeof window.pvSheetsOnValidated === 'function') {
                    window.pvSheetsOnValidated(data);
                }
            })
            .catch(function () {
                setBusy(false);
                showSheetsError('Could not reach ProVendor. Check your connection and try again.');
            });
    }

    function setBusy(busy) {
        var btn = document.getElementById('gs-confirm-btn');
        if (!btn) return;
        btn.disabled  = busy;
        btn.innerHTML = busy ? '<span class="gs-spinner"></span> Checking the sheet…' : CONFIRM_BTN_TXT;

        var input = document.getElementById('gs-url-input');
        if (input) input.disabled = busy;
    }

    window.showSheetsError = function (msg) {
        var el = document.getElementById('gs-error');
        if (!el) return;
        el.textContent   = msg;
        el.style.display = 'block';
    };

    window.clearSheetsError = function () {
        var el = document.getElementById('gs-error');
        if (!el) return;
        el.textContent   = '';
        el.style.display = '';
    };

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var dlg = document.getElementById('sheets-dialog');
        if (dlg && !dlg.classList.contains('hidden')) closeSheetsDialog();
    });
}());
</script>
