<?php
// pages/landing.view.php
// Presentation only — renders the store setup wizard (CSV import).
// All logic is handled by landing.logic.php.

require_once __DIR__ . '/landing.logic.php';

$pageTitle = 'ProVendor — Set Up Your Store';
$pageCss   = 'import.css';
require_once __DIR__ . '/../includes/header.php';

?>

<!-- ════════════════════════════════════════════
     TOP NAVBAR
════════════════════════════════════════════ -->
<header class="sticky top-0 z-50 border-b border-[#D2C8AE]" style="background:rgba(240,232,208,0.92);backdrop-filter:blur(10px);">
    <div class="max-w-5xl mx-auto px-6 h-16 flex items-center justify-between">

        <!-- Logo -->
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-[#261F0E] flex items-center justify-center">
                <span class="text-[#F0E8D0] text-xs font-semibold tracking-widest">PV</span>
            </div>
            <span class="text-[#261F0E] font-semibold">ProVendor</span>
        </div>

        <!-- User + Logout -->
        <div class="flex items-center gap-4">
            <span class="text-sm text-[#261F0E]" style="opacity:0.5">
                <?php echo htmlspecialchars($userName); ?>
            </span>
            <button type="button"
                onclick="showConfirm({ title: 'Log out?', message: 'You will be returned to the login page.', confirmText: 'Log out', confirmStyle: 'danger', onConfirm: function(){ pvLogout(); } })"
                class="text-sm text-[#261F0E] border border-[#D2C8AE] rounded-lg px-3 py-1.5 hover:bg-[#D2C8AE] transition-colors">
                Log out
            </button>
        </div>
    </div>
</header>

<!-- ════════════════════════════════════════════
     MAIN
════════════════════════════════════════════ -->
<main class="max-w-5xl mx-auto px-6 py-10">

    <!-- Page Heading -->
    <div class="mb-10">
        <h1 class="text-3xl font-semibold text-[#261F0E] mb-2 tracking-tight">Set up your store</h1>
        <p class="text-sm text-[#261F0E] leading-relaxed" style="opacity:0.5">
            Upload your sales history to unlock your demand forecasts.
        </p>
    </div>

    <!-- ── Step Indicator ── -->
    <div class="flex items-center mb-10">

        <!-- Step 1 -->
        <div id="ind-1" class="flex items-center gap-2.5">
            <div id="dot-1" class="w-7 h-7 rounded-full bg-[#261F0E] flex items-center justify-center">
                <span class="text-[#F0E8D0] text-xs font-semibold">1</span>
            </div>
            <span class="text-[#261F0E] text-sm font-semibold">Upload Data</span>
        </div>

        <!-- Connector 1–2 -->
        <div class="flex-1 h-px bg-[#D2C8AE] mx-4 relative overflow-hidden">
            <div id="connector-1" class="absolute inset-0 bg-[#261F0E] reveal-line"></div>
        </div>

        <!-- Step 2 -->
        <div id="ind-2" class="flex items-center gap-2.5" style="opacity:0.35">
            <div id="dot-2" class="w-7 h-7 rounded-full border-2 border-[#261F0E] flex items-center justify-center">
                <span class="text-[#261F0E] text-xs font-semibold">2</span>
            </div>
            <span class="text-[#261F0E] text-sm font-semibold">Map Columns</span>
        </div>

    </div>

    <!-- ════════════════════════════════════════════
         STEP 1 — Upload CSV
    ════════════════════════════════════════════ -->
    <div id="step-1">
        <div class="rounded-2xl border border-[#D2C8AE] overflow-hidden" style="box-shadow:0 4px 24px rgba(38,31,14,0.08)">

            <!-- Card Header -->
            <div class="px-8 py-6 border-b border-[#D2C8AE] bg-[#F0E8D0] flex items-start justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-[#261F0E] mb-0.5">Upload your sales data</h2>
                    <p class="text-sm text-[#261F0E]" style="opacity:0.5">
                        Export your transaction records as a CSV and upload below.
                    </p>
                </div>
            </div>

            <!-- Card Body -->
            <div class="px-8 py-8 bg-[#F0E8D0]">

                <!-- Drop zone -->
                <div id="drop-zone"
                    class="border-2 border-dashed border-[#D2C8AE] rounded-2xl p-14 flex flex-col items-center justify-center text-center cursor-pointer transition-all"
                    onclick="document.getElementById('csv-file').click()"
                    ondragover="handleDragOver(event)"
                    ondragleave="handleDragLeave(event)"
                    ondrop="handleDrop(event)">

                    <div class="w-14 h-14 rounded-2xl border border-[#D2C8AE] flex items-center justify-center mb-4"
                        style="background:rgba(38,31,14,0.05)">
                        <svg class="w-6 h-6 text-[#261F0E]" style="opacity:0.45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                    </div>

                    <p id="drop-text" class="text-[#261F0E] font-semibold text-sm mb-1">
                        Drop your CSV here, or click to browse
                    </p>
                    <p class="text-[#261F0E] text-xs" style="opacity:0.4">Supports .csv files only</p>

                    <input type="file" id="csv-file" accept=".csv" class="hidden" onchange="handleFileSelect(event)">
                </div>

                <!-- Column requirements -->
                <div class="mt-6 grid grid-cols-2 gap-4">

                    <!-- Required -->
                    <div class="rounded-xl border border-[#D2C8AE] p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="w-5 h-5 rounded flex items-center justify-center flex-shrink-0"
                                style="background:rgba(26,105,51,0.15); border:1px solid rgba(26,105,51,0.3)">
                                <svg class="w-2.5 h-2.5 text-[#1A6933]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                            </div>
                            <p class="text-[10px] font-semibold text-[#261F0E] uppercase tracking-wider" style="opacity:0.65">Required Columns</p>
                        </div>
                        <ul class="space-y-2">
                            <li class="text-sm text-[#261F0E] flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-[#261F0E] flex-shrink-0" style="opacity:0.4"></span>
                                Date of sale
                            </li>
                            <li class="text-sm text-[#261F0E] flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-[#261F0E] flex-shrink-0" style="opacity:0.4"></span>
                                Product name or ID
                            </li>
                            <li class="text-sm text-[#261F0E] flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-[#261F0E] flex-shrink-0" style="opacity:0.4"></span>
                                Quantity sold
                            </li>
                        </ul>
                    </div>

                    <!-- Optional -->
                    <div class="rounded-xl border border-[#D2C8AE] p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="w-5 h-5 rounded flex items-center justify-center flex-shrink-0"
                                style="background:rgba(38,31,14,0.08); border:1px solid rgba(38,31,14,0.14)">
                                <svg class="w-2.5 h-2.5 text-[#261F0E]" style="opacity:0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                                </svg>
                            </div>
                            <p class="text-[10px] font-semibold text-[#261F0E] uppercase tracking-wider" style="opacity:0.65">Optional Columns</p>
                        </div>
                        <ul class="space-y-2">
                            <li class="text-sm text-[#261F0E] flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-[#261F0E] flex-shrink-0" style="opacity:0.22"></span>
                                Category
                            </li>
                            <li class="text-sm text-[#261F0E] flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-[#261F0E] flex-shrink-0" style="opacity:0.22"></span>
                                Cost price
                            </li>
                            <li class="text-sm text-[#261F0E] flex items-center gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-[#261F0E] flex-shrink-0" style="opacity:0.22"></span>
                                Selling price
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Action -->
                <div class="flex justify-end pt-6">
                    <button id="upload-btn" onclick="detectColumns()" disabled
                        class="bg-[#261F0E] text-[#F0E8D0] rounded-xl px-6 py-2.5 text-sm font-semibold flex items-center gap-2 transition-opacity"
                        style="opacity:0.3; cursor:not-allowed">
                        Upload &amp; Detect Columns
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="9 18 15 12 9 6"/>
                        </svg>
                    </button>
                </div>

            </div>
        </div>
    </div><!-- /step-2 -->


    <!-- ════════════════════════════════════════════
         STEP 2 — Column Mapping
    ════════════════════════════════════════════ -->
    <div id="step-2" class="hidden">
        <div class="rounded-2xl border border-[#D2C8AE] overflow-hidden" style="box-shadow:0 4px 24px rgba(38,31,14,0.08)">

            <!-- Card Header -->
            <div class="px-8 py-6 border-b border-[#D2C8AE] bg-[#F0E8D0] flex items-start justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-[#261F0E] mb-0.5">Confirm column mapping</h2>
                    <p class="text-sm text-[#261F0E]" style="opacity:0.5">
                        ProVendor auto-detected your CSV columns. Adjust any mismatches before importing.
                    </p>
                </div>
                <button onclick="goToStep(1)"
                    class="text-sm text-[#261F0E] flex items-center gap-1.5 transition-opacity hover:opacity-70 mt-1"
                    style="opacity:0.45">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                    Back
                </button>
            </div>

            <!-- Card Body -->
            <div class="px-8 py-7 bg-[#F0E8D0]">

                <!-- File info bar -->
                <div class="flex items-center justify-between mb-4 pb-4 border-b border-[#D2C8AE]">
                    <div class="flex items-center gap-2">
                        <svg class="w-3.5 h-3.5 text-[#261F0E]" style="opacity:0.4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                        <span id="file-name-display" class="text-xs text-[#261F0E] font-medium" style="opacity:0.5">file.csv</span>
                    </div>
                    <span id="granularity-badge" class="inline-block text-xs rounded-full px-3 py-0.5 font-medium"
                          style="background:rgba(26,105,51,0.12); color:#1A6933; border:1px solid rgba(26,105,51,0.25)">
                        Detecting...
                    </span>
                </div>

                <!-- Required fields legend -->
                <div class="flex items-center gap-2 mb-4 flex-wrap">
                    <span class="text-[10px] font-semibold text-[#261F0E] uppercase tracking-widest" style="opacity:0.4">Required:</span>
                    <span class="text-xs px-2.5 py-0.5 rounded-full font-semibold" style="background:rgba(38,31,14,0.08); color:#261F0E; border:1px solid rgba(38,31,14,0.18)">Date</span>
                    <span class="text-xs px-2.5 py-0.5 rounded-full font-semibold" style="background:rgba(38,31,14,0.08); color:#261F0E; border:1px solid rgba(38,31,14,0.18)">Product (Primary)</span>
                    <span class="text-xs px-2.5 py-0.5 rounded-full font-semibold" style="background:rgba(38,31,14,0.08); color:#261F0E; border:1px solid rgba(38,31,14,0.18)">Quantity</span>
                    <span class="text-[10px] text-[#261F0E] ml-1" style="opacity:0.38">— unassigned columns are ignored</span>
                </div>

                <!-- Column assignment table -->
                <div class="col-table-wrap">
                    <div id="col-table-inner"></div>
                </div>

                <!-- Mapping error -->
                <div id="mapping-error" class="hidden text-sm font-semibold mb-4"
                     style="color:#b91c1c; background:rgba(185,28,28,0.07); border:1px solid rgba(185,28,28,0.2); border-radius:0.75rem; padding:0.875rem 1.25rem;"></div>

                <!-- Preflight results -->
                <div id="preflight-container"></div>

                <!-- Actions -->
                <div class="flex items-center justify-between pt-5 border-t border-[#D2C8AE]">
                    <button onclick="goToStep(1)"
                        class="text-sm text-[#261F0E] flex items-center gap-1.5 transition-opacity hover:opacity-70"
                        style="opacity:0.45">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="15 18 9 12 15 6"/>
                        </svg>
                        Back
                    </button>
                    <button id="import-btn" onclick="submitImport()"
                        class="rounded-xl px-6 py-2.5 text-sm font-semibold hover:opacity-90 transition-opacity flex items-center gap-2"
                        style="background:#1A6933; color:#F0E8D0">
                        Confirm &amp; Import Data
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="9 18 15 12 9 6"/>
                        </svg>
                    </button>
                </div>

            </div>
        </div>
    </div><!-- /step-3 -->

</main>


<!-- ════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════ -->
<script>
let currentStep = 1;

// ── Step Navigation ──────────────────────────────────────────────────────────
function goToStep(n) {
    document.getElementById('step-1').classList.add('hidden');
    document.getElementById('step-2').classList.add('hidden');
    document.getElementById('step-' + n).classList.remove('hidden');
    currentStep = n;
    updateIndicators(n);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateIndicators(active) {
    for (let i = 1; i <= 2; i++) {
        const ind  = document.getElementById('ind-' + i);
        const dot  = document.getElementById('dot-' + i);
        const label = ind.querySelector('span');

        if (i < active) {
            // Completed
            ind.style.opacity = '1';
            dot.className = 'w-7 h-7 rounded-full flex items-center justify-center';
            dot.style.background = '#1A6933';
            dot.innerHTML = '<svg class="w-3.5 h-3.5" style="color:#F0E8D0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        } else if (i === active) {
            // Active
            ind.style.opacity = '1';
            dot.className = 'w-7 h-7 rounded-full flex items-center justify-center';
            dot.style.background = '#261F0E';
            dot.style.border = 'none';
            dot.innerHTML = '<span style="color:#F0E8D0;font-size:0.75rem;font-weight:600">' + i + '</span>';
        } else {
            // Inactive
            ind.style.opacity = '0.35';
            dot.className = 'w-7 h-7 rounded-full flex items-center justify-center';
            dot.style.background = 'transparent';
            dot.style.border = '2px solid #261F0E';
            dot.innerHTML = '<span style="color:#261F0E;font-size:0.75rem;font-weight:600">' + i + '</span>';
        }
    }

    // Connector
    if (active > 1) {
        document.getElementById('connector-1').classList.add('visible');
    } else {
        document.getElementById('connector-1').classList.remove('visible');
    }
}

// ── Drag & Drop ──────────────────────────────────────────────────────────────
function handleDragOver(e) {
    e.preventDefault();
    const zone = document.getElementById('drop-zone');
    zone.style.borderColor = 'rgba(38,31,14,0.5)';
    zone.style.background  = 'rgba(210,200,174,0.3)';
}

function handleDragLeave(e) {
    const zone = document.getElementById('drop-zone');
    zone.style.borderColor = '';
    zone.style.background  = '';
}

function handleDrop(e) {
    e.preventDefault();
    handleDragLeave(e);
    const file = e.dataTransfer.files[0];
    if (file && file.name.toLowerCase().endsWith('.csv')) {
        setFileSelected(file.name);
    }
}

function handleFileSelect(e) {
    const file = e.target.files[0];
    if (file) setFileSelected(file.name);
}

function setFileSelected(name) {
    document.getElementById('drop-text').textContent = name;
    document.getElementById('file-name-display').textContent = name;

    const btn = document.getElementById('upload-btn');
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.style.cursor  = 'pointer';
}

// ── Detect columns (Step 2 → Step 3) ─────────────────────────────────────────
async function detectColumns() {
    const file = document.getElementById('csv-file').files[0];
    if (!file) return;

    const btn = document.getElementById('upload-btn');
    btn.textContent = 'Detecting…';
    btn.disabled = true;

    const formData = new FormData();
    formData.append('csv', file);

    try {
        const res  = await fetch('<?php echo BASE_URL; ?>/api/detect.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.error) {
            alert('Detection failed: ' + data.error);
            btn.textContent = 'Upload & Detect Columns';
            btn.disabled = false;
            return;
        }

        populateMappingUI(data);
        goToStep(2);

    } catch (e) {
        alert('Network error. Please try again.');
    } finally {
        btn.innerHTML = 'Upload &amp; Detect Columns <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
        btn.disabled = false;
    }
}

// ── Column mapping (Step 3) ───────────────────────────────────────────────────
var COL_FIELDS = [
    { key: 'date',        label: 'Date',               required: true  },
    { key: 'product',     label: 'Product (Primary)',   required: true  },
    { key: 'quantity',    label: 'Quantity',            required: true  },
    { key: 'sku',         label: 'Product (Secondary)', required: false },
    { key: 'category',    label: 'Category',            required: false },
    { key: 'subcategory', label: 'Sub-Category',        required: false },
    { key: 'cost',        label: 'Cost Price',          required: false },
    { key: 'price',       label: 'Selling Price',       required: false },
];

var colHeaders     = [];
var colSample      = [];
var colRowCount    = 0;
var colAssignments = {};
var colPending     = null;

var IMPORT_BTN_HTML = 'Confirm &amp; Import Data <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';

// Populates Step 3 from detect.php response — builds a live column table
function populateMappingUI(data) {
    colHeaders     = data.headers;
    colSample      = data.sample  || [];
    colRowCount    = data.row_count;
    colAssignments = {};

    COL_FIELDS.forEach(function(f) {
        var suggested = data.suggestions[f.key];
        if (suggested) {
            var idx = colHeaders.indexOf(suggested);
            if (idx !== -1) colAssignments[f.key] = idx;
        }
    });

    buildColumnTable();

    var colWord = colHeaders.length + ' column' + (colHeaders.length !== 1 ? 's' : '');
    var rowWord = colRowCount.toLocaleString() + ' row' + (colRowCount !== 1 ? 's' : '');
    document.getElementById('file-name-display').textContent = colWord + ' · ' + rowWord + ' total';
    document.getElementById('granularity-badge').textContent = colSample.length + ' sample rows shown';
}

function buildColumnTable() {
    var html = '<table class="col-table">';

    // thead — one column per CSV header
    html += '<thead><tr>';
    colHeaders.forEach(function(col, i) {
        var ignored = !isColAssigned(i);
        html += '<th data-col="' + i + '"' + (ignored ? ' class="col-ignored"' : '') + '>' + escHtml(col) + '</th>';
    });
    html += '</tr></thead>';

    // tbody — sample rows
    html += '<tbody>';
    colSample.forEach(function(row) {
        html += '<tr>';
        colHeaders.forEach(function(col, i) {
            var val     = row[col] !== undefined ? String(row[col]) : '';
            var ignored = !isColAssigned(i);
            html += '<td data-col="' + i + '"' + (ignored ? ' class="col-ignored"' : '') + '>' + escHtml(val) + '</td>';
        });
        html += '</tr>';
    });
    html += '</tbody>';

    // tfoot — assignment dropdowns
    html += '<tfoot><tr>';
    colHeaders.forEach(function(col, i) {
        var assignedField = getAssignedField(i);
        var selClass = 'col-assign-select';
        if (assignedField) {
            var fd = null;
            COL_FIELDS.forEach(function(f) { if (f.key === assignedField) fd = f; });
            selClass += fd && fd.required ? ' sel-required' : ' sel-optional';
        } else {
            selClass += ' sel-ignore';
        }

        html += '<td class="col-assign-cell">';
        html += '<select class="' + selClass + '" data-col-index="' + i + '" onchange="handleAssignment(' + i + ', this.value)">';
        html += '<option value="">— Ignore —</option>';
        COL_FIELDS.forEach(function(f) {
            var selected = (assignedField === f.key) ? ' selected' : '';
            html += '<option value="' + f.key + '"' + selected + '>' + escHtml(f.label) + (f.required ? ' *' : '') + '</option>';
        });
        html += '</select></td>';
    });
    html += '</tr></tfoot></table>';

    document.getElementById('col-table-inner').innerHTML = html;
}

function isColAssigned(colIdx) {
    var vals = Object.keys(colAssignments).map(function(k) { return colAssignments[k]; });
    return vals.indexOf(colIdx) !== -1;
}

function getAssignedField(colIdx) {
    var keys = Object.keys(colAssignments);
    for (var k = 0; k < keys.length; k++) {
        if (colAssignments[keys[k]] === colIdx) return keys[k];
    }
    return null;
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function handleAssignment(colIdx, fieldKey) {
    if (!fieldKey) {
        var prev = getAssignedField(colIdx);
        if (prev) delete colAssignments[prev];
        buildColumnTable();
        clearPreflight();
        return;
    }

    // If this field is already mapped to a different column, ask before reassigning
    if (colAssignments.hasOwnProperty(fieldKey) && colAssignments[fieldKey] !== colIdx) {
        var existingColName = colHeaders[colAssignments[fieldKey]];
        var fieldLabel = '';
        COL_FIELDS.forEach(function(f) { if (f.key === fieldKey) fieldLabel = f.label; });

        buildColumnTable();

        colPending = { colIdx: colIdx, fieldKey: fieldKey };
        showConfirm({
            title:        'Column Already Assigned',
            message:      '"' + fieldLabel + '" is already mapped to "' + escHtml(existingColName) + '". Reassign it to "' + escHtml(colHeaders[colIdx]) + '" instead?',
            confirmText:  'Reassign',
            confirmStyle: 'warning',
            onConfirm: function() {
                colAssignments[colPending.fieldKey] = colPending.colIdx;
                colPending = null;
                buildColumnTable();
            }
        });
        return;
    }

    var prevField = getAssignedField(colIdx);
    if (prevField) delete colAssignments[prevField];

    colAssignments[fieldKey] = colIdx;
    buildColumnTable();
    clearPreflight();
}

function buildMapping() {
    var mapping = {};
    Object.keys(colAssignments).forEach(function(fieldKey) {
        mapping[fieldKey] = colHeaders[colAssignments[fieldKey]];
    });
    if (!mapping.date || !mapping.product || !mapping.quantity) {
        showMappingError('Please assign the Date, Product (Primary), and Quantity columns before importing.');
        return null;
    }
    return mapping;
}

var preflightDone = false;
var mappingCache  = null;

function clearPreflight() {
    preflightDone = false;
    mappingCache  = null;
    var c = document.getElementById('preflight-container');
    if (c) c.innerHTML = '';
    var btn = document.getElementById('import-btn');
    if (btn) { btn.innerHTML = IMPORT_BTN_HTML; btn.disabled = false; btn.style.opacity = '1'; }
}

// ── Submit import (Step 3 confirm) ────────────────────────────────────────────
async function submitImport() {
    if (preflightDone) {
        var replace = !!(document.getElementById('replace-overlap') || { checked: false }).checked;
        await doImport(mappingCache, replace);
        return;
    }

    var mapping = buildMapping();
    if (!mapping) return;
    mappingCache = mapping;

    var btn = document.getElementById('import-btn');
    btn.textContent = 'Checking…';
    btn.disabled    = true;

    var formData = new FormData();
    formData.append('mapping', JSON.stringify(mapping));

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/preflight.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.error) {
            showMappingError('Check failed: ' + data.error);
            btn.innerHTML = IMPORT_BTN_HTML;
            btn.disabled  = false;
            return;
        }

        // Always show the preview so the user sees totals + breakdown before
        // anything commits — no auto-commit branch, even for a "clean" upload.
        renderPreviewCard(data);
        preflightDone = true;

        var b          = data.buckets;
        var hasAnything = b.new.count > 0 || b.overlap.count > 0;

        if (!hasAnything) {
            btn.innerHTML     = 'No changes to apply';
            btn.disabled      = true;
            btn.style.opacity = '0.55';
        } else {
            btn.innerHTML     = 'Apply changes <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
            btn.disabled      = false;
            btn.style.opacity = '1';
        }
    } catch(e) {
        showMappingError('Network error during check. Please try again.');
        btn.innerHTML = IMPORT_BTN_HTML;
        btn.disabled  = false;
    }
}

// 3-color preview card: green new / orange overlap / red invalid.
// Header shows the totals at a glance; buckets below have expandable sample tables.
function renderPreviewCard(data) {
    var b = data.buckets;
    var html = '<div class="preview-card">';
    html += '<div class="preview-card-title">Preview of changes</div>';

    html += renderSummary(data);

    html += renderBucket('new', 'Will be added',
        b.new.count, b.new.samples,
        ['Row', 'Product', 'Date', 'Qty'],
        function(r) { return [r.row, r.product, r.date, r.qty]; });

    html += renderBucket('overlap', 'Will conflict with existing data',
        b.overlap.count, b.overlap.samples,
        ['Row', 'Product', 'Date', 'Existing', 'New'],
        function(r) { return [r.row, r.product, r.date, r.qty_existing, r.qty_new]; });

    if (b.overlap.count > 0) {
        html += '<label class="preview-replace-label">';
        html += '<input type="checkbox" id="replace-overlap" class="preview-replace-check">';
        html += '<span>Replace the existing values with the new ones</span>';
        html += '</label>';
        html += '<p class="preview-replace-help">Leave unchecked to keep the existing values and skip these rows.</p>';
    }

    html += renderBucket('invalid', 'Can\'t be accepted',
        b.invalid.count, b.invalid.samples,
        ['Row', 'Product', 'Date', 'Qty', 'Reason'],
        function(r) { return [r.row, r.product, r.date, r.qty, r.reason]; });

    if (b.noop && b.noop.count > 0) {
        html += '<p class="preview-noop">';
        html += b.noop.count.toLocaleString() + ' row' + (b.noop.count !== 1 ? 's' : '') + ' already match what\'s in the database — no change needed.';
        html += '</p>';
    }

    html += '</div>';
    document.getElementById('preflight-container').innerHTML = html;
}

function renderBucket(kind, title, count, samples, columns, rowFn) {
    var icon = bucketIcon(kind);
    var html = '<div class="preview-bucket preview-bucket-' + kind + '">';
    html += '<div class="preview-bucket-head">';
    html += icon;
    html += '<span><strong>' + count.toLocaleString() + '</strong> ' + title + '</span>';
    html += '</div>';

    if (count > 0 && samples && samples.length) {
        var shown = Math.min(samples.length, count);
        html += '<details class="preview-bucket-samples">';
        html += '<summary>View examples (' + shown + ' of ' + count.toLocaleString() + ')</summary>';
        html += '<div class="preview-samples-wrap"><table class="preview-samples-table">';
        html += '<thead><tr>';
        columns.forEach(function(c) { html += '<th>' + c + '</th>'; });
        html += '</tr></thead><tbody>';
        samples.forEach(function(r) {
            html += '<tr>';
            rowFn(r).forEach(function(v) { html += '<td>' + escHtml(String(v)) + '</td>'; });
            html += '</tr>';
        });
        html += '</tbody></table></div></details>';
    }

    html += '</div>';
    return html;
}

function renderSummary(data) {
    var b   = data.buckets;
    var tot = (data.csv_rows || 0).toLocaleString();
    var rows = [
        { cls: 'preview-summary-total', label: 'Total rows in file', value: tot },
        { cls: 'preview-summary-new',   label: 'Will be added',      value: b.new.count.toLocaleString() },
    ];
    if (b.overlap.count > 0) rows.push({ cls: 'preview-summary-overlap', label: 'Conflicts with existing data', value: b.overlap.count.toLocaleString() });
    if (b.invalid.count > 0) rows.push({ cls: 'preview-summary-invalid', label: 'Can’t be accepted',       value: b.invalid.count.toLocaleString() });
    if (b.noop && b.noop.count > 0) rows.push({ cls: 'preview-summary-noop', label: 'Already match (no change)', value: b.noop.count.toLocaleString() });

    var html = '<div class="preview-summary">';
    rows.forEach(function(r) {
        html += '<div class="preview-summary-row ' + r.cls + '">';
        html += '<span class="preview-summary-label">' + r.label + '</span>';
        html += '<span class="preview-summary-value">' + r.value + '</span>';
        html += '</div>';
    });
    html += '</div>';
    return html;
}

function bucketIcon(kind) {
    if (kind === 'new')     return '<svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    if (kind === 'overlap') return '<svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    return                        '<svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
}

async function doImport(mapping, replace) {
    var btn = document.getElementById('import-btn');
    btn.textContent = 'Importing…';
    btn.disabled    = true;

    var formData = new FormData();
    formData.append('mapping',  JSON.stringify(mapping));
    formData.append('csv_rows', colRowCount);
    formData.append('replace',  replace ? '1' : '0');

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/import.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.success) {
            window.location = '<?php echo BASE_URL; ?>/pages/forecast.view.php';
        } else {
            showMappingError('Import failed: ' + (data.error || 'Unknown error.'));
            btn.innerHTML = IMPORT_BTN_HTML;
            btn.disabled  = false;
        }
    } catch(e) {
        showMappingError('Network error. Please try again.');
        btn.innerHTML = IMPORT_BTN_HTML;
        btn.disabled  = false;
    }
}

function showMappingError(msg) {
    var el = document.getElementById('mapping-error');
    el.textContent = msg;
    el.classList.remove('hidden');
    setTimeout(function() { el.classList.add('hidden'); }, 6000);
}

// ── Scroll-reveal (connector animates in naturally via CSS) ──────────────────
// Initial state is handled by CSS .reveal-line
</script>


<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
