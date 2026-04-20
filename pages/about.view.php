<?php
// pages/about.view.php
session_start();

define('BASE_URL', '/ProVendor');

$isLoggedIn = isset($_SESSION['user_id']);
$userName   = 'Account';

if ($isLoggedIn) {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../queries/user.query.php';
    $_aboutUser = getUserById($pdo, (int) $_SESSION['user_id']);
    $userName   = $_aboutUser ? $_aboutUser['name'] : 'Account';
}

$pageTitle = 'ProVendor — About Us';
require_once __DIR__ . '/../includes/header.php';

// Active link helper for dark navbar
$_navFile  = basename($_SERVER['PHP_SELF']);
$_darkNav  = function(string $keyword) use ($_navFile): string {
    return str_contains($_navFile, $keyword)
        ? 'color:#F0E8D0; font-weight:600; border-bottom:2px solid rgba(240,232,208,0.6); padding-bottom:2px; text-decoration:none; font-size:0.875rem;'
        : 'color:rgba(210,200,174,0.45); text-decoration:none; font-size:0.875rem; transition:color 0.15s;';
};
?>
<body class="bg-[#261F0E]">

    <!-- ════════════════════════════════════════════
         TOP NAV (context-aware)
    ════════════════════════════════════════════ -->
    <nav class="sticky top-0 z-50 border-b border-[#F0E8D0]/10" style="background:rgba(38,31,14,0.92); backdrop-filter:blur(12px)">
        <div class="max-w-5xl mx-auto px-6 h-16 flex items-center justify-between">

            <!-- Logo -->
            <a href="<?php echo BASE_URL; ?>/<?php echo $isLoggedIn ? 'pages/import.view.php' : 'pages/login.view.php'; ?>" class="flex items-center gap-2.5" style="text-decoration:none">
                <div class="w-7 h-7 rounded-lg flex items-center justify-center" style="background:rgba(240,232,208,0.10); border:1px solid rgba(240,232,208,0.20)">
                    <span class="text-[#F0E8D0] text-[9px] font-semibold tracking-widest">PV</span>
                </div>
                <span class="text-[#F0E8D0] font-semibold text-sm">ProVendor</span>
            </a>

            <?php if ($isLoggedIn): ?>
            <!-- Full nav when logged in -->
            <div class="flex items-center gap-6">

                <nav class="flex items-center gap-6">
                    <a href="<?php echo BASE_URL; ?>/pages/dashboard.view.php" style="<?php echo $_darkNav('dashboard'); ?>">Dashboard</a>
                    <a href="<?php echo BASE_URL; ?>/pages/forecast.view.php"  style="<?php echo $_darkNav('forecast'); ?>">Forecast</a>
                    <a href="<?php echo BASE_URL; ?>/pages/events.view.php"    style="<?php echo $_darkNav('event'); ?>">Events</a>
                    <a href="<?php echo BASE_URL; ?>/pages/import.view.php"    style="<?php echo $_darkNav('import'); ?>">My Store</a>
                    <a href="<?php echo BASE_URL; ?>/pages/reports.view.php"   style="<?php echo $_darkNav('reports'); ?>">Reports</a>
                    <a href="<?php echo BASE_URL; ?>/pages/about.view.php"     style="<?php echo $_darkNav('about'); ?>">About</a>
                </nav>

                <!-- Divider -->
                <div style="width:1px; height:1rem; background:rgba(210,200,174,0.25); flex-shrink:0"></div>

                <!-- User + Logout -->
                <div class="flex items-center gap-3">
                    <span style="font-size:0.875rem; color:rgba(210,200,174,0.45); white-space:nowrap">
                        <?php echo htmlspecialchars($userName); ?>
                    </span>
                    <button type="button"
                            onclick="showConfirm({
                                title:        'Log out?',
                                message:      'You will be returned to the login page.',
                                confirmText:  'Log out',
                                confirmStyle: 'danger',
                                onConfirm:    function(){ window.location='<?php echo BASE_URL; ?>/pages/landing.view.php?logout=1'; }
                            })"
                            style="font-size:0.875rem; color:rgba(210,200,174,0.65); border:1px solid rgba(210,200,174,0.25); border-radius:0.5rem; padding:0.25rem 0.75rem; background:transparent; cursor:pointer; white-space:nowrap; transition:background 0.15s;">
                        Log out
                    </button>
                </div>

            </div>

            <?php else: ?>
            <!-- Guest: just a back link -->
            <a href="<?php echo BASE_URL; ?>/pages/login.view.php"
               style="display:flex; align-items:center; gap:0.375rem; color:rgba(210,200,174,0.60); font-size:0.75rem; text-decoration:none; text-transform:uppercase; letter-spacing:0.1em; transition:color 0.15s;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                Back to Login
            </a>
            <?php endif; ?>

        </div>
    </nav>


    <!-- ════════════════════════════════════════════
         HERO
    ════════════════════════════════════════════ -->
    <section class="dot-pattern pt-24 pb-20 border-b border-[#F0E8D0]/08">
        <div class="max-w-3xl mx-auto px-8 text-center">
            <p class="text-[#1A6933] text-[10px] font-semibold uppercase tracking-widest mb-4">About the Project</p>
            <h1 class="text-5xl font-semibold text-[#F0E8D0] leading-tight mb-5">
                Built by students.<br>Designed for real stores.
            </h1>
            <p class="text-[#D2C8AE] text-base leading-relaxed max-w-xl mx-auto">
                ProVendor is an academic capstone project developed to help SME convenience store owners make
                data-driven restocking decisions — powered by machine learning, built with care.
            </p>
            <div class="mt-8 h-px w-16 bg-[#D2C8AE]/30 mx-auto"></div>
        </div>
    </section>


    <!-- ════════════════════════════════════════════
         WHAT WE BUILT
    ════════════════════════════════════════════ -->
    <section class="dot-pattern py-20 border-b border-[#F0E8D0]/08">
        <div class="max-w-5xl mx-auto px-8">

            <div class="grid grid-cols-3 gap-8">

                <div class="col-span-1">
                    <p class="text-[#1A6933] text-[10px] font-semibold uppercase tracking-widest mb-3">The System</p>
                    <h2 class="text-3xl font-semibold text-[#F0E8D0] leading-tight">What ProVendor does</h2>
                </div>

                <div class="col-span-2 space-y-6">
                    <div class="flex items-start gap-4">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" style="background:rgba(26,105,51,0.15); border:1px solid rgba(26,105,51,0.3)">
                            <svg class="w-4 h-4 text-[#1A6933]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        </div>
                        <div>
                            <p class="text-[#F0E8D0] font-semibold text-sm mb-1">Demand Forecasting via Prophet</p>
                            <p class="text-[#D2C8AE]/70 text-sm leading-relaxed">Analyzes historical sales CSV data using Meta's Prophet model to detect trends, weekly cycles, and seasonal spikes. Integrates Philippine public holiday data and local weather as additional regressors.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" style="background:rgba(26,105,51,0.15); border:1px solid rgba(26,105,51,0.3)">
                            <svg class="w-4 h-4 text-[#1A6933]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                        </div>
                        <div>
                            <p class="text-[#F0E8D0] font-semibold text-sm mb-1">Optimal Restock via Newsvendor Model</p>
                            <p class="text-[#D2C8AE]/70 text-sm leading-relaxed">Converts demand forecasts into exact restock quantities. Balances the cost of underordering (stockouts) against overordering (spoilage), using the store owner's own cost and price inputs.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" style="background:rgba(26,105,51,0.15); border:1px solid rgba(26,105,51,0.3)">
                            <svg class="w-4 h-4 text-[#1A6933]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        </div>
                        <div>
                            <p class="text-[#F0E8D0] font-semibold text-sm mb-1">Built for Philippine SMEs</p>
                            <p class="text-[#D2C8AE]/70 text-sm leading-relaxed">Evaluated with a real partner store, factoring in local context — payday cycles, Philippine holidays, and regional weather patterns that affect small-store demand.</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>


    <!-- ════════════════════════════════════════════
         TEAM
    ════════════════════════════════════════════ -->
    <section class="py-24">
        <div class="max-w-5xl mx-auto px-8">

            <div class="text-center mb-16">
                <p class="text-[#1A6933] text-[10px] font-semibold uppercase tracking-widest mb-3">The Team</p>
                <h2 class="text-4xl font-semibold text-[#F0E8D0] leading-tight">Meet the developers</h2>
                <div class="mt-6 h-px w-16 bg-[#D2C8AE]/30 mx-auto"></div>
            </div>

            <div class="grid grid-cols-4 gap-6">

                <!-- Member 1 -->
                <article class="flex flex-col items-center text-center group">
                    <div class="w-36 h-36 rounded-2xl mb-5 overflow-hidden border border-[#F0E8D0]/10 flex items-center justify-center relative"
                         style="background:rgba(240,232,208,0.06)">
                        <svg class="w-16 h-16 text-[#D2C8AE]/20" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                        </svg>
                        <div class="absolute inset-x-0 bottom-0 py-1.5 text-center" style="background:rgba(38,31,14,0.6)">
                            <span class="text-[#D2C8AE]/50 text-[9px] uppercase tracking-widest">Photo</span>
                        </div>
                    </div>
                    <p class="text-[#F0E8D0] font-semibold text-sm mb-0.5">John Martin Sapanta</p>
                    <p class="text-[#1A6933] text-[10px] font-semibold uppercase tracking-widest mb-2">BSIT</p>
                    <p class="text-[#D2C8AE]/50 text-xs leading-relaxed">Short description or contribution to the project goes here.</p>
                </article>

                <!-- Member 2 -->
                <article class="flex flex-col items-center text-center group">
                    <div class="w-36 h-36 rounded-2xl mb-5 overflow-hidden border border-[#F0E8D0]/10 flex items-center justify-center relative"
                         style="background:rgba(240,232,208,0.06)">
                        <svg class="w-16 h-16 text-[#D2C8AE]/20" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                        </svg>
                        <div class="absolute inset-x-0 bottom-0 py-1.5 text-center" style="background:rgba(38,31,14,0.6)">
                            <span class="text-[#D2C8AE]/50 text-[9px] uppercase tracking-widest">Photo</span>
                        </div>
                    </div>
                    <p class="text-[#F0E8D0] font-semibold text-sm mb-0.5">Ken Delos Santos</p>
                    <p class="text-[#1A6933] text-[10px] font-semibold uppercase tracking-widest mb-2">BSIT</p>
                    <p class="text-[#D2C8AE]/50 text-xs leading-relaxed">Short description or contribution to the project goes here.</p>
                </article>

                <!-- Member 3 -->
                <article class="flex flex-col items-center text-center group">
                    <div class="w-36 h-36 rounded-2xl mb-5 overflow-hidden border border-[#F0E8D0]/10 flex items-center justify-center relative"
                         style="background:rgba(240,232,208,0.06)">
                        <svg class="w-16 h-16 text-[#D2C8AE]/20" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                        </svg>
                        <div class="absolute inset-x-0 bottom-0 py-1.5 text-center" style="background:rgba(38,31,14,0.6)">
                            <span class="text-[#D2C8AE]/50 text-[9px] uppercase tracking-widest">Photo</span>
                        </div>
                    </div>
                    <p class="text-[#F0E8D0] font-semibold text-sm mb-0.5">John Denver Davis</p>
                    <p class="text-[#1A6933] text-[10px] font-semibold uppercase tracking-widest mb-2">BSIT</p>
                    <p class="text-[#D2C8AE]/50 text-xs leading-relaxed">Short description or contribution to the project goes here.</p>
                </article>

                <!-- Member 4 -->
                <article class="flex flex-col items-center text-center group">
                    <div class="w-36 h-36 rounded-2xl mb-5 overflow-hidden border border-[#F0E8D0]/10 flex items-center justify-center relative"
                         style="background:rgba(240,232,208,0.06)">
                        <svg class="w-16 h-16 text-[#D2C8AE]/20" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                        </svg>
                        <div class="absolute inset-x-0 bottom-0 py-1.5 text-center" style="background:rgba(38,31,14,0.6)">
                            <span class="text-[#D2C8AE]/50 text-[9px] uppercase tracking-widest">Photo</span>
                        </div>
                    </div>
                    <p class="text-[#F0E8D0] font-semibold text-sm mb-0.5">John Lorenz Rolloque</p>
                    <p class="text-[#1A6933] text-[10px] font-semibold uppercase tracking-widest mb-2">BSIT</p>
                    <p class="text-[#D2C8AE]/50 text-xs leading-relaxed">Short description or contribution to the project goes here.</p>
                </article>

            </div>
        </div>
    </section>


    <!-- ════════════════════════════════════════════
         TECH STACK STRIP
    ════════════════════════════════════════════ -->
    <section class="border-t border-[#F0E8D0]/08 py-14" style="background:rgba(240,232,208,0.03)">
        <div class="max-w-5xl mx-auto px-8">
            <p class="text-center text-[#D2C8AE]/30 text-[10px] uppercase tracking-widest mb-8">Built with</p>
            <div class="flex items-center justify-center gap-10 flex-wrap">
                <div class="flex items-center gap-2 text-[#D2C8AE]/40">
                    <span class="text-xs font-semibold">PHP</span>
                </div>
                <div class="w-px h-4 bg-[#F0E8D0]/10"></div>
                <div class="flex items-center gap-2 text-[#D2C8AE]/40">
                    <span class="text-xs font-semibold">MySQL</span>
                </div>
                <div class="w-px h-4 bg-[#F0E8D0]/10"></div>
                <div class="flex items-center gap-2 text-[#D2C8AE]/40">
                    <span class="text-xs font-semibold">Python · Flask</span>
                </div>
                <div class="w-px h-4 bg-[#F0E8D0]/10"></div>
                <div class="flex items-center gap-2 text-[#D2C8AE]/40">
                    <span class="text-xs font-semibold">Prophet</span>
                </div>
                <div class="w-px h-4 bg-[#F0E8D0]/10"></div>
                <div class="flex items-center gap-2 text-[#D2C8AE]/40">
                    <span class="text-xs font-semibold">Newsvendor Model</span>
                </div>
                <div class="w-px h-4 bg-[#F0E8D0]/10"></div>
                <div class="flex items-center gap-2 text-[#D2C8AE]/40">
                    <span class="text-xs font-semibold">Tailwind CSS</span>
                </div>
                <div class="w-px h-4 bg-[#F0E8D0]/10"></div>
                <div class="flex items-center gap-2 text-[#D2C8AE]/40">
                    <span class="text-xs font-semibold">Chart.js</span>
                </div>
            </div>
        </div>
    </section>


    <!-- ════════════════════════════════════════════
         FOOTER
    ════════════════════════════════════════════ -->
    <footer class="border-t border-[#F0E8D0]/10 py-12">
        <div class="max-w-5xl mx-auto px-8">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-[#F0E8D0]/10 border border-[#F0E8D0]/20 flex items-center justify-center">
                        <span class="text-[#F0E8D0] text-[9px] font-semibold tracking-widest">PV</span>
                    </div>
                    <span class="text-[#F0E8D0] font-semibold text-sm">ProVendor</span>
                </div>
                <p class="text-[#D2C8AE]/25 text-xs">© <?php echo date('Y'); ?> ProVendor</p>
            </div>
        </div>
    </footer>

<?php if ($isLoggedIn): ?>
<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
<?php endif; ?>

</body>
</html>
