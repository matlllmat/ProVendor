<?php
// pages/login.view.php
// Presentation only — renders the login/signup and password reset pages.

require_once __DIR__ . '/login.logic.php';

$pageTitle = 'ProVendor — Login';
$pageCss   = 'login.css';
$bodyClass = 'bg-[#261F0E]';
require_once __DIR__ . '/../includes/header.php';
?>
    <style>
        /* Inline styles for the new password strength meter */
        .strength-meter-container {
            height: 5px;
            background-color: rgba(38,31,14,0.15);
            border-radius: 3px;
            margin-top: 0.35rem;
            overflow: hidden;
            width: 100%;
        }
        .strength-meter-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
        }
        .text-success {
            background: rgba(26,105,51,0.07);
            border: 1px solid #1A6933;
            color: #1A6933;
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }
        .text-xs-muted { font-size: 0.65rem; color: #a89f8a; margin-top: 0.25rem; display: block; }
    </style>

    <div class="flex min-h-screen relative">

        <section class="w-[55%] flex flex-col justify-center px-16 py-12 bg-[#261F0E] dot-pattern relative">
            <div class="max-w-lg">
                <div class="w-11 h-11 rounded-xl bg-[#F0E8D0]/10 border border-[#F0E8D0]/20 flex items-center justify-center mb-8">
                    <span class="text-[#F0E8D0] text-sm font-semibold tracking-widest">PV</span>
                </div>
                <h1 class="text-6xl font-semibold text-[#F0E8D0] mb-3 leading-tight tracking-tight">ProVendor</h1>
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
                </ul>
            </div>
        </section>

        <section class="w-[45%] bg-[#D2C8AE] dot-pattern-right relative flex items-center justify-center px-12 py-12 overflow-y-auto">
            <div class="absolute bottom-6 right-8 font-semibold text-[#261F0E] select-none pointer-events-none leading-none" style="font-size:9rem; opacity:0.06; letter-spacing:-0.04em;">PV</div>

            <div class="w-full max-w-md relative">
                <div class="auth-card">

                    <p id="card-heading" class="auth-heading">Welcome back</p>
                    <p id="card-subheading" class="auth-subheading">Sign in to your ProVendor account</p>

                    <?php if ($error): ?>
                    <div class="auth-error">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                    <div class="text-success">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                    <?php endif; ?>

                    <div class="auth-tabs" id="auth-tabs">
                        <button id="tab-login"  onclick="switchTab('login')"  class="auth-tab active">Login</button>
                        <button id="tab-signup" onclick="switchTab('signup')" class="auth-tab">Sign Up</button>
                    </div>

                    <form id="form-login" action="<?php echo BASE_URL; ?>/pages/login.view.php" method="POST" class="auth-form hidden">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

                        <div class="form-group">
                            <label for="login-email" class="form-label">Email Address</label>
                            <input type="email" id="login-email" name="email" class="form-input" required placeholder="you@example.com">
                        </div>

                        <div class="form-group">
                            <label for="login-password" class="form-label">Password</label>
                            <input type="password" id="login-password" name="password" class="form-input" required placeholder="••••••••">
                            <a href="#" onclick="switchTab('forgot'); return false;" class="form-link mt-1">Forgot Password?</a>
                        </div>

                        <button type="submit" class="btn-submit">Login</button>
                    </form>

                    <form id="form-signup" action="<?php echo BASE_URL; ?>/pages/login.view.php" method="POST" class="auth-form hidden" onsubmit="return interceptSignup(event)">
                        <input type="hidden" name="action" value="signup">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

                        <div class="form-group">
                            <label for="signup-name" class="form-label">Full Name</label>
                            <input type="text" id="signup-name" name="name" class="form-input" required placeholder="Juan dela Cruz">
                        </div>

                        <div class="form-group">
                            <label for="signup-store" class="form-label">Store Name</label>
                            <input type="text" id="signup-store" name="store_name" class="form-input" required placeholder="dela Cruz Merchandise">
                        </div>

                        <div class="form-group">
                            <label for="signup-email" class="form-label">Email Address</label>
                            <input type="email" id="signup-email" name="email" class="form-input" required placeholder="you@example.com">
                        </div>

                        <div class="form-group">
                            <label for="signup-password" class="form-label">Password</label>
                            <input type="password" id="signup-password" name="password" class="form-input" required placeholder="••••••••" oninput="checkStrength(this, 'signup-meter')">
                            <div class="strength-meter-container"><div id="signup-meter" class="strength-meter-fill"></div></div>
                            <span class="text-xs-muted">Requires 1 uppercase, 1 lowercase, 1 number, 1 special char (min 8 chars)</span>
                        </div>

                        <div class="form-group">
                            <label for="signup-confirm" class="form-label">Confirm Password</label>
                            <input type="password" id="signup-confirm" name="confirm_password" class="form-input" required placeholder="••••••••">
                        </div>

                        <button type="submit" class="btn-submit">Create Account</button>
                    </form>

                    <form id="form-forgot" action="<?php echo BASE_URL; ?>/pages/login.view.php" method="POST" class="auth-form hidden">
                        <input type="hidden" name="action" value="send_code">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        
                        <div class="form-group">
                            <label for="forgot-email" class="form-label">Account Email</label>
                            <input type="email" id="forgot-email" name="email" class="form-input" required placeholder="you@example.com">
                        </div>

                        <button type="submit" class="btn-submit">Send Code</button>
                        <a href="#" onclick="switchTab('login'); return false;" class="form-link text-center mt-2" style="align-self: center;">Back to Login</a>
                    </form>

                    <form id="form-verify" action="<?php echo BASE_URL; ?>/pages/login.view.php" method="POST" class="auth-form hidden">
                        <input type="hidden" name="action" value="verify_code">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        
                        <div class="form-group">
                            <label for="verify-code" class="form-label">6-Digit Reset Code</label>
                            <input type="text" id="verify-code" name="code" class="form-input" required placeholder="123456" autocomplete="off">
                        </div>

                        <button type="submit" class="btn-submit">Verify Code</button>
                    </form>

                    <form id="form-reset" action="<?php echo BASE_URL; ?>/pages/login.view.php" method="POST" class="auth-form hidden">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                        
                        <div class="form-group">
                            <label for="reset-password" class="form-label">New Password</label>
                            <input type="password" id="reset-password" name="password" class="form-input" required placeholder="••••••••" oninput="checkStrength(this, 'reset-meter')">
                            <div class="strength-meter-container"><div id="reset-meter" class="strength-meter-fill"></div></div>
                            <span class="text-xs-muted">Requires 1 uppercase, 1 lowercase, 1 number, 1 special char (min 8 chars)</span>
                        </div>

                        <div class="form-group">
                            <label for="reset-confirm" class="form-label">Confirm New Password</label>
                            <input type="password" id="reset-confirm" name="confirm_password" class="form-input" required placeholder="••••••••">
                        </div>

                        <button type="submit" class="btn-submit">Save New Password</button>
                    </form>

                </div>
            </div>
        </section>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            switchTab('<?php echo htmlspecialchars($activeTab); ?>');
        });

        function switchTab(tab) {
            const allForms = ['login', 'signup', 'forgot', 'verify', 'reset'];
            allForms.forEach(f => {
                const el = document.getElementById('form-' + f);
                if(el) el.classList.add('hidden');
            });

            const tabsContainer = document.getElementById('auth-tabs');
            const heading       = document.getElementById('card-heading');
            const subheading    = document.getElementById('card-subheading');

            // Hide the dual tab UI if we are in forgot/reset flow
            if (tab === 'login' || tab === 'signup') {
                tabsContainer.classList.remove('hidden');
                document.getElementById('tab-login').classList.remove('active');
                document.getElementById('tab-signup').classList.remove('active');
                document.getElementById('tab-' + tab).classList.add('active');
            } else {
                tabsContainer.classList.add('hidden');
            }

            document.getElementById('form-' + tab).classList.remove('hidden');

            switch(tab) {
                case 'login':
                    heading.textContent = 'Welcome back';
                    subheading.textContent = 'Sign in to your ProVendor account';
                    break;
                case 'signup':
                    heading.textContent = 'Create your account';
                    subheading.textContent = 'Start making smarter restocking decisions';
                    break;
                case 'forgot':
                    heading.textContent = 'Forgot Password';
                    subheading.textContent = 'Enter your email to receive a reset code';
                    break;
                case 'verify':
                    heading.textContent = 'Verify Email';
                    subheading.textContent = 'Check your email for the 6-digit recovery code';
                    break;
                case 'reset':
                    heading.textContent = 'New Password';
                    subheading.textContent = 'Secure your account with a strong password';
                    break;
            }
        }

        function interceptSignup(e) {
            e.preventDefault();
            // Assuming showConfirm is loaded via confirm_modal.php
            if (typeof showConfirm === 'function') {
                showConfirm({
                    title: 'Create your account?',
                    message: 'Please make sure your details are correct.',
                    confirmText: 'Yes, create account',
                    confirmStyle: 'primary',
                    onConfirm: function () { document.getElementById('form-signup').submit(); }
                });
            } else {
                document.getElementById('form-signup').submit();
            }
            return false;
        }

        // Dynamic Password Strength Meter
        function checkStrength(input, meterId) {
            const val = input.value;
            let strength = 0;
            
            if (val.match(/[a-z]/)) strength++;      // lowercase
            if (val.match(/[A-Z]/)) strength++;      // uppercase
            if (val.match(/[0-9]/)) strength++;      // number
            if (val.match(/[\W_]/)) strength++;      // special char
            if (val.length >= 8) strength++;         // length

            const meter = document.getElementById(meterId);
            let color = 'transparent';
            let width = '0%';

            if (val.length > 0) {
                if (strength <= 2) { 
                    color = '#FF1A1A'; // Red (Weak)
                    width = '33%'; 
                } else if (strength <= 4) { 
                    color = '#ffa64d'; // Orange (Medium)
                    width = '66%'; 
                } else if (strength === 5) { 
                    color = '#1A6933'; // Green (Strong)
                    width = '100%'; 
                }
            }

            meter.style.width = width;
            meter.style.backgroundColor = color;
        }
    </script>

<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
</body>
</html>