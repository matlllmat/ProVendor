<?php
// includes/event_form_modal.php
// Create / Edit Event modal — shared by the Events page (and any future page
// that needs the same form). Trigger from JS:
//   openCreateModal()       — clears the form, shows the modal
//   openEditModal(eventObj) — prefills the form for editing
//   closeModal()            — hides it (also bound to Escape + overlay click)
//
// The actual save call lives in assets/page_js/events.js (saveEvent()), which
// POSTs to api/events.php. Keeping JS out of this file means the same modal
// can be included on other pages without duplicating logic.

$swatches = ['#FF5722','#EF4444','#F59E0B','#EAB308','#22C55E','#3B82F6','#8B5CF6','#EC4899'];
?>

<!-- ════════════════════════════════════════════
     CREATE / EDIT EVENT MODAL
════════════════════════════════════════════ -->
<div id="event-modal-overlay" class="event-modal-overlay hidden" role="dialog" aria-modal="true">
    <div class="event-modal">

        <div class="event-modal-header">
            <h2 id="modal-title">Add Event</h2>
            <button class="event-modal-close" onclick="closeModal()" aria-label="Close">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="event-modal-body">

            <input type="hidden" id="modal-event-id" value="">

            <div class="form-field">
                <label class="form-label" for="modal-name">Event Name *</label>
                <input type="text" id="modal-name" class="form-input"
                       placeholder="e.g. Summer Sale, Fiesta Day">
            </div>

            <div class="form-field">
                <label class="form-label" for="modal-recurrence">Recurrence</label>
                <select id="modal-recurrence" class="form-select" onchange="handleRecurrenceChange()">
                    <option value="yearly">Every year</option>
                    <option value="monthly">Every month</option>
                    <option value="custom">Specific dates (irregular)</option>
                    <option value="none">One-time (does not repeat)</option>
                </select>
            </div>

            <!-- ── "When" controls. Exactly one block is visible at a time,
                 chosen by handleRecurrenceChange() in assets/page_js/events.js.
                 Each block collects only the parts that recurrence type
                 actually stores, so nothing the owner types is discarded. ── -->

            <!-- Every year: month + day. The year is not stored. -->
            <div class="form-field" id="when-yearly">
                <label class="form-label">When does it happen?</label>
                <div class="form-grid-2">
                    <select id="modal-year-month" class="form-select" aria-label="Month">
                        <?php
                        $months = ['January','February','March','April','May','June',
                                   'July','August','September','October','November','December'];
                        foreach ($months as $i => $m): ?>
                        <option value="<?php echo $i + 1; ?>"><?php echo $m; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="modal-year-day" class="form-select" aria-label="Day"
                            onchange="handleYearlyDayChange()">
                        <?php for ($d = 1; $d <= 31; $d++): ?>
                        <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <p class="form-hint form-hint-warn hidden" id="yearly-date-hint"></p>
            </div>

            <!-- Every month: a fixed day number, or the month's real last day. -->
            <div class="form-field" id="when-monthly">
                <label class="form-label">When does it happen?</label>

                <label class="form-radio-row">
                    <input type="radio" name="month-mode" id="month-mode-day" value="day"
                           onchange="handleMonthModeChange()">
                    <span>Day of month</span>
                    <select id="modal-month-day" class="form-select form-select-inline"
                            aria-label="Day of month" onchange="handleMonthDayChange()">
                        <?php for ($d = 1; $d <= 31; $d++): ?>
                        <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                        <?php endfor; ?>
                    </select>
                </label>

                <label class="form-radio-row">
                    <input type="radio" name="month-mode" id="month-mode-last" value="last"
                           onchange="handleMonthModeChange()">
                    <span>Last day of month</span>
                    <span class="info-tip" tabindex="0" role="note"
                          aria-label="Why choose last day of month">i<span class="info-tip-bubble">Month-end is not a fixed number &mdash; it is the 31st, 30th, 29th or 28th depending on the month. Choose this and every month uses its own real last day. Picking a fixed 31 instead would skip February, April, June, September and November completely.</span></span>
                </label>

                <p class="form-hint form-hint-warn hidden" id="month-day-hint"></p>
            </div>

            <!-- Specific dates: an explicit list, for happenings with no calendar
                 rule (storms, movable holidays). Rows are built by events.js. -->
            <div class="form-field hidden" id="when-custom">
                <label class="form-label">Which dates?
                    <span class="info-tip" tabindex="0" role="note"
                          aria-label="How specific dates are used">i<span class="info-tip-bubble">Use this for things that repeat but follow no calendar rule &mdash; storms, or holidays that move each year like Holy Week or Chinese New Year. List every date it happened. The system learns one combined effect from all of them, so more dates means a more reliable figure. Group only dates of similar severity.</span></span>
                </label>

                <div id="custom-date-list" class="custom-date-list"></div>

                <button type="button" class="custom-date-add" onclick="addCustomDateRow()">
                    + Add another date
                </button>

                <p class="form-hint" id="custom-date-hint"></p>
            </div>

            <!-- One-time: real calendar dates, stored exactly as typed. -->
            <div class="form-grid-2 hidden" id="when-once">
                <div class="form-field">
                    <label class="form-label" for="modal-start">Start Date *</label>
                    <input type="date" id="modal-start" class="form-input">
                </div>
                <div class="form-field">
                    <label class="form-label" for="modal-end">End Date
                        <span class="form-label-optional">(optional)</span>
                    </label>
                    <input type="date" id="modal-end" class="form-input">
                </div>
            </div>

            <!-- Duration for recurring events. Stored as event_end = start + (n-1) days. -->
            <div class="form-field" id="duration-row">
                <label class="form-label" for="modal-duration">How long does it last?</label>
                <div class="form-duration-row">
                    <input type="number" id="modal-duration" class="form-input form-input-narrow"
                           min="1" max="365" step="1" value="1">
                    <span class="form-duration-unit">day(s)</span>
                </div>
                <p class="form-hint">Leave as 1 for a single-day event.</p>
            </div>

            <!-- Color picker -->
            <div class="form-field">
                <label class="form-label">Color</label>
                <div class="color-picker-row" id="color-picker-row">
                    <?php foreach ($swatches as $sw): ?>
                    <button type="button" class="color-swatch"
                            data-color="<?php echo $sw; ?>"
                            style="background:<?php echo $sw; ?>"
                            onclick="selectColor('<?php echo $sw; ?>')"
                            title="<?php echo $sw; ?>"></button>
                    <?php endforeach; ?>
                    <label class="color-swatch color-swatch-custom" title="Custom color">
                        <input type="color" id="modal-color-custom"
                               oninput="selectColor(this.value)">
                    </label>
                </div>
                <input type="hidden" id="modal-color" value="#FF5722">
            </div>

            <div class="form-field">
                <label class="form-label" for="modal-note">Notes
                    <span class="form-label-optional">(optional)</span>
                </label>
                <textarea id="modal-note" class="form-textarea"
                          placeholder="Describe how this event typically affects sales…"></textarea>
            </div>

        </div>

        <div class="event-modal-footer">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-save" onclick="saveEvent()">Save Event</button>
        </div>

    </div>
</div>
