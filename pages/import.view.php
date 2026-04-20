<?php
// pages/import.view.php
// Import Data + Profile page — two tabs in one place.

require_once __DIR__ . '/import.logic.php';

$pageTitle = 'ProVendor — My Store';
$pageCss   = 'import.css';
require_once __DIR__ . '/../includes/header.php';
?>
<body class="bg-[#F0E8D0] min-h-screen dot-pattern-light">

<!-- Leaflet (needed for the profile map) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- ════════════════════════════════════════════
     MAIN
════════════════════════════════════════════ -->
<main class="max-w-5xl mx-auto px-6 py-8">

    <!-- Page heading -->
    <div class="mb-1">
        <h1 class="text-2xl font-semibold text-[#261F0E] tracking-tight">My Store</h1>
        <p class="text-sm text-[#261F0E] mt-1" style="opacity:0.5">
            Manage your sales data and account settings.
        </p>
    </div>

    <!-- Tab bar -->
    <div class="tab-bar">
        <button id="tab-btn-import"  class="tab-btn"        onclick="switchTab('import')">Sales Data</button>
        <button id="tab-btn-profile" class="tab-btn active" onclick="switchTab('profile')">My Profile</button>
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
                        echo implode(', ', $parts) . '.';
                    ?>
                </span>
            </div>
            <?php if ($csvRows > 0 && $csvRows !== ($rows + $replaced + $skipped)): ?>
            <p style="font-size:0.78rem; font-weight:400; opacity:0.75; margin:0;">
                <?php echo number_format($csvRows); ?> CSV rows were processed into <?php echo number_format($rows + $replaced); ?> daily records.
                Transactions for the same product on the same date are aggregated into a single daily total, as required by the forecasting model.
            </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

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
                <div class="summary-card-value"><?php echo number_format($summary['total_sessions']); ?></div>
                <div class="summary-card-label">Import Sessions</div>
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

        <!-- ── Sessions list ── -->
        <div class="section-header">
            <span class="section-title">Import History</span>
            <button onclick="openWizard()" class="upload-btn-primary">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Upload New Data
            </button>
        </div>

        <div class="sessions-list" id="sessions-list">
            <?php if (empty($sessions)): ?>
            <div class="sessions-empty">No imports yet. Upload your first CSV to get started.</div>
            <?php else: ?>
                <?php foreach ($sessions as $s): ?>
                <div class="session-entry" id="session-<?php echo $s['id']; ?>">

                    <div class="session-row">

                        <div class="session-icon">
                            <svg class="w-4 h-4 text-[#261F0E]" style="opacity:0.4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                            </svg>
                        </div>

                        <span class="session-filename" title="<?php echo htmlspecialchars($s['filename']); ?>">
                            <?php echo htmlspecialchars($s['filename']); ?>
                        </span>

                        <div class="session-meta">
                            <?php if ($s['granularity']): ?>
                            <span class="session-badge"><?php echo htmlspecialchars($s['granularity']); ?></span>
                            <?php endif; ?>
                            <?php if ($s['date_from'] && $s['date_to']): ?>
                            <span class="session-daterange">
                                <?php
                                    $df = date('M Y', strtotime($s['date_from']));
                                    $dt = date('M Y', strtotime($s['date_to']));
                                    echo $df === $dt ? $df : $df . ' – ' . $dt;
                                ?>
                            </span>
                            <?php endif; ?>
                            <span class="session-rows"><?php echo number_format($s['row_count']); ?> records</span>
                            <span class="session-date"><?php echo date('M j, Y', strtotime($s['imported_at'])); ?></span>
                        </div>

                        <button id="records-toggle-<?php echo $s['id']; ?>"
                                class="session-view-btn"
                                onclick="toggleRecords(<?php echo $s['id']; ?>)"
                                title="View records">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9"/>
                            </svg>
                        </button>

                        <button class="session-delete-btn"
                                onclick="confirmDeleteSession(<?php echo $s['id']; ?>, '<?php echo htmlspecialchars(addslashes($s['filename'])); ?>')"
                                title="Delete this import">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                <path d="M10 11v6"/><path d="M14 11v6"/>
                                <path d="M9 6V4h6v2"/>
                            </svg>
                        </button>

                    </div>

                    <div class="session-records-panel hidden" id="records-panel-<?php echo $s['id']; ?>"></div>

                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div><!-- /tab-content-import -->


    <!-- ════════════════════════════════════════════
         TAB: MY PROFILE
    ════════════════════════════════════════════ -->
    <div id="tab-content-profile">

        <!-- 2-column grid: Account + Password (left) | Store (right) -->
        <div class="grid grid-cols-2 gap-6 items-start mb-6">

            <!-- LEFT COLUMN -->
            <div class="flex flex-col gap-6">

                <!-- Account Card -->
                <div class="profile-card">
                    <p class="profile-card-title">Account</p>

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

                <!-- Change Password Card -->
                <div class="profile-card">
                    <p class="profile-card-title">Change Password</p>

                    <div class="profile-field">
                        <label class="profile-label" for="profile-current-pwd">Current Password</label>
                        <input type="password" id="profile-current-pwd" class="profile-input" placeholder="••••••••">
                    </div>

                    <div class="profile-field">
                        <label class="profile-label" for="profile-new-pwd">New Password</label>
                        <input type="password" id="profile-new-pwd" class="profile-input" placeholder="Min. 8 characters">
                    </div>

                    <div class="profile-field">
                        <label class="profile-label" for="profile-confirm-pwd">Confirm New Password</label>
                        <input type="password" id="profile-confirm-pwd" class="profile-input" placeholder="••••••••">
                    </div>

                    <div id="profile-pwd-feedback" class="profile-feedback hidden"></div>

                    <div class="flex justify-end mt-5">
                        <button id="profile-pwd-btn" onclick="changePassword()" class="profile-action-btn">
                            Update Password
                        </button>
                    </div>
                </div>

            </div><!-- /left column -->

            <!-- RIGHT COLUMN: Store Card -->
            <div class="profile-card">
                <p class="profile-card-title">Store</p>

                <div class="profile-field">
                    <label class="profile-label" for="profile-store-name">Store Name</label>
                    <input type="text" id="profile-store-name" class="profile-input"
                           value="<?php echo htmlspecialchars($profile['store_name'] ?? ''); ?>"
                           maxlength="100" oninput="updateSaveBtn()">
                </div>

                <hr class="profile-divider">

                <div class="profile-field">
                    <label class="profile-label">Store Location</label>
                    <p class="profile-field-hint" style="margin-bottom:0.75rem;">
                        Click the map or search an address to move your store's pin.
                    </p>

                    <!-- Address search -->
                    <div class="relative mb-3">
                        <input type="text" id="profile-address-search" class="profile-input"
                               placeholder="e.g. 123 Rizal Street, Quezon City"
                               onkeydown="if(event.key==='Enter'){ event.preventDefault(); profileSearchAddress(); }">
                        <button type="button" onclick="profileSearchAddress()"
                                class="absolute right-3 top-1/2 -translate-y-1/2 hover:opacity-70 transition-opacity"
                                style="background:none; border:none; cursor:pointer; padding:0;">
                            <svg class="w-4 h-4 text-[#261F0E]" style="opacity:0.45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Leaflet map -->
                    <div id="profile-map" class="profile-map"></div>

                    <!-- Coordinates display -->
                    <div class="profile-coord-row">
                        <div>
                            <span class="profile-coord-label">Latitude</span>
                            <input type="text" id="profile-lat" class="profile-coord-input"
                                   value="<?php echo $profileLat ?? ''; ?>" readonly>
                        </div>
                        <div>
                            <span class="profile-coord-label">Longitude</span>
                            <input type="text" id="profile-lng" class="profile-coord-input"
                                   value="<?php echo $profileLng ?? ''; ?>" readonly>
                        </div>
                    </div>

                    <?php if (!$profileLat): ?>
                    <p class="profile-no-location">No location set — click the map to pin your store.</p>
                    <?php endif; ?>
                </div>
            </div><!-- /right column -->

        </div><!-- /grid -->

        <!-- Save Profile feedback + button -->
        <div id="profile-save-feedback" class="profile-feedback hidden" style="margin-bottom:1rem;"></div>
        <div class="flex justify-end mb-8">
            <button id="profile-save-btn" onclick="saveProfile()" disabled
                    class="profile-save-btn" style="opacity:0.3; cursor:not-allowed;">
                Save Profile Changes
                <svg class="w-4 h-4 inline-block ml-1.5 -mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </button>
        </div>

        <!-- Danger Zone -->
        <div class="danger-zone-card">
            <div class="flex items-start justify-between gap-6">
                <div>
                    <p class="danger-zone-title">Danger Zone</p>
                    <p class="danger-zone-desc">
                        Delete all imported sales data — products, sales records, and import sessions will be
                        permanently erased. Your account, store name, and location will remain intact.
                        You will be redirected to the setup page to re-import data.
                    </p>
                </div>
                <button onclick="confirmClearData()" class="danger-zone-btn flex-shrink-0">
                    Delete All Data
                </button>
            </div>
        </div>

    </div><!-- /tab-content-profile -->

</main>


<!-- ════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════ -->
<script>

// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(name) {
    document.getElementById('tab-btn-import') .classList.toggle('active', name === 'import');
    document.getElementById('tab-btn-profile').classList.toggle('active', name === 'profile');
    document.getElementById('tab-content-import') .classList.toggle('hidden', name !== 'import');
    document.getElementById('tab-content-profile').classList.toggle('hidden', name !== 'profile');

    window.location.hash = name;

    if (name === 'profile') {
        initProfileMap();
    }
}

// Restore the active tab on load.
// Default is My Profile. Switch to Sales Data if:
//   - arriving from a successful import (?imported=1), or
//   - the URL hash explicitly requests it (#import).
document.addEventListener('DOMContentLoaded', function() {
    var fromImport = window.location.search.indexOf('imported=1') !== -1;
    var hashImport = window.location.hash === '#import';

    if (fromImport || hashImport) {
        switchTab('import');
    } else {
        // Profile is already the visible default — just init the map.
        initProfileMap();
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
    lat:       <?php echo $profileLat !== null ? json_encode($profileLat) : 'null'; ?>,
    lng:       <?php echo $profileLng !== null ? json_encode($profileLng) : 'null'; ?>,
};

// ── Change detection ──────────────────────────────────────────────────────────
function profileHasChanges() {
    var name      = document.getElementById('profile-name').value.trim();
    var storeName = document.getElementById('profile-store-name').value.trim();
    var lat       = document.getElementById('profile-lat').value;
    var lng       = document.getElementById('profile-lng').value;

    // Empty input matches a null original (no location was set — still no change).
    var latMatch = (lat === '' && profileOriginal.lat === null) || (lat === profileOriginal.lat);
    var lngMatch = (lng === '' && profileOriginal.lng === null) || (lng === profileOriginal.lng);

    return name !== profileOriginal.name
        || storeName !== profileOriginal.storeName
        || !latMatch
        || !lngMatch;
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

// ── Save profile (name + store name + location) ───────────────────────────────
function saveProfile() {
    if (!profileHasChanges()) return;

    showConfirm({
        title:        'Save Profile Changes?',
        message:      'Your name, store name, and location will be updated.',
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
    formData.append('lat',        document.getElementById('profile-lat').value);
    formData.append('lng',        document.getElementById('profile-lng').value);

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/update_profile.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.success) {
            // Update the stored originals so the button re-disables correctly.
            profileOriginal.name      = document.getElementById('profile-name').value.trim();
            profileOriginal.storeName = document.getElementById('profile-store-name').value.trim();
            profileOriginal.lat       = document.getElementById('profile-lat').value  || null;
            profileOriginal.lng       = document.getElementById('profile-lng').value  || null;

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
// PROFILE MAP (Leaflet — lazy init on first tab switch)
// ══════════════════════════════════════════════════════════════════════════════
var profileMap            = null;
var profileMarker         = null;
var profileMapInitialized = false;

function initProfileMap() {
    if (profileMapInitialized) return;
    profileMapInitialized = true;

    var lat = <?php echo $profileLat !== null ? (float) $profileLat : 'null'; ?>;
    var lng = <?php echo $profileLng !== null ? (float) $profileLng : 'null'; ?>;

    var center = (lat !== null && lng !== null) ? [lat, lng] : [12.8797, 122.7740];
    var zoom   = (lat !== null && lng !== null) ? 15 : 6;

    profileMap = L.map('profile-map', { zoomControl: true }).setView(center, zoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors',
        maxZoom: 19,
    }).addTo(profileMap);

    // Place existing pin if coordinates are already saved.
    if (lat !== null && lng !== null) {
        profileMarker = L.marker([lat, lng], { draggable: true }).addTo(profileMap);
        profileMarker.on('dragend', function() {
            updateProfileCoords(profileMarker.getLatLng());
        });
    }

    profileMap.on('click', function(e) { placeProfileMarker(e.latlng); });

    // Leaflet renders incorrectly inside a hidden element — invalidate size after reveal.
    setTimeout(function() { profileMap.invalidateSize(); }, 60);
}

function placeProfileMarker(latlng) {
    if (profileMarker) {
        profileMarker.setLatLng(latlng);
    } else {
        profileMarker = L.marker(latlng, { draggable: true }).addTo(profileMap);
        profileMarker.on('dragend', function() {
            updateProfileCoords(profileMarker.getLatLng());
        });
    }
    updateProfileCoords(latlng);
    profileMap.panTo(latlng);
}

function updateProfileCoords(latlng) {
    document.getElementById('profile-lat').value = latlng.lat.toFixed(6);
    document.getElementById('profile-lng').value = latlng.lng.toFixed(6);
    updateSaveBtn();
}

// Address search via Nominatim (OpenStreetMap's free geocoder).
function profileSearchAddress() {
    var query = document.getElementById('profile-address-search').value.trim();
    if (!query) return;

    var url = 'https://nominatim.openstreetmap.org/search?'
        + 'q=' + encodeURIComponent(query)
        + '&countrycodes=ph&format=json&limit=1&accept-language=en';

    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(results) {
            if (!results.length) {
                alert('Address not found. Try a more specific search.');
                return;
            }
            var latlng = L.latLng(parseFloat(results[0].lat), parseFloat(results[0].lon));
            placeProfileMarker(latlng);
            profileMap.setView(latlng, 16);
        })
        .catch(function() { alert('Address search failed. Please try again.'); });
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
    var btn = document.getElementById('w-upload-btn');
    btn.disabled      = true;
    btn.style.opacity = '0.3';
    btn.style.cursor  = 'not-allowed';
    wHeaders     = [];
    wSample      = [];
    wRowCount    = 0;
    wAssignments = {};
    wCurrentStep = 1;
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
    if (file && file.name.toLowerCase().endsWith('.csv')) wSetFile(file.name);
}

function wHandleFileSelect(e) {
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
    var file = document.getElementById('w-csv-file').files[0];
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

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/preflight.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.error) {
            showMappingError('Check failed: ' + data.error);
            btn.innerHTML = IMPORT_BTN_HTML;
            btn.disabled  = false;
            return;
        }

        var hasIssues = data.invalid > 0 || data.overlap.count > 0;
        if (!hasIssues) {
            wPreflightDone = true;
            btn.innerHTML  = IMPORT_BTN_HTML;
            btn.disabled   = false;
            await wDoImport(mapping, false);
            return;
        }

        wRenderPreflightPanel(data);
        wPreflightDone    = true;
        btn.innerHTML     = 'Proceed with import <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
        btn.disabled      = false;
        btn.style.opacity = '1';
    } catch(e) {
        showMappingError('Network error during check. Please try again.');
        btn.innerHTML = IMPORT_BTN_HTML;
        btn.disabled  = false;
    }
}

function wRenderPreflightPanel(data) {
    var html = '<div class="preflight-panel">';
    html += '<div class="preflight-panel-title">Review before importing</div>';

    if (data.invalid > 0) {
        html += '<div class="preflight-section">';
        html += '<div class="preflight-section-head preflight-warn">';
        html += '<svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
        html += '<span><strong>' + data.invalid.toLocaleString() + ' row' + (data.invalid !== 1 ? 's' : '') + '</strong> will be skipped due to data issues.</span>';
        html += '</div>';

        if (data.error_samples && data.error_samples.length) {
            html += '<details class="preflight-samples">';
            html += '<summary class="preflight-samples-toggle">View examples (' + Math.min(data.error_samples.length, 10) + ' shown)</summary>';
            html += '<div class="preflight-samples-table-wrap"><table class="preflight-samples-table">';
            html += '<thead><tr><th>Row</th><th>Product</th><th>Date</th><th>Qty</th><th>Issue</th></tr></thead><tbody>';
            data.error_samples.forEach(function(e) {
                html += '<tr><td>' + e.row + '</td><td>' + escHtml(e.product) + '</td><td>' + escHtml(e.date) + '</td><td>' + escHtml(e.qty) + '</td><td>' + escHtml(e.reason) + '</td></tr>';
            });
            html += '</tbody></table></div></details>';
        }
        html += '</div>';
    }

    if (data.overlap && data.overlap.count > 0) {
        var df = data.overlap.date_from, dt = data.overlap.date_to;
        var rangeLabel = df === dt ? df : df + ' to ' + dt;
        html += '<div class="preflight-section">';
        html += '<div class="preflight-section-head preflight-overlap">';
        html += '<svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
        html += '<span><strong>' + data.overlap.count.toLocaleString() + ' existing record' + (data.overlap.count !== 1 ? 's' : '') + '</strong> fall within this file\'s date range (' + escHtml(rangeLabel) + ').</span>';
        html += '</div>';
        html += '<label class="preflight-replace-label"><input type="checkbox" id="w-replace-overlap" class="preflight-replace-check"><span>Replace overlapping records with values from this file</span></label>';
        html += '</div>';
    }

    html += '</div>';
    document.getElementById('w-preflight-container').innerHTML = html;
}

async function wDoImport(mapping, replace) {
    var btn = document.getElementById('w-import-btn');
    btn.textContent = 'Importing…';
    btn.disabled    = true;

    var formData = new FormData();
    formData.append('mapping',  JSON.stringify(mapping));
    formData.append('csv_rows', wRowCount);
    formData.append('replace',  replace ? '1' : '0');

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/import.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.success) {
            wClearState();
            window.location = '<?php echo BASE_URL; ?>/pages/import.view.php?imported=1&rows=' + data.rows + '&replaced=' + (data.replaced || 0) + '&skipped=' + (data.skipped || 0) + '&csv_rows=' + (data.csv_rows || 0);
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

// ── Inline qty edit ───────────────────────────────────────────────────────────
function startQtyEdit(saleId, currentQty) {
    var cell = document.getElementById('qty-cell-' + saleId);
    if (!cell) return;
    cell.innerHTML =
        '<input id="qty-input-' + saleId + '" type="number" min="1" value="' + currentQty + '" ' +
        'class="qty-edit-input" ' +
        'onkeydown="qtyKeyDown(event,' + saleId + ',' + currentQty + ')" ' +
        'onblur="saveQty(' + saleId + ',' + currentQty + ')">';
    var inp = document.getElementById('qty-input-' + saleId);
    inp.focus(); inp.select();
}

function qtyKeyDown(e, saleId, originalQty) {
    if (e.key === 'Enter')  { e.preventDefault(); saveQty(saleId, originalQty); }
    if (e.key === 'Escape') { e.preventDefault(); cancelQtyEdit(saleId, originalQty); }
}

function cancelQtyEdit(saleId, originalQty)  { restoreQtyCell(saleId, originalQty); }

function restoreQtyCell(saleId, qty) {
    var cell = document.getElementById('qty-cell-' + saleId);
    if (cell) cell.innerHTML = qtyDisplay(saleId, qty);
}

function qtyDisplay(saleId, qty) {
    return parseInt(qty).toLocaleString() +
        ' <button class="qty-edit-btn" onclick="startQtyEdit(' + saleId + ',' + qty + ')" title="Edit quantity">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>' +
        '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>' +
        '</svg></button>';
}

var wQtyConfirmPending = false;

function saveQty(saleId, originalQty) {
    if (wQtyConfirmPending) return;

    var inp = document.getElementById('qty-input-' + saleId);
    if (!inp) return;
    var newQty = parseInt(inp.value);

    if (isNaN(newQty) || newQty <= 0)    { cancelQtyEdit(saleId, originalQty); return; }
    if (newQty === parseInt(originalQty)) { cancelQtyEdit(saleId, originalQty); return; }

    wQtyConfirmPending = true;
    inp.disabled = true;

    showConfirm({
        title:        'Update Quantity?',
        message:      'Change the quantity from ' + parseInt(originalQty).toLocaleString() + ' to ' + newQty.toLocaleString() + '? This will affect forecasting calculations for this product.',
        confirmText:  'Update',
        confirmStyle: 'warning',
        onConfirm: async function() {
            wQtyConfirmPending = false;
            var cell = document.getElementById('qty-cell-' + saleId);
            if (cell) cell.innerHTML = '<span class="qty-saving">Saving…</span>';

            var formData = new FormData();
            formData.append('sale_id',  saleId);
            formData.append('quantity', newQty);

            try {
                var res  = await fetch('<?php echo BASE_URL; ?>/api/update_sale.php', { method: 'POST', body: formData });
                var data = await res.json();
                if (data.success) {
                    restoreQtyCell(saleId, newQty);
                } else {
                    restoreQtyCell(saleId, originalQty);
                    alert('Could not save: ' + (data.error || 'Unknown error.'));
                }
            } catch(e) {
                restoreQtyCell(saleId, originalQty);
                alert('Network error. Please try again.');
            }
        },
        onCancel: function() {
            wQtyConfirmPending = false;
            cancelQtyEdit(saleId, originalQty);
        },
    });
}

// ── Session records expand / collapse ─────────────────────────────────────────
var recordsState = {};

function toggleRecords(sessionId) {
    var panel  = document.getElementById('records-panel-' + sessionId);
    var toggle = document.getElementById('records-toggle-' + sessionId);
    var isOpen = !panel.classList.contains('hidden');

    if (isOpen) { panel.classList.add('hidden'); toggle.classList.remove('active'); return; }

    panel.classList.remove('hidden');
    toggle.classList.add('active');

    if (!recordsState[sessionId]) {
        recordsState[sessionId] = { page: 1 };
        loadRecords(sessionId, 1);
    }
}

async function loadRecords(sessionId, page) {
    var panel = document.getElementById('records-panel-' + sessionId);
    panel.innerHTML = '<div class="records-loading">Loading records…</div>';

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/session_records.php?session_id=' + sessionId + '&page=' + page);
        var data = await res.json();

        if (data.error) { panel.innerHTML = '<div class="records-empty">' + escHtml(data.error) + '</div>'; return; }

        recordsState[sessionId].page = page;
        renderRecords(sessionId, data);
    } catch(e) {
        panel.innerHTML = '<div class="records-empty">Failed to load records. Please try again.</div>';
    }
}

function renderRecords(sessionId, data) {
    var panel = document.getElementById('records-panel-' + sessionId);

    if (!data.records || !data.records.length) {
        panel.innerHTML = '<div class="records-empty">No records in this import.</div>';
        return;
    }

    var start = (data.page - 1) * data.per_page + 1;
    var end   = Math.min(data.page * data.per_page, data.total);

    var html = '<div class="records-header">';
    html += '<span class="records-count">Showing ' + start.toLocaleString() + '–' + end.toLocaleString() + ' of <strong>' + data.total.toLocaleString() + '</strong> daily records</span>';
    html += '<div class="records-pagination">';
    if (data.page > 1)              html += '<button class="records-page-btn" onclick="loadRecords(' + sessionId + ', ' + (data.page - 1) + ')">← Prev</button>';
    if (data.page < data.total_pages) html += '<button class="records-page-btn" onclick="loadRecords(' + sessionId + ', ' + (data.page + 1) + ')">Next →</button>';
    html += '</div></div>';

    html += '<div class="records-table-wrap"><table class="records-table">';
    html += '<thead><tr><th>Date</th><th>Product</th><th>Category</th><th style="text-align:right">Qty Sold</th></tr></thead>';
    html += '<tbody>';
    data.records.forEach(function(r) {
        html += '<tr>';
        html += '<td>' + escHtml(r.sale_date) + '</td>';
        html += '<td>' + escHtml(r.product_name) + '</td>';
        html += '<td>' + (r.category ? escHtml(r.category) : '<span style="opacity:0.28">—</span>') + '</td>';
        html += '<td style="text-align:right" id="qty-cell-' + r.sale_id + '">' + qtyDisplay(r.sale_id, r.quantity_sold) + '</td>';
        html += '</tr>';
    });
    html += '</tbody></table></div>';

    html += '<div class="records-header" style="margin-top:0.75rem; margin-bottom:0;">';
    html += '<span class="records-count">Page ' + data.page + ' of ' + data.total_pages + '</span>';
    html += '<div class="records-pagination">';
    if (data.page > 1)              html += '<button class="records-page-btn" onclick="loadRecords(' + sessionId + ', ' + (data.page - 1) + ')">← Prev</button>';
    if (data.page < data.total_pages) html += '<button class="records-page-btn" onclick="loadRecords(' + sessionId + ', ' + (data.page + 1) + ')">Next →</button>';
    html += '</div></div>';

    panel.innerHTML = html;
}

// ── Delete import session ─────────────────────────────────────────────────────
function confirmDeleteSession(sessionId, filename) {
    showConfirm({
        title:        'Delete Import?',
        message:      'This will permanently delete "' + filename + '" and all its associated sales records. This cannot be undone.',
        confirmText:  'Delete',
        confirmStyle: 'danger',
        onConfirm:    function() { deleteSession(sessionId); },
    });
}

async function deleteSession(sessionId) {
    var formData = new FormData();
    formData.append('session_id', sessionId);

    try {
        var res  = await fetch('<?php echo BASE_URL; ?>/api/delete_import.php', { method: 'POST', body: formData });
        var data = await res.json();

        if (data.success) {
            var row = document.getElementById('session-' + sessionId);
            if (row) {
                row.style.transition = 'opacity 0.25s';
                row.style.opacity    = '0';
                setTimeout(function() {
                    row.remove();
                    var list = document.getElementById('sessions-list');
                    if (!list.querySelector('.session-row')) {
                        list.innerHTML = '<div class="sessions-empty">No imports yet. Upload your first CSV to get started.</div>';
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

</script>

<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
</body>
</html>
