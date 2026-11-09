<?php
// pages/import.view.php
// Import Data + Profile page — two tabs in one place.

require_once __DIR__ . '/import.logic.php';
require_once __DIR__ . '/../api/import_helpers.php';

$pageTitle = 'ProVendor — Settings';
$pageCss   = 'import.css';
$extraCss  = ['settings.css'];   // Forecast Range tab styles (merged from the old Settings page)
if (SHOW_ACCURACY_FEATURES) {
    $extraCss[] = 'reports.css'; // Reports tab styles (merged from the old Reports page)
}
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
        <button id="tab-btn-profile"  class="tab-btn active" onclick="switchTab('profile')">My Profile</button>
        <button id="tab-btn-import"   class="tab-btn"        onclick="switchTab('import')">Sales Data</button>
        <button id="tab-btn-forecast" class="tab-btn"        onclick="switchTab('forecast')">Forecast Range</button>
        <?php if (SHOW_ACCURACY_FEATURES): ?>
        <button id="tab-btn-reports"  class="tab-btn"        onclick="switchTab('reports')">Reports</button>
        <?php endif; ?>
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
                        if ($dropped > 0)  $parts[] = number_format($dropped)  . ' row' . ($dropped !== 1 ? 's' : '') . ' left out';
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
            <span>Version restored and marked Active<?php echo ($_GET['restored'] ?? '') === 'noprices' ? '. This older version has no saved prices, so your current prices were kept' : ', with its prices'; ?>. Your forecast is being re-run from it. The previous state was auto-saved in your history if you need to undo.</span>
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
                        <li>+ <strong>80%</strong> confidence band</li>
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

        <!-- ── Connected Google Sheet ──────────────────────────────────────────
             Only rendered once a sheet is linked. Everything here is about the
             live connection: where the data comes from, when it last moved, and
             the two levers over it (auto-refresh, disconnect).
        ─────────────────────────────────────────────────────────────────────── -->
        <?php if ($sheetLink): ?>
        <?php
            $syncFailed = $sheetLink['last_sync_status'] === 'error';
            $syncedAt   = $sheetLink['last_synced_at']
                ? date('M j, Y · g:i A', strtotime($sheetLink['last_synced_at']))
                : null;
        ?>
        <div class="sheet-card" id="sheet-card">

            <div class="sheet-card-head">
                <div class="sheet-card-id">
                    <p class="sheet-eyebrow">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                        Connected Google Sheet
                    </p>
                    <p class="sheet-name"><?php echo htmlspecialchars($sheetLink['sheet_title'] ?? 'Untitled sheet'); ?></p>
                    <?php if (!empty($sheetLink['worksheet_title'])): ?>
                    <p class="sheet-tab">Tab: <?php echo htmlspecialchars($sheetLink['worksheet_title']); ?></p>
                    <?php endif; ?>
                </div>

                <div class="sheet-actions">
                    <a class="sheet-open-btn" target="_blank" rel="noopener"
                       href="<?php echo htmlspecialchars($sheetLink['sheet_url']); ?>">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                            <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                        Open sheet
                    </a>

                    <!-- Shown only when auto-refresh is off: with it on, the
                         5-minute heartbeat already keeps this current. -->
                    <button type="button" id="sheet-update-btn" class="sheet-update-btn"
                            onclick="sheetUpdateNow()"
                            style="<?php echo $sheetLink['auto_sync'] ? 'display:none' : ''; ?>">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12a9 9 0 1 1-6.219-8.56"/><polyline points="21 3 21 9 15 9"/>
                        </svg>
                        Update Data
                    </button>

                    <button type="button" class="sheet-unlink-btn" onclick="confirmSheetUnlink()">
                        Disconnect
                    </button>
                </div>
            </div>

            <div class="sheet-autosync">
                <div>
                    <p class="sheet-autosync-label">Refresh automatically</p>
                    <p class="sheet-autosync-desc">
                        Re-reads the sheet every 5 minutes while ProVendor is open, adding new days
                        and updating any quantity you changed there. Turn this off to refresh only
                        when you press <strong>Update Data</strong>.
                    </p>
                </div>
                <button type="button" id="sheet-autosync-switch" role="switch"
                        aria-checked="<?php echo $sheetLink['auto_sync'] ? 'true' : 'false'; ?>"
                        aria-label="Refresh automatically"
                        class="sheet-switch<?php echo $sheetLink['auto_sync'] ? ' is-on' : ''; ?>"
                        onclick="toggleSheetAutoSync()"></button>
            </div>

            <div class="sheet-status<?php echo $syncFailed ? ' is-error' : ''; ?>" id="sheet-status">
                <span class="sheet-status-dot"></span>
                <span class="sheet-status-text" id="sheet-status-text">
                    <?php
                    if ($syncFailed) {
                        echo 'Last refresh failed: ' . htmlspecialchars($sheetLink['last_sync_error'] ?? 'unknown error');
                    } elseif ($syncedAt) {
                        echo 'Last refreshed ' . $syncedAt;
                        $moved = (int) $sheetLink['last_sync_added'] + (int) $sheetLink['last_sync_updated'];
                        if ($moved > 0) {
                            echo ' — ' . number_format((int) $sheetLink['last_sync_added']) . ' added, '
                               . number_format((int) $sheetLink['last_sync_updated']) . ' updated.';
                        } else {
                            echo ' — nothing had changed.';
                        }
                    } else {
                        echo 'Not refreshed yet.';
                    }
                    ?>
                </span>
            </div>

        </div>
        <?php endif; ?>

        <!-- ── Upload Wizard (hidden until triggered) ── -->
        <div id="wizard-panel" class="wizard-panel hidden">

            <div class="wizard-header">
                <div class="wizard-header-text">
                    <?php if ((int) $summary['total_sales'] > 0): ?>
                    <h2>Replace Sales Data</h2>
                    <p>Upload your full, updated sales file. It replaces your current data, which stays in Version History.</p>
                    <?php else: ?>
                    <h2>Upload Sales Data</h2>
                    <p>Upload a CSV or Excel file and map its columns to proceed.</p>
                    <?php endif; ?>
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
                        <span class="lbl">Upload File</span>
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
                            Drop your CSV or Excel file here, or click to browse
                        </p>
                        <p class="text-[#261F0E] text-xs" style="opacity:0.4">Supports .csv and .xlsx files · Max 10 MB · Max 200,000 data rows</p>
                        <button type="button" onclick="downloadSampleCsv(event)"
                                class="mt-3 text-xs text-[#261F0E] underline underline-offset-2 transition-opacity hover:opacity-60"
                                style="opacity:0.38; background:none; border:none; cursor:pointer;">
                            Download sample CSV
                        </button>

                        <input type="file" id="w-csv-file" accept=".csv,.xlsx" class="hidden" onchange="wHandleFileSelect(event)">
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
                        <span class="text-[10px] text-[#261F0E] ml-1" style="opacity:0.38">— unassigned columns are ignored. Category can be set on more than one column.</span>
                    </div>

                    <div class="mb-5 flex items-center gap-3 p-3.5 rounded-xl border border-[#D2C8AE]" style="background:rgba(38,31,14,0.03);">
                        <label class="text-[11px] font-bold text-[#261F0E] uppercase tracking-wider" style="opacity:0.8" for="w-date-format-select">Date Format:</label>
                        <select id="w-date-format-select" class="text-sm font-semibold border-2 border-[#D2C8AE] rounded-lg px-3 py-1.5 bg-white focus:border-[#261F0E] hover:border-[#261F0E] outline-none transition-colors cursor-pointer shadow-sm" style="color:#261F0E;" onchange="wClearPreflight(); wApplyDateLock(); wSaveState();">
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
                        <span id="w-date-format-hint" class="date-format-hint" hidden></span>
                    </div>

                    <div class="col-table-wrap">
                        <div id="w-col-table-inner"></div>
                    </div>

                    <div id="w-mapping-error" class="hidden text-sm font-semibold mb-4"
                         style="color:#b91c1c; background:rgba(185,28,28,0.07); border:1px solid rgba(185,28,28,0.2); border-radius:0.75rem; padding:0.875rem 1.25rem;"></div>

                    <div id="w-preflight-container"></div>
                    <script src="<?php echo BASE_URL; ?>/assets/global_js/import_preview.js?v=<?php echo filemtime(__DIR__ . '/../assets/global_js/import_preview.js'); ?>"></script>

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

                <?php if ($sheetLink): ?>
                <!-- Sheet linked: the button stays, and tells the owner to disconnect first. -->
                <button type="button" class="upload-btn-primary" onclick="notifySheetConnected()">
                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Replace Sales Data
                </button>
                <?php else: ?>
                <div class="data-source">
                    <button type="button" id="data-source-toggle" class="upload-btn-primary" aria-haspopup="true" aria-expanded="false">
                        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        <?php echo (int) $summary['total_sales'] > 0 ? 'Replace Sales Data' : 'Upload Sales Data'; ?>
                        <span aria-hidden="true">▾</span>
                    </button>
                    <div class="data-source-menu" id="data-source-menu" hidden>
                        <button type="button" class="data-source-item" id="data-source-upload">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            <span>
                                <span class="data-source-title">Manual upload</span>
                                <span class="data-source-desc">Choose a CSV or Excel file from your computer.</span>
                            </span>
                        </button>
                        <button type="button" class="data-source-item" id="data-source-sheets">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                                <line x1="8" y1="13" x2="16" y2="13"/>
                                <line x1="8" y1="17" x2="16" y2="17"/>
                            </svg>
                            <span>
                                <span class="data-source-title">Connect Google Sheets</span>
                                <span class="data-source-desc">Link a sheet so your sales stay up to date.</span>
                            </span>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <p class="versions-help">
            The row marked <strong>Active</strong> is the data your forecast uses. Each import saves a snapshot. Restore any version to roll your sales data back —
            the current state is auto-saved first so the restore itself is reversible.
            Only the most recent <?php echo MAX_VERSIONS_PER_USER; ?> versions are kept.
        </p>

        <div class="versions-list" id="versions-list">
            <?php if ($sheetLink): ?>
            <div class="version-entry version-sheet" id="version-sheet-link">
                <div class="version-row">
                    <div class="version-icon">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="8" y1="13" x2="16" y2="13"/>
                            <line x1="8" y1="17" x2="16" y2="17"/>
                        </svg>
                    </div>
                    <a class="version-label" target="_blank" rel="noopener"
                       href="<?php echo htmlspecialchars($sheetLink['sheet_url']); ?>"
                       title="Open this Google Sheet">
                        Google Sheet: <?php echo htmlspecialchars($sheetLink['sheet_title'] ?? 'Untitled sheet'); ?>
                    </a>
                    <div class="version-meta">
                        <span class="version-badge version-badge-active">Active</span>
                        <span class="version-badge version-badge-added">Connected</span>
                    </div>
                    <button type="button" class="version-disconnect-btn" onclick="confirmSheetUnlink()">Disconnect</button>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($versions) && !$sheetLink): ?>
            <div class="versions-empty">No saved versions yet. Upload your first CSV to get started.</div>
            <?php else: ?>
                <?php foreach ($versions as $v): ?>
                <?php $isActiveVersion = $v['is_current'] && !$sheetLink; ?>
                <div class="version-entry<?php echo $v['is_pre_restore_snapshot'] ? ' version-pre-restore' : ''; ?><?php echo $isActiveVersion ? ' version-active' : ''; ?>"
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
                            <?php if ($isActiveVersion): ?>
                            <span class="version-badge version-badge-active">Active</span>
                            <?php endif; ?>
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

        <!-- Delete-All-Data password confirmation modal -->
        <div id="delete-data-modal"
            class="fixed inset-0 z-[2000] flex items-center justify-center hidden"
            role="dialog" aria-modal="true" aria-labelledby="delete-data-title">

            <div class="absolute inset-0" style="background:rgba(38,31,14,0.55)" onclick="closeDeleteDataModal()"></div>

            <div class="relative bg-[#F0E8D0] rounded-2xl border border-[#D2C8AE] w-full max-w-sm mx-4 p-8"
                style="box-shadow:0 24px 64px rgba(38,31,14,0.3)">

                <h3 id="delete-data-title" class="text-lg font-semibold text-[#261F0E] mb-2 leading-snug">
                    Delete All Imported Data?
                </h3>
                <p class="text-sm text-[#261F0E] leading-relaxed mb-5" style="opacity:0.55">
                    This will permanently erase all products, sales records, and import sessions. Your account and
                    store settings will be kept. This cannot be undone.
                </p>

                <div class="profile-field">
                    <label class="profile-label" for="delete-data-password">Enter your password to confirm</label>
                    <input type="password" id="delete-data-password" class="profile-input" placeholder="••••••••"
                           onkeydown="if (event.key === 'Enter') submitDeleteData();">
                </div>

                <div id="delete-data-feedback" class="profile-feedback hidden"></div>

                <div class="flex gap-3 mt-5">
                    <button type="button" onclick="closeDeleteDataModal()"
                        class="flex-1 border border-[#D2C8AE] rounded-xl py-2.5 text-sm font-semibold text-[#261F0E] hover:bg-[#D2C8AE] transition-colors">
                        Cancel
                    </button>
                    <button type="button" id="delete-data-confirm-btn" onclick="submitDeleteData()"
                        class="flex-1 rounded-xl py-2.5 text-sm font-semibold transition-opacity hover:opacity-90"
                        style="background:#FF1A1A;color:#F0E8D0">
                        Delete Everything
                    </button>
                </div>
            </div>
        </div>

    </div><!-- /tab-content-profile -->


    <!-- ════════════════════════════════════════════
         TAB: FORECAST RANGE  (merged from the old Settings page)
    ════════════════════════════════════════════ -->
    <div id="tab-content-forecast" class="hidden">

        <?php
        $curHorizon  = (int) ($profile['forecast_horizon_days'] ?? 30);
        $curReminder = min((int) ($profile['forecast_reminder_days'] ?? 10), $curHorizon);
        $jobRunning =$lastJob && in_array($lastJob['status'], ['queued', 'running'], true);
        ?>

        <!-- ── Forecast range ──────────────────────────────────────────────── -->
        <div class="settings-card">
            <div class="settings-card-head">
                <p class="settings-eyebrow">Forecast</p>
                <h2 class="settings-title">Forecast Range</h2>
                <p class="settings-sub">
                    How many days ahead ProVendor projects demand for each product. Shorter ranges
                    are more accurate; longer ranges plan further out. Individual products can still
                    be given their own range on the Forecast page.
                </p>
            </div>

            <!-- Current state, stated plainly before any controls -->
            <div class="settings-current">
                <span class="settings-current-label">Currently forecasting</span>
                <span class="settings-current-value" id="settings-current-days"><?php echo $curHorizon; ?> days ahead</span>
            </div>

            <div class="settings-field">
                <label class="settings-label" for="horizon-input">Days to forecast</label>

                <!-- Quick presets — most owners want one of these, not a spinner -->
                <div class="settings-presets" role="group" aria-label="Quick ranges">
                    <?php foreach ([7 => '1 week', 14 => '2 weeks', 30 => '1 month', 60 => '2 months'] as $d => $lbl): ?>
                    <button type="button" class="settings-preset<?php echo $curHorizon === $d ? ' is-active' : ''; ?>"
                            data-days="<?php echo $d; ?>" onclick="pickHorizon(<?php echo $d; ?>)">
                        <span class="settings-preset-days"><?php echo $d; ?></span>
                        <span class="settings-preset-lbl"><?php echo $lbl; ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>

                <div class="settings-input-row">
                    <div class="settings-input-wrap">
                        <input type="number" id="horizon-input" class="settings-input" min="1" max="60"
                               value="<?php echo $curHorizon; ?>" oninput="syncHorizonPresets()">
                        <span class="settings-input-affix">days</span>
                    </div>
                    <!-- Disabled until the range differs from the saved one (syncHorizonPresets). -->
                    <button type="button" id="settings-save-btn" class="settings-save-btn" onclick="saveHorizon()" disabled>
                        Save &amp; re-forecast
                    </button>
                </div>
                <p class="settings-hint">Any value from 1 to 60 days. Default is 30.</p>
                <div id="settings-msg" class="settings-msg" style="display:none"></div>
            </div>

            <div class="settings-field settings-reminder-field">
                <label class="settings-label" for="reminder-input">Remind me to re-forecast after</label>
                <div class="settings-input-row">
                    <div class="settings-input-wrap">
                        <input type="number" id="reminder-input" class="settings-input" min="1" max="<?php echo $curHorizon; ?>"
                               value="<?php echo $curReminder; ?>" oninput="syncReminderSave()">
                        <span class="settings-input-affix">days</span>
                    </div>
                    <button type="button" id="reminder-save-btn" class="settings-save-btn" onclick="saveReminder()" disabled>
                        Save reminder
                    </button>
                </div>
                <p class="settings-hint">Counted from the first day of your forecast. It can't be longer than your forecast range. Default is 10.</p>
                <div id="reminder-msg" class="settings-msg" style="display:none"></div>
            </div>
        </div>

        <!-- ── Re-forecast the catalogue (manual action) ────────────────────── -->
        <div class="settings-card settings-card-secondary">
            <div class="settings-card-head">
                <p class="settings-eyebrow">Maintenance</p>
                <h2 class="settings-title">Re-forecast All Products</h2>
                <p class="settings-sub">
                    Rebuild every product's forecast now — for example after editing prices or events,
                    or to push the window back out to the full <?php echo (int) $curHorizon; ?> days.
                </p>
            </div>

            <div class="settings-run-row">
                <div class="settings-run-status">
                    <span class="settings-run-label">Last catalogue run</span>
                    <span class="settings-run-value" id="settings-last-run">
                        <?php
                        if (!$lastJob) {
                            echo 'Never run';
                        } elseif ($jobRunning) {
                            echo 'Running now — ' . (int) $lastJob['done'] . ' of ' . (int) $lastJob['total'] . ' done';
                        } elseif ($lastJob['status'] === 'failed') {
                            echo 'Stopped before finishing';
                        } else {
                            $ok = (int) $lastJob['total'] - (int) $lastJob['failed'];
                            echo $ok . ' of ' . (int) $lastJob['total'] . ' products · '
                               . date('M j, Y g:i A', strtotime($lastJob['finished_at'] ?? $lastJob['updated_at']));
                        }
                        ?>
                    </span>
                </div>
                <button type="button" id="reforecast-btn" class="settings-reforecast-btn"
                        onclick="confirmReforecastAll()"<?php echo $jobRunning ? ' disabled' : ''; ?>>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56"/><polyline points="21 3 21 9 15 9"/>
                    </svg>
                    <?php echo $jobRunning ? 'Run in progress…' : 'Re-forecast all products'; ?>
                </button>
            </div>

            <p class="settings-run-note">
                Runs in the background — you can keep using ProVendor while it works, and follow
                progress from the indicator in the corner.
            </p>
            <div id="reforecast-msg" class="settings-msg" style="display:none"></div>
        </div>

    </div><!-- /tab-content-forecast -->

    <?php if (SHOW_ACCURACY_FEATURES): ?>
    <!-- ════════════════════════════════════════════
         TAB: REPORTS  (merged from the old Reports page)
    ════════════════════════════════════════════ -->
    <div id="tab-content-reports" class="hidden">

        <!-- ── Catalogue Accuracy (PRIMARY — the system's own accuracy, tested
             automatically against each product's held-out recent history; no
             upload needed) ─────────────────────────────────────────────────── -->
        <?php
            $_ca      = $catalogueAccuracy;
            $_evald   = (int) $_ca['evaluated_count'];
            $_total   = (int) $_ca['total_count'];
            $_accPct  = $_ca['weighted_accuracy_pct'];
            $_hasData = $_accPct !== null;
            $_accTone = $_hasData
                ? ($_accPct >= 80 ? 'good' : ($_accPct >= 60 ? 'okay' : 'low'))
                : 'note';
        ?>
        <div class="catalogue-accuracy catalogue-accuracy-<?php echo $_accTone; ?>" id="catalogue-accuracy-card">
            <div class="catalogue-accuracy-head">
                <div>
                    <p class="catalogue-accuracy-eyebrow">Catalogue Accuracy</p>
                    <h2 class="catalogue-accuracy-title" id="ca-title">
                        <?php if ($_hasData): ?>
                            <strong><?php echo number_format($_accPct, 1); ?>%</strong>
                            <span class="catalogue-accuracy-title-sub">average forecast accuracy</span>
                        <?php else: ?>
                            <span class="catalogue-accuracy-title-sub">Testing your products&hellip;</span>
                        <?php endif; ?>
                    </h2>
                    <p class="catalogue-accuracy-sub" id="ca-sub">
                        <?php if ($_hasData): ?>
                            Volume-weighted across <strong><?php echo $_evald; ?></strong>
                            of <strong><?php echo $_total; ?></strong>
                            product<?php echo $_total !== 1 ? 's' : ''; ?>. High-volume products contribute more to this number.
                        <?php else: ?>
                            Automatically testing each product against its own recent sales history —
                            this can take a moment on your first visit.
                        <?php endif; ?>
                    </p>
                </div>
                <button type="button" class="ca-refresh-btn" id="ca-refresh-btn"
                        onclick="runCatalogueAccuracy(true)" title="Re-test every product">&#8635;</button>
            </div>

            <div class="catalogue-accuracy-grid" id="ca-grid" style="<?php echo $_hasData ? '' : 'display:none'; ?>">
                <div class="catalogue-accuracy-card">
                    <p class="catalogue-accuracy-label">
                        WAPE
                        <span class="info-tip" tabindex="0" role="note" aria-label="What WAPE means">i<span class="info-tip-bubble">Weighted Absolute Percentage Error: all the missed units added together, divided by all the units actually sold. Busy products count more and slow sellers do not distort it, so it is the fairest single figure for restocking. Lower is better.</span></span>
                    </p>
                    <p class="catalogue-accuracy-value" id="ca-wape"><?php echo $_hasData && $_ca['weighted_wape'] !== null ? number_format($_ca['weighted_wape'], 1) . '%' : ($_hasData ? '—' : ''); ?></p>
                    <p class="catalogue-accuracy-unit">total error &divide; total sales</p>
                </div>
                <div class="catalogue-accuracy-card">
                    <p class="catalogue-accuracy-label">
                        MAE
                        <span class="info-tip" tabindex="0" role="note" aria-label="What MAE means">i<span class="info-tip-bubble">Mean Absolute Error — average units off per day, in the same units as your sales. Easier to judge on a specific product than a percentage.</span></span>
                    </p>
                    <p class="catalogue-accuracy-value" id="ca-mae"><?php echo $_hasData && $_ca['weighted_mae'] !== null ? number_format($_ca['weighted_mae'], 1) : ''; ?></p>
                    <p class="catalogue-accuracy-unit">units/day off</p>
                </div>
            </div>
        </div>

        <!-- ── Per-Product Accuracy (kept for the weighted overall number; not shown — only the header card above is) ──── -->
        <div class="breakdown-panel" id="accuracy-breakdown-panel" style="display:none">
            <div class="breakdown-head">
                <div>
                    <p class="breakdown-eyebrow">Per-Product Accuracy</p>
                    <h2 class="breakdown-title">Which products affect the catalogue number most?</h2>
                    <p class="breakdown-sub">
                        Sorted by impact on the catalogue-weighted MAE (volume &times; absolute error).
                    </p>
                </div>
            </div>

            <div class="breakdown-table-wrap">
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th class="bk-col-product">Product</th>
                            <th class="bk-col-num">Avg/day</th>
                            <th class="bk-col-num">
                                WAPE
                                <span class="info-tip" tabindex="0" role="note" aria-label="What WAPE means">i<span class="info-tip-bubble">Weighted Absolute Percentage Error: all the missed units added together, divided by all the units actually sold. Busy products count more and slow sellers do not distort it, so it is the fairest single figure for restocking. Lower is better.</span></span>
                            </th>
                            <th class="bk-col-num">
                                MAE
                                <span class="info-tip" tabindex="0" role="note" aria-label="What MAE means">i<span class="info-tip-bubble">Mean Absolute Error — average units off per day, in the same units as your sales. Easier to judge on a specific product than a percentage.</span></span>
                            </th>
                            <th class="bk-col-status">Status</th>
                        </tr>
                    </thead>
                    <tbody id="accuracy-breakdown-tbody">
                        <?php foreach ($productBreakdown as $p): ?>
                        <?php
                            $totalUnits = (int) $p['total_units'];
                            $saleDays   = (int) $p['sale_days'];
                            $avgDaily   = $saleDays > 0 ? $totalUnits / $saleDays : null;

                            $pct   = $p['accuracy_pct'];
                            $evald = $pct !== null;
                            $tone  = !$evald
                                ? 'untested'
                                : ((float) $pct >= 80 ? 'good' : ((float) $pct >= 60 ? 'okay' : 'low'));
                            $toneLabel = [
                                'good'     => 'Good',
                                'okay'     => 'Fair',
                                'low'      => 'Poor',
                                'untested' => 'Untested',
                            ][$tone];
                        ?>
                        <tr class="bk-row bk-row-<?php echo $tone; ?>">
                            <td class="bk-col-product">
                                <span class="bk-product-name"><?php echo htmlspecialchars($p['name']); ?></span>
                                <?php if (!empty($p['category'])): ?>
                                <span class="bk-product-category"><?php echo htmlspecialchars($p['category']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="bk-col-num"><?php echo $avgDaily !== null ? number_format($avgDaily, 1) : '—'; ?></td>
                            <td class="bk-col-num"><?php echo $p['accuracy_wape'] !== null ? number_format((float) $p['accuracy_wape'], 1) . '%' : '—'; ?></td>
                            <td class="bk-col-num"><?php echo $p['accuracy_mae']  !== null ? number_format((float) $p['accuracy_mae'],  1) : '—'; ?></td>
                            <td class="bk-col-status"><span class="bk-status bk-status-<?php echo $tone; ?>"><?php echo $toneLabel; ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Backtest with your own data (SECONDARY — optional, uses data you
             upload for dates ProVendor doesn't have) ──────────────────────────── -->
        <div class="settings-card settings-card-secondary">
            <div class="settings-card-head">
                <p class="settings-eyebrow">Optional</p>
                <h2 class="settings-title">Backtest With Your Own Data</h2>
                <p class="settings-sub">
                    Upload actual sales for dates that aren&rsquo;t in ProVendor yet — a week you held
                    back, or results that came in after your last import. Each matching product trains
                    on its full existing history and predicts those dates; how close it gets is the
                    score below. Only your most recent run is kept — uploading again replaces it, or
                    clear it below.
                </p>
            </div>

            <div class="backtest-upload-row">
                <label class="backtest-file-label" for="backtest-file-input">
                    <span id="backtest-file-name">Choose a CSV file&hellip;</span>
                </label>
                <input type="file" id="backtest-file-input" accept=".csv" style="display:none"
                       onchange="onBacktestFileChosen(this)">
                <button type="button" id="backtest-run-btn" class="settings-save-btn" disabled
                        onclick="runBacktestUpload()">
                    Run Backtest
                </button>
            </div>
            <p class="settings-hint">
                Expects columns for product, date, and quantity — headers are auto-detected.
                Large catalogues may take a minute to test.
            </p>
            <div id="backtest-msg" class="settings-msg" style="display:none"></div>
            <p id="backtest-notes" class="settings-hint" style="display:none; margin-top:0.5rem;"></p>

            <div id="upload-result" style="<?php echo $backtestRun ? '' : 'display:none;'; ?> margin-top:1.1rem; padding-top:1.1rem; border-top:1px dashed rgba(38,31,14,0.15);">
                <div style="display:flex; align-items:baseline; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
                    <p class="settings-label" style="margin-bottom:0;">
                        Result
                        <span id="upload-timestamp" style="text-transform:none; font-weight:400; opacity:0.7;">
                            <?php echo $backtestRun ? '— ' . date('M j, Y g:i A', strtotime($backtestRun['created_at'])) : ''; ?>
                        </span>
                    </p>
                    <div id="upload-result-actions" style="display:flex; gap:0.6rem; flex-shrink:0;">
                        <a id="upload-download-link" href="<?php echo BASE_URL; ?>/api/download_backtest_csv.php"
                           style="font-size:0.72rem; font-weight:600; color:#261F0E; opacity:0.65; text-decoration:underline;">
                            Download data
                        </a>
                        <button type="button" id="upload-clear-btn" onclick="clearBacktest()"
                                style="font-size:0.72rem; font-weight:600; color:#c0392b; opacity:0.75; background:none; border:none; cursor:pointer; padding:0;">
                            Clear
                        </button>
                    </div>
                </div>
                <p id="upload-summary" class="settings-sub" style="margin-top:0.3rem;"></p>
                <div class="catalogue-accuracy-grid" style="margin-top:0.85rem; margin-bottom:0;">
                    <div class="catalogue-accuracy-card">
                        <p class="catalogue-accuracy-label">
                            WAPE
                            <span class="info-tip" tabindex="0" role="note" aria-label="What WAPE means">i<span class="info-tip-bubble">Weighted Absolute Percentage Error: all the missed units added together, divided by all the units actually sold. Busy products count more and slow sellers do not distort it, so it is the fairest single figure for restocking. Lower is better.</span></span>
                        </p>
                        <p class="catalogue-accuracy-value" id="ub-wape"></p>
                        <p class="catalogue-accuracy-unit">total error &divide; total sales</p>
                    </div>
                    <div class="catalogue-accuracy-card">
                        <p class="catalogue-accuracy-label">
                            MAE
                            <span class="info-tip" tabindex="0" role="note" aria-label="What MAE means">i<span class="info-tip-bubble">Mean Absolute Error — average units off per day, in the same units as your sales. Easier to judge on a specific product than a percentage.</span></span>
                        </p>
                        <p class="catalogue-accuracy-value" id="ub-mae"></p>
                        <p class="catalogue-accuracy-unit">units/day off</p>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /tab-content-reports -->
    <?php endif; ?>

</main>


<!-- ════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════ -->
<script>

// ── Tab switching ─────────────────────────────────────────────────────────────
var TABS = ['import', 'profile', 'forecast'<?php echo SHOW_ACCURACY_FEATURES ? ", 'reports'" : ''; ?>];
function switchTab(name) {
    TABS.forEach(function (t) {
        document.getElementById('tab-btn-' + t)    .classList.toggle('active', name === t);
        document.getElementById('tab-content-' + t).classList.toggle('hidden', name !== t);
    });
    window.location.hash = name;

    // First time the Reports tab is opened, quietly test any product that
    // doesn't have a cached accuracy figure yet — no button, no upload needed.
    if (name === 'reports' && typeof runCatalogueAccuracy === 'function' && !window.pvCatalogueAccuracyLoaded) {
        window.pvCatalogueAccuracyLoaded = true;
        runCatalogueAccuracy(false);
    }
}

// Restore the active tab on load.
// Default is My Profile. Switch away from it if:
//   - arriving from a successful import (?imported=1) → Sales Data, or
//   - the URL hash explicitly requests a tab (#import / #forecast / #profile / #reports).
document.addEventListener('DOMContentLoaded', function() {
    var fromImport = window.location.search.indexOf('imported=1') !== -1;
    var hash       = window.location.hash.replace('#', '');

    if (fromImport || hash === 'import') {
        switchTab('import');
    } else if (TABS.indexOf(hash) !== -1) {
        switchTab(hash);
    }
    wRestoreState();

    <?php if (SHOW_ACCURACY_FEATURES && $backtestRun): ?>
    renderUploadResult({
        catalogue: {
            evaluated_count:       <?php echo (int) $backtestRun['evaluated_count']; ?>,
            total_count:           <?php echo (int) $backtestRun['total_count']; ?>,
            weighted_wape:         <?php echo $backtestRun['weighted_wape']         !== null ? (float) $backtestRun['weighted_wape']         : 'null'; ?>,
            weighted_mae:          <?php echo $backtestRun['weighted_mae']          !== null ? (float) $backtestRun['weighted_mae']          : 'null'; ?>,
            weighted_accuracy_pct: <?php echo $backtestRun['weighted_accuracy_pct'] !== null ? (float) $backtestRun['weighted_accuracy_pct'] : 'null'; ?>
        }
    });
    <?php endif; ?>
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
    document.getElementById('delete-data-password').value = '';
    document.getElementById('delete-data-feedback').classList.add('hidden');
    document.getElementById('delete-data-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeDeleteDataModal() {
    document.getElementById('delete-data-modal').classList.add('hidden');
    document.body.style.overflow = '';
}

async function submitDeleteData() {
    var password = document.getElementById('delete-data-password').value;

    if (!password) {
        showProfileFeedback('delete-data-feedback', 'error', 'Please enter your password.');
        return;
    }

    var btn = document.getElementById('delete-data-confirm-btn');
    btn.textContent = 'Deleting…';
    btn.disabled    = true;

    var formData = new FormData();
    formData.append('password', password);

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/clear_data.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.success) {
            window.location = '<?php echo BASE_URL; ?>/pages/landing.view.php';
        } else {
            showProfileFeedback('delete-data-feedback', 'error', data.error || 'Delete failed.');
            btn.textContent = 'Delete Everything';
            btn.disabled    = false;
        }
    } catch (e) {
        showProfileFeedback('delete-data-feedback', 'error', 'Network error. Please try again.');
        btn.textContent = 'Delete Everything';
        btn.disabled    = false;
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// IMPORT WIZARD (unchanged)
// ══════════════════════════════════════════════════════════════════════════════

// "Replace Sales Data" menu: manual upload or Google Sheets.
(function () {
    var toggle = document.getElementById('data-source-toggle');
    var menu   = document.getElementById('data-source-menu');
    if (!toggle) return;   // no menu while a Google Sheet is connected

    function setOpen(open) {
        menu.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        setOpen(menu.hidden);
    });
    document.getElementById('data-source-upload').addEventListener('click', function () { openWizard(); });
    document.getElementById('data-source-sheets').addEventListener('click', function () { openSheetsDialog(); });
    document.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
}());

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
    document.getElementById('w-drop-text').textContent = 'Drop your CSV or Excel file here, or click to browse';
    document.getElementById('w-csv-file').value = '';
    
    var dfSel = document.getElementById('w-date-format-select');
    if (dfSel) { dfSel.value = 'auto'; dfSel.disabled = false; }

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
    if (!/\.(csv|xlsx)$/i.test(file.name)) {
        document.getElementById('w-drop-text').textContent = 'Only .csv and .xlsx files are accepted';
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
    // Reject before PHP's POST limit can turn the response into an error page.
    if (file.size > <?php echo MAX_IMPORT_BYTES; ?>) {
        alert(<?php echo json_encode(IMPORT_FILE_TOO_LARGE_MESSAGE); ?>);
        return;
    }

    var btn = document.getElementById('w-upload-btn');
    btn.textContent = 'Detecting…';
    btn.disabled    = true;

    var formData = new FormData();
    formData.append('csv', file);

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/detect.php', { method: 'POST', body: formData });
        if (res.status === 413) {
            alert(<?php echo json_encode(IMPORT_FILE_TOO_LARGE_MESSAGE); ?>);
            return;
        }
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
    { key: 'category',    label: 'Category',             required: false, multiple: true },
    { key: 'cost',       label: 'Cost Price',           required: false },
    { key: 'price',       label: 'Selling Price',        required: false },
];

var wHeaders     = [];
var wSample      = [];
var wRowCount    = 0;
var wAssignments = {};
var wDateFormats = {};   // column name → what the server worked out about its dates
var wPending     = null;
var wCurrentStep = 1;
var wDroppedFile = null; // holds File from drag-and-drop (file input can't receive dragged files)

var WZ_KEY = 'pv_import_wizard_v2';

function wSaveState() {
    if (!wHeaders.length) return;
    try {
        localStorage.setItem(WZ_KEY, JSON.stringify({
            headers:     wHeaders,
            sample:      wSample,
            rowCount:    wRowCount,
            assignments: wAssignments,
            dateFormats: wDateFormats,
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
        wDateFormats = state.dateFormats || {};

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
    wDateFormats = data.date_formats || {};

    W_FIELDS.forEach(function(f) {
        [].concat(data.suggestions[f.key] || []).forEach(function(suggested) {
            var idx = wHeaders.indexOf(suggested);
            if (idx === -1) return;
            if (f.multiple) wAssignments[f.key] = (wAssignments[f.key] || []).concat(idx);
            else            wAssignments[f.key] = idx;
        });
    });

    buildColumnTable();

    var colWord = wHeaders.length + ' column' + (wHeaders.length !== 1 ? 's' : '');
    var rowWord = wRowCount.toLocaleString() + ' row' + (wRowCount !== 1 ? 's' : '');
    document.getElementById('w-file-name').textContent         = colWord + ' · ' + rowWord + ' total';
    document.getElementById('w-granularity-badge').textContent = wSample.length + ' sample rows shown';

    wSaveState();
}

// Locks the Date Format selector when every date in the assigned column proves
// the format. Otherwise the owner has to choose it.
function wApplyDateLock() {
    var sel   = document.getElementById('w-date-format-select');
    var hint  = document.getElementById('w-date-format-hint');
    var auto  = sel.querySelector('option[value="auto"]');
    var info  = wDateFormats[wHeaders[wAssignments.date]] || {};
    var sure  = !!info.format && !info.ambiguous;
    var unassigned = wAssignments.date === undefined;

    if (sure) {
        var option = sel.querySelector('option[value="' + info.format + '"]');
        auto.textContent = 'Auto-detected: ' + (option ? option.textContent.split(' (')[0] : 'written-out dates');
        sel.value = 'auto';
    } else {
        auto.textContent = unassigned ? 'Auto-detect' : 'Choose a format…';
    }
    sel.disabled = sure;

    var mustChoose = !sure && !unassigned && sel.value === 'auto';
    hint.hidden = !mustChoose;
    hint.textContent = mustChoose ? 'We can\'t tell from your dates. Please choose the format.' : '';
}

// The server refused the date format: clear it so the owner has to choose again.
function wRejectDateFormat(message) {
    var sel  = document.getElementById('w-date-format-select');
    var hint = document.getElementById('w-date-format-hint');
    sel.value = 'auto';
    wApplyDateLock();
    hint.textContent = message;
    hint.hidden = false;
    sel.focus();
    showMappingError(message);
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
    wApplyDateLock();
}

function isColAssigned(colIdx) {
    return getAssignedField(colIdx) !== null;
}

function getAssignedField(colIdx) {
    var keys = Object.keys(wAssignments);
    for (var k = 0; k < keys.length; k++) {
        if ([].concat(wAssignments[keys[k]]).indexOf(colIdx) !== -1) return keys[k];
    }
    return null;
}

function wUnassignColumn(colIdx) {
    var field = getAssignedField(colIdx);
    if (!field) return;
    if (Array.isArray(wAssignments[field])) {
        wAssignments[field] = wAssignments[field].filter(function(i) { return i !== colIdx; });
        if (wAssignments[field].length) return;
    }
    delete wAssignments[field];
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
        wUnassignColumn(colIdx);
        buildColumnTable();
        wSaveState();
        wClearPreflight();
        return;
    }

    // Category can be set on any number of columns, kept in column order.
    if (fieldKey === 'category') {
        wUnassignColumn(colIdx);
        wAssignments.category = (wAssignments.category || []).concat(colIdx).sort(function(a, b) { return a - b; });
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
                wUnassignColumn(wPending.colIdx);
                wAssignments[wPending.fieldKey] = wPending.colIdx;
                wPending = null;
                buildColumnTable();
                wSaveState();
            }
        });
        return;
    }

    wUnassignColumn(colIdx);

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
        var col = wAssignments[fieldKey];
        mapping[fieldKey] = Array.isArray(col) ? col.map(function(i) { return wHeaders[i]; }) : wHeaders[col];
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
        ImportPreview.confirmUnresolved(function () { wDoImport(wMappingCache); });
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
            if (data.code === 'date_format') wRejectDateFormat(data.error);
            else showMappingError('Check failed: ' + data.error);
            btn.innerHTML = IMPORT_BTN_HTML;
            btn.disabled  = false;
            return;
        }

        // Always show the editable preview so the user can see + tweak every
        // row before anything commits.
        wRenderPreviewCard(data);
        wPreflightDone = true;
    } catch(e) {
        showMappingError('Network error during check. Please try again.');
        btn.innerHTML = IMPORT_BTN_HTML;
        btn.disabled  = false;
    }
}

// The review step itself lives in assets/global_js/import_preview.js.
function wRenderPreviewCard(data) {
    ImportPreview.render('w-preflight-container', data, wRefreshImportButton);
}

function wRefreshImportButton(counts) {
    var btn = document.getElementById('w-import-btn');
    if (counts.importable === 0) {
        btn.innerHTML     = 'No valid rows to import';
        btn.disabled      = true;
        btn.style.opacity = '0.55';
    } else {
        btn.innerHTML     = 'Replace dataset <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
        btn.disabled      = false;
        btn.style.opacity = '1';
    }
}

async function wDoImport(mapping, replace) {
    var btn = document.getElementById('w-import-btn');
    btn.textContent = 'Importing…';
    btn.disabled    = true;

    var counts  = ImportPreview.counts();
    var leftOut = counts.invalid + counts.dropped;

    var formData = new FormData();
    formData.append('rows',     JSON.stringify(ImportPreview.payload()));
    formData.append('csv_rows', wRowCount);

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/import.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.success) {
            wClearState();
            // A re-import is a dataset update — re-forecast the catalogue at the
            // saved horizon before returning to My Store.
            var redirect = '<?php echo BASE_URL; ?>/pages/import.view.php?imported=1&rows=' + data.rows + '&replaced=' + (data.replaced || 0) + '&skipped=' + (data.skipped || 0) + '&dropped=' + ((data.dropped || 0) + leftOut) + '&csv_rows=' + (data.csv_rows || 0);
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

// A re-import is a dataset update, so the catalogue needs re-forecasting — but
// that's handed to the BACKGROUND worker and we redirect straight away. The
// progress pill reports it from wherever the owner goes next.
// Best-effort: if the job can't be started we still continue to My Store.
async function reforecastAfterImport(redirectUrl) {
    try {
        await fetch('<?php echo BASE_URL; ?>/api/start_forecast_job.php', {
            method: 'POST',
            body:   new FormData(),
        });
    } catch (e) { /* the owner can re-run it from Settings */ }
    window.location = redirectUrl;
}

function downloadSampleCsv(e) {
    e.stopPropagation();
    var rows = [
        ['date',       'product_name',  'quantity_sold', 'category',  'cost_price', 'selling_price', 'sku'],
        ['2024-01-15', 'Pepsi 500ml',   '24',            'Beverages', '12.50',      '18.00',         'PEP-500'],
        ['2024-01-15', 'Lays Original', '15',            'Snacks',    '8.00',       '12.50',         'LAYS-ORG'],
        ['2024-01-16', 'Pepsi 500ml',   '18',            'Beverages', '12.50',      '18.00',         'PEP-500'],
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
        message:      'Your sales data and prices will be replaced with the snapshot from "' + label + '". ' +
                      'The current state is auto-saved first, so you can undo this restore from the history. Your forecast will be re-run from this version.',
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
            reforecastAfterImport('<?php echo BASE_URL; ?>/pages/import.view.php?restored=' + (data.prices_restored ? '1' : 'noprices') + '#import');
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
// Both actions here hand the work to the background worker via
// api/start_forecast_job.php — the floating pill reports progress.
// ══════════════════════════════════════════════════════════════════════════════
function _settingsMsg(type, text) {
    var msg = document.getElementById('settings-msg');
    msg.className = 'settings-msg settings-msg-' + type;
    msg.textContent = text;
    msg.style.display = '';
}

// What's saved right now — the Save buttons stay off until the inputs differ.
var _savedHorizon  = <?php echo (int) $curHorizon; ?>;
var _savedReminder = <?php echo (int) $curReminder; ?>;

// Quick-pick preset → fills the number input.
function pickHorizon(days) {
    var inp = document.getElementById('horizon-input');
    if (inp) inp.value = days;
    syncHorizonPresets();
}

// Highlights whichever preset matches the current value (none, if it's custom).
function syncHorizonPresets() {
    var val = parseInt((document.getElementById('horizon-input') || {}).value, 10);
    document.querySelectorAll('.settings-preset').forEach(function (b) {
        b.classList.toggle('is-active', parseInt(b.dataset.days, 10) === val);
    });
    document.getElementById('settings-save-btn').disabled = (val === _savedHorizon);
}

function syncReminderSave() {
    var val = parseInt(document.getElementById('reminder-input').value, 10);
    document.getElementById('reminder-save-btn').disabled = (val === _savedReminder);
}

// Save the range, then hand the catalogue re-forecast to the background worker.
function saveHorizon() {
    var days = parseInt(document.getElementById('horizon-input').value, 10);
    if (isNaN(days) || days < 1 || days > 60) {
        _settingsMsg('error', 'Enter a number of days between 1 and 60.');
        return;
    }
    if (days === _savedHorizon) return;

    var btn = document.getElementById('settings-save-btn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    var body = new FormData();
    body.append('horizon_days', days);   // start_forecast_job saves the horizon too

    fetch('<?php echo BASE_URL; ?>/api/start_forecast_job.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.textContent = 'Save & re-forecast';
            if (data.error) { _settingsMsg('error', data.error); return; }

            var cur = document.getElementById('settings-current-days');
            if (cur) cur.textContent = days + ' days ahead';

            _savedHorizon = days;
            syncHorizonPresets();

            // A reminder can't outlast the range it belongs to.
            var rem = document.getElementById('reminder-input');
            rem.max = days;
            if (parseInt(rem.value, 10) > days) rem.value = days;
            _savedReminder = Math.min(_savedReminder, days);
            syncReminderSave();

            _settingsMsg('success', data.already
                ? 'Range saved. A forecast run is already in progress.'
                : 'Range saved. Re-forecasting ' + (data.total || '') + ' products in the background — you can keep working.');
            if (window.pvWatchForecastJob) window.pvWatchForecastJob();
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Save & re-forecast';
            _settingsMsg('error', 'Network error. Please try again.');
        });
}

// ── Re-forecast reminder ────────────────────────────────────────────────────
function saveReminder() {
    var input = document.getElementById('reminder-input');
    var days  = parseInt(input.value, 10);
    var max   = parseInt(input.max, 10);
    if (isNaN(days) || days < 1 || days > max) {
        _msg('reminder-msg', 'error', 'Enter a number of days between 1 and ' + max + '.');
        return;
    }
    if (days === _savedReminder) return;

    var btn = document.getElementById('reminder-save-btn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    var body = new FormData();
    body.append('reminder_days', days);

    fetch('<?php echo BASE_URL; ?>/api/update_forecast_reminder.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.textContent = 'Save reminder';
            if (data.error) {
                btn.disabled = false;
                _msg('reminder-msg', 'error', data.error);
                return;
            }
            _savedReminder = days;
            syncReminderSave();
            _msg('reminder-msg', 'success', 'Reminder saved.');
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Save reminder';
            _msg('reminder-msg', 'error', 'Network error. Please try again.');
        });
}

// ── Re-forecast all products ────────────────────────────────────────────────
function confirmReforecastAll() {
    showConfirm({
        title:        'Re-forecast every product?',
        message:      'ProVendor will rebuild the demand forecast and restock recommendation for every product in your catalogue, replacing the current ones. This runs in the background, so you can keep using the app while it works.',
        confirmText:  'Re-forecast all',
        confirmStyle: 'primary',
        onConfirm:    doReforecastAll,
    });
}

function doReforecastAll() {
    var btn = document.getElementById('reforecast-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Starting…'; }

    fetch('<?php echo BASE_URL; ?>/api/start_forecast_job.php', { method: 'POST', body: new FormData() })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                if (btn) { btn.disabled = false; btn.textContent = 'Re-forecast all products'; }
                _msg('reforecast-msg', 'error', data.error);
                return;
            }
            if (btn) btn.textContent = 'Run in progress…';
            var last = document.getElementById('settings-last-run');
            if (last) last.textContent = 'Running now — 0 of ' + (data.total || 0) + ' done';
            _msg('reforecast-msg', 'success', data.already
                ? 'A forecast run is already in progress.'
                : 'Re-forecasting ' + data.total + ' products in the background.');
            if (window.pvWatchForecastJob) window.pvWatchForecastJob();
        })
        .catch(function () {
            if (btn) { btn.disabled = false; btn.textContent = 'Re-forecast all products'; }
            _msg('reforecast-msg', 'error', 'Network error. Please try again.');
        });
}

function _msg(id, type, text) {
    var el = document.getElementById(id);
    if (!el) return;
    el.className = 'settings-msg settings-msg-' + type;
    el.textContent = text;
    el.style.display = '';
}


// ══════════════════════════════════════════════════════════════════════════════
// GOOGLE SHEETS
// The dialog (includes/sheets_modal.php) validates the link and hands back the
// same payload shape api/detect.php returns — so a sheet import rejoins the
// wizard at step 2 and walks the identical mapping → preview → commit path.
// ══════════════════════════════════════════════════════════════════════════════

window.pvSheetsOnValidated = function (data) {
    // The wizard's saved localStorage state describes whatever ran last; a stale
    // restore over the sheet's columns would silently mis-map them.
    wClearState();

    var panel = document.getElementById('wizard-panel');
    panel.classList.remove('hidden');

    var head = panel.querySelector('.wizard-header-text');
    if (head) {
        head.querySelector('h2').textContent = 'Import from Google Sheets';
        head.querySelector('p').textContent  =
            'Reading “' + (data.sheet ? data.sheet.title : 'your sheet') + '”. Map its columns to proceed.';
    }

    var step1Label = document.querySelector('#wdot-1 .lbl');
    if (step1Label) step1Label.textContent = 'Read Sheet';

    wPopulateMappingUI(data);
    wGoToStep(2);
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

// ── Manual refresh ("Update Data", shown when auto-refresh is off) ────────────
function sheetUpdateNow() {
    var btn = document.getElementById('sheet-update-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Updating…'; }

    var body = new FormData();
    body.append('force', '1');   // manual press ignores the 5-minute throttle

    fetch('<?php echo BASE_URL; ?>/api/sheets_sync.php', { method: 'POST', body: body })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            restoreUpdateBtn();
            if (data.error) { setSheetStatus(true, data.error); return; }

            var moved = (data.added || 0) + (data.updated || 0);
            setSheetStatus(false, moved > 0
                ? 'Refreshed just now — ' + data.added + ' added, ' + data.updated + ' updated.'
                : 'Refreshed just now — nothing had changed.');

            // New rows change every figure on this page, so reload rather than
            // leave the summary cards contradicting the status line.
            if (moved > 0) setTimeout(function () { window.location.reload(); }, 1200);
        })
        .catch(function () {
            restoreUpdateBtn();
            setSheetStatus(true, 'Could not reach ProVendor. Check your connection and try again.');
        });
}

function restoreUpdateBtn() {
    var btn = document.getElementById('sheet-update-btn');
    if (!btn) return;
    btn.disabled  = false;
    btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"/><polyline points="21 3 21 9 15 9"/></svg> Update Data';
}

function setSheetStatus(isError, text) {
    var wrap = document.getElementById('sheet-status');
    var el   = document.getElementById('sheet-status-text');
    if (!wrap || !el) return;
    wrap.classList.toggle('is-error', !!isError);
    el.textContent = text;
}

// Lets the background heartbeat (includes/sheets_autosync.php) report into the
// card when the owner happens to be sitting on this page.
window.pvOnSheetSync = function (data) {
    if (data.error) { setSheetStatus(true, data.error); return; }
    if (data.skipped_recent) return;

    var moved = (data.added || 0) + (data.updated || 0);
    setSheetStatus(false, moved > 0
        ? 'Refreshed just now — ' + data.added + ' added, ' + data.updated + ' updated.'
        : 'Refreshed just now — nothing had changed.');
};

// ── Auto-refresh toggle ──────────────────────────────────────────────────────
function toggleSheetAutoSync() {
    var sw      = document.getElementById('sheet-autosync-switch');
    var updates = document.getElementById('sheet-update-btn');
    var turnOn  = !sw.classList.contains('is-on');

    sw.disabled = true;

    var body = new FormData();
    body.append('enabled', turnOn ? '1' : '0');

    fetch('<?php echo BASE_URL; ?>/api/sheets_autosync.php', { method: 'POST', body: body })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            sw.disabled = false;
            if (data.error) { setSheetStatus(true, data.error); return; }

            sw.classList.toggle('is-on', data.auto_sync);
            sw.setAttribute('aria-checked', data.auto_sync ? 'true' : 'false');

            // Manual refresh only earns its place when nothing refreshes for you.
            if (updates) updates.style.display = data.auto_sync ? 'none' : '';

            // The heartbeat lives on this page too — start or stop it to match,
            // instead of waiting for a reload.
            if (window.pvSheetHeartbeat) window.pvSheetHeartbeat(data.auto_sync);
        })
        .catch(function () {
            sw.disabled = false;
            setSheetStatus(true, 'Could not change the refresh setting. Please try again.');
        });
}

// ── Disconnect ───────────────────────────────────────────────────────────────
function notifySheetConnected() {
    showConfirm({
        title:        'Disconnect your Google Sheet first',
        message:      'Your sales data is coming from a connected Google Sheet. Disconnect it before '
                    + 'uploading a file or connecting another sheet. '
                    + 'Sales already brought in from the sheet stay exactly as they are.',
        confirmText:  'Disconnect',
        confirmStyle: 'danger',
        onConfirm:    doSheetUnlink,
    });
}

function confirmSheetUnlink() {
    showConfirm({
        title:        'Disconnect this sheet?',
        message:      'ProVendor will stop reading it, and CSV import becomes available again. '
                    + 'Sales already brought in from the sheet stay exactly as they are.',
        confirmText:  'Disconnect',
        confirmStyle: 'danger',
        onConfirm:    doSheetUnlink,
    });
}

function doSheetUnlink() {
    fetch('<?php echo BASE_URL; ?>/api/sheets_unlink.php', { method: 'POST' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.error) { setSheetStatus(true, data.error); return; }
            window.location = '<?php echo BASE_URL; ?>/pages/import.view.php#import';
            window.location.reload();
        })
        .catch(function () {
            setSheetStatus(true, 'Could not disconnect the sheet. Please try again.');
        });
}

<?php if (SHOW_ACCURACY_FEATURES): ?>
// ══════════════════════════════════════════════════════════════════════════════
// REPORTS TAB — primary (automatic) + optional (uploaded-data) backtests
// ══════════════════════════════════════════════════════════════════════════════

// ── Primary: automatic backtest against each product's own held-out history ──
function runCatalogueAccuracy(forceRefresh) {
    var btn = document.getElementById('ca-refresh-btn');
    btn.disabled = true;
    btn.classList.add('spinning');

    var body = new FormData();
    if (forceRefresh) body.append('refresh', '1');

    fetch('<?php echo BASE_URL; ?>/api/run_catalogue_accuracy.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { showAccuracyError(data.error); return; }

            var ca      = data.catalogue;
            var card    = document.getElementById('catalogue-accuracy-card');
            var hasData = ca.weighted_accuracy_pct !== null;
            var tone    = !hasData ? 'note' : (ca.weighted_accuracy_pct >= 80 ? 'good' : (ca.weighted_accuracy_pct >= 60 ? 'okay' : 'low'));

            card.className = 'catalogue-accuracy catalogue-accuracy-' + tone;

            document.getElementById('ca-title').innerHTML = hasData
                ? '<strong>' + ca.weighted_accuracy_pct.toFixed(1) + '%</strong> <span class="catalogue-accuracy-title-sub">average forecast accuracy</span>'
                : '<span class="catalogue-accuracy-title-sub">No products could be tested yet</span>';

            document.getElementById('ca-sub').innerHTML = hasData
                ? 'Volume-weighted across <strong>' + ca.evaluated_count + '</strong> of <strong>' + ca.total_count + '</strong> product' +
                  (ca.total_count !== 1 ? 's' : '') + '. High-volume products contribute more to this number.'
                : 'Products need at least ~7 weeks of sales history before they can be tested.';

            document.getElementById('ca-grid').style.display = hasData ? '' : 'none';
            if (hasData) {
                document.getElementById('ca-wape').textContent = ca.weighted_wape != null ? ca.weighted_wape.toFixed(1) + '%' : '—';
                document.getElementById('ca-mae').textContent  = ca.weighted_mae != null ? ca.weighted_mae.toFixed(1) : '—';
            }

        })
        .catch(function () { showAccuracyError('The accuracy test could not finish. Please try again.'); })
        .finally(function () {
            btn.disabled = false;
            btn.classList.remove('spinning');
        });
}

function showAccuracyError(message) {
    document.getElementById('catalogue-accuracy-card').className = 'catalogue-accuracy catalogue-accuracy-low';
    document.getElementById('ca-title').innerHTML = '<span class="catalogue-accuracy-title-sub">Accuracy test failed</span>';
    document.getElementById('ca-sub').textContent = message;
    document.getElementById('ca-grid').style.display = 'none';
}

// ── Optional: backtest against data the user uploads for dates ProVendor doesn't have ──
function onBacktestFileChosen(input) {
    var file = input.files && input.files[0];
    document.getElementById('backtest-file-name').textContent = file ? file.name : 'Choose a CSV file…';
    document.getElementById('backtest-run-btn').disabled = !file;
}

function runBacktestUpload() {
    var input = document.getElementById('backtest-file-input');
    var file  = input.files && input.files[0];
    if (!file) return;

    var btn   = document.getElementById('backtest-run-btn');
    var msg   = document.getElementById('backtest-msg');
    var notes = document.getElementById('backtest-notes');
    btn.disabled    = true;
    btn.textContent = 'Running…';
    msg.style.display   = 'none';
    notes.style.display = 'none';

    var body = new FormData();
    body.append('csv', file);

    fetch('<?php echo BASE_URL; ?>/api/run_backtest_upload.php', { method: 'POST', body: body })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled    = false;
            btn.textContent = 'Run Backtest';

            if (data.error) {
                showBacktestMsg(true, data.error);
                return;
            }

            renderUploadResult(data, '— just now');

            var noteParts = [];
            if (data.rows_dropped) {
                noteParts.push(data.rows_dropped + ' row' + (data.rows_dropped !== 1 ? 's' : '') + ' skipped (missing or invalid date/quantity).');
            }
            if (data.unmatched_products && data.unmatched_products.length) {
                noteParts.push(data.unmatched_products.length + ' product name' + (data.unmatched_products.length !== 1 ? 's' : '') +
                    " didn't match your catalogue: " + data.unmatched_products.slice(0, 5).join(', ') +
                    (data.unmatched_products.length > 5 ? ', …' : '') + '.');
            }
            if (data.skipped && data.skipped.length) {
                noteParts.push(data.skipped.length + ' matched product' + (data.skipped.length !== 1 ? 's' : '') + " couldn't be tested: " +
                    data.skipped.slice(0, 3).map(function (s) { return s.name + ' (' + s.reason + ')'; }).join('; ') +
                    (data.skipped.length > 3 ? ', …' : '') + '.');
            }
            if (noteParts.length) {
                notes.textContent   = noteParts.join(' ');
                notes.style.display = '';
            }

            if (!data.products.length) {
                showBacktestMsg(false, 'No products could be tested from this file — see the notes above.');
            } else {
                showBacktestMsg(false, 'Tested ' + data.products.length + ' of ' + data.catalogue.total_count +
                    ' matched product' + (data.catalogue.total_count !== 1 ? 's' : '') + '.');
            }
        })
        .catch(function () {
            btn.disabled    = false;
            btn.textContent = 'Run Backtest';
            showBacktestMsg(true, 'Could not run the backtest. Please try again.');
        });
}

function showBacktestMsg(isError, text) {
    var msg = document.getElementById('backtest-msg');
    msg.textContent    = text;
    msg.className      = 'settings-msg ' + (isError ? 'settings-msg-error' : 'settings-msg-success');
    msg.style.display  = '';
}

function renderUploadResult(data, timestampText) {
    var ca      = data.catalogue;
    var result  = document.getElementById('upload-result');
    var summary = document.getElementById('upload-summary');
    var grid    = result.querySelector('.catalogue-accuracy-grid');

    result.style.display = '';
    document.getElementById('upload-result-actions').style.display = '';
    if (timestampText !== undefined) {
        document.getElementById('upload-timestamp').textContent = timestampText;
    }

    if (!ca.evaluated_count) {
        summary.textContent = 'No matched products could be tested.';
        grid.style.display  = 'none';
        return;
    }

    summary.innerHTML = '<strong>' + ca.weighted_accuracy_pct.toFixed(1) + '%</strong> average accuracy — volume-weighted across ' +
        ca.evaluated_count + ' of ' + ca.total_count + ' matched product' + (ca.total_count !== 1 ? 's' : '') + '.';

    grid.style.display = '';
    document.getElementById('ub-wape').textContent = ca.weighted_wape != null ? ca.weighted_wape.toFixed(1) + '%' : '—';
    document.getElementById('ub-mae').textContent  = ca.weighted_mae != null ? ca.weighted_mae.toFixed(1) : '—';
}

// ── Clear the saved run (both the DB row and the CSV kept for re-download) ──
function clearBacktest() {
    showConfirm({
        title:        'Clear this backtest?',
        message:      'This removes the saved result and the uploaded file. It can\'t be undone — you\'d need to upload the CSV again.',
        confirmText:  'Clear',
        confirmStyle: 'danger',
        onConfirm:    function () {
            fetch('<?php echo BASE_URL; ?>/api/clear_backtest.php', { method: 'POST' })
                .then(function (r) { return r.json(); })
                .then(function () {
                    document.getElementById('upload-result').style.display = 'none';
                    document.getElementById('backtest-msg').style.display  = 'none';
                    document.getElementById('backtest-notes').style.display = 'none';
                });
        },
    });
}
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/sheets_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
