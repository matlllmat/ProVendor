<?php
// includes/navbar.php
// Shared top navigation bar for all post-data pages.
// Requires BASE_URL constant and $userName variable (both set by each page's logic file).

// Auto-detect which nav link is active based on the current file name.
$_navFile = basename($_SERVER['PHP_SELF']);

// Returns the CSS classes for an active vs. inactive nav link.
// "event" intentionally matches both events.view.php and event_detail.view.php.
$_navClass = function(string $keyword) use ($_navFile): string {
    return str_contains($_navFile, $keyword)
        ? 'text-sm text-[#261F0E] font-semibold border-b-2 border-[#261F0E] pb-0.5'
        : 'text-sm text-[#261F0E] opacity-50 hover:opacity-100 transition-opacity';
};
?>
<!-- ════════════════════════════════════════════
     TOP NAVBAR  (includes/navbar.php)
════════════════════════════════════════════ -->
<header class="sticky top-0 z-50 border-b border-[#D2C8AE]" style="background:rgba(240,232,208,0.92);backdrop-filter:blur(10px);">
    <div class="max-w-5xl mx-auto px-6 h-16 flex items-center justify-between">

        <!-- Logo -->
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-[#261F0E] flex items-center justify-center">
                <span class="text-[#F0E8D0] text-xs font-semibold tracking-widest">PV</span>
            </div>
            <span class="text-[#261F0E] font-semibold">ProVendor</span>
        </div>

        <!-- Nav links + user + logout -->
        <div class="flex items-center gap-6">

            <nav class="flex items-center gap-6">
                <a href="<?php echo BASE_URL; ?>/pages/dashboard.view.php"
                   class="<?php echo $_navClass('dashboard'); ?>">Dashboard</a>
                <a href="<?php echo BASE_URL; ?>/pages/forecast.view.php"
                   class="<?php echo $_navClass('forecast'); ?>">Forecast</a>
                <a href="<?php echo BASE_URL; ?>/pages/events.view.php"
                   class="<?php echo $_navClass('event'); ?>">Events</a>
                <a href="<?php echo BASE_URL; ?>/pages/import.view.php"
                   class="<?php echo $_navClass('import'); ?>">My Store</a>
                <a href="<?php echo BASE_URL; ?>/pages/reports.view.php"
                   class="<?php echo $_navClass('reports'); ?>">Reports</a>
                <a href="<?php echo BASE_URL; ?>/pages/about.view.php"
                   class="<?php echo $_navClass('about'); ?>">About</a>
            </nav>

            <!-- Divider -->
            <div class="w-px h-4 bg-[#D2C8AE]" style="flex-shrink:0"></div>

            <!-- User + Logout -->
            <div class="flex items-center gap-3">
                <span class="text-sm text-[#261F0E]" style="opacity:0.45; white-space:nowrap">
                    <?php echo htmlspecialchars($userName ?? 'Account'); ?>
                </span>
                <button type="button"
                        onclick="showConfirm({
                            title:        'Log out?',
                            message:      'You will be returned to the login page.',
                            confirmText:  'Log out',
                            confirmStyle: 'danger',
                            onConfirm:    function(){ window.location='<?php echo BASE_URL; ?>/pages/landing.view.php?logout=1'; }
                        })"
                        class="text-sm text-[#261F0E] border border-[#D2C8AE] rounded-lg px-3 py-1.5 hover:bg-[#D2C8AE] transition-colors"
                        style="white-space:nowrap; opacity:0.75">
                    Log out
                </button>
            </div>

        </div>
    </div>
</header>
