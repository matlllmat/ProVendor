<?php
// includes/forecast_progress.php
// Floating progress indicator for the background catalogue forecast.
// Included by navbar.php, so it rides along on every signed-in page — the owner
// can browse the whole app while the worker runs and still see how far along it is.
//
// Polls api/forecast_job_status.php; stops polling as soon as there's nothing
// running, so an idle session makes exactly one request.
?>
<div id="fj-pill" class="fj-pill" hidden>
    <div class="fj-pill-main">
        <svg class="fj-spinner" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
        </svg>
        <svg class="fj-check" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" hidden>
            <polyline points="20 6 9 17 4 12"/>
        </svg>
        <div class="fj-pill-text">
            <span id="fj-title" class="fj-title">Forecasting your products…</span>
            <span id="fj-sub" class="fj-sub">Starting…</span>
        </div>
        <button type="button" id="fj-close" class="fj-close" aria-label="Dismiss">&times;</button>
    </div>
    <div class="fj-track"><div id="fj-fill" class="fj-fill"></div></div>
</div>

<script>
(function () {
    var pill  = document.getElementById('fj-pill');
    if (!pill) return;
    var fill  = document.getElementById('fj-fill');
    var title = document.getElementById('fj-title');
    var sub   = document.getElementById('fj-sub');
    var spin  = pill.querySelector('.fj-spinner');
    var check = pill.querySelector('.fj-check');
    var BASE  = <?php echo json_encode(BASE_URL); ?>;

    var timer = null;
    var dismissedId = null;      // job the user closed — don't re-show it
    try { dismissedId = sessionStorage.getItem('pv_fj_dismissed'); } catch (e) {}

    document.getElementById('fj-close').addEventListener('click', function () {
        pill.hidden = true;
        if (pill.dataset.jobId) {
            dismissedId = pill.dataset.jobId;
            try { sessionStorage.setItem('pv_fj_dismissed', dismissedId); } catch (e) {}
        }
        stop();
    });

    function stop() { if (timer) { clearInterval(timer); timer = null; } }

    function render(job) {
        if (!job || String(job.id) === String(dismissedId)) { pill.hidden = true; stop(); return; }

        pill.dataset.jobId = job.id;
        var running = (job.status === 'queued' || job.status === 'running');

        pill.hidden = false;
        pill.classList.toggle('fj-done',   job.status === 'done');
        pill.classList.toggle('fj-failed', job.status === 'failed');
        spin.hidden  = !running;
        check.hidden = (job.status !== 'done');
        fill.style.width = job.percent + '%';

        if (running) {
            title.textContent = 'Forecasting your products…';
            sub.textContent = job.total
                ? job.done + ' of ' + job.total + ' done'
                    + (job.current_product ? ' · ' + job.current_product : '')
                : 'Starting…';
        } else if (job.status === 'done') {
            title.textContent = 'Forecasts ready';
            var ok = job.total - job.failed;
            sub.textContent = ok + ' of ' + job.total + ' products forecast'
                + (job.failed ? ' · ' + job.failed + ' could not be forecast' : '');
            stop();
            // Let a finished run linger briefly, then tidy itself away.
            setTimeout(function () { pill.hidden = true; }, 9000);
        } else if (job.status === 'failed') {
            title.textContent = 'Forecast run stopped';
            sub.textContent   = job.error || 'Something interrupted the run.';
            stop();
        }
    }

    function poll() {
        fetch(BASE + '/api/forecast_job_status.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { render(d.job); })
            .catch(function () { /* transient — the next tick retries */ });
    }

    function start() {
        poll();
        if (!timer) timer = setInterval(poll, 2500);
    }

    // Other pages (onboarding, Settings) call this right after kicking off a run
    // so the pill appears immediately instead of on the next poll.
    window.pvWatchForecastJob = function () {
        dismissedId = null;
        try { sessionStorage.removeItem('pv_fj_dismissed'); } catch (e) {}
        start();
    };

    start();
}());
</script>
