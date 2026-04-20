<?php
// pages/event_detail.view.php
// Shows a single event's forecast-model impact analysis.

require_once __DIR__ . '/event_detail.logic.php';

$pageTitle = 'ProVendor — ' . htmlspecialchars($event['name']);
$pageCss   = 'event_detail.css';
require_once __DIR__ . '/../includes/header.php';

$scheduleLabel = match($event['recurrence']) {
    'yearly'  => 'Every year',
    'monthly' => $event['is_last_day'] ? 'Last day of every month' : 'Every month',
    default   => 'One-time',
};

// Pre-compute Prophet summary values (used in top-3 callout + card)
$hasProphet  = !empty($prophetCache);
$overallPct  = 0.0;
$overallDir  = 'neutral';
$overallArrow = '→';
$overallSign  = '';
$confCss      = 'weak';
$confLabel    = 'Weak';
$rec          = $event['recurrence'];

if ($hasProphet) {
    $sumCoef = array_sum(array_column($prophetCache, 'coefficient'));
    $sumMean = array_sum(array_column($prophetCache, 'mean_daily_sales'));
    $overallPct   = $sumMean > 0 ? round($sumCoef / $sumMean * 100, 1) : 0.0;
    $overallDir   = $overallPct >  2 ? 'positive' : ($overallPct < -2 ? 'negative' : 'neutral');
    $overallArrow = $overallPct > 0 ? '↑' : ($overallPct < 0 ? '↓' : '→');
    $overallSign  = $overallPct > 0 ? '+' : '';

    $maxOcc = max(array_column($prophetCache, 'occurrence_count'));
    if ($rec === 'monthly') {
        $confCss   = $maxOcc >= 12 ? 'strong' : ($maxOcc >= 6 ? 'moderate' : 'weak');
        $confLabel = $maxOcc >= 12 ? 'Strong' : ($maxOcc >= 6 ? 'Moderate' : 'Weak');
    } else {
        $confCss   = $maxOcc >= 4  ? 'strong' : ($maxOcc >= 2 ? 'moderate' : 'weak');
        $confLabel = $maxOcc >= 4  ? 'Strong' : ($maxOcc >= 2 ? 'Moderate' : 'Weak');
    }
}
?>
<body class="bg-[#F0E8D0] min-h-screen dot-pattern-light">

<?php require_once __DIR__ . '/../includes/navbar.php'; ?>

<!-- ════════════════════════════════════════════
     MAIN
════════════════════════════════════════════ -->
<main class="max-w-5xl mx-auto px-6 py-8">

    <!-- Back link -->
    <a href="<?php echo BASE_URL; ?>/pages/events.view.php"
       class="inline-flex items-center gap-1.5 text-sm text-[#261F0E] hover:opacity-70 transition-opacity mb-5"
       style="opacity:0.45">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="15 18 9 12 15 6"/>
        </svg>
        Back to Events
    </a>

    <!-- ── Event header ────────────────────────────────────────────────────── -->
    <div class="event-header-card">
        <div class="event-header-left">
            <div class="event-header-name"><?php echo htmlspecialchars($event['name']); ?></div>
            <div class="event-header-meta">
                <span class="event-header-schedule"><?php echo htmlspecialchars($scheduleLabel); ?></span>
                <?php if ($event['event_start'] && $rec !== 'monthly'): ?>
                <span class="event-header-schedule" style="opacity:0.3">·</span>
                <span class="event-header-schedule">
                    <?php
                    $s = new DateTime($event['event_start']);
                    $e = $event['event_end'] ? new DateTime($event['event_end']) : null;
                    echo $rec === 'yearly'
                        ? $s->format('M j') . ($e ? '–' . $e->format('M j') : '')
                        : $s->format('M j, Y') . ($e ? ' – ' . $e->format('M j, Y') : '');
                    ?>
                </span>
                <?php endif; ?>
                <?php $occCount = count($occurrences); if ($occCount > 0): ?>
                <span class="event-header-schedule" style="opacity:0.3">·</span>
                <span class="event-header-occ-count"><?php echo $occCount; ?>&times; in your data</span>
                <?php endif; ?>
            </div>
            <?php if (!$event['is_seeded'] && $event['impact_note']): ?>
            <p class="event-header-note"><?php echo htmlspecialchars($event['impact_note']); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($hasProphet): ?>

    <!-- ── Top-3 most-impacted (Prophet) ──────────────────────────────────── -->
    <?php $top3 = array_slice($prophetCache, 0, 3); if (!empty($top3)): ?>
    <div class="top-impact-callout">
        <p class="top-impact-label">Most impacted products</p>
        <div class="top-impact-items">
            <?php foreach ($top3 as $tp):
                $tPct  = (float) $tp['impact_pct'];
                $tDir  = $tPct >= 0 ? 'positive' : 'negative';
                $tArrow = $tPct >= 0 ? '↑' : '↓';
                $tSign  = $tPct >= 0 ? '+' : '';
            ?>
            <a class="top-impact-item top-impact-<?php echo $tDir; ?>"
               href="<?php echo BASE_URL; ?>/pages/forecast.view.php?product_id=<?php echo $tp['product_id']; ?>&event_id=<?php echo $eventId; ?>">
                <span class="top-impact-name"><?php echo htmlspecialchars($tp['product_name']); ?></span>
                <span class="top-impact-pct">
                    <?php echo $tArrow . ' ' . $tSign . number_format($tPct, 1); ?>%
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Forecast Model Analysis card ───────────────────────────────────── -->
    <div class="prophet-card">

        <div class="prophet-card-header">
            <div class="prophet-card-title-row">
                <svg class="prophet-card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                <span class="prophet-card-title">Forecast Model Analysis</span>
                <span class="event-conf-badge conf-<?php echo $confCss; ?>"><?php echo $confLabel; ?> confidence</span>
            </div>
            <p class="prophet-card-sub">What the model has learned about how this event affects your sales, based on your full sales history.</p>
        </div>

        <div class="prophet-summary-row">
            <span class="prophet-overall-badge <?php echo $overallDir; ?>">
                <?php echo $overallArrow . ' ' . $overallSign . $overallPct; ?>% overall
            </span>
            <span class="prophet-direction-label <?php echo $overallDir; ?>">
                <?php
                if ($overallDir === 'positive') echo 'Positive impact on sales';
                elseif ($overallDir === 'negative') echo 'Negative impact on sales';
                else echo 'Neutral — minimal detectable effect';
                ?>
            </span>
            <span class="prophet-computed-at">
                <?php echo count($prophetCache); ?> product<?php echo count($prophetCache) !== 1 ? 's' : ''; ?>
                · Last computed <?php echo date('M j, Y', strtotime($prophetCache[0]['computed_at'])); ?>
            </span>
        </div>

        <div class="prophet-product-list">
            <div class="prophet-product-list-header">
                <span>Product</span>
                <span>Confidence</span>
                <span style="text-align:right">Model Impact</span>
            </div>
            <?php foreach ($prophetCache as $pc):
                $pPct   = (float) $pc['impact_pct'];
                $pDir   = $pPct >  2 ? 'positive' : ($pPct < -2 ? 'negative' : 'neutral');
                $pArrow = $pPct > 0 ? '↑' : ($pPct < 0 ? '↓' : '→');
                $pSign  = $pPct > 0 ? '+' : '';
                $pOcc   = (int) $pc['occurrence_count'];
                if ($rec === 'monthly') {
                    $pConfCss   = $pOcc >= 12 ? 'strong' : ($pOcc >= 6  ? 'moderate' : 'weak');
                    $pConfLabel = $pOcc >= 12 ? 'Strong' : ($pOcc >= 6  ? 'Moderate' : 'Weak');
                } else {
                    $pConfCss   = $pOcc >= 4  ? 'strong' : ($pOcc >= 2  ? 'moderate' : 'weak');
                    $pConfLabel = $pOcc >= 4  ? 'Strong' : ($pOcc >= 2  ? 'Moderate' : 'Weak');
                }
            ?>
            <div class="prophet-product-row">
                <div>
                    <div class="prophet-product-name"><?php echo htmlspecialchars($pc['product_name']); ?></div>
                    <?php if ($pc['category']): ?>
                    <div class="prophet-product-cat"><?php echo htmlspecialchars($pc['category']); ?></div>
                    <?php endif; ?>
                </div>
                <span class="event-conf-badge conf-<?php echo $pConfCss; ?>"
                      title="<?php echo $pOcc; ?> occurrence<?php echo $pOcc !== 1 ? 's' : ''; ?> in training data">
                    <?php echo $pConfLabel; ?>
                </span>
                <div class="prophet-pct-cell">
                    <span class="prophet-pct <?php echo $pDir; ?>">
                        <?php echo $pArrow . ' ' . $pSign . number_format($pPct, 1); ?>%
                    </span>
                    <div class="impact-bar-wrap">
                        <div class="impact-bar <?php echo $pDir !== 'neutral' ? $pDir : 'positive'; ?>"
                             style="width:<?php echo min(100, abs($pPct)); ?>%; opacity:<?php echo $pDir === 'neutral' ? '0.2' : '1'; ?>">
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>

    <?php else: ?>

    <!-- ── No forecast data yet ────────────────────────────────────────────── -->
    <div class="no-data-box">
        <p>No forecast model data for this event yet.<br>
        Run a forecast on any product to see how this event is weighted in the model.</p>
    </div>

    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
</body>
</html>
