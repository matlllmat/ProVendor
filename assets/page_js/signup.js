// Signup stays on this page until email ownership has been verified.
var signupBusy = false;
var signupResendAt = 0;
var signupTimer = null;

function signupNotice(message, isError) {
    var notice = document.getElementById('signup-feedback');
    notice.textContent = message || '';
    notice.className = message ? (isError ? 'auth-error' : 'text-success') : 'hidden';
}

function updateSignupResend() {
    var button = document.getElementById('signup-resend');
    var seconds = Math.max(0, Math.ceil((signupResendAt - Date.now()) / 1000));
    button.disabled = signupBusy || seconds > 0;
    button.textContent = seconds > 0 ? 'Resend in ' + seconds + 's' : 'Resend code';
    if (seconds === 0 && signupTimer) {
        clearInterval(signupTimer);
        signupTimer = null;
    }
}

function showSignupVerification(state) {
    document.getElementById('signup-code-email').textContent = state.email;
    signupResendAt = Date.now() + (state.resend_after || 0) * 1000;
    if (signupTimer) clearInterval(signupTimer);
    signupTimer = setInterval(updateSignupResend, 1000);
    updateSignupResend();
    switchTab('signup-verify');
    document.getElementById('signup-code').focus();
}

async function signupRequest(action, form) {
    if (signupBusy) return;
    signupBusy = true;
    var buttons = document.querySelectorAll('#form-signup button, #form-signup-verify button, #auth-tabs button');
    buttons.forEach(function (button) { button.disabled = true; });
    signupNotice('');
    var submit = form && form.querySelector('button[type="submit"]');
    var previousLabel = submit ? submit.textContent : '';
    if (submit) submit.textContent = action === 'signup' || action === 'resend_signup' ? 'Sending code...' : 'Verifying...';

    try {
        var body = form ? new FormData(form) : new FormData();
        body.set('action', action);
        var meta = document.querySelector('meta[name="csrf-token"]');
        body.set('csrf_token', meta.content);
        var response = await fetch(meta.dataset.base + '/api/signup.php', {method: 'POST', body: body});
        var result = await response.json();
        if (result.success) {
            window.location.assign(result.redirect);
            return;
        }
        if (result.cancelled || result.restart) {
            if (signupTimer) clearInterval(signupTimer);
            signupTimer = null;
            signupResendAt = 0;
            document.getElementById('signup-code').value = '';
            switchTab('signup');
        } else if (result.requires_verification) {
            showSignupVerification(result);
            if (!result.error) {
                document.getElementById('signup-password').value = '';
                document.getElementById('signup-confirm').value = '';
                document.getElementById('signup-code').value = '';
            }
        }
        if (result.retry_after && action === 'resend_signup') {
            signupResendAt = Date.now() + result.retry_after * 1000;
            if (!signupTimer) signupTimer = setInterval(updateSignupResend, 1000);
        }
        signupNotice(result.error || result.message, !!result.error);
    } catch (error) {
        signupNotice('We could not reach the server. Please try again.', true);
    } finally {
        signupBusy = false;
        buttons.forEach(function (button) { button.disabled = false; });
        if (submit) submit.textContent = previousLabel;
        updateSignupResend();
    }
}

function interceptSignup(event) {
    event.preventDefault();
    signupRequest('signup', document.getElementById('form-signup'));
    return false;
}

function verifySignup(event) {
    event.preventDefault();
    var action = event.submitter && event.submitter.value === 'resend_signup' ? 'resend_signup' : 'verify_signup';
    signupRequest(action, document.getElementById('form-signup-verify'));
    return false;
}

function changeSignupEmail() {
    signupRequest('cancel_signup', null);
}

document.addEventListener('DOMContentLoaded', function () {
    if (window.signupVerification && window.signupVerification.requires_verification) {
        showSignupVerification(window.signupVerification);
    }
});
