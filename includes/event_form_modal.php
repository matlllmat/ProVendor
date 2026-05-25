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
                </select>
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

            <!-- Last-day-of-month option — only visible when recurrence=monthly. -->
            <div id="last-day-row" class="hidden">
                <label class="form-checkbox-row">
                    <input type="checkbox" id="modal-is-last-day" onchange="handleLastDayChange()">
                    Use last day of month (instead of a fixed date)
                </label>
            </div>

            <div class="form-grid-2">
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
