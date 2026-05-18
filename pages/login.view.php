<?php
// pages/login.view.php
// Presentation only — renders the login/signup page.
// All logic and queries are handled by login.logic.php.

require_once __DIR__ . '/login.logic.php';

$pageTitle = 'ProVendor — Login';
$pageCss   = 'login.css';
$bodyClass = 'bg-[#261F0E]';
require_once __DIR__ . '/../includes/header.php';
?>

    <!-- ════════════════════════════════════════════
         Login / Sign Up — Two Panel Layout
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
                            <p class="text-[#D2C8AE] text-sm leading-relaxed">Automatically accounts for Philippine public holidays and payday spikes — so you're ready before demand shifts.</p>
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
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

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

                        <button type="submit" class="btn-submit">Login</button>
                    </form>

                    <!-- Sign Up Form -->
                    <form id="form-signup" action="<?php echo BASE_URL; ?>/pages/login.view.php" method="POST" class="auth-form hidden"
                        onsubmit="return interceptSignup(event)">
                        <input type="hidden" name="action" value="signup">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

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

    </div><!-- /end of layout -->


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
