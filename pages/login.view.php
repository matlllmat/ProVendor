<?php
// pages/login.view.php
// Presentation only — renders the login/signup page.
// All logic and queries are handled by login.logic.php.

require_once __DIR__ . '/login.logic.php';

$pageTitle = 'ProVendor — Login';
$pageCss   = 'login.css';
require_once __DIR__ . '/../includes/header.php';
?>
<body class="bg-[#261F0E]">

    <!-- ════════════════════════════════════════════
         Rightside Bar — Login / Sign Up
    ════════════════════════════════════════════ -->
    <div class="flex min-h-screen relative">

        <!-- ── Left Panel ── -->
        <section class="w-[55%] flex flex-col justify-center px-16 py-12 bg-[#261F0E] dot-pattern relative">
            <div class="max-w-lg">

                <!-- Monogram mark -->
                <div class="w-11 h-11 rounded-xl bg-[#F0E8D0]/10 border border-[#F0E8D0]/20 flex items-center justify-center mb-8">
                    <span class="text-[#F0E8D0] text-sm font-semibold tracking-widest">PV</span>
                </div>

                <h1 class="text-6xl font-semibold text-[#F0E8D0] mb-3 leading-tight tracking-tight">
                    ProVendor
                </h1>
                <p class="text-[#D2C8AE] text-base mb-12 leading-relaxed">
                    Data-driven restocking for convenience store owners.<br>
                    Stop guessing. Start stocking smarter.
                </p>

                <ul class="space-y-6">
                    <li class="flex items-start gap-4">
                        <div class="mt-0.5 w-6 h-6 rounded-md bg-[#1A6933]/25 border border-[#1A6933]/40 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 text-[#1A6933]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <div>
                            <p class="text-[#F0E8D0] font-semibold text-sm mb-1">Demand Forecasting</p>
                            <p class="text-[#D2C8AE] text-sm leading-relaxed">Upload your sales history and ProVendor forecasts product demand using Meta's Prophet model, adjusted for your store's actual patterns.</p>
                        </div>
                    </li>
                    <li class="flex items-start gap-4">
                        <div class="mt-0.5 w-6 h-6 rounded-md bg-[#1A6933]/25 border border-[#1A6933]/40 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 text-[#1A6933]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <div>
                            <p class="text-[#F0E8D0] font-semibold text-sm mb-1">Optimal Restock Quantities</p>
                            <p class="text-[#D2C8AE] text-sm leading-relaxed">The Newsvendor model weighs the cost of running short against the cost of overstocking, so every order protects your margin.</p>
                        </div>
                    </li>
                    <li class="flex items-start gap-4">
                        <div class="mt-0.5 w-6 h-6 rounded-md bg-[#1A6933]/25 border border-[#1A6933]/40 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 text-[#1A6933]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <div>
                            <p class="text-[#F0E8D0] font-semibold text-sm mb-1">Seasonal &amp; Event Awareness</p>
                            <p class="text-[#D2C8AE] text-sm leading-relaxed">Automatically accounts for Philippine public holidays, payday spikes, and local weather — so you're ready before demand shifts.</p>
                        </div>
                    </li>
                    <li class="flex items-start gap-4">
                        <div class="mt-0.5 w-6 h-6 rounded-md bg-[#1A6933]/25 border border-[#1A6933]/40 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3 text-[#1A6933]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <div>
                            <p class="text-[#F0E8D0] font-semibold text-sm mb-1">All You Need Is a CSV</p>
                            <p class="text-[#D2C8AE] text-sm leading-relaxed">No special hardware or POS system required. Export your transaction records, upload once, and the system does the rest.</p>
                        </div>
                    </li>
                </ul>

            </div>

            <!-- About link pinned to bottom-left of panel -->
            <div class="absolute bottom-8 left-16">
                <a href="<?php echo BASE_URL; ?>/pages/about.view.php"
                   class="flex items-center gap-1.5 text-[#D2C8AE]/45 hover:text-[#D2C8AE]/80 transition-colors text-xs uppercase tracking-widest">
                    About the project
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>
        </section>

        <!-- ── Right Panel ── -->
        <section class="w-[45%] bg-[#D2C8AE] dot-pattern-right relative flex items-center justify-center px-12 py-12 overflow-y-auto">

            <!-- Watermark monogram -->
            <div class="absolute bottom-6 right-8 font-semibold text-[#261F0E] select-none pointer-events-none leading-none" style="font-size:9rem; opacity:0.06; letter-spacing:-0.04em;">PV</div>

            <div class="w-full max-w-md relative">

                <!-- Card -->
                <div class="auth-card">

                    <p id="card-heading" class="auth-heading">Welcome back</p>
                    <p id="card-subheading" class="auth-subheading">Sign in to your ProVendor account</p>

                    <?php if ($error): ?>
                    <div class="auth-error">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Tabs -->
                    <div class="auth-tabs">
                        <button id="tab-login"  onclick="switchTab('login')"  class="auth-tab active">Login</button>
                        <button id="tab-signup" onclick="switchTab('signup')" class="auth-tab">Sign Up</button>
                    </div>

                    <!-- Login Form -->
                    <form id="form-login" action="<?php echo BASE_URL; ?>/pages/login.view.php" method="POST" class="auth-form">
                        <input type="hidden" name="action" value="login">

                        <div class="form-group">
                            <label for="login-email" class="form-label">Email Address</label>
                            <input type="email" id="login-email" name="email" class="form-input"
                                required autocomplete="email" placeholder="you@example.com">
                        </div>

                        <div class="form-group">
                            <label for="login-password" class="form-label">Password</label>
                            <input type="password" id="login-password" name="password" class="form-input"
                                required autocomplete="current-password" placeholder="••••••••">
                        </div>

                        <a href="<?php echo BASE_URL; ?>/pages/forgot_password.php" class="form-link">Forgot Password?</a>

                        <button type="submit" class="btn-submit">Login</button>
                    </form>

                    <!-- Sign Up Form -->
                    <form id="form-signup" action="<?php echo BASE_URL; ?>/pages/login.view.php" method="POST" class="auth-form hidden"
                        onsubmit="return interceptSignup(event)">
                        <input type="hidden" name="action" value="signup">

                        <div class="form-group">
                            <label for="signup-name" class="form-label">Full Name</label>
                            <input type="text" id="signup-name" name="name" class="form-input"
                                required autocomplete="name" placeholder="Juan dela Cruz">
                        </div>

                        <div class="form-group">
                            <label for="signup-store" class="form-label">Store Name</label>
                            <input type="text" id="signup-store" name="store_name" class="form-input"
                                required autocomplete="organization" placeholder="dela Cruz General Merchandise">
                        </div>

                        <div class="form-group">
                            <label for="signup-email" class="form-label">Email Address</label>
                            <input type="email" id="signup-email" name="email" class="form-input"
                                required autocomplete="email" placeholder="you@example.com">
                        </div>

                        <div class="form-group">
                            <label for="signup-password" class="form-label">Password</label>
                            <input type="password" id="signup-password" name="password" class="form-input"
                                required autocomplete="new-password" placeholder="••••••••">
                        </div>

                        <button type="submit" class="btn-submit">Create Account</button>
                    </form>
                </div><!-- /card -->
            </div>
        </section>

        <!-- Scroll indicator -->
        <div class="absolute bottom-8 left-1/2 -translate-x-1/2 flex flex-col items-center gap-2 pointer-events-none">
            <span class="text-[#D2C8AE]/40 text-[10px] uppercase tracking-widest">Scroll</span>
            <div class="nudge">
                <svg class="w-4 h-4 text-[#D2C8AE]/30" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
        </div>

    </div><!-- /end of hero -->


    <!-- ════════════════════════════════════════════
         SECTION 1 — How It Works
    ════════════════════════════════════════════ -->
    <section class="bg-[#261F0E] dot-pattern py-28 border-t border-[#F0E8D0]/10">
        <div class="max-w-5xl mx-auto px-16">

            <div class="sa mb-16">
                <p class="text-[#1A6933] text-[10px] font-semibold uppercase tracking-widest mb-3">How It Works</p>
                <h2 class="text-4xl font-semibold text-[#F0E8D0] leading-tight">From spreadsheet to smart decisions<br>in three steps.</h2>
                <div class="reveal-line mt-6 h-px w-16 bg-[#D2C8AE]/30"></div>
            </div>

            <div class="grid grid-cols-3 gap-10">

                <!-- Step 1 -->
                <div class="sa d1">
                    <div class="w-10 h-10 rounded-xl bg-[#F0E8D0]/08 border border-[#F0E8D0]/15 flex items-center justify-center mb-6" style="background:rgba(240,232,208,0.06)">
                        <svg class="w-5 h-5 text-[#D2C8AE]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                    </div>
                    <p class="text-[#D2C8AE]/40 text-xs font-semibold uppercase tracking-widest mb-2">Step 01</p>
                    <p class="text-[#F0E8D0] font-semibold text-base mb-2">Upload Your CSV</p>
                    <p class="text-[#D2C8AE] text-sm leading-relaxed">Export your transaction records from any source — notebook, spreadsheet, POS. As long as it has dates, products, and quantities, ProVendor can work with it.</p>
                </div>

                <!-- Step 2 -->
                <div class="sa d2">
                    <div class="w-10 h-10 rounded-xl border border-[#F0E8D0]/15 flex items-center justify-center mb-6" style="background:rgba(240,232,208,0.06)">
                        <svg class="w-5 h-5 text-[#D2C8AE]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                    </div>
                    <p class="text-[#D2C8AE]/40 text-xs font-semibold uppercase tracking-widest mb-2">Step 02</p>
                    <p class="text-[#F0E8D0] font-semibold text-base mb-2">System Analyzes</p>
                    <p class="text-[#D2C8AE] text-sm leading-relaxed">Meta's Prophet model detects trends, weekly cycles, and seasonal spikes — automatically. It layers in local weather and Philippine holiday data to sharpen its predictions.</p>
                </div>

                <!-- Step 3 -->
                <div class="sa d3">
                    <div class="w-10 h-10 rounded-xl border border-[#F0E8D0]/15 flex items-center justify-center mb-6" style="background:rgba(240,232,208,0.06)">
                        <svg class="w-5 h-5 text-[#D2C8AE]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                    </div>
                    <p class="text-[#D2C8AE]/40 text-xs font-semibold uppercase tracking-widest mb-2">Step 03</p>
                    <p class="text-[#F0E8D0] font-semibold text-base mb-2">Get Recommendations</p>
                    <p class="text-[#D2C8AE] text-sm leading-relaxed">The Newsvendor model turns forecasts into exact restock quantities — balancing the cost of running out against the cost of over-ordering, per product.</p>
                </div>

            </div>
        </div>
    </section>


    <!-- ════════════════════════════════════════════
         SECTION 2 — Pain Point Stats
    ════════════════════════════════════════════ -->
    <section class="bg-[#F0E8D0] dot-pattern-light py-28">
        <div class="max-w-5xl mx-auto px-16">

            <div class="sa mb-16 text-center">
                <p class="text-[#1A6933] text-[10px] font-semibold uppercase tracking-widest mb-3">The Problem</p>
                <h2 class="text-4xl font-semibold text-[#261F0E] leading-tight">Guessing is costing you more<br>than you think.</h2>
                <div class="reveal-line mt-6 h-px w-16 bg-[#261F0E]/20 mx-auto"></div>
            </div>

            <div class="grid grid-cols-3 gap-10 text-center">

                <div class="sa d1">
                    <p class="text-6xl font-semibold text-[#261F0E] counter" data-target="34">0</p>
                    <p class="text-[#1A6933] font-semibold text-lg mt-1">%</p>
                    <div class="w-8 h-px bg-[#D2C8AE] mx-auto my-4"></div>
                    <p class="text-[#261F0E] font-semibold text-sm mb-1">of small retailers</p>
                    <p class="text-[#261F0E]/60 text-sm leading-relaxed">experience sales losses directly caused by running out of stock at the wrong time.</p>
                </div>

                <div class="sa d2">
                    <p class="text-6xl font-semibold text-[#261F0E] counter" data-target="75">0</p>
                    <p class="text-[#1A6933] font-semibold text-lg mt-1">%+</p>
                    <div class="w-8 h-px bg-[#D2C8AE] mx-auto my-4"></div>
                    <p class="text-[#261F0E] font-semibold text-sm mb-1">forecast accuracy</p>
                    <p class="text-[#261F0E]/60 text-sm leading-relaxed">ProVendor targets versus actual sales records — far better than gut-feel restocking decisions.</p>
                </div>

                <div class="sa d3">
                    <p class="text-6xl font-semibold text-[#261F0E]">1</p>
                    <p class="text-[#1A6933] font-semibold text-lg mt-1">CSV</p>
                    <div class="w-8 h-px bg-[#D2C8AE] mx-auto my-4"></div>
                    <p class="text-[#261F0E] font-semibold text-sm mb-1">all you need to start</p>
                    <p class="text-[#261F0E]/60 text-sm leading-relaxed">No POS system, no special hardware, no IT setup. Just your sales history in a spreadsheet.</p>
                </div>

            </div>
        </div>
    </section>


    <!-- ════════════════════════════════════════════
         SECTION 3 — What You'll See
    ════════════════════════════════════════════ -->
    <section class="bg-[#261F0E] dot-pattern py-28 border-t border-[#F0E8D0]/10">
        <div class="max-w-5xl mx-auto px-16">

            <div class="sa mb-16">
                <p class="text-[#1A6933] text-[10px] font-semibold uppercase tracking-widest mb-3">What You'll See</p>
                <h2 class="text-4xl font-semibold text-[#F0E8D0] leading-tight">Every insight you need.<br>Nothing you don't.</h2>
                <div class="reveal-line mt-6 h-px w-16 bg-[#D2C8AE]/30"></div>
            </div>

            <div class="grid grid-cols-3 gap-6">

                <!-- Preview: Demand Chart -->
                <div class="sa d1 rounded-xl border border-[#F0E8D0]/10 overflow-hidden" style="background:rgba(240,232,208,0.05)">
                    <div class="px-5 py-4 border-b border-[#F0E8D0]/10">
                        <p class="text-[#F0E8D0] text-xs font-semibold">Demand Forecast</p>
                        <p class="text-[#D2C8AE]/50 text-[10px] mt-0.5">Beverages · Next 30 days</p>
                    </div>
                    <div class="px-5 py-5">
                        <!-- Mini bar chart mockup -->
                        <div class="flex items-end gap-1 h-20 mb-3">
                            <div class="flex-1 rounded-t-sm bg-[#1A6933]/40" style="height:55%"></div>
                            <div class="flex-1 rounded-t-sm bg-[#1A6933]/40" style="height:40%"></div>
                            <div class="flex-1 rounded-t-sm bg-[#1A6933]/40" style="height:70%"></div>
                            <div class="flex-1 rounded-t-sm bg-[#1A6933]/40" style="height:50%"></div>
                            <div class="flex-1 rounded-t-sm bg-[#1A6933]/60" style="height:85%"></div>
                            <div class="flex-1 rounded-t-sm bg-[#1A6933]/60" style="height:90%"></div>
                            <div class="flex-1 rounded-t-sm bg-[#1A6933]/40" style="height:65%"></div>
                            <div class="flex-1 rounded-t-sm bg-[#1A6933]/40" style="height:45%"></div>
                            <div class="flex-1 rounded-t-sm bg-[#1A6933]/30" style="height:75%"></div>
                            <div class="flex-1 rounded-t-sm bg-[#1A6933]/30" style="height:100%"></div>
                            <div class="flex-1 rounded-t-sm bg-[#1A6933]/30" style="height:80%"></div>
                            <div class="flex-1 rounded-t-sm bg-[#1A6933]/30" style="height:60%"></div>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-[#D2C8AE]/40 text-[9px]">Today</span>
                            <span class="text-[#D2C8AE]/40 text-[9px]">+30 days</span>
                        </div>
                        <div class="mt-3 flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full bg-[#1A6933]/60"></div>
                            <span class="text-[#D2C8AE]/50 text-[10px]">Forecasted demand</span>
                        </div>
                    </div>
                </div>

                <!-- Preview: Product Cards -->
                <div class="sa d2 rounded-xl border border-[#F0E8D0]/10 overflow-hidden" style="background:rgba(240,232,208,0.05)">
                    <div class="px-5 py-4 border-b border-[#F0E8D0]/10">
                        <p class="text-[#F0E8D0] text-xs font-semibold">Product Overview</p>
                        <p class="text-[#D2C8AE]/50 text-[10px] mt-0.5">12 products tracked</p>
                    </div>
                    <div class="px-5 py-4 space-y-3">
                        <div class="flex items-center justify-between py-2 border-b border-[#F0E8D0]/08">
                            <div>
                                <p class="text-[#F0E8D0] text-xs font-semibold">Bottled Water</p>
                                <p class="text-[#D2C8AE]/50 text-[10px]">Beverages</p>
                            </div>
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full text-[#1A6933]" style="background:rgba(26,105,51,0.2)">Restock: 48</span>
                        </div>
                        <div class="flex items-center justify-between py-2 border-b border-[#F0E8D0]/08">
                            <div>
                                <p class="text-[#F0E8D0] text-xs font-semibold">Instant Noodles</p>
                                <p class="text-[#D2C8AE]/50 text-[10px]">Food</p>
                            </div>
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full text-[#FF5722]" style="background:rgba(255,87,34,0.2)">Restock: 24</span>
                        </div>
                        <div class="flex items-center justify-between py-2">
                            <div>
                                <p class="text-[#F0E8D0] text-xs font-semibold">Canned Sardines</p>
                                <p class="text-[#D2C8AE]/50 text-[10px]">Food</p>
                            </div>
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full text-[#1A6933]" style="background:rgba(26,105,51,0.2)">Restock: 36</span>
                        </div>
                    </div>
                </div>

                <!-- Preview: Restock Calculator -->
                <div class="sa d3 rounded-xl border border-[#F0E8D0]/10 overflow-hidden" style="background:rgba(240,232,208,0.05)">
                    <div class="px-5 py-4 border-b border-[#F0E8D0]/10">
                        <p class="text-[#F0E8D0] text-xs font-semibold">Restock Calculator</p>
                        <p class="text-[#D2C8AE]/50 text-[10px] mt-0.5">Bottled Water</p>
                    </div>
                    <div class="px-5 py-4 space-y-3">
                        <div>
                            <p class="text-[#D2C8AE]/50 text-[9px] uppercase tracking-wider mb-1">Forecast Days</p>
                            <div class="h-7 rounded bg-[#F0E8D0]/08 border border-[#F0E8D0]/10 px-3 flex items-center">
                                <span class="text-[#D2C8AE]/60 text-xs">30</span>
                            </div>
                        </div>
                        <div>
                            <p class="text-[#D2C8AE]/50 text-[9px] uppercase tracking-wider mb-1">Cost / Selling Price</p>
                            <div class="h-7 rounded bg-[#F0E8D0]/08 border border-[#F0E8D0]/10 px-3 flex items-center">
                                <span class="text-[#D2C8AE]/60 text-xs">₱12 / ₱20</span>
                            </div>
                        </div>
                        <div class="pt-2 border-t border-[#F0E8D0]/10">
                            <p class="text-[#D2C8AE]/50 text-[9px] uppercase tracking-wider mb-1">Recommended Restock</p>
                            <p class="text-[#1A6933] text-2xl font-semibold">48 <span class="text-sm font-normal text-[#D2C8AE]/50">units</span></p>
                            <p class="text-[#D2C8AE]/50 text-[10px] mt-1">Est. profit: ₱384</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>


    <!-- ════════════════════════════════════════════
         SECTION 4 — What Data You Need
    ════════════════════════════════════════════ -->
    <section class="bg-[#F0E8D0] dot-pattern-light py-28">
        <div class="max-w-5xl mx-auto px-16">

            <div class="grid grid-cols-2 gap-20 items-center">

                <div class="sa">
                    <p class="text-[#1A6933] text-[10px] font-semibold uppercase tracking-widest mb-3">Getting Started</p>
                    <h2 class="text-4xl font-semibold text-[#261F0E] leading-tight mb-6">You probably already<br>have what you need.</h2>
                    <p class="text-[#261F0E]/60 text-sm leading-relaxed mb-8">ProVendor works with any CSV that has three columns: a date, a product name, and a quantity sold. Everything else is optional — the system adapts to what you provide.</p>

                    <div class="space-y-4">
                        <div class="flex items-start gap-3">
                            <div class="w-5 h-5 rounded bg-[#1A6933]/20 border border-[#1A6933]/30 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <svg class="w-2.5 h-2.5 text-[#1A6933]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                            <div>
                                <p class="text-[#261F0E] font-semibold text-sm">Required: Date, Product, Quantity</p>
                                <p class="text-[#261F0E]/50 text-xs mt-0.5">The minimum needed to run a forecast</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="w-5 h-5 rounded bg-[#D2C8AE] border border-[#D2C8AE] flex items-center justify-center flex-shrink-0 mt-0.5">
                                <svg class="w-2.5 h-2.5 text-[#261F0E]/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            </div>
                            <div>
                                <p class="text-[#261F0E] font-semibold text-sm">Optional: Category, Cost, Price</p>
                                <p class="text-[#261F0E]/50 text-xs mt-0.5">Unlocks category grouping and profit estimates</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <div class="w-5 h-5 rounded bg-[#D2C8AE] border border-[#D2C8AE] flex items-center justify-center flex-shrink-0 mt-0.5">
                                <svg class="w-2.5 h-2.5 text-[#261F0E]/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            </div>
                            <div>
                                <p class="text-[#261F0E] font-semibold text-sm">Column names don't matter</p>
                                <p class="text-[#261F0E]/50 text-xs mt-0.5">ProVendor auto-detects columns and asks you to confirm</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CSV mockup table -->
                <div class="sa d1">
                    <div class="rounded-xl border border-[#D2C8AE] overflow-hidden shadow-[0_8px_30px_rgba(38,31,14,0.08)]">
                        <!-- Header bar -->
                        <div class="bg-[#261F0E] px-5 py-3 flex items-center gap-2">
                            <div class="w-2.5 h-2.5 rounded-full bg-[#FF1A1A]/60"></div>
                            <div class="w-2.5 h-2.5 rounded-full bg-[#FF5722]/60"></div>
                            <div class="w-2.5 h-2.5 rounded-full bg-[#1A6933]/60"></div>
                            <span class="ml-2 text-[#D2C8AE]/50 text-[10px]">sales_data.csv</span>
                        </div>
                        <!-- Table -->
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="bg-[#D2C8AE]">
                                    <th class="text-left px-4 py-2.5 text-[#261F0E] font-semibold text-[10px] uppercase tracking-wider">Date</th>
                                    <th class="text-left px-4 py-2.5 text-[#261F0E] font-semibold text-[10px] uppercase tracking-wider">Product</th>
                                    <th class="text-left px-4 py-2.5 text-[#261F0E] font-semibold text-[10px] uppercase tracking-wider">Qty</th>
                                    <th class="text-left px-4 py-2.5 text-[#261F0E]/40 font-semibold text-[10px] uppercase tracking-wider">Category</th>
                                </tr>
                            </thead>
                            <tbody class="bg-[#F0E8D0]">
                                <tr class="border-t border-[#D2C8AE]">
                                    <td class="px-4 py-2.5 text-[#261F0E]/70">2024-01-03</td>
                                    <td class="px-4 py-2.5 text-[#261F0E]">Bottled Water</td>
                                    <td class="px-4 py-2.5 text-[#261F0E] font-semibold">12</td>
                                    <td class="px-4 py-2.5 text-[#261F0E]/40">Beverages</td>
                                </tr>
                                <tr class="border-t border-[#D2C8AE]">
                                    <td class="px-4 py-2.5 text-[#261F0E]/70">2024-01-03</td>
                                    <td class="px-4 py-2.5 text-[#261F0E]">Instant Noodles</td>
                                    <td class="px-4 py-2.5 text-[#261F0E] font-semibold">8</td>
                                    <td class="px-4 py-2.5 text-[#261F0E]/40">Food</td>
                                </tr>
                                <tr class="border-t border-[#D2C8AE]">
                                    <td class="px-4 py-2.5 text-[#261F0E]/70">2024-01-04</td>
                                    <td class="px-4 py-2.5 text-[#261F0E]">Bottled Water</td>
                                    <td class="px-4 py-2.5 text-[#261F0E] font-semibold">15</td>
                                    <td class="px-4 py-2.5 text-[#261F0E]/40">Beverages</td>
                                </tr>
                                <tr class="border-t border-[#D2C8AE]">
                                    <td class="px-4 py-2.5 text-[#261F0E]/70">2024-01-04</td>
                                    <td class="px-4 py-2.5 text-[#261F0E]">Canned Sardines</td>
                                    <td class="px-4 py-2.5 text-[#261F0E] font-semibold">6</td>
                                    <td class="px-4 py-2.5 text-[#261F0E]/40">Food</td>
                                </tr>
                                <tr class="border-t border-[#D2C8AE] bg-[#D2C8AE]/30">
                                    <td class="px-4 py-2 text-[#261F0E]/30 text-[10px]" colspan="4">· · ·</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="bg-[#D2C8AE]/40 px-4 py-2 border-t border-[#D2C8AE]">
                            <span class="text-[#261F0E]/40 text-[10px]">Required columns highlighted — rest is optional</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </section>


    <!-- ════════════════════════════════════════════
         FOOTER
    ════════════════════════════════════════════ -->
    <footer class="bg-[#261F0E] border-t border-[#F0E8D0]/10 py-14">
        <div class="max-w-5xl mx-auto px-16">
            <div class="flex items-start justify-between">

                <div>
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-8 h-8 rounded-lg bg-[#F0E8D0]/10 border border-[#F0E8D0]/20 flex items-center justify-center">
                            <span class="text-[#F0E8D0] text-[10px] font-semibold tracking-widest">PV</span>
                        </div>
                        <span class="text-[#F0E8D0] font-semibold text-base">ProVendor</span>
                    </div>
                    <p class="text-[#D2C8AE]/50 text-sm leading-relaxed max-w-xs">Data-driven restocking for SME convenience store owners. Stop guessing. Start stocking smarter.</p>
                </div>

                <div class="text-right">
                    <p class="text-[#D2C8AE]/30 text-xs uppercase tracking-widest mb-1">Academic Prototype</p>
                    <p class="text-[#D2C8AE]/50 text-sm">Built with Prophet &amp; Newsvendor Model</p>
                </div>

            </div>

            <div class="mt-10 pt-6 border-t border-[#F0E8D0]/08 flex items-center justify-between">
                <p class="text-[#D2C8AE]/25 text-xs">© <?php echo date('Y'); ?> ProVendor. All rights reserved.</p>
                <div class="flex items-center gap-6">
                    <a href="<?php echo BASE_URL; ?>/pages/about.view.php"
                       class="text-[#D2C8AE]/55 hover:text-[#D2C8AE]/90 transition-colors text-xs uppercase tracking-widest">
                        About Us
                    </a>
                    <a href="#" onclick="window.scrollTo({top:0,behavior:'smooth'}); return false;"
                       class="text-[#D2C8AE]/30 hover:text-[#D2C8AE]/60 transition-colors text-xs uppercase tracking-widest">
                        Back to top ↑
                    </a>
                </div>
            </div>
        </div>
    </footer>


    <script>
        // ── Tab switching ──
        document.addEventListener('DOMContentLoaded', function () {
            switchTab('<?php echo htmlspecialchars($activeTab); ?>');
        });

        function switchTab(tab) {
            const loginForm  = document.getElementById('form-login');
            const signupForm = document.getElementById('form-signup');
            const tabLogin   = document.getElementById('tab-login');
            const tabSignup  = document.getElementById('tab-signup');
            const heading    = document.getElementById('card-heading');
            const subheading = document.getElementById('card-subheading');

            if (tab === 'login') {
                loginForm.classList.remove('hidden');
                signupForm.classList.add('hidden');
                tabLogin.classList.add('active');
                tabSignup.classList.remove('active');
                heading.textContent    = 'Welcome back';
                subheading.textContent = 'Sign in to your ProVendor account';
            } else {
                signupForm.classList.remove('hidden');
                loginForm.classList.add('hidden');
                tabSignup.classList.add('active');
                tabLogin.classList.remove('active');
                heading.textContent    = 'Create your account';
                subheading.textContent = 'Start making smarter restocking decisions';
            }
        }

        // ── Scroll animations ──
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15 });

        document.querySelectorAll('.sa, .reveal-line').forEach(el => observer.observe(el));

        // ── Counter animation ──
        const counterObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const el     = entry.target;
                const target = parseInt(el.dataset.target, 10);
                const dur    = 1200;
                const step   = 16;
                const inc    = target / (dur / step);
                let current  = 0;
                const timer  = setInterval(() => {
                    current += inc;
                    if (current >= target) {
                        el.textContent = target;
                        clearInterval(timer);
                    } else {
                        el.textContent = Math.floor(current);
                    }
                }, step);
                counterObserver.unobserve(el);
            });
        }, { threshold: 0.5 });

        document.querySelectorAll('.counter').forEach(el => counterObserver.observe(el));

        // ── Signup confirmation ──
        function interceptSignup(e) {
            e.preventDefault();
            showConfirm({
                title: 'Create your account?',
                message: 'Please make sure your name, store name, and email are correct. You can update them later in your profile.',
                confirmText: 'Yes, create account',
                confirmStyle: 'primary',
                onConfirm: function () {
                    document.getElementById('form-signup').submit();
                },
            });
            return false;
        }
    </script>


<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
</body>
</html>
