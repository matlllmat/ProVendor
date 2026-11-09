<?php
// pages/event_detail.view.php
// Shows a single event's forecast-model impact analysis.

require_once __DIR__ . '/event_detail.logic.php';

$pageTitle = 'ProVendor — ' . $event['name'];
$pageCss   = 'event_detail.css';
require_once __DIR__ . '/../includes/header.php';

$scheduleLabel = match($event['recurrence']) {
    'yearly'  => 'Every year',
    'monthly' => $event['is_last_day'] ? 'Last day of every month' : 'Every month',
    'custom'  => count($event['occurrences'] ?? []) . ' specific dates',
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

    $maxLevel  = confidenceLevel($rec, (int) max(array_column($prophetCache, 'occurrence_count')));
    $confCss   = $maxLevel['css'];
    $confLabel = $maxLevel['label'];
}
?>

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
                <?php $occCount = $datesInHistory; if ($occCount > 0): ?>
                <span class="event-header-schedule" style="opacity:0.3">·</span>
                <span class="event-header-occ-count"><?php echo $occCount; ?>&times; in your data</span>
                <?php endif; ?>
            </div>
            <?php if (!$event['is_seeded'] && $event['impact_note']): ?>
            <p class="event-header-note"><?php echo htmlspecialchars($event['impact_note']); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($eventUnused): ?>
    <div class="prophet-card" style="margin-bottom:1rem;">
        <div class="prophet-card-header">
            <p class="prophet-card-title">Not used in forecasts yet</p>
            <p class="prophet-card-sub">
                <?php echo (int) $datesInHistory; ?> of this event's dates <?php echo $datesInHistory === 1 ? 'is' : 'are'; ?> in your sales history.
                The forecast needs at least <?php echo MIN_CUSTOM_DATES; ?> to learn a real effect, because one date can't be told apart from a one-off.
                Add more dates from your history, and any upcoming dates, by editing this event.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($hasProphet): ?>


    <!-- ── Forecast Model Analysis card ───────────────────────────────────── -->
    <div class="prophet-card">

        <div class="prophet-card-header">
            <div class="prophet-card-title-row">
                <svg class="prophet-card-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                <span class="prophet-card-title">Forecast Model Analysis</span>
                <!-- Confidence cluster — pushed to the right; label matches the
                     uppercase column-header style used in the product table below. -->
                <span class="prophet-confidence-cluster">
                    <span class="prophet-confidence-label">Confidence</span>
                    <span class="event-conf-badge conf-<?php echo $confCss; ?>"><?php echo $confLabel; ?></span>
                </span>
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
                <span class="prophet-impact-col-head">Model Impact</span>
            </div>
            <?php foreach ($prophetCache as $pc):
                $pPct   = (float) $pc['impact_pct'];
                $pDir   = $pPct >  2 ? 'positive' : ($pPct < -2 ? 'negative' : 'neutral');
                $pArrow = $pPct > 0 ? '↑' : ($pPct < 0 ? '↓' : '→');
                $pSign  = $pPct > 0 ? '+' : '';
                $pOcc   = (int) $pc['occurrence_count'];
                $pLevel     = confidenceLevel($rec, $pOcc);
                $pConfCss   = $pLevel['css'];
                $pConfLabel = $pLevel['label'];
                $pCoef  = (float) $pc['coefficient'];
                $pMean  = (float) $pc['mean_daily_sales'];
                $pCoefSign = $pCoef >= 0 ? '+' : '';
            ?>
            <a class="prophet-product-row"
               href="<?php echo BASE_URL; ?>/pages/forecast.view.php?product_id=<?php echo $pc['product_id']; ?>">
                <div>
                    <div class="prophet-product-name"><?php echo htmlspecialchars($pc['product_name']); ?></div>
                    <?php if ($pc['category']): ?>
                    <div class="prophet-product-cat"><?php echo htmlspecialchars($pc['category']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="prophet-conf-cell">
                    <span class="event-conf-badge conf-<?php echo $pConfCss; ?>"
                          title="<?php echo $pOcc; ?> occurrence<?php echo $pOcc !== 1 ? 's' : ''; ?> in training data">
                        <?php echo $pConfLabel; ?>
                    </span>
                    <span class="prophet-conf-detail">
                        <?php echo $pOcc; ?> occurrence<?php echo $pOcc !== 1 ? 's' : ''; ?>
                    </span>
                </div>
                <div class="prophet-pct-cell">
                    <span class="prophet-pct <?php echo $pDir; ?>">
                        <?php echo $pArrow . ' ' . $pSign . number_format($pPct, 1); ?>%
                    </span>
                    <span class="prophet-pct-detail">
                        <?php echo $pCoefSign . number_format($pCoef, 1); ?> units/day
                        &middot; mean <?php echo number_format($pMean, 1); ?>/day
                    </span>
                    <div class="impact-bar-wrap">
                        <div class="impact-bar <?php echo $pDir !== 'neutral' ? $pDir : 'positive'; ?>"
                             style="width:<?php echo min(100, abs($pPct)); ?>%; opacity:<?php echo $pDir === 'neutral' ? '0.2' : '1'; ?>">
                        </div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

    </div>

    <?php elseif (!$eventUnused): ?>

    <!-- ── No forecast data yet ────────────────────────────────────────────── -->
    <div class="no-data-box">
        <p>No forecast model data for this event yet.<br>
        Run a forecast on any product to see how this event is weighted in the model.</p>
    </div>

    <?php endif; ?>

</main>

<?php require_once __DIR__ . '/../includes/confirm_modal.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
