<?php
// includes/sheets_autosync.php
// The 5-minute refresh for a linked Google Sheet.
//
// Included from navbar.php, so it runs on every signed-in page: whichever page
// the owner is sitting on keeps their sales data current. It emits nothing at
// all unless this user has a sheet linked with auto-refresh on.
//
// The browser is the trigger, but the schedule is enforced server-side —
// api/sheets_sync.php refuses to re-read the sheet within 5 minutes of the last
// sync, so several open tabs (or a fast reload loop) still produce one refresh
// per interval rather than one per tab.
//
// Consequence worth knowing: nothing syncs while ProVendor is closed in every
// browser. The first page load after a quiet night catches up immediately.

// Every page's logic file opens $pdo before the navbar renders; the fallback is
// only for a caller that hasn't (require_once would be a no-op by then).
if (!isset($pdo)) require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../queries/sheets.query.php';

$_asLink = isset($_SESSION['user_id'])
    ? getSheetLink($pdo, (int) $_SESSION['user_id'])
    : null;

if (!$_asLink) return;
?>
<script>
(function () {
    var BASE     = '<?php echo BASE_URL; ?>';
    var EVERY_MS = 300000;                                  // 5 minutes
    var timer    = null;

    function sync() {
        // A hidden tab syncing is pure waste — the owner isn't looking at it,
        // and the visible tab (or the next page load) will do it anyway.
        if (document.hidden) return;

        fetch(BASE + '/api/sheets_sync.php', { method: 'POST', body: new FormData() })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                // Only the Sales Data tab has somewhere to show this; everywhere
                // else the refresh is silent and lands on the next navigation.
                if (window.pvOnSheetSync) window.pvOnSheetSync(data);
            })
            .catch(function () { /* offline or server down — the next tick retries */ });
    }

    // Exposed so the auto-refresh toggle can start/stop the timer immediately
    // instead of leaving the page a reload behind its own setting.
    window.pvSheetHeartbeat = function (on) {
        if (timer) { clearInterval(timer); timer = null; }
        if (!on) return;
        timer = setInterval(sync, EVERY_MS);
        sync();
    };

    window.pvSheetHeartbeat(<?php echo $_asLink['auto_sync'] ? 'true' : 'false'; ?>);
}());
</script>
