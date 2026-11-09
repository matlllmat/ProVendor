<?php
// includes/event_info_modal.php
// "How Events Work" reference modal — explains the event system, impact badges,
// confidence levels, recurrence, analyze, and presets. Surfaced via the (?)
// button in the Events page toolbar.
//
// Trigger from JS:
//   openInfoModal()  — show
//   closeInfoModal() — hide (also bound to Escape + overlay click)
?>

<!-- ════════════════════════════════════════════
     INFO MODAL — "How Events Work"
════════════════════════════════════════════ -->
<div id="info-modal-overlay" class="event-modal-overlay hidden" role="dialog" aria-modal="true">
    <div class="event-modal event-modal-wide">

        <div class="event-modal-header">
            <h2>How Events Work</h2>
            <button class="event-modal-close" onclick="closeInfoModal()" aria-label="Close">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="event-modal-body event-modal-body-info">

            <div class="info-section">
                <p class="info-section-title">What events do</p>
                <p class="info-section-body">
                    Events mark important recurring dates on your sales chart and are automatically
                    included as <strong>regressors</strong> in the forecast model. This means the
                    model learns each event's specific sales effect from your history and projects
                    it into future forecasts. Adding an event improves accuracy for products that
                    are affected by it; removing an event excludes it from the next run.
                </p>
            </div>

            <div class="info-section">
                <p class="info-section-title">Model Impact</p>
                <p class="info-section-body">
                    The % effect an event has on daily sales, derived from the
                    <strong>forecast model</strong> (Prophet regressor analysis). The model isolates
                    the event's contribution from the trend and the weekly and yearly patterns — making
                    it more reliable than a simple before/after comparison. It appears on an event's
                    page after you run your first forecast that includes the event. Click any event
                    row to see the full product-by-product breakdown.
                </p>
            </div>

            <div class="info-section">
                <p class="info-section-title">Confidence column &nbsp; Strong &middot; Moderate &middot; Weak</p>
                <p class="info-section-body">
                    This shows <strong>how many times the event has happened in your sales history</strong>.
                    The forecast learns an event's effect from those past times, so more times means
                    it can trust what it learned. It does <strong>not</strong> show how big the effect is:
                    a Strong event can have a small effect, and a Weak one a large effect.
                </p>
                <div class="info-conf-table">
                    <div class="info-conf-row">
                        <span class="event-conf-badge conf-strong">Strong</span>
                        <span>Plenty of past examples, so the learned effect is reliable.<br>
                            Every year: 4+ times &middot; Every month: 12+ &middot; Specific dates: 6+</span>
                    </div>
                    <div class="info-conf-row">
                        <span class="event-conf-badge conf-moderate">Moderate</span>
                        <span>A few examples. The effect is a fair estimate.<br>
                            Every year: 2&ndash;3 times &middot; Every month: 6&ndash;11 &middot; Specific dates: 3&ndash;5</span>
                    </div>
                    <div class="info-conf-row">
                        <span class="event-conf-badge conf-weak">Weak</span>
                        <span>Very few examples, so the effect could be a coincidence.<br>
                            Every year: 1 time &middot; Every month: 1&ndash;5 &middot; Specific dates: 2</span>
                    </div>
                    <div class="info-conf-row">
                        <span class="event-conf-badge conf-unused">Not used yet</span>
                        <span>The forecast doesn't use this event for now.<br>
                            Specific dates need at least 2 dates inside your sales history.</span>
                    </div>
                </div>
                <p class="info-section-body" style="margin-top:0.5rem">
                    <strong>How it is counted:</strong> each time the event falls on days that have sales
                    counts once, and a date range counts as one time. Hover a badge to see the exact number. No badge means the event hasn't
                    happened in your sales history yet.
                </p>
            </div>

            <div class="info-section">
                <p class="info-section-title">Recurrence</p>
                <p class="info-section-body">
                    <strong>Every year</strong> repeats on the same date each year (e.g. Dec 25).
                    <strong>Every month</strong> repeats on a fixed day each month — either a specific
                    date (e.g. the 15th) or the last day of the month.
                </p>
            </div>

            <div class="info-section">
                <p class="info-section-title">Analyze</p>
                <p class="info-section-body">
                    Scans your sales history for recurring spikes not yet covered by an existing event —
                    both yearly (dates that consistently spike year-over-year) and monthly (days that
                    consistently spike month-over-month). Each suggestion shows a confidence level and
                    estimated impact. Nothing is added automatically — you review and name each one.
                </p>
            </div>

            <div class="info-section">
                <p class="info-section-title">Preset events</p>
                <p class="info-section-body">
                    Built-in events for common Filipino convenience store patterns (paydays, Christmas,
                    New Year, etc.). You can hide any preset you don't need — click the eye-off icon
                    on its row. Hidden presets won't appear on your charts or affect your forecast.
                    To restore them, click <strong>Show hidden presets</strong> at the bottom of the
                    events list.
                </p>
            </div>

        </div>

        <div class="event-modal-footer">
            <button class="btn-save" onclick="closeInfoModal()">Got it</button>
        </div>

    </div>
</div>
