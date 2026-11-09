const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function harness() {
    const elements = new Map();
    function element(id) {
        if (!elements.has(id)) elements.set(id, {id, value: '', textContent: '', className: '', disabled: false, focus() { this.focused = true; }, querySelector() { return button; }});
        return elements.get(id);
    }
    const button = element('submit');
    button.textContent = 'Send verification code';
    element('signup-password').value = 'TestPassword!42';
    element('signup-confirm').value = 'TestPassword!42';
    const state = {requests: [], tabs: [], redirects: [], queue: [], elements};
    const context = vm.createContext({
        document: {
            getElementById: element,
            querySelector: () => ({content: 'csrf-test-token', dataset: {base: '/ProVendor'}}),
            querySelectorAll: () => [button, element('signup-resend')],
            addEventListener() {},
        },
        window: {location: {assign: url => state.redirects.push(url)}},
        FormData: class {constructor() {this.values = new Map();} set(k, v) {this.values.set(k, v);}},
        Date, setInterval: () => 1, clearInterval() {},
        switchTab: tab => state.tabs.push(tab),
        fetch: async (url, request) => {
            state.requests.push({url, request});
            const result = state.queue.shift();
            if (result instanceof Error) throw result;
            return {json: async () => result};
        },
    });
    vm.runInContext(fs.readFileSync('assets/page_js/signup.js', 'utf8'), context);
    return {state, context, element};
}
(async () => {
    const {state, context, element} = harness();
    state.queue.push({requires_verification: true, email: 'owner@example.invalid', resend_after: 60, message: 'Code sent'});
    await context.signupRequest('signup', element('form-signup'));
    assert.equal(state.requests[0].url, '/ProVendor/api/signup.php');
    assert.equal(state.requests[0].request.body.values.get('csrf_token'), 'csrf-test-token');
    assert.equal(state.requests[0].request.body.values.get('action'), 'signup');
    assert.equal(state.redirects.length, 0, 'sending a code must not navigate away');
    assert.equal(state.tabs.at(-1), 'signup-verify');
    assert.equal(element('signup-code-email').textContent, 'owner@example.invalid');
    assert.equal(element('signup-password').value, '');
    assert.equal(element('signup-confirm').value, '');
    assert.equal(element('signup-resend').disabled, true);
    assert.equal(element('signup-code').focused, true);

    state.queue.push({requires_verification: true, email: 'owner@example.invalid', resend_after: 50, error: 'Incorrect code'});
    await context.signupRequest('verify_signup', element('form-signup-verify'));
    assert.equal(state.redirects.length, 0, 'wrong codes must keep the user on the page');
    assert.equal(element('signup-feedback').textContent, 'Incorrect code');
    assert.equal(element('signup-feedback').className, 'auth-error');

    state.queue.push(new Error('network unavailable'));
    await context.signupRequest('resend_signup', element('form-signup-verify'));
    assert.equal(context.signupBusy, false);
    assert.equal(element('submit').disabled, false, 'failed requests must restore controls');
    assert.match(element('signup-feedback').textContent, /try again/);

    state.queue.push({cancelled: true});
    await context.signupRequest('cancel_signup', null);
    assert.equal(state.tabs.at(-1), 'signup');
    assert.equal(context.signupResendAt, 0);
    assert.equal(context.signupTimer, null);

    state.queue.push({success: true, redirect: '/ProVendor/pages/landing.view.php'});
    await context.signupRequest('verify_signup', element('form-signup-verify'));
    assert.equal(state.redirects.length, 1, 'navigation is allowed only after verification succeeds');
    assert.equal(state.redirects[0], '/ProVendor/pages/landing.view.php');
    console.log('PASS: signup UI requests, CSRF, inline verification, cooldown, wrong-code feedback, recovery, cancellation and success redirect.');
})().catch(error => { console.error(error); process.exitCode = 1; });
