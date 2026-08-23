<?php
// pages/import.view.php
// Import Data + Profile page — two tabs in one place.

require_once __DIR__ . '/import.logic.php';

$pageTitle = 'ProVendor — Settings';
$pageCss   = 'import.css';
$extraCss  = 'settings.css';   // Forecast Range tab styles (merged from the old Settings page)
require_once __DIR__ . '/../includes/header.php';
?>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- ════════════════════════════════════════════
     MAIN
════════════════════════════════════════════ -->
<main class="max-w-5xl mx-auto px-6 py-8">

    <!-- Page heading -->
    <div class="mb-1">
        <h1 class="text-2xl font-semibold text-[#261F0E] tracking-tight">Settings</h1>
        <p class="text-sm text-[#261F0E] mt-1" style="opacity:0.5">
            Manage your sales data, account, and forecasting.
        </p>
    </div>

    <!-- Tab bar -->
    <div class="tab-bar">
        <button id="tab-btn-import"   class="tab-btn"        onclick="switchTab('import')">Sales Data</button>
        <button id="tab-btn-profile"  class="tab-btn active" onclick="switchTab('profile')">My Profile</button>
        <button id="tab-btn-forecast" class="tab-btn"        onclick="switchTab('forecast')">Forecast Range</button>
    </div>


    <!-- ════════════════════════════════════════════
         TAB: IMPORT DATA
    ════════════════════════════════════════════ -->
    <div id="tab-content-import" class="hidden">

        <!-- Import success notice -->
        <?php if (isset($_GET['imported'])): ?>
        <?php
            $rows     = (int) ($_GET['rows']     ?? 0);
            $replaced = (int) ($_GET['replaced'] ?? 0);
            $skipped  = (int) ($_GET['skipped']  ?? 0);
            $dropped  = (int) ($_GET['dropped']  ?? 0);
            $csvRows  = (int) ($_GET['csv_rows'] ?? 0);
        ?>
        <div class="import-success" style="flex-direction:column; align-items:flex-start; gap:0.5rem;">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                <span>
                    <?php
                        $parts = [];
                        if ($rows > 0)     $parts[] = number_format($rows)     . ' new record' . ($rows !== 1 ? 's' : '') . ' imported';
                        if ($replaced > 0) $parts[] = number_format($replaced) . ' record' . ($replaced !== 1 ? 's' : '') . ' updated';
                        if ($skipped > 0)  $parts[] = number_format($skipped)  . ' row' . ($skipped !== 1 ? 's' : '') . ' skipped (already in database)';
                        if ($dropped > 0)  $parts[] = number_format($dropped)  . ' row' . ($dropped !== 1 ? 's' : '') . ' dropped (invalid data)';
                        echo implode(', ', $parts) . '.';
                    ?>
                </span>
            </div>
            <?php if ($csvRows > 0 && $csvRows !== ($rows + $replaced + $skipped + $dropped)): ?>
            <p style="font-size:0.78rem; font-weight:400; opacity:0.75; margin:0;">
                <?php echo number_format($csvRows); ?> CSV rows were processed into <?php echo number_format($rows + $replaced); ?> daily records.
                Transactions for the same product on the same date are aggregated into a single daily total, as required by the forecasting model.
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Restore success notice -->
        <?php if (isset($_GET['restored'])): ?>
        <div class="import-success">
            <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 4 3 10 9 10"/>
            </svg>
            <span>Version restored. The previous state was auto-saved at the top of your history if you need to undo.</span>
        </div>
        <?php endif; ?>

        <!-- ── Prophet Pipeline Explainer ──────────────────────────────────
             Shows the academic panel exactly how the system uses the rows
             below: data → Prophet decomposition → forecast.
        ─────────────────────────────────────────────────────────────────── -->
        <div class="pipeline-explainer">

            <div class="pipeline-header">
                <p class="pipeline-eyebrow">Forecasting Pipeline</p>
                <h2 class="pipeline-title">How ProVendor learns from your data</h2>
                <p class="pipeline-sub">
                    There is no separate &ldquo;training&rdquo; step. Every forecast refits
                    <a href="https://facebook.github.io/prophet/" target="_blank" rel="noopener" class="pipeline-link">Meta&rsquo;s Prophet model</a>
                    on the sales history below &mdash; per product, per request.
                </p>
            </div>

            <div class="pipeline-flow">

                <!-- Step 1: Your data ─────────────────────────────────── -->
                <div class="pipeline-step">
                    <div class="pipeline-step-head">
                        <span class="pipeline-step-num">01</span>
                        <div class="pipeline-step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <ellipse cx="12" cy="5" rx="9" ry="3"/>
                                <path d="M3 5v6c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                                <path d="M3 11v6c0 1.66 4 3 9 3s9-1.34 9-3v-6"/>
                            </svg>
                        </div>
                    </div>
                    <h3 class="pipeline-step-title">Your Sales History</h3>
                    <p class="pipeline-step-desc">The raw daily rows below are what Prophet trains on.</p>
                    <ul class="pipeline-step-list">
                        <li><strong><?php echo number_format($summary['total_sales']); ?></strong> daily records</li>
                        <li><strong><?php echo number_format($summary['total_products']); ?></strong> products</li>
                        <?php if ($summary['date_from'] && $summary['date_to']): ?>
                        <li>
                            <strong><?php echo date('M Y', strtotime($summary['date_from'])); ?></strong>
                            <span class="pipeline-arrow-inline">&rarr;</span>
                            <strong><?php echo date('M Y', strtotime($summary['date_to'])); ?></strong>
                        </li>
                        <?php else: ?>
                        <li class="pipeline-step-muted">Upload a CSV to start</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="pipeline-arrow" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                        <polyline points="13 6 19 12 13 18"/>
                    </svg>
                </div>

                <!-- Step 2: Prophet decomposes ────────────────────────── -->
                <div class="pipeline-step">
                    <div class="pipeline-step-head">
                        <span class="pipeline-step-num">02</span>
                        <div class="pipeline-step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 8c2-2.5 4-2.5 6 0s4 2.5 6 0 4-2.5 6 0"/>
                                <path d="M3 14c2-1.5 4-1.5 6 0s4 1.5 6 0 4-1.5 6 0"/>
                                <line x1="3" y1="20" x2="21" y2="20"/>
                            </svg>
                        </div>
                    </div>
                    <h3 class="pipeline-step-title">Prophet Decomposes</h3>
                    <p class="pipeline-step-desc">The model splits demand into independent signals.</p>
                    <ul class="pipeline-step-list">
                        <li>Long-term <strong>trend</strong></li>
                        <li>Weekly cycle <span class="pipeline-step-muted">(Mon&ndash;Sun)</span></li>
                        <li>Yearly <strong>seasonality</strong></li>
                        <li><strong><?php echo (int) $summary['total_events']; ?></strong> custom event <?php echo $summary['total_events'] == 1 ? 'regressor' : 'regressors'; ?></li>
                    </ul>
                </div>

                <div class="pipeline-arrow" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                        <polyline points="13 6 19 12 13 18"/>
                    </svg>
                </div>

                <!-- Step 3: Forecast output ────────────────────────────── -->
                <div class="pipeline-step">
                    <div class="pipeline-step-head">
                        <span class="pipeline-step-num">03</span>
                        <div class="pipeline-step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 16 8 11 12 14 21 6"/>
                                <polyline points="15 6 21 6 21 12"/>
                                <path d="M3 20h18" opacity="0.4"/>
                            </svg>
                        </div>
                    </div>
                    <h3 class="pipeline-step-title">Forecast &amp; Uncertainty</h3>
                    <p class="pipeline-step-desc">Prophet sums the signals to project future demand.</p>
                    <ul class="pipeline-step-list">
                        <li>Daily <strong>predicted units</strong></li>
                        <li>+ <strong>95%</strong> confidence band</li>
                        <li>Feeds the <strong>Newsvendor</strong> restock model</li>
                    </ul>
                </div>

            </div>
        </div>

        <!-- ── Summary Cards ── -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-card-value"><?php echo number_format($summary['total_products']); ?></div>
                <div class="summary-card-label">Products</div>
            </div>
            <div class="summary-card">
                <div class="summary-card-value"><?php echo number_format($summary['total_sales']); ?></div>
                <div class="summary-card-label">Sales Records</div>
            </div>
            <div class="summary-card">
                <div class="summary-card-value"><?php echo number_format($summary['total_versions']); ?></div>
                <div class="summary-card-label">Saved Versions</div>
            </div>
        </div>

        <!-- ── Upload Wizard (hidden until triggered) ── -->
        <div id="wizard-panel" class="wizard-panel hidden">

            <div class="wizard-header">
                <div class="wizard-header-text">
                    <h2>Upload Sales Data</h2>
                    <p>Upload a CSV and map its columns to proceed.</p>
                </div>
                <button class="wizard-close-btn" onclick="closeWizard()" title="Cancel">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </div>

            <div class="wizard-body">

                <!-- Step indicator -->
                <div class="wizard-steps">
                    <div id="wdot-1" class="wizard-step-dot">
                        <span class="num" style="background:#261F0E; color:#F0E8D0">1</span>
                        <span class="lbl">Upload CSV</span>
                    </div>
                    <div class="wizard-connector"></div>
                    <div id="wdot-2" class="wizard-step-dot" style="opacity:0.35">
                        <span class="num" style="border:2px solid #261F0E; color:#261F0E">2</span>
                        <span class="lbl">Map Columns</span>
                    </div>
                </div>

                <!-- ── Wizard Step 1: Upload ── -->
                <div id="wstep-1">

                    <div id="w-drop-zone" class="drop-zone"
                         onclick="document.getElementById('w-csv-file').click()"
                         ondragover="wHandleDragOver(event)"
                         ondragleave="wHandleDragLeave(event)"
                         ondrop="wHandleDrop(event)">

                        <div class="w-12 h-12 rounded-xl border border-[#D2C8AE] flex items-center justify-center mb-3"
                             style="background:rgba(38,31,14,0.05)">
                            <svg class="w-5 h-5 text-[#261F0E]" style="opacity:0.45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/>
                                <line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                        </div>

                        <p id="w-drop-text" class="text-[#261F0E] font-semibold text-sm mb-1">
                            Drop your CSV here, or click to browse
                        </p>
                        <p class="text-[#261F0E] text-xs" style="opacity:0.4">Supports .csv files only · Max 10 MB</p>
                        <button type="button" onclick="downloadSampleCsv(event)"
                                class="mt-3 text-xs text-[#261F0E] underline underline-offset-2 transition-opacity hover:opacity-60"
                                style="opacity:0.38; background:none; border:none; cursor:pointer;">
                            Download sample CSV
                        </button>

                        <input type="file" id="w-csv-file" accept=".csv" class="hidden" onchange="wHandleFileSelect(event)">
                    </div>

                    <!-- Column requirements -->
                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <div class="rounded-xl border border-[#D2C8AE] p-4">
                            <div class="flex items-center gap-2 mb-2.5">
                                <div class="w-4 h-4 rounded flex items-center justify-center flex-shrink-0"
                                     style="background:rgba(26,105,51,0.15); border:1px solid rgba(26,105,51,0.3)">
                                    <svg class="w-2 h-2 text-[#1A6933]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                </div>
                                <p class="text-[10px] font-semibold text-[#261F0E] uppercase tracking-wider" style="opacity:0.65">Required</p>
                            </div>
                            <ul class="space-y-1.5">
                                <li class="text-xs text-[#261F0E] flex items-center gap-1.5"><span class="w-1 h-1 rounded-full bg-[#261F0E] flex-shrink-0" style="opacity:0.4"></span>Date of sale</li>
                                <li class="text-xs text-[#261F0E] flex items-center gap-1.5"><span class="w-1 h-1 rounded-full bg-[#261F0E] flex-shrink-0" style="opacity:0.4"></span>Product name or ID</li>
                                <li class="text-xs text-[#261F0E] flex items-center gap-1.5"><span class="w-1 h-1 rounded-full bg-[#261F0E] flex-shrink-0" style="opacity:0.4"></span>Quantity sold</li>
                            </ul>
                        </div>
                        <div class="rounded-xl border border-[#D2C8AE] p-4">
                            <div class="flex items-center gap-2 mb-2.5">
                                <div class="w-4 h-4 rounded flex items-center justify-center flex-shrink-0"
                                     style="background:rgba(38,31,14,0.08); border:1px solid rgba(38,31,14,0.14)">
                                    <svg class="w-2 h-2 text-[#261F0E]" style="opacity:0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                                    </svg>
                                </div>
                                <p class="text-[10px] font-semibold text-[#261F0E] uppercase tracking-wider" style="opacity:0.65">Optional</p>
                            </div>
                            <ul class="space-y-1.5">
                                <li class="text-xs text-[#261F0E] flex items-center gap-1.5"><span class="w-1 h-1 rounded-full bg-[#261F0E] flex-shrink-0" style="opacity:0.22"></span>Category</li>
                                <li class="text-xs text-[#261F0E] flex items-center gap-1.5"><span class="w-1 h-1 rounded-full bg-[#261F0E] flex-shrink-0" style="opacity:0.22"></span>Cost &amp; selling price</li>
                                <li class="text-xs text-[#261F0E] flex items-center gap-1.5"><span class="w-1 h-1 rounded-full bg-[#261F0E] flex-shrink-0" style="opacity:0.22"></span>SKU / Sub-category</li>
                            </ul>
                        </div>
                    </div>

                    <div class="flex justify-end mt-5">
                        <button id="w-upload-btn" onclick="wDetectColumns()" disabled
                                class="bg-[#261F0E] text-[#F0E8D0] rounded-xl px-5 py-2.5 text-sm font-semibold flex items-center gap-2 transition-opacity"
                                style="opacity:0.3; cursor:not-allowed">
                            Upload &amp; Detect Columns
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </button>
                    </div>

                </div><!-- /wstep-1 -->

                <!-- ── Wizard Step 2: Assign Columns ── -->
                <div id="wstep-2" class="hidden">

                    <div class="flex items-center justify-between mb-4 pb-4 border-b border-[#D2C8AE]">
                        <div class="flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 text-[#261F0E]" style="opacity:0.4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                            </svg>
                            <span id="w-file-name" class="text-xs text-[#261F0E] font-medium" style="opacity:0.5">file.csv</span>
                        </div>
                        <span id="w-granularity-badge" class="inline-block text-xs rounded-full px-3 py-0.5 font-medium"
                              style="background:rgba(26,105,51,0.12); color:#1A6933; border:1px solid rgba(26,105,51,0.25)">
                            Detecting...
                        </span>
                    </div>

                    <div class="flex items-center gap-2 mb-4 flex-wrap">
                        <span class="text-[10px] font-semibold text-[#261F0E] uppercase tracking-widest" style="opacity:0.4">Required:</span>
                        <span class="text-xs px-2.5 py-0.5 rounded-full font-semibold" style="background:rgba(38,31,14,0.08); color:#261F0E; border:1px solid rgba(38,31,14,0.18)">Date</span>
                        <span class="text-xs px-2.5 py-0.5 rounded-full font-semibold" style="background:rgba(38,31,14,0.08); color:#261F0E; border:1px solid rgba(38,31,14,0.18)">Product (Primary)</span>
                        <span class="text-xs px-2.5 py-0.5 rounded-full font-semibold" style="background:rgba(38,31,14,0.08); color:#261F0E; border:1px solid rgba(38,31,14,0.18)">Quantity</span>
                        <span class="text-[10px] text-[#261F0E] ml-1" style="opacity:0.38">— unassigned columns are ignored</span>
                    </div>
                    
                    <div class="mb-5 flex items-center gap-3 p-3.5 rounded-xl border border-[#D2C8AE]" style="background:rgba(38,31,14,0.03);">
                        <label class="text-[11px] font-bold text-[#261F0E] uppercase tracking-wider" style="opacity:0.8" for="w-date-format-select">Date Format:</label>
                        <select id="w-date-format-select" class="text-sm font-semibold border-2 border-[#D2C8AE] rounded-lg px-3 py-1.5 bg-white focus:border-[#261F0E] hover:border-[#261F0E] outline-none transition-colors cursor-pointer shadow-sm" style="color:#261F0E;" onchange="wClearPreflight(); wSaveState();">
                            <option value="auto">Auto-detect</option>
                            <option value="Y-m-d">YYYY-MM-DD (e.g. 2024-01-31)</option>
                            <option value="d/m/Y">DD/MM/YYYY (e.g. 31/01/2024)</option>
                            <option value="m/d/Y">MM/DD/YYYY (e.g. 01/31/2024)</option>
                            <option value="d-m-Y">DD-MM-YYYY (e.g. 31-01-2024)</option>
                            <option value="m-d-Y">MM-DD-YYYY (e.g. 01-31-2024)</option>
                            <option value="Y/m/d">YYYY/MM/DD (e.g. 2024/01/31)</option>
                            <option value="j/n/y">D/M/YY (e.g. 31/1/24)</option>
                            <option value="n/j/y">M/D/YY (e.g. 1/31/24)</option>
                        </select>
                    </div>

                    <div class="col-table-wrap">
                        <div id="w-col-table-inner"></div>
                    </div>

                    <div id="w-mapping-error" class="hidden text-sm font-semibold mb-4"
                         style="color:#b91c1c; background:rgba(185,28,28,0.07); border:1px solid rgba(185,28,28,0.2); border-radius:0.75rem; padding:0.875rem 1.25rem;"></div>

                    <div id="w-preflight-container"></div>

                    <div class="flex items-center justify-between pt-4 border-t border-[#D2C8AE]">
                        <button onclick="wGoToStep(1)"
                                class="text-sm text-[#261F0E] flex items-center gap-1.5 transition-opacity hover:opacity-70"
                                style="opacity:0.45">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="15 18 9 12 15 6"/>
                            </svg>
                            Back
                        </button>
                        <button id="w-import-btn" onclick="wSubmitImport()"
                                class="rounded-xl px-5 py-2.5 text-sm font-semibold hover:opacity-90 transition-opacity flex items-center gap-2"
                                style="background:#1A6933; color:#F0E8D0">
                            Confirm &amp; Import
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </button>
                    </div>

                </div><!-- /wstep-2 -->

            </div><!-- /wizard-body -->
        </div><!-- /wizard-panel -->

        <!-- ── Version history ── -->
        <div class="section-header">
            <span class="section-title">Version History</span>
            <div class="section-header-actions">
                <?php if ((int) $summary['total_sales'] > 0): ?>
                <a href="<?php echo BASE_URL; ?>/api/export_data.php" class="export-data-btn"
                   title="Download all your current sales data as a CSV file">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Export CSV
                </a>
                <?php endif; ?>
                <button onclick="openWizard()" class="upload-btn-primary">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Update Sales Data
                </button>
            </div>
        </div>

        <p class="versions-help">
            Each import saves a snapshot. Restore any version to roll your sales data back —
            the current state is auto-saved first so the restore itself is reversible.
            Only the most recent <?php echo MAX_VERSIONS_PER_USER; ?> versions are kept.
        </p>

        <div class="versions-list" id="versions-list">
            <?php if (empty($versions)): ?>
            <div class="versions-empty">No saved versions yet. Upload your first CSV to get started.</div>
            <?php else: ?>
                <?php foreach ($versions as $v): ?>
                <div class="version-entry<?php echo $v['is_pre_restore_snapshot'] ? ' version-pre-restore' : ''; ?>"
                     id="version-<?php echo $v['id']; ?>">

                    <div class="version-row">

                        <div class="version-icon">
                            <?php if ($v['is_pre_restore_snapshot']): ?>
                            <!-- Counter-clockwise arrow: pre-restore safety snapshot -->
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 4 3 10 9 10"/>
                            </svg>
                            <?php else: ?>
                            <!-- Document: regular import snapshot -->
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                            </svg>
                            <?php endif; ?>
                        </div>

                        <span class="version-label" title="<?php echo htmlspecialchars($v['label']); ?>">
                            <?php echo htmlspecialchars($v['label']); ?>
                        </span>

                        <div class="version-meta">
                            <?php if ($v['rows_added'] > 0): ?>
                            <span class="version-badge version-badge-added">+<?php echo number_format($v['rows_added']); ?> added</span>
                            <?php endif; ?>
                            <?php if ($v['rows_changed'] > 0): ?>
                            <span class="version-badge version-badge-changed"><?php echo number_format($v['rows_changed']); ?> changed</span>
                            <?php endif; ?>
                            <span class="version-rows"><?php echo number_format($v['total_rows']); ?> records</span>
                            <span class="version-date"><?php echo date('M j, Y · g:i A', strtotime($v['created_at'])); ?></span>
                        </div>

                        <a class="version-download-btn"
                           href="<?php echo BASE_URL; ?>/api/export_version.php?version_id=<?php echo $v['id']; ?>"
                           title="Download this version's data as CSV">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                            </svg>
                        </a>

                        <button class="version-restore-btn"
                                onclick="confirmRestoreVersion(<?php echo $v['id']; ?>, <?php echo htmlspecialchars(json_encode($v['label']), ENT_QUOTES); ?>)"
                                title="Restore this version">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 4 3 10 9 10"/>
                            </svg>
                        </button>

                        <button class="version-delete-btn"
                                onclick="confirmDeleteVersion(<?php echo $v['id']; ?>, <?php echo htmlspecialchars(json_encode($v['label']), ENT_QUOTES); ?>)"
                                title="Delete this version (current data is unaffected)">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                <path d="M10 11v6"/><path d="M14 11v6"/>
                                <path d="M9 6V4h6v2"/>
                            </svg>
                        </button>

                    </div>

                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div><!-- /tab-content-import -->


    <!-- ════════════════════════════════════════════
         TAB: MY PROFILE
    ════════════════════════════════════════════ -->
    <div id="tab-content-profile">

        <!-- ── Account Card (Name + Email + Store Name) ── -->
        <div class="profile-card mb-6">
            <p class="profile-card-title">Account</p>

            <div class="profile-grid-2col">
                <div class="profile-field">
                    <label class="profile-label" for="profile-name">Name</label>
                    <input type="text" id="profile-name" class="profile-input"
                           value="<?php echo htmlspecialchars($profile['name'] ?? ''); ?>"
                           maxlength="100" oninput="updateSaveBtn()">
                </div>

                <div class="profile-field">
                    <label class="profile-label">Email</label>
                    <input type="text" class="profile-input-readonly"
                           value="<?php echo htmlspecialchars($profile['email'] ?? ''); ?>"
                           readonly>
                    <p class="profile-field-hint">Email address cannot be changed.</p>
                </div>
            </div>

            <hr class="profile-divider">

            <div class="profile-field">
                <label class="profile-label" for="profile-store-name">Store Name</label>
                <input type="text" id="profile-store-name" class="profile-input"
                       value="<?php echo htmlspecialchars($profile['store_name'] ?? ''); ?>"
                       maxlength="100" oninput="updateSaveBtn()">
                <p class="profile-field-hint">This is how your store appears across ProVendor.</p>
            </div>

            <!-- Save feedback + button live inside the card so they're tied to the fields above -->
            <div id="profile-save-feedback" class="profile-feedback hidden" style="margin-top:1rem;"></div>
            <div class="flex justify-end mt-5">
                <button id="profile-save-btn" onclick="saveProfile()" disabled
                        class="profile-save-btn" style="opacity:0.3; cursor:not-allowed;">
                    Save Profile Changes
                    <svg class="w-4 h-4 inline-block ml-1.5 -mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- ── Change Password Card ── -->
        <div class="profile-card mb-8">
            <p class="profile-card-title">Change Password</p>

            <div class="profile-field">
                <label class="profile-label" for="profile-current-pwd">Current Password</label>
                <input type="password" id="profile-current-pwd" class="profile-input" placeholder="••••••••">
            </div>

            <div class="profile-grid-2col">
                <div class="profile-field">
                    <label class="profile-label" for="profile-new-pwd">New Password</label>
                    <input type="password" id="profile-new-pwd" class="profile-input" placeholder="Min. 8 characters">
                </div>

                <div class="profile-field">
                    <label class="profile-label" for="profile-confirm-pwd">Confirm New Password</label>
                    <input type="password" id="profile-confirm-pwd" class="profile-input" placeholder="••••••••">
                </div>
            </div>

            <div id="profile-pwd-feedback" class="profile-feedback hidden"></div>

            <div class="flex justify-end mt-5">
                <button id="profile-pwd-btn" onclick="changePassword()" class="profile-action-btn">
                    Update Password
                </button>
            </div>
        </div>

        <!-- Danger Zone -->
        <div class="danger-zone-card">
            <div class="flex items-start justify-between gap-6">
                <div>
                    <p class="danger-zone-title">Danger Zone</p>
                    <p class="danger-zone-desc">
                        Delete all imported sales data — products, sales records, and import sessions will be
                        permanently erased. Your account and store name will remain intact.
                        You will be redirected to the setup page to re-import data.
                    </p>
                </div>
                <button onclick="confirmClearData()" class="danger-zone-btn flex-shrink-0">
                    Delete All Data
                </button>
            </div>
        </div>

    </div><!-- /tab-content-profile -->


    <!-- ════════════════════════════════════════════
         TAB: FORECAST RANGE  (merged from the old Settings page)
    ════════════════════════════════════════════ -->
    <div id="tab-content-forecast" class="hidden">

        <div class="settings-card">
            <div class="settings-card-head">
                <p class="settings-eyebrow">Forecast</p>
                <h2 class="settings-title">Forecast Range</h2>
                <p class="settings-sub">
                    How many days ahead ProVendor forecasts each product. Shorter ranges forecast
                    more accurately. Changing this re-forecasts your whole catalogue for the new window.
                    Individual products can still be given their own range on the Forecast page.
                </p>
            </div>

            <div class="settings-field">
                <label class="settings-label" for="horizon-input">Days to forecast</label>
                <div class="settings-input-row">
                    <div class="settings-input-wrap">
                        <input type="number" id="horizon-input" class="settings-input" min="1" max="60"
                               value="<?php echo (int) ($profile['forecast_horizon_days'] ?? 30); ?>">
                        <span class="settings-input-affix">days</span>
                    </div>
                    <button type="button" id="settings-save-btn" class="settings-save-btn" onclick="saveHorizon()">
                        Save &amp; Re-forecast
                    </button>
                </div>
                <p class="settings-hint">Between 1 and 60 days. Default is 30.</p>
                <div id="settings-msg" class="settings-msg" style="display:none"></div>
            </div>
        </div>

    </div><!-- /tab-content-forecast -->

</main>


<!-- ════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════ -->
<script>

// ── Tab switching ─────────────────────────────────────────────────────────────
var TABS = ['import', 'profile', 'forecast'];
function switchTab(name) {
    TABS.forEach(function (t) {
        document.getElementById('tab-btn-' + t)    .classList.toggle('active', name === t);
        document.getElementById('tab-content-' + t).classList.toggle('hidden', name !== t);
    });
    window.location.hash = name;
}

// Restore the active tab on load.
// Default is My Profile. Switch away from it if:
//   - arriving from a successful import (?imported=1) → Sales Data, or
//   - the URL hash explicitly requests a tab (#import / #forecast / #profile).
document.addEventListener('DOMContentLoaded', function() {
    var fromImport = window.location.search.indexOf('imported=1') !== -1;
    var hash       = window.location.hash.replace('#', '');

    if (fromImport || hash === 'import') {
        switchTab('import');
    } else if (hash === 'forecast' || hash === 'profile') {
        switchTab(hash);
    }
    wRestoreState();
});


// ══════════════════════════════════════════════════════════════════════════════
// PROFILE TAB
// ══════════════════════════════════════════════════════════════════════════════

// Original values (used to detect whether anything has changed).
var profileOriginal = {
    name:      <?php echo json_encode($profile['name']       ?? ''); ?>,
    storeName: <?php echo json_encode($profile['store_name'] ?? ''); ?>,
};

// ── Change detection ──────────────────────────────────────────────────────────
function profileHasChanges() {
    var name      = document.getElementById('profile-name').value.trim();
    var storeName = document.getElementById('profile-store-name').value.trim();

    return name !== profileOriginal.name
        || storeName !== profileOriginal.storeName;
}

function updateSaveBtn() {
    var btn = document.getElementById('profile-save-btn');
    if (profileHasChanges()) {
        btn.disabled      = false;
        btn.style.opacity = '1';
        btn.style.cursor  = 'pointer';
    } else {
        btn.disabled      = true;
        btn.style.opacity = '0.3';
        btn.style.cursor  = 'not-allowed';
    }
}

// ── Save profile (name + store name) ──────────────────────────────────────────
function saveProfile() {
    if (!profileHasChanges()) return;

    showConfirm({
        title:        'Save Profile Changes?',
        message:      'Your name and store name will be updated.',
        confirmText:  'Save Changes',
        confirmStyle: 'primary',
        onConfirm:    doSaveProfile,
    });
}

async function doSaveProfile() {
    var btn = document.getElementById('profile-save-btn');
    btn.textContent = 'Saving…';
    btn.disabled    = true;
    btn.style.opacity = '0.6';

    var formData = new FormData();
    formData.append('name',       document.getElementById('profile-name').value.trim());
    formData.append('store_name', document.getElementById('profile-store-name').value.trim());

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/update_profile.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.success) {
            // Update the stored originals so the button re-disables correctly.
            profileOriginal.name      = document.getElementById('profile-name').value.trim();
            profileOriginal.storeName = document.getElementById('profile-store-name').value.trim();

            showProfileFeedback('profile-save-feedback', 'success', 'Profile saved successfully.');
        } else {
            showProfileFeedback('profile-save-feedback', 'error', data.error || 'Could not save. Please try again.');
        }
    } catch (e) {
        showProfileFeedback('profile-save-feedback', 'error', 'Network error. Please try again.');
    } finally {
        btn.innerHTML     = 'Save Profile Changes <svg class="w-4 h-4 inline-block ml-1.5 -mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        updateSaveBtn(); // re-evaluate disabled state
    }
}

// ── Change password ───────────────────────────────────────────────────────────
async function changePassword() {
    var currentPwd = document.getElementById('profile-current-pwd').value;
    var newPwd     = document.getElementById('profile-new-pwd').value;
    var confirmPwd = document.getElementById('profile-confirm-pwd').value;

    if (!currentPwd || !newPwd || !confirmPwd) {
        showProfileFeedback('profile-pwd-feedback', 'error', 'Please fill in all three password fields.');
        return;
    }

    if (newPwd.length < 8) {
        showProfileFeedback('profile-pwd-feedback', 'error', 'New password must be at least 8 characters.');
        return;
    }

    if (newPwd !== confirmPwd) {
        showProfileFeedback('profile-pwd-feedback', 'error', 'New password and confirmation do not match.');
        return;
    }

    var btn = document.getElementById('profile-pwd-btn');
    btn.textContent = 'Updating…';
    btn.disabled    = true;

    var formData = new FormData();
    formData.append('current_password', currentPwd);
    formData.append('new_password',     newPwd);
    formData.append('confirm_password', confirmPwd);

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/change_password.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.success) {
            document.getElementById('profile-current-pwd').value = '';
            document.getElementById('profile-new-pwd').value     = '';
            document.getElementById('profile-confirm-pwd').value = '';
            showProfileFeedback('profile-pwd-feedback', 'success', 'Password updated successfully.');
        } else {
            showProfileFeedback('profile-pwd-feedback', 'error', data.error || 'Could not update password.');
        }
    } catch (e) {
        showProfileFeedback('profile-pwd-feedback', 'error', 'Network error. Please try again.');
    } finally {
        btn.textContent = 'Update Password';
        btn.disabled    = false;
    }
}

// ── Inline feedback helper ────────────────────────────────────────────────────
function showProfileFeedback(elementId, type, message) {
    var el = document.getElementById(elementId);
    el.textContent = message;
    el.className   = 'profile-feedback ' + type;
    el.classList.remove('hidden');
    setTimeout(function() { el.classList.add('hidden'); }, 5000);
}

// ── Danger zone ───────────────────────────────────────────────────────────────
function confirmClearData() {
    showConfirm({
        title:        'Delete All Imported Data?',
        message:      'This will permanently erase all products, sales records, and import sessions. Your account and store settings will be kept. You will be sent back to the setup page. This cannot be undone.',
        confirmText:  'Delete Everything',
        confirmStyle: 'danger',
        onConfirm:    doClearData,
    });
}

async function doClearData() {
    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/clear_data.php', { method: 'POST' });
        var data = await res.json();

        if (data.success) {
            window.location = '<?php echo BASE_URL; ?>/pages/landing.view.php';
        } else {
            alert('Delete failed: ' + (data.error || 'Unknown error.'));
        }
    } catch (e) {
        alert('Network error. Please try again.');
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// IMPORT WIZARD (unchanged)
// ══════════════════════════════════════════════════════════════════════════════

function openWizard() {
    var panel = document.getElementById('wizard-panel');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (!panel.classList.contains('hidden')) return;
    panel.classList.remove('hidden');
    wGoToStep(1);
}

function closeWizard() {
    if (wHeaders.length > 0) {
        showConfirm({
            title:        'Discard Progress?',
            message:      'You have unsaved column mapping in progress. Closing will discard it. Are you sure?',
            confirmText:  'Discard',
            confirmStyle: 'danger',
            onConfirm:    wForceClose,
        });
        return;
    }
    wForceClose();
}

function wForceClose() {
    document.getElementById('wizard-panel').classList.add('hidden');
    document.getElementById('w-drop-text').textContent = 'Drop your CSV here, or click to browse';
    document.getElementById('w-csv-file').value = '';
    
    var dfSel = document.getElementById('w-date-format-select');
    if (dfSel) dfSel.value = 'auto';

    var btn = document.getElementById('w-upload-btn');
    btn.disabled      = true;
    btn.style.opacity = '0.3';
    btn.style.cursor  = 'not-allowed';
    wHeaders     = [];
    wSample      = [];
    wRowCount    = 0;
    wAssignments = {};
    wCurrentStep = 1;
    wDroppedFile = null;
    wGoToStep(1);
    wClearState();
}

function wGoToStep(n) {
    wCurrentStep = n;
    document.getElementById('wstep-1').classList.toggle('hidden', n !== 1);
    document.getElementById('wstep-2').classList.toggle('hidden', n !== 2);

    var dot1 = document.getElementById('wdot-1');
    var dot2 = document.getElementById('wdot-2');

    if (n === 1) {
        dot1.style.opacity = '1';
        dot1.querySelector('.num').style.cssText = 'background:#261F0E; color:#F0E8D0; width:1.5rem; height:1.5rem; border-radius:999px; display:flex; align-items:center; justify-content:center; font-size:0.7rem; font-weight:600;';
        dot2.style.opacity = '0.35';
        dot2.querySelector('.num').style.cssText = 'border:2px solid #261F0E; color:#261F0E; width:1.5rem; height:1.5rem; border-radius:999px; display:flex; align-items:center; justify-content:center; font-size:0.7rem; font-weight:600;';
    } else {
        dot1.style.opacity = '1';
        dot1.querySelector('.num').style.cssText = 'background:#1A6933; color:#F0E8D0; width:1.5rem; height:1.5rem; border-radius:999px; display:flex; align-items:center; justify-content:center; font-size:0.7rem; font-weight:600;';
        dot2.style.opacity = '1';
        dot2.querySelector('.num').style.cssText = 'background:#261F0E; color:#F0E8D0; width:1.5rem; height:1.5rem; border-radius:999px; display:flex; align-items:center; justify-content:center; font-size:0.7rem; font-weight:600;';
    }
    wSaveState();
    if (n === 1) wClearPreflight();
}

function wHandleDragOver(e) {
    e.preventDefault();
    document.getElementById('w-drop-zone').classList.add('drag-over');
}

function wHandleDragLeave() {
    document.getElementById('w-drop-zone').classList.remove('drag-over');
}

function wHandleDrop(e) {
    e.preventDefault();
    wHandleDragLeave();
    var file = e.dataTransfer.files[0];
    if (!file) return;
    if (!file.name.toLowerCase().endsWith('.csv')) {
        document.getElementById('w-drop-text').textContent = 'Only .csv files are accepted';
        return;
    }
    wDroppedFile = file;
    wSetFile(file.name);
}

function wHandleFileSelect(e) {
    wDroppedFile = null;
    var file = e.target.files[0];
    if (file) wSetFile(file.name);
}

function wSetFile(name) {
    document.getElementById('w-drop-text').textContent = name;
    var btn = document.getElementById('w-upload-btn');
    btn.disabled      = false;
    btn.style.opacity = '1';
    btn.style.cursor  = 'pointer';
}

async function wDetectColumns() {
    var file = wDroppedFile || document.getElementById('w-csv-file').files[0];
    if (!file) return;

    var btn = document.getElementById('w-upload-btn');
    btn.textContent = 'Detecting…';
    btn.disabled    = true;

    var formData = new FormData();
    formData.append('csv', file);

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/detect.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.error) { alert('Detection failed: ' + data.error); return; }
        wPopulateMappingUI(data);
        wGoToStep(2);
    } catch (e) {
        alert('Network error. Please try again.');
    } finally {
        btn.innerHTML = 'Upload &amp; Detect Columns <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
        btn.disabled      = false;
        btn.style.opacity = '1';
        btn.style.cursor  = 'pointer';
    }
}

var W_FIELDS = [
    { key: 'date',        label: 'Date',                required: true  },
    { key: 'product',     label: 'Product (Primary)',    required: true  },
    { key: 'quantity',    label: 'Quantity',             required: true  },
    { key: 'sku',         label: 'Product (Secondary)',  required: false },
    { key: 'category',    label: 'Category',             required: false },
    { key: 'subcategory', label: 'Sub-Category',         required: false },
    { key: 'cost',        label: 'Cost Price',           required: false },
    { key: 'price',       label: 'Selling Price',        required: false },
];

var wHeaders     = [];
var wSample      = [];
var wRowCount    = 0;
var wAssignments = {};
var wPending     = null;
var wCurrentStep = 1;
var wDroppedFile = null; // holds File from drag-and-drop (file input can't receive dragged files)

var WZ_KEY = 'pv_import_wizard';

function wSaveState() {
    if (!wHeaders.length) return;
    try {
        localStorage.setItem(WZ_KEY, JSON.stringify({
            headers:     wHeaders,
            sample:      wSample,
            rowCount:    wRowCount,
            assignments: wAssignments,
            fileName:    document.getElementById('w-file-name').textContent,
            dateFormat:  document.getElementById('w-date-format-select').value,
        }));
    } catch(e) {}
}

function wClearState() {
    try { localStorage.removeItem(WZ_KEY); } catch(e) {}
}

function wRestoreState() {
    if (window.location.search.indexOf('imported=1') !== -1) { wClearState(); return; }
    try {
        var raw = localStorage.getItem(WZ_KEY);
        if (!raw) return;
        var state = JSON.parse(raw);
        if (!state || !Array.isArray(state.headers) || !state.headers.length) return;

        wHeaders     = state.headers;
        wSample      = state.sample      || [];
        wRowCount    = state.rowCount    || 0;
        wAssignments = state.assignments || {};

        if (state.dateFormat) {
            var dfSel = document.getElementById('w-date-format-select');
            if (dfSel) dfSel.value = state.dateFormat;
        }

        buildColumnTable();
        document.getElementById('w-file-name').textContent         = state.fileName || '';
        document.getElementById('w-granularity-badge').textContent = wSample.length + ' sample rows shown';

        document.getElementById('wizard-panel').classList.remove('hidden');
        wGoToStep(2);
    } catch(e) {
        wClearState();
    }
}

function wPopulateMappingUI(data) {
    wHeaders     = data.headers;
    wSample      = data.sample  || [];
    wRowCount    = data.row_count;
    wAssignments = {};

    W_FIELDS.forEach(function(f) {
        var suggested = data.suggestions[f.key];
        if (suggested) {
            var idx = wHeaders.indexOf(suggested);
            if (idx !== -1) wAssignments[f.key] = idx;
        }
    });

    buildColumnTable();

    var colWord = wHeaders.length + ' column' + (wHeaders.length !== 1 ? 's' : '');
    var rowWord = wRowCount.toLocaleString() + ' row' + (wRowCount !== 1 ? 's' : '');
    document.getElementById('w-file-name').textContent         = colWord + ' · ' + rowWord + ' total';
    document.getElementById('w-granularity-badge').textContent = wSample.length + ' sample rows shown';
    
    var detectedFormat = data.date_format && data.date_format.format ? data.date_format.format : '';
    var autoText = 'Auto-detect' + (detectedFormat ? ' (' + detectedFormat + ')' : '');
    document.querySelector('#w-date-format-select option[value="auto"]').textContent = autoText;

    wSaveState();
}

function buildColumnTable() {
    var html = '<table class="col-table">';

    html += '<thead><tr>';
    wHeaders.forEach(function(col, i) {
        var ignored = !isColAssigned(i);
        html += '<th data-col="' + i + '"' + (ignored ? ' class="col-ignored"' : '') + '>' + escHtml(col) + '</th>';
    });
    html += '</tr></thead>';

    html += '<tbody>';
    wSample.forEach(function(row) {
        html += '<tr>';
        wHeaders.forEach(function(col, i) {
            var val     = row[col] !== undefined ? String(row[col]) : '';
            var ignored = !isColAssigned(i);
            html += '<td data-col="' + i + '"' + (ignored ? ' class="col-ignored"' : '') + '>' + escHtml(val) + '</td>';
        });
        html += '</tr>';
    });
    html += '</tbody>';

    html += '<tfoot><tr>';
    wHeaders.forEach(function(col, i) {
        var assignedField = getAssignedField(i);
        var selClass = 'col-assign-select';
        if (assignedField) {
            var fd = null;
            W_FIELDS.forEach(function(f) { if (f.key === assignedField) fd = f; });
            selClass += fd && fd.required ? ' sel-required' : ' sel-optional';
        } else {
            selClass += ' sel-ignore';
        }

        html += '<td class="col-assign-cell">';
        html += '<select class="' + selClass + '" data-col-index="' + i + '" onchange="wHandleAssignment(' + i + ', this.value)">';
        html += '<option value="">— Ignore —</option>';
        W_FIELDS.forEach(function(f) {
            var selected = (assignedField === f.key) ? ' selected' : '';
            html += '<option value="' + f.key + '"' + selected + '>' + escHtml(f.label) + (f.required ? ' *' : '') + '</option>';
        });
        html += '</select></td>';
    });
    html += '</tr></tfoot></table>';

    document.getElementById('w-col-table-inner').innerHTML = html;
}

function isColAssigned(colIdx) {
    var vals = Object.keys(wAssignments).map(function(k) { return wAssignments[k]; });
    return vals.indexOf(colIdx) !== -1;
}

function getAssignedField(colIdx) {
    var keys = Object.keys(wAssignments);
    for (var k = 0; k < keys.length; k++) {
        if (wAssignments[keys[k]] === colIdx) return keys[k];
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

function wHandleAssignment(colIdx, fieldKey) {
    if (!fieldKey) {
        var prev = getAssignedField(colIdx);
        if (prev) delete wAssignments[prev];
        buildColumnTable();
        wSaveState();
        wClearPreflight();
        return;
    }

    if (wAssignments.hasOwnProperty(fieldKey) && wAssignments[fieldKey] !== colIdx) {
        var existingColName = wHeaders[wAssignments[fieldKey]];
        var fieldLabel = '';
        W_FIELDS.forEach(function(f) { if (f.key === fieldKey) fieldLabel = f.label; });

        buildColumnTable();

        wPending = { colIdx: colIdx, fieldKey: fieldKey };
        showConfirm({
            title:        'Column Already Assigned',
            message:      '"' + fieldLabel + '" is already mapped to "' + escHtml(existingColName) + '". Reassign it to "' + escHtml(wHeaders[colIdx]) + '" instead?',
            confirmText:  'Reassign',
            confirmStyle: 'warning',
            onConfirm: function() {
                wAssignments[wPending.fieldKey] = wPending.colIdx;
                wPending = null;
                buildColumnTable();
                wSaveState();
            }
        });
        return;
    }

    var prevField = getAssignedField(colIdx);
    if (prevField) delete wAssignments[prevField];

    wAssignments[fieldKey] = colIdx;
    buildColumnTable();
    wSaveState();
    wClearPreflight();
}

var wPreflightDone = false;
var wMappingCache  = null;

var IMPORT_BTN_HTML = 'Confirm &amp; Import <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';

function wBuildMapping() {
    var mapping = {};
    Object.keys(wAssignments).forEach(function(fieldKey) {
        mapping[fieldKey] = wHeaders[wAssignments[fieldKey]];
    });
    if (!mapping.date || !mapping.product || !mapping.quantity) {
        showMappingError('Please assign the Date, Product (Primary), and Quantity columns before importing.');
        return null;
    }
    return mapping;
}

function wClearPreflight() {
    wPreflightDone = false;
    wMappingCache  = null;
    var c = document.getElementById('w-preflight-container');
    if (c) c.innerHTML = '';
    var btn = document.getElementById('w-import-btn');
    if (btn) { btn.innerHTML = IMPORT_BTN_HTML; btn.disabled = false; btn.style.opacity = '1'; }
}

async function wSubmitImport() {
    if (wPreflightDone) {
        var replace = !!(document.getElementById('w-replace-overlap') || {checked: false}).checked;
        await wDoImport(wMappingCache, replace);
        return;
    }

    var mapping = wBuildMapping();
    if (!mapping) return;
    wMappingCache = mapping;

    var btn = document.getElementById('w-import-btn');
    btn.textContent = 'Checking…';
    btn.disabled    = true;

    var formData = new FormData();
    formData.append('mapping', JSON.stringify(mapping));
    formData.append('date_format', document.getElementById('w-date-format-select').value);

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/preflight.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.error) {
            showMappingError('Check failed: ' + data.error);
            btn.innerHTML = IMPORT_BTN_HTML;
            btn.disabled  = false;
            return;
        }

        // Always show the editable preview so the user can see + tweak every
        // row before anything commits.
        wRenderPreviewCard(data);
        wPreflightDone = true;
        wRefreshSummary();  // sets the Apply button label/enabled state too
    } catch(e) {
        showMappingError('Network error during check. Please try again.');
        btn.innerHTML = IMPORT_BTN_HTML;
        btn.disabled  = false;
    }
}

// ── Editable preview state ────────────────────────────────────────────────────
// wPreviewRows holds the full row list returned by preflight, mutated in place
// as the user edits qty/date. Each row carries its original values so we can
// tell what's been touched (the yellow "edited" indicator).
var wPreviewRows   = [];
var wPreviewData   = null;
var wPreviewFilter = 'all'; // all | new | overlap | invalid | noop
var wPreviewPage   = 1;
var W_PER_PAGE     = 50;
var wSortColumn    = null;  // null = preflight's default order (invalid → overlap → new → noop)
var wSortDir       = 'asc';

function wRenderPreviewCard(data) {
    wPreviewData = data;
    wPreviewRows = data.rows.map(function (r) {
        r.originalStatus = r.status;
        r.originalQty    = r.qty;
        r.originalDate   = r.date;
        r.edited         = false;
        return r;
    });

    var html = '<div class="preview-card">';
    html += '<div class="preview-card-title">Preview of changes</div>';
    html += wRenderSummary();

    if (data.recovered_count > 0) {
        var rc = data.recovered_count;
        html += '<div class="recovery-notice">';
        html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
        html += '<span><strong>' + rc.toLocaleString() + ' row' + (rc !== 1 ? 's' : '') + '</strong> ignored the selected date format and were parsed automatically — their structure was unambiguous (e.g. ISO&nbsp;YYYY-MM-DD, or a day value above&nbsp;12). If any dates look wrong in the table, adjust the <strong>Date Format</strong> selector above and re-run.</span>';
        html += '</div>';
    }

    html += '<div class="preview-toolbar">';
    html += '<div class="preview-filter-chips" id="w-filter-chips">';
    html += wFilterChip('all',     'All');
    html += wFilterChip('new',     'New');
    html += wFilterChip('overlap', 'Conflict');
    html += wFilterChip('invalid', 'Invalid');
    html += wFilterChip('noop',    'Unchanged');
    html += '</div>';
    html += '<label class="preview-replace-label">';
    html += '<input type="checkbox" id="w-replace-overlap" class="preview-replace-check">';
    html += '<span>Replace existing values for un-edited conflicts</span>';
    html += '</label>';
    html += '</div>';

    html += '<div class="preview-table-wrap"><table class="preview-table">';
    html += '<thead><tr>';
    html += wSortableTh('row',      'Row');
    html += wSortableTh('product',  'Product');
    html += wSortableTh('date',     'Date');
    html += wSortableTh('qty',      'Qty');
    html += wSortableTh('existing', 'Existing');
    html += wSortableTh('status',   'Status');
    html += '<th>Actions</th>';
    html += '</tr></thead>';
    html += '<tbody id="w-preview-tbody"></tbody>';
    html += '</table></div>';

    html += '<div class="preview-pagination" id="w-preview-pagination"></div>';

    html += '<p class="preview-edit-help">Click <strong>Edit</strong> on any row to change its Qty or Date, then <strong>Save</strong>. Edited rows show a yellow indicator and are always applied on commit; un-edited conflicts respect the toggle above.</p>';

    html += '</div>';

    document.getElementById('w-preflight-container').innerHTML = html;
    wRenderRows();
}

function wRenderSummary() {
    var s   = computePreviewCounts(wPreviewRows);
    var tot = (wPreviewData.csv_rows || 0).toLocaleString();

    var html = '<div class="preview-summary">';
    html += wSummaryRow('preview-summary-total',   'Total rows in file', tot);
    html += wSummaryRow('preview-summary-new',     'Will be added',      s.new.toLocaleString());
    if (s.overlap > 0) html += wSummaryRow('preview-summary-overlap', 'Conflicts with existing data', s.overlap.toLocaleString());
    if (s.invalid > 0) html += wSummaryRow('preview-summary-invalid', 'Can’t be accepted',       s.invalid.toLocaleString());
    if (s.noop > 0)    html += wSummaryRow('preview-summary-noop',    'Already match (no change)', s.noop.toLocaleString());
    if (s.edited > 0)  html += wSummaryRow('preview-summary-edited',  'Manually edited', s.edited.toLocaleString());
    html += '</div>';
    return html;
}

function wSummaryRow(cls, label, value) {
    return '<div class="preview-summary-row ' + cls + '">' +
           '<span class="preview-summary-label">' + label + '</span>' +
           '<span class="preview-summary-value">' + value + '</span></div>';
}

function wFilterChip(key, label) {
    var active = wPreviewFilter === key ? ' preview-filter-chip-active' : '';
    return '<button type="button" class="preview-filter-chip' + active + '" ' +
           'data-filter="' + key + '" onclick="wSetFilter(\'' + key + '\')">' + label + '</button>';
}

function wSetFilter(key) {
    wPreviewFilter = key;
    wPreviewPage   = 1; // changing filter resets to page 1 since the row set shrinks/grows
    document.querySelectorAll('#w-filter-chips .preview-filter-chip').forEach(function (el) {
        el.classList.toggle('preview-filter-chip-active', el.dataset.filter === key);
    });
    wRenderRows();
}

function wGoToPage(p) {
    wPreviewPage = p;
    wRenderRows();
}

function wSortableTh(col, label) {
    var active = wSortColumn === col;
    var arrow  = active ? (wSortDir === 'asc' ? ' ▲' : ' ▼') : '';
    var cls    = 'preview-th-sortable' + (active ? ' preview-th-sorted' : '');
    return '<th class="' + cls + '" data-col="' + col + '" onclick="wSetSort(\'' + col + '\')">' + label +
           '<span class="preview-sort-arrow">' + arrow + '</span></th>';
}

function wSetSort(col) {
    if (wSortColumn === col) {
        // Same column → toggle direction. Third click clears the sort entirely
        // (back to preflight's default invalid-first ordering).
        if (wSortDir === 'asc') {
            wSortDir = 'desc';
        } else {
            wSortColumn = null;
            wSortDir    = 'asc';
        }
    } else {
        wSortColumn = col;
        wSortDir    = 'asc';
    }
    wPreviewPage = 1;
    wUpdateSortHeaders();
    wRenderRows();
}

// Updates the arrow indicators on the existing thead instead of re-rendering
// the whole card — that would wipe out any rows the user is currently editing.
function wUpdateSortHeaders() {
    document.querySelectorAll('#w-preflight-container .preview-th-sortable').forEach(function (th) {
        var col   = th.dataset.col;
        var arrow = th.querySelector('.preview-sort-arrow');
        if (wSortColumn === col) {
            th.classList.add('preview-th-sorted');
            if (arrow) arrow.textContent = wSortDir === 'asc' ? ' ▲' : ' ▼';
        } else {
            th.classList.remove('preview-th-sorted');
            if (arrow) arrow.textContent = '';
        }
    });
}

// Re-applies the active sort to wPreviewRows in place. Returns nothing.
function wApplySort() {
    if (!wSortColumn) {
        // Restore preflight's default order: invalid → overlap → new → noop, then rowNum.
        var defaultOrder = { invalid: 0, overlap: 1, new: 2, noop: 3 };
        wPreviewRows.sort(function (a, b) {
            var sa = defaultOrder[a.status] !== undefined ? defaultOrder[a.status] : 9;
            var sb = defaultOrder[b.status] !== undefined ? defaultOrder[b.status] : 9;
            if (sa !== sb) return sa - sb;
            return a.rowNum - b.rowNum;
        });
        return;
    }
    var dir = wSortDir === 'asc' ? 1 : -1;
    wPreviewRows.sort(function (a, b) { return wCompareRows(a, b, wSortColumn) * dir; });
}

function wCompareRows(a, b, col) {
    if (col === 'row')      return a.rowNum - b.rowNum;
    if (col === 'product')  return String(a.product || '').localeCompare(String(b.product || ''));
    if (col === 'date')     return String(a.date || '').localeCompare(String(b.date || '')); // YYYY-MM-DD sorts lexicographically
    if (col === 'qty') {
        var aq = (a.qty === null || a.qty === undefined) ? -Infinity : a.qty;
        var bq = (b.qty === null || b.qty === undefined) ? -Infinity : b.qty;
        return aq - bq;
    }
    if (col === 'existing') {
        var ae = a.existing_qty === undefined ? -Infinity : a.existing_qty;
        var be = b.existing_qty === undefined ? -Infinity : b.existing_qty;
        return ae - be;
    }
    if (col === 'status') {
        var order = { invalid: 0, overlap: 1, new: 2, noop: 3 };
        return (order[a.status] !== undefined ? order[a.status] : 9) -
               (order[b.status] !== undefined ? order[b.status] : 9);
    }
    return 0;
}

function wRenderRows() {
    var tbody = document.getElementById('w-preview-tbody');
    if (!tbody) return;

    wApplySort();

    // Filtered set first, then paginate so the page indicator reflects what
    // the user is actually navigating.
    var filtered = [];
    wPreviewRows.forEach(function (r, idx) {
        if (wPreviewFilter === 'all' || r.status === wPreviewFilter) {
            filtered.push({ row: r, idx: idx });
        }
    });

    var totalPages = Math.max(1, Math.ceil(filtered.length / W_PER_PAGE));
    if (wPreviewPage > totalPages) wPreviewPage = totalPages;
    if (wPreviewPage < 1)          wPreviewPage = 1;

    var start = (wPreviewPage - 1) * W_PER_PAGE;
    var slice = filtered.slice(start, start + W_PER_PAGE);

    var html = '';
    slice.forEach(function (entry) { html += wRenderRow(entry.row, entry.idx); });
    if (!html) html = '<tr><td colspan="7" class="preview-empty">No rows match this filter.</td></tr>';
    tbody.innerHTML = html;

    wRenderPagination(filtered.length, totalPages, start, slice.length);
}

function wRenderPagination(totalFiltered, totalPages, start, shown) {
    var el = document.getElementById('w-preview-pagination');
    if (!el) return;
    if (totalFiltered === 0) { el.innerHTML = ''; return; }

    var from = (start + 1).toLocaleString();
    var to   = (start + shown).toLocaleString();
    var tot  = totalFiltered.toLocaleString();

    var html = '<div class="preview-pagination-info">Showing ' + from + '–' + to + ' of <strong>' + tot + '</strong></div>';
    html += '<div class="preview-pagination-controls">';
    html += '<button type="button" class="preview-page-btn" onclick="wGoToPage(1)"'                          + (wPreviewPage === 1          ? ' disabled' : '') + '>« First</button>';
    html += '<button type="button" class="preview-page-btn" onclick="wGoToPage(' + (wPreviewPage - 1) + ')"' + (wPreviewPage === 1          ? ' disabled' : '') + '>← Prev</button>';
    html += '<span class="preview-pagination-page">Page ' + wPreviewPage + ' of ' + totalPages + '</span>';
    html += '<button type="button" class="preview-page-btn" onclick="wGoToPage(' + (wPreviewPage + 1) + ')"' + (wPreviewPage === totalPages ? ' disabled' : '') + '>Next →</button>';
    html += '<button type="button" class="preview-page-btn" onclick="wGoToPage(' + totalPages       + ')"'  + (wPreviewPage === totalPages ? ' disabled' : '') + '>Last »</button>';
    html += '</div>';
    el.innerHTML = html;
}

function wRenderRow(r, idx) {
    var rowClass = 'preview-row preview-row-' + r.status + (r.edited ? ' preview-row-edited' : '');
    var qtyVal   = r.qty === null || r.qty === undefined ? (r.raw_qty || '') : r.qty;
    var dateVal  = r.date || r.raw_date || '';
    var qtyDisp  = typeof qtyVal === 'number' ? qtyVal.toLocaleString() : escHtml(String(qtyVal));

    var html = '<tr class="' + rowClass + '" data-idx="' + idx + '">';
    html += '<td class="preview-row-num">' + r.rowNum + (r.agg_count > 1 ? ' <span class="preview-agg">+' + (r.agg_count - 1) + '</span>' : '') + '</td>';
    html += '<td class="preview-product" title="' + escHtml(r.product) + '">' + escHtml(r.product || '(empty)') + '</td>';

    // Date + Qty cells render as plain text by default. Click Edit to swap in
    // editable inputs — the gate stops accidental keystrokes from changing data.
    if (r.editing) {
        html += '<td><input type="text" class="preview-input preview-input-date" value="' + escHtml(String(dateVal)) + '" ' +
                'placeholder="YYYY-MM-DD" onchange="wOnRowEdit(' + idx + ', \'date\', this.value)"></td>';
        html += '<td><input type="number" min="1" step="1" class="preview-input preview-input-qty" value="' + escHtml(String(qtyVal)) + '" ' +
                'onchange="wOnRowEdit(' + idx + ', \'qty\', this.value)"></td>';
    } else {
        html += '<td class="preview-cell-display">' + escHtml(String(dateVal)) + '</td>';
        html += '<td class="preview-cell-display preview-cell-qty-display">' + qtyDisp + '</td>';
    }

    html += '<td class="preview-existing">' + (r.existing_qty !== undefined ? r.existing_qty.toLocaleString() : '<span class="preview-dash">—</span>') + '</td>';
    html += '<td>' + wStatusBadge(r) + '</td>';
    html += '<td class="preview-actions">' + wRenderActions(r, idx) + '</td>';
    html += '</tr>';
    return html;
}

function wRenderActions(r, idx) {
    if (r.editing) {
        return '<button type="button" class="preview-row-btn preview-row-btn-save"   onclick="wSaveEdit(' + idx + ')">Save</button>' +
               '<button type="button" class="preview-row-btn preview-row-btn-cancel" onclick="wCancelEdit(' + idx + ')">Cancel</button>';
    }
    return '<button type="button" class="preview-row-btn preview-row-btn-edit" onclick="wStartEdit(' + idx + ')">Edit</button>';
}

function wStartEdit(idx) {
    var r = wPreviewRows[idx];
    if (!r) return;
    // Snapshot the current state so Cancel can revert in-flight changes.
    r._snapshotQty  = r.qty;
    r._snapshotDate = r.date;
    r.editing       = true;
    wRenderRows();
}

function wSaveEdit(idx) {
    var r = wPreviewRows[idx];
    if (!r) return;
    r.editing = false;
    delete r._snapshotQty;
    delete r._snapshotDate;
    wRenderRows();
    wRefreshSummary();
}

function wCancelEdit(idx) {
    var r = wPreviewRows[idx];
    if (!r) return;
    if (r._snapshotQty !== undefined)  r.qty  = r._snapshotQty;
    if (r._snapshotDate !== undefined) r.date = r._snapshotDate;
    r.edited = (r.qty !== r.originalQty || r.date !== r.originalDate);
    wReclassifyRow(r);
    r.editing = false;
    delete r._snapshotQty;
    delete r._snapshotDate;
    wRenderRows();
    wRefreshSummary();
}

function wStatusBadge(r) {
    var label = { new: 'New', overlap: 'Conflict', invalid: 'Invalid', noop: 'Unchanged' }[r.status] || r.status;
    var html = '<span class="preview-badge preview-badge-' + r.status + '">' + label + '</span>';
    if (r.status === 'invalid' && r.reason) {
        html += '<div class="preview-row-reason" title="' + escHtml(r.reason) + '">' + escHtml(r.reason) + '</div>';
    }
    return html;
}

function wOnRowEdit(idx, field, value) {
    var r = wPreviewRows[idx];
    if (!r) return;

    if (field === 'qty')  r.qty  = value === '' ? null : Number(value);
    if (field === 'date') r.date = value;

    r.edited = (r.qty !== r.originalQty || r.date !== r.originalDate);
    wReclassifyRow(r);

    // Update the visible cells in place rather than re-rendering the tbody —
    // a full re-render mid-edit destroys the inputs and steals user focus.
    var tr = document.querySelector('#w-preview-tbody tr[data-idx="' + idx + '"]');
    if (tr) {
        tr.className = 'preview-row preview-row-' + r.status + (r.edited ? ' preview-row-edited' : '');
        var statusTd = tr.children[5];
        if (statusTd) statusTd.innerHTML = wStatusBadge(r);
    }

    wRefreshSummary();
}

// Client-side reclassification — only does what we can without a DB roundtrip.
// (Cross-row overlap detection against the existing DB is left to the server.)
function wReclassifyRow(r) {
    var qtyOk  = r.qty !== null && Number.isInteger(r.qty) && r.qty > 0;
    var dateOk = /^\d{4}-\d{2}-\d{2}$/.test(String(r.date).trim()) && !isNaN(new Date(r.date).getTime());

    if (!qtyOk || !dateOk) {
        r.status = 'invalid';
        r.reason = !qtyOk ? 'Quantity must be a positive whole number' : 'Date must be in YYYY-MM-DD format';
        return;
    }
    delete r.reason;

    // Fixed an originally-invalid row → optimistically "new" (server confirms on apply).
    if (r.originalStatus === 'invalid') { r.status = 'new'; return; }

    // Overlap & noop hinge on whether qty matches the existing DB value
    // (only valid when date hasn't changed — otherwise we can't tell).
    if (r.date === r.originalDate && r.existing_qty !== undefined) {
        r.status = (r.qty === r.existing_qty) ? 'noop' : 'overlap';
        return;
    }

    // Date changed for a previously-overlap/noop row — unknown overlap with the new
    // date, so treat as "new" client-side; server will reclassify.
    if (r.originalStatus === 'overlap' || r.originalStatus === 'noop') {
        r.status = 'new';
        return;
    }

    r.status = r.originalStatus;
}

function wRefreshSummary() {
    var card = document.querySelector('#w-preflight-container .preview-card');
    if (!card) return;
    var oldSummary = card.querySelector('.preview-summary');
    if (!oldSummary) return;
    var tmp = document.createElement('div');
    tmp.innerHTML = wRenderSummary();
    oldSummary.replaceWith(tmp.firstElementChild);

    // Apply button gets toggled too based on whether anything would actually commit.
    var s   = computePreviewCounts(wPreviewRows);
    var btn = document.getElementById('w-import-btn');
    var willCommit = s.new + s.overlap + s.edited; // rough upper bound
    if (willCommit === 0) {
        btn.innerHTML     = 'No changes to apply';
        btn.disabled      = true;
        btn.style.opacity = '0.55';
    } else {
        btn.innerHTML     = 'Apply changes <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
        btn.disabled      = false;
        btn.style.opacity = '1';
    }
}

function computePreviewCounts(rows) {
    var s = { new: 0, overlap: 0, invalid: 0, noop: 0, edited: 0 };
    rows.forEach(function (r) {
        if (s[r.status] !== undefined) s[r.status]++;
        if (r.edited) s.edited++;
    });
    return s;
}

async function wDoImport(mapping, replace) {
    var btn = document.getElementById('w-import-btn');
    btn.textContent = 'Importing…';
    btn.disabled    = true;

    // Build the rows payload from the editor state. Skip rows that would be
    // server-side no-ops (saves payload bytes) and rows still marked invalid
    // and unedited (nothing we can do with them).
    var payloadRows = wPreviewRows.filter(function (r) {
        if (r.status === 'noop'    && !r.edited) return false;
        if (r.status === 'invalid' && !r.edited) return false;
        return true;
    });

    var formData = new FormData();
    formData.append('rows',     JSON.stringify(payloadRows));
    formData.append('csv_rows', wRowCount);
    formData.append('replace',  replace ? '1' : '0');

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/import.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.success) {
            wClearState();
            // A re-import is a dataset update — re-forecast the catalogue at the
            // saved horizon before returning to My Store.
            var redirect = '<?php echo BASE_URL; ?>/pages/import.view.php?imported=1&rows=' + data.rows + '&replaced=' + (data.replaced || 0) + '&skipped=' + (data.skipped || 0) + '&dropped=' + (data.dropped || 0) + '&csv_rows=' + (data.csv_rows || 0);
            reforecastAfterImport(redirect);
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
    var el = document.getElementById('w-mapping-error');
    el.textContent = msg;
    el.classList.remove('hidden');
    setTimeout(function() { el.classList.add('hidden'); }, 6000);
}

// Re-forecast every product at the saved horizon after a re-import, then redirect.
// Best-effort — on any failure we still continue to My Store.
async function reforecastAfterImport(redirectUrl) {
    var overlay = document.getElementById('af-overlay');
    var fill    = document.getElementById('af-progress-fill');
    var label   = document.getElementById('af-progress-label');
    if (overlay) overlay.classList.remove('hidden');

    var products = [];
    try {
        products = ((await (await fetch('<?php echo BASE_URL; ?>/api/catalogue_products.php')).json()).products) || [];
    } catch (e) { products = []; }

    if (products.length) {
        await AutoForecast.run(products, USER_HORIZON, LAST_SALE_DATE, {
            onProgress: function (done, total, name) {
                var pct = total > 0 ? Math.round((done / total) * 100) : 0;
                if (fill)  fill.style.width  = pct + '%';
                if (label) label.textContent = (name && done < total)
                    ? 'Forecasting ' + name + '… (' + (done + 1) + ' / ' + total + ')'
                    : done + ' / ' + total + ' products forecast';
            },
        });
    }
    window.location = redirectUrl;
}

function downloadSampleCsv(e) {
    e.stopPropagation();
    var rows = [
        ['date',       'product_name',  'quantity_sold', 'category',  'cost_price', 'selling_price', 'sku',      'subcategory'],
        ['2024-01-15', 'Pepsi 500ml',   '24',            'Beverages', '12.50',      '18.00',         'PEP-500',  'Carbonated'],
        ['2024-01-15', 'Lays Original', '15',            'Snacks',    '8.00',       '12.50',         'LAYS-ORG', 'Chips'],
        ['2024-01-16', 'Pepsi 500ml',   '18',            'Beverages', '12.50',      '18.00',         'PEP-500',  'Carbonated'],
    ];
    var csv  = rows.map(function(r) {
        return r.map(function(v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(',');
    }).join('\r\n');
    var blob = new Blob([csv], { type: 'text/csv' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href = url; a.download = 'provendor_sample.csv';
    document.body.appendChild(a); a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// ── Version history actions ───────────────────────────────────────────────────
function confirmRestoreVersion(versionId, label) {
    showConfirm({
        title:        'Restore this version?',
        message:      'Your sales data will be replaced with the snapshot from "' + label + '". ' +
                      'The current state is auto-saved first, so you can undo this restore from the history.',
        confirmText:  'Restore',
        confirmStyle: 'warning',
        onConfirm:    function() { restoreVersion(versionId); },
    });
}

async function restoreVersion(versionId) {
    var formData = new FormData();
    formData.append('version_id', versionId);

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/restore_version.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.success) {
            window.location = '<?php echo BASE_URL; ?>/pages/import.view.php?restored=1';
        } else {
            alert('Restore failed: ' + (data.error || 'Unknown error.'));
        }
    } catch(e) {
        alert('Network error. Please try again.');
    }
}

function confirmDeleteVersion(versionId, label) {
    showConfirm({
        title:        'Delete this version?',
        message:      'The snapshot "' + label + '" will be permanently removed from history. Your current sales data is not affected.',
        confirmText:  'Delete',
        confirmStyle: 'danger',
        onConfirm:    function() { deleteVersion(versionId); },
    });
}

async function deleteVersion(versionId) {
    var formData = new FormData();
    formData.append('version_id', versionId);

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/delete_version.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.success) {
            var row = document.getElementById('version-' + versionId);
            if (row) {
                row.style.transition = 'opacity 0.25s';
                row.style.opacity    = '0';
                setTimeout(function() {
                    row.remove();
                    var list = document.getElementById('versions-list');
                    if (list && !list.querySelector('.version-entry')) {
                        list.innerHTML = '<div class="versions-empty">No saved versions yet. Upload your first CSV to get started.</div>';
                    }
                }, 260);
            }
        } else {
            alert('Delete failed: ' + data.error);
        }
    } catch (e) {
        alert('Network error. Please try again.');
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// FORECAST RANGE TAB  (merged from the old Settings page)
// Reuses the af-overlay + AutoForecast + AUTO_BASE_URL/LAST_SALE_DATE already on
// this page for the after-import re-forecast.
// ══════════════════════════════════════════════════════════════════════════════
function _settingsMsg(type, text) {
    var msg = document.getElementById('settings-msg');
    msg.className = 'settings-msg settings-msg-' + type;
    msg.textContent = text;
    msg.style.display = '';
}

function saveHorizon() {
    var days = parseInt(document.getElementById('horizon-input').value, 10);
    if (isNaN(days) || days < 1 || days > 60) {
        _settingsMsg('error', 'Enter a number of days between 1 and 60.');
        return;
    }

    var btn = document.getElementById('settings-save-btn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    var body = new FormData();
    body.append('forecast_horizon_days', days);

    fetch('<?php echo BASE_URL; ?>/api/update_settings.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                _settingsMsg('error', data.error);
                btn.disabled = false; btn.textContent = 'Save & Re-forecast';
                return;
            }
            reforecastCatalogue(days);
        })
        .catch(function () {
            _settingsMsg('error', 'Network error. Please try again.');
            btn.disabled = false; btn.textContent = 'Save & Re-forecast';
        });
}

// After saving, re-forecast every product at the new horizon, then reload back
// onto this tab (products with their own per-product override keep it).
function reforecastCatalogue(days) {
    var overlay = document.getElementById('af-overlay');
    var fill    = document.getElementById('af-progress-fill');
    var label   = document.getElementById('af-progress-label');
    if (overlay) overlay.classList.remove('hidden');

    fetch('<?php echo BASE_URL; ?>/api/catalogue_products.php')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            var products = d.products || [];
            if (!products.length) { window.location.hash = 'forecast'; window.location.reload(); return; }
            AutoForecast.run(products, days, LAST_SALE_DATE, {
                onProgress: function (done, total, name) {
                    var pct = total > 0 ? Math.round((done / total) * 100) : 0;
                    if (fill)  fill.style.width  = pct + '%';
                    if (label) label.textContent = (name && done < total)
                        ? 'Forecasting ' + name + '… (' + (done + 1) + ' / ' + total + ')'
                        : done + ' / ' + total + ' products forecast';
                },
                onDone: function () { window.location.hash = 'forecast'; window.location.reload(); },
            });
        })
        .catch(function () {
            if (overlay) overlay.classList.add('hidden');
            _settingsMsg('error', 'Saved, but the re-forecast could not start. Open the Forecast page to refresh.');
        });
}
</script>

<!-- Re-forecast progress overlay — shown after a re-import (dataset update) -->
<div id="af-overlay" class="af-overlay hidden">
    <div class="af-overlay-card">
        <svg class="af-spinner" width="30" height="30" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
        </svg>
        <h2 class="af-title">Updating your forecasts…</h2>
        <p class="af-sub">Re-forecasting every product with your new data. This runs once.</p>
        <div class="af-progress-track"><div id="af-progress-fill" class="af-progress-fill"></div></div>
        <p id="af-progress-label" class="af-progress-label">Starting…</p>
    </div>
</div>

<script>
const AUTO_BASE_URL  = '<?php echo BASE_URL; ?>';
const LAST_SALE_DATE = <?php echo json_encode($summary['date_to'] ?? null); ?>;
const USER_HORIZON   = <?php echo (int) ($profile['forecast_horizon_days'] ?? 30); ?>;
</script>
<script src="<?php echo BASE_URL; ?>/pages/js/autorun_forecast.js?v=<?php echo filemtime(__DIR__ . '/js/autorun_forecast.js'); ?>"></script>

<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
