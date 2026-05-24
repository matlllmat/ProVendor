# python/generate_synthetic.py
# Generates a CSV of 5 years of synthetic-but-pattern-rich daily sales for 10
# Philippine SME sari-sari / convenience-store products. The output exercises
# the full ProVendor pipeline (CSV import → Prophet fit → Newsvendor → backtest)
# with data that has *real* signal to learn — unlike flat-random placeholder data.
#
# Patterns baked in (so Prophet/Newsvendor have something to fit):
#   1. Pareto product mix       — 2 top sellers, 5 mid-tier, 3 long-tail.
#   2. Weekly cycle             — Fri/Sat lift, Tue/Wed dip. Strength varies by
#                                 category (food/beverage strongest, body-care flattest).
#   3. End-of-month payday      — last 3 days of every month spike (matches
#                                 the seeded `End-of-month Payday` event).
#   4. Yearly holiday surges    — Christmas (Dec 15→31), New Year, All Souls,
#                                 Holy Week (Easter shifts yearly). Christmas/
#                                 NYE peaks match seeded events for Prophet to
#                                 attribute via additive regressors.
#   5. Per-product trend        — modest linear growth/decline so Prophet's
#                                 trend component has something non-zero to fit.
#
# Structural randomness layered on top (so the data isn't suspiciously clean):
#   A. Poisson count noise      — integer counts with variance ≈ mean.
#   B. Multiplicative noise     — ±10% Gaussian wobble (±20% for long-tail),
#                                 simulating day-to-day demand jitter beyond
#                                 pure Poisson.
#   C. Anomaly days (~2%)       — random 0.55× to 1.45× multiplier for a single
#                                 day. Simulates brownouts, neighborhood
#                                 fiestas, supplier delays, weather extremes.
#   D. Per-year baseline drift  — each year shifts ±6%. Simulates business
#                                 cycles / neighborhood changes / inflation.
#   E. Random stockouts (~1%)   — qty forced to 0. Reflects supply reality.
#
# Run:  python python/generate_synthetic.py
# Out:  python/synthetic_sales.csv  (~1,800 days × 10 products ≈ 18k rows)

import csv
import os
import random
from datetime import date, timedelta

# ── Configuration ─────────────────────────────────────────────────────────────
SEED        = 42
START_DATE  = date(2021, 5, 1)
END_DATE    = date(2026, 4, 30)
OUTPUT_FILE = os.path.join(os.path.dirname(__file__), 'synthetic_sales.csv')

# ── Product catalogue ────────────────────────────────────────────────────────
# (name, category, base_demand, trend_total_pct, cost, price, holiday_sensitivity)
# holiday_sensitivity: 1.0 = full seasonal swing, 0.4 = mostly flat (toiletries
# don't surge for Christmas the way food/beverages do); 1.3 = beer, which spikes
# extra-hard on Friday paydays and holidays.
#
# Volumes and prices reflect a typical Philippine sari-sari mix — rice and oil
# anchor the catalogue, canned goods and noodles are payday-driven mid-tier,
# household items are the steadier long tail.
PRODUCTS = [
    # Top sellers (Pareto head) — daily essentials with payday bulk-buying
    ('Rice 5kg',                       'Grains',     25,  0.05, 240.00, 280.00, 1.0),
    ('Cooking Oil 1L',                 'Pantry',     30,  0.05,  60.00,  75.00, 1.0),

    # Mid-tier — classic payday "stock the pantry" items
    ('Lucky Me Pancit Canton',         'Pantry',     80,  0.10,  10.00,  14.00, 1.0),
    ('Argentina Corned Beef 175g',     'Canned Goods', 22, 0.00, 45.00,  55.00, 1.0),
    ('Century Tuna Flakes in Oil',     'Canned Goods', 22, 0.05, 38.00,  48.00, 1.0),
    ('Nescafé 3-in-1 Original',        'Beverages',  45,  0.05,   5.00,   7.00, 0.7),
    ('San Miguel Pale Pilsen 330ml',   'Beverages',  35,  0.10,  50.00,  65.00, 1.3),

    # Long tail — household / body care, steadier demand, less event-driven
    ('Surf Detergent Powder 65g',      'Household',  35,  -0.05,  6.00,   9.00, 0.5),
    ('Safeguard Soap 90g',             'Body Care',  12,  0.00,  22.00,  30.00, 0.4),
    ('Bear Brand Powdered Milk 320g',  'Dairy',      14, -0.10,  70.00,  90.00, 0.7),
]

# ── Pattern multipliers ──────────────────────────────────────────────────────

# Weekly cycle — index by date.weekday() (0=Mon … 6=Sun).
# Friday/Saturday peaks, Tuesday/Wednesday troughs.
WEEKLY_BASE = [0.92, 0.85, 0.85, 0.98, 1.20, 1.32, 1.10]


def _weekly(d, sensitivity):
    """Apply the weekly cycle, dampened by per-product holiday sensitivity."""
    raw = WEEKLY_BASE[d.weekday()]
    return 1.0 + (raw - 1.0) * sensitivity


def _last_day_of_month(d):
    """Return the day number of the last day of d's month (28-31)."""
    next_month = (d.replace(day=28) + timedelta(days=4)).replace(day=1)
    return (next_month - timedelta(days=1)).day


def _monthly(d, sensitivity):
    """End-of-month payday surge (last 3 days) — matches seeded event."""
    if d.day >= _last_day_of_month(d) - 2:
        return 1.0 + 0.50 * sensitivity
    return 1.0


def _easter_sunday(year):
    """Anonymous Gregorian computus — used to slide Holy Week each year."""
    a = year % 19
    b, c = year // 100, year % 100
    d, e = b // 4, b % 4
    f = (b + 8) // 25
    g = (b - f + 1) // 3
    h = (19 * a + b - d - g + 15) % 30
    i, k = c // 4, c % 4
    el = (32 + 2 * e + 2 * i - h - k) % 7
    m = (a + 11 * h + 22 * el) // 451
    month = (h + el - 7 * m + 114) // 31
    day = ((h + el - 7 * m + 114) % 31) + 1
    return date(year, month, day)


# Pre-compute Easter for every year in range so we don't recompute per day.
_EASTER = {y: _easter_sunday(y) for y in range(START_DATE.year, END_DATE.year + 1)}


def _yearly(d, sensitivity):
    """Yearly seasonality — Christmas, NYE, All Souls, Holy Week."""
    m, day = d.month, d.day

    # Christmas surge — Dec 15 onward, peaking Dec 23-25.
    if m == 12 and day >= 15:
        peak_distance = abs(24 - day)
        intensity = max(0.40, 0.70 - peak_distance * 0.05)
        return 1.0 + intensity * sensitivity

    # New Year hangover — Jan 1-2 lift, taper.
    if m == 1 and day <= 2:
        return 1.0 + 0.35 * sensitivity

    # All Souls Day (Nov 1-2) — Filipino cemetery-visit shopping.
    if m == 11 and 1 <= day <= 2:
        return 1.0 + 0.35 * sensitivity

    # Holy Week — 7 days leading up to Easter Sunday + Easter itself.
    easter = _EASTER[d.year]
    if 0 <= (easter - d).days <= 7:
        return 1.0 + 0.25 * sensitivity

    return 1.0


def _trend(d, total_pct):
    """Linear ramp from 1.0 (start) to 1.0 + total_pct (end)."""
    days_elapsed = (d - START_DATE).days
    total_days   = (END_DATE - START_DATE).days
    progress     = days_elapsed / total_days
    return 1.0 + total_pct * progress


def _poisson(mean, rng):
    """Knuth's algorithm — pure-Python Poisson sampler (avoids numpy dependency)."""
    if mean <= 0:
        return 0
    # For large means, fall back to a Gaussian approximation (Poisson → Normal
    # as λ→∞). Saves O(λ) work and is statistically indistinguishable above ~30.
    if mean > 50:
        import math
        v = rng.gauss(mean, math.sqrt(mean))
        return max(0, int(round(v)))
    import math
    L = math.exp(-mean)
    k = 0
    p = 1.0
    while True:
        k += 1
        p *= rng.random()
        if p <= L:
            return k - 1


# ── Randomness knobs ─────────────────────────────────────────────────────────
# Tune these to dial overall noise up or down. Higher noise = more realistic
# but lower headline accuracy.

NOISE_STD_NORMAL    = 0.10   # ±10% Gaussian multiplicative wobble (normal products)
NOISE_STD_LONGTAIL  = 0.20   # ±20% for low-volume long-tail (real low-vol noise is higher)
LONGTAIL_THRESHOLD  = 20     # base_demand <= this is considered long-tail
ANOMALY_PROB        = 0.02   # 2% of (product, day) pairs get an anomaly multiplier
ANOMALY_LO          = 0.55   # anomaly multiplier range — covers brownouts on the low end
ANOMALY_HI          = 1.45   # ... and neighborhood fiestas on the high end
STOCKOUT_PROB       = 0.01   # 1% chance of forced qty=0 (supplier delay etc.)
YEAR_DRIFT_STD      = 0.06   # per-year baseline shift std (±6% one-sigma)


# ── Generation ───────────────────────────────────────────────────────────────

def generate():
    rng = random.Random(SEED)

    # Per-year baseline drift — sampled once per calendar year and applied to
    # all days that year. Simulates inflation, neighborhood change, a competitor
    # opening, etc. Same drift across all products keeps the chart story coherent.
    year_drift = {
        y: max(0.5, rng.gauss(1.0, YEAR_DRIFT_STD))
        for y in range(START_DATE.year, END_DATE.year + 1)
    }

    total_days = (END_DATE - START_DATE).days + 1
    row_count    = 0
    skipped_zero = 0
    stockouts    = 0
    anomalies    = 0

    with open(OUTPUT_FILE, 'w', newline='', encoding='utf-8') as f:
        writer = csv.writer(f)
        writer.writerow(['date', 'product', 'quantity', 'category', 'cost_price', 'selling_price'])

        current = START_DATE
        while current <= END_DATE:
            for name, category, base, trend_pct, cost, price, sens in PRODUCTS:
                # Deterministic pattern stack.
                expected = (base
                            * _weekly(current, sens)
                            * _monthly(current, sens)
                            * _yearly(current, sens)
                            * _trend(current, trend_pct)
                            * year_drift[current.year])

                # Multiplicative noise layer. Long-tail products are noisier
                # (their MAPE will saturate more — by design, to exercise the
                # MAPE-is-unreliable-on-low-volume disclosure in the UI).
                noise_std = NOISE_STD_LONGTAIL if base <= LONGTAIL_THRESHOLD else NOISE_STD_NORMAL
                expected *= max(0.3, rng.gauss(1.0, noise_std))

                # Anomaly day — rare big swing up or down.
                if rng.random() < ANOMALY_PROB:
                    expected *= rng.uniform(ANOMALY_LO, ANOMALY_HI)
                    anomalies += 1

                # Stockout — forces qty=0 regardless of expected demand.
                if rng.random() < STOCKOUT_PROB:
                    qty = 0
                    stockouts += 1
                else:
                    qty = _poisson(expected, rng)

                if qty <= 0:
                    skipped_zero += 1
                    continue

                writer.writerow([
                    current.strftime('%Y-%m-%d'),
                    name,
                    qty,
                    category,
                    f'{cost:.2f}',
                    f'{price:.2f}',
                ])
                row_count += 1
            current += timedelta(days=1)

    print(f'Wrote {OUTPUT_FILE}')
    print(f'  Range:        {START_DATE} -> {END_DATE} ({total_days} days)')
    print(f'  Products:     {len(PRODUCTS)}')
    print(f'  Rows:         {row_count:,} written, {skipped_zero:,} skipped (qty=0)')
    print(f'  Anomalies:    {anomalies:,} ({anomalies/(total_days*len(PRODUCTS))*100:.1f}% of pairs)')
    print(f'  Stockouts:    {stockouts:,} ({stockouts/(total_days*len(PRODUCTS))*100:.1f}% of pairs)')
    print(f'  Year drift:   ' + ', '.join(f'{y}={v:.2f}x' for y, v in year_drift.items()))
    print(f'  Seed:         {SEED}  (re-run produces identical output)')


if __name__ == '__main__':
    generate()
