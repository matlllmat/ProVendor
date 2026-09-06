# python/generate_synthetic.py
# Generates a CSV of post-pandemic synthetic-but-pattern-rich daily sales for 20
# well-known, fast-moving Philippine sari-sari / convenience-store products. The
# output exercises the full ProVendor pipeline (CSV import → Prophet fit →
# Newsvendor → backtest) with data that has *real* signal to learn — unlike
# flat-random placeholder data.
#
# Covers Aug 2023 → Feb 2026. See START_DATE for why it begins there.
#
# Perishability is NOT modeled here. Real POS exports don't carry an expiry
# column — that was an earlier, unrealistic assumption. It's now something the
# owner declares themselves per product (perishable? what's the usual shelf-life
# range?) via the forecast page's batch editor, independent of any CSV.
#
# Patterns baked in (so Prophet/Newsvendor have something to fit):
#   1. Fast-moving catalogue    — 20 real, recognizable PH sari-sari products,
#                                 all comfortably above the long-tail noise
#                                 threshold (see LONGTAIL_THRESHOLD below), with
#                                 varied base demand and event sensitivity.
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
#   B. Multiplicative noise     — ±10% Gaussian wobble. (A ±20% long-tail branch
#                                 exists in the noise model for any low-volume
#                                 product added later — nothing in this
#                                 catalogue is slow-moving enough to trigger it.)
#   C. Anomaly days (~2%)       — random 0.55× to 1.45× multiplier for a single
#                                 day. Simulates brownouts, neighborhood
#                                 fiestas, supplier delays, weather extremes.
#   D. Per-year baseline drift  — each year shifts ±6%. Simulates business
#                                 cycles / neighborhood changes / inflation.
#   E. Random stockouts (~1%)   — qty forced to 0. Reflects supply reality.
#
# Run:  python python/generate_synthetic.py            -> python/synthetic_sales.csv
#       python python/generate_synthetic.py --dirty    -> defect-injected variant,
#                                                         fixtures and a manifest
# Out:  ~940 days × 20 products ≈ 18k rows

import argparse
import csv
import hashlib
import json
import os
import random
from datetime import date, datetime, timedelta

# ── Configuration ─────────────────────────────────────────────────────────────
# The window deliberately begins after COVID. The Philippines lifted its state of
# public health emergency on 22 July 2023 (Proclamation 297), so August 2023 is
# the first clean month: no quarantine levels, no panic-buying spikes, no
# mobility restrictions suppressing footfall. Pandemic-era demand is not a
# pattern worth teaching a forecaster — it's a shock that will not repeat, and
# leaving it in would have Prophet fitting trend and seasonality to conditions
# that no longer exist.
SEED        = 42
START_DATE  = date(2023, 8, 1)
END_DATE    = date(2026, 2, 28)
OUTPUT_FILE = os.path.join(os.path.dirname(__file__), 'synthetic_sales.csv')

# ── Product catalogue ────────────────────────────────────────────────────────
# (name, sku, category, base_demand, trend_total_pct, cost, price, holiday_sensitivity)
# 20 real, widely-recognized Philippine sari-sari brands — every one a fast
# mover relative to a store this size (base_demand comfortably above
# LONGTAIL_THRESHOLD; see the noise knobs below), no slow-moving long tail.
#
# base_demand is sized for a small-to-medium neighborhood store, not a
# convenience-chain outlet: ~₱7,000/day average revenue, ~₱15,000 on a
# Christmas/payday peak. (An earlier pass sized these for a store roughly
# 5.8x bigger — corrected here, along with LONGTAIL_THRESHOLD, which scales
# with it: "fast-moving" is relative to what a store this size actually
# expects to sell, not a universal unit count.)
#
# The bakery/fresh lines (pandesal, eggs, hotdog) are genuinely perishable in
# real life — worth declaring as such via the forecast page's batch editor
# after import, to exercise the Newsvendor's shelf-life cap. That's a manual
# step, not something this generator can express.
# sku follows a typical retail-export style (category prefix + short code).
# holiday_sensitivity: 1.0 = full seasonal swing, 0.3 = mostly flat (toiletries
# don't surge for Christmas the way food/beverages do); 1.3 = beer, which spikes
# extra-hard on Friday paydays and holidays.
PRODUCTS = [
    # Pantry & grocery staples
    ('Golden Fiesta Cooking Oil 1L',   'PNT-OIL-1L',  'Pantry',        5,  0.05,  60.00,  75.00, 1.0),
    ('Lucky Me Pancit Canton',         'PNT-LMC-55G', 'Pantry',       14,  0.10,  10.00,  14.00, 1.0),

    # Canned goods — payday "stock the pantry" staples
    ('Argentina Corned Beef 175g',     'CAN-ACB-175', 'Canned Goods',  4,  0.00,  45.00,  55.00, 1.0),
    ('Century Tuna Flakes in Oil',     'CAN-CTF-155', 'Canned Goods',  4,  0.05,  38.00,  48.00, 1.0),

    # Beverages
    ('Nescafé 3-in-1 Original',        'BEV-N3N-01',  'Beverages',     8,  0.05,   5.00,   7.00, 0.7),
    ('San Miguel Pale Pilsen 330ml',   'BEV-SMB-330', 'Beverages',     6,  0.10,  50.00,  65.00, 1.3),
    ('Coca-Cola 1.5L',                 'BEV-CCR-15L', 'Beverages',     7,  0.06,  55.00,  70.00, 1.1),
    ('Milo Powdered Drink 33g Sachet', 'BEV-MIL-33',  'Beverages',     9,  0.08,   6.00,   9.00, 0.8),
    ('Kopiko 3-in-1 Coffee Sachet',    'BEV-KPK-01',  'Beverages',     9,  0.07,   6.00,   9.00, 0.7),

    # Snacks
    ('Sky Flakes Crackers',            'SNK-SKF-01',  'Snacks',        7,  0.04,   8.00,  12.00, 0.6),
    ('Chippy Barbecue 110g',           'SNK-CHP-110', 'Snacks',        7,  0.05,  12.00,  18.00, 0.7),

    # Household & personal care
    ('Surf Detergent Powder 65g',      'HSH-SRF-65',  'Household',     6,  -0.05,  6.00,   9.00, 0.5),
    ('Safeguard Soap 90g',             'BDY-SFG-90',  'Body Care',     5,  0.00,  22.00,  30.00, 0.4),
    ('Colgate Toothpaste 150g',        'BDY-COL-150', 'Body Care',     4,  0.03,  55.00,  72.00, 0.3),
    ('Palmolive Shampoo Sachet',       'BDY-PLM-01',  'Body Care',     8,  0.04,   5.00,   8.00, 0.35),
    ('Datu Puti Soy Sauce 385ml',      'CON-DTP-385', 'Condiments',    5,  -0.03, 18.00,  25.00, 0.5),

    # Dairy
    ('Bear Brand Powdered Milk 320g',  'DRY-BBM-320', 'Dairy',         4,  -0.10, 70.00,  90.00, 0.7),

    # Bakery & fresh
    ('Pandesal 10pcs',                 'BKY-PDS-10',  'Bakery',       10,  0.05,  45.00,  60.00, 0.8),
    ('Fresh Eggs Tray 30s',            'FRS-EGG-30',  'Fresh',         5,  0.05, 210.00, 260.00, 0.9),
    ('CDO Hotdog 1kg',                 'FRS-HTD-1K',  'Fresh',         4,  0.10, 180.00, 230.00, 1.0),
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
LONGTAIL_THRESHOLD  = 3      # base_demand <= this is considered long-tail — scaled down
                              # along with base_demand for the small-store sizing above;
                              # every product in PRODUCTS still clears it (min is 4)
ANOMALY_PROB        = 0.02   # 2% of (product, day) pairs get an anomaly multiplier
ANOMALY_LO          = 0.55   # anomaly multiplier range — covers brownouts on the low end
ANOMALY_HI          = 1.45   # ... and neighborhood fiestas on the high end
STOCKOUT_PROB       = 0.01   # 1% chance of forced qty=0 (supplier delay etc.)
YEAR_DRIFT_STD      = 0.06   # per-year baseline shift std (±6% one-sigma)


# ── Generation ───────────────────────────────────────────────────────────────

# No cosmetic/unused column here anymore. A transaction_id would only be
# meaningful on a per-sale row; this generator emits one row per (product, day)
# — already the daily total, not an individual sale — so a transaction_id would
# just be a fake label stamped on an aggregate. Every column below is one the
# importer actually maps.
HEADER = [
    'date', 'product', 'sku', 'quantity', 'category', 'cost_price', 'selling_price',
]


class RawLine(str):
    """A line written verbatim, bypassing csv.writer.

    Structural defects (a stray unquoted comma, a ragged row, a blank line) only
    exist as defects because a *correct* writer would quote them away. Marking
    them lets write_csv emit the exact bytes a broken export would contain.
    """
    terminator = '\r\n'

    def __new__(cls, text, terminator='\r\n'):
        obj = super().__new__(cls, text)
        obj.terminator = terminator
        return obj


def build_rows(rng):
    """Generate the clean row set.

    Returns (rows, stats). Kept separate from writing so dirty mode is a pure
    post-processing pass over the result — it cannot perturb this random stream
    by construction, which is what keeps the clean output byte-identical.
    """
    # Per-year baseline drift — sampled once per calendar year and applied to
    # all days that year. Simulates inflation, neighborhood change, a competitor
    # opening, etc. Same drift across all products keeps the chart story coherent.
    year_drift = {
        y: max(0.5, rng.gauss(1.0, YEAR_DRIFT_STD))
        for y in range(START_DATE.year, END_DATE.year + 1)
    }

    rows         = []
    skipped_zero = 0
    stockouts    = 0
    anomalies    = 0

    current = START_DATE
    while current <= END_DATE:
        for name, sku, category, base, trend_pct, cost, price, sens in PRODUCTS:
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

            rows.append([
                current.strftime('%Y-%m-%d'),
                name,
                sku,
                qty,
                category,
                f'{cost:.2f}',
                f'{price:.2f}',
            ])
        current += timedelta(days=1)

    stats = {
        'row_count':    len(rows),
        'skipped_zero': skipped_zero,
        'stockouts':    stockouts,
        'anomalies':    anomalies,
        'year_drift':   year_drift,
        'total_days':   (END_DATE - START_DATE).days + 1,
    }
    return rows, stats


def write_csv(path, header, items, *, encoding='utf-8', bom=False, delimiter=','):
    """Write header + items. A list goes through csv.writer; a RawLine is
    emitted verbatim so structural defects survive."""
    with open(path, 'w', newline='', encoding=encoding) as f:
        if bom:
            f.write('﻿')
        writer = csv.writer(f, delimiter=delimiter)
        writer.writerow(header)
        for item in items:
            if isinstance(item, RawLine):
                f.write(str(item) + item.terminator)
            else:
                writer.writerow(item)


def print_summary(stats, path):
    total_days = stats['total_days']
    anomalies  = stats['anomalies']
    stockouts  = stats['stockouts']
    pairs      = total_days * len(PRODUCTS)

    print(f'Wrote {path}')
    print(f'  Range:        {START_DATE} -> {END_DATE} ({total_days} days)')
    print(f'  Products:     {len(PRODUCTS)}')
    print(f'  Rows:         {stats["row_count"]:,} written, {stats["skipped_zero"]:,} skipped (qty=0)')
    print(f'  Anomalies:    {anomalies:,} ({anomalies/pairs*100:.1f}% of pairs)')
    print(f'  Stockouts:    {stockouts:,} ({stockouts/pairs*100:.1f}% of pairs)')
    print(f'  Year drift:   ' + ', '.join(f'{y}={v:.2f}x' for y, v in stats['year_drift'].items()))
    print(f'  Seed:         {SEED}  (re-run produces identical output)')


# ══ Dirty mode ═══════════════════════════════════════════════════════════════
# Everything below exists to answer one question: does ProVendor accept a real
# export *as-is*? The clean file above proves the happy path. These defects are
# the ones that actually turn up in Excel/POS exports — no injection strings, no
# pathological cases. Each is recorded in a manifest with the outcome it should
# produce, so "did the importer do the right thing across 18k rows" is checkable
# rather than a matter of squinting at a preview table.

DIRT_SEED = 4242

# preflight.php sniffs the date format from roughly the first 50 data rows, and
# the DD/MM-vs-MM/DD choice it makes there is applied to the entire file. A date
# defect inside that window silently reinterprets all 18k rows and invalidates
# every other expectation in the manifest, so the window is left strictly clean.
SNIFF_WINDOW = 50

COL = {name: i for i, name in enumerate(HEADER)}

NBSP = ' '

DIRTY_OUTPUT = os.path.join(os.path.dirname(__file__), 'synthetic_sales_dirty.csv')
DIRTY_DIR    = os.path.join(os.path.dirname(__file__), 'dirty')


class EditPlan:
    """Collects defects, then applies them in one pass.

    Two rules matter. Only one defect ever lands on a row — preflight reports the
    *first* failing reason, so a row with two defects has an ambiguous expected
    outcome. And targets are drawn from a sorted candidate list, never by
    iterating a set, because set order is not stable across runs.
    """

    def __init__(self, n, rng, protect_before=0):
        self.n              = n
        self.rng            = rng
        self.protect_before = protect_before
        self.taken          = set()
        self.edits          = {}   # row_index -> manifest entry (cells already mutated)
        self.inserts        = {}   # row_index -> list of (payload, entry)

    def pick(self, count, predicate=None):
        eligible = [
            i for i in range(self.protect_before, self.n)
            if i not in self.taken and (predicate is None or predicate(i))
        ]
        count = min(count, len(eligible))
        chosen = self.rng.sample(sorted(eligible), count)
        for i in chosen:
            self.taken.add(i)
        return sorted(chosen)

    def edit(self, rows, idx, field, new_value, *, defect_id, goal, expect,
             expect_value=None, expect_reason=None, note=''):
        col = COL[field]
        entry = {
            'defect_id':     defect_id,
            'goal':          goal,
            'field':         field,
            'original':      str(rows[idx][col]),
            'dirty':         str(new_value),
            'expect':        expect,
            'expect_value':  expect_value,
            'expect_reason': expect_reason,
            'note':          note,
        }
        rows[idx][col] = new_value
        self.edits[idx] = entry

    def insert_before(self, idx, payload, *, defect_id, goal, expect,
                      expect_reason=None, note=''):
        self.inserts.setdefault(idx, []).append((payload, {
            'defect_id':     defect_id,
            'goal':          goal,
            'field':         None,
            'original':      None,
            'dirty':         payload if isinstance(payload, str) else ','.join(map(str, payload)),
            'expect':        expect,
            'expect_value':  None,
            'expect_reason': expect_reason,
            'note':          note,
        }))

    def apply(self, rows):
        """Emit the final item list and stamp each defect with its real csv row.

        Row numbers follow fgetcsv: the header is row 1, the first data row is 2,
        and blank or ragged lines still consume a number. Numbers are assigned
        here, during emission, because inserts shift everything after them.
        """
        items, entries = [], []
        for idx, row in enumerate(rows):
            for payload, entry in self.inserts.get(idx, []):
                items.append(payload)
                entry['csv_row'] = len(items) + 1
                entries.append(entry)
            items.append(row)
            if idx in self.edits:
                entry = self.edits[idx]
                entry['csv_row'] = len(items) + 1
                entries.append(entry)
        entries.sort(key=lambda e: e['csv_row'])
        return items, entries


# ── Injectors ────────────────────────────────────────────────────────────────
# Each takes (rows, plan) and applies one family of defects. They run in a fixed
# order so output stays reproducible.

def _inject_rejects(rows, plan):
    """Goal (a): must be flagged invalid with a readable reason, never imported."""
    for i in plan.pick(8):
        plan.edit(rows, i, 'quantity', '0', defect_id='qty_zero', goal='a',
                  expect='reject',
                  expect_reason='Quantity must be a whole number between 1 and 999,999 (got "0")',
                  note='POS exports log stockout days as 0; must not count as a sale.')
    for i in plan.pick(4):
        plan.edit(rows, i, 'quantity', '-2', defect_id='qty_negative', goal='a',
                  expect='reject', note='Refund / return line.')
    for i in plan.pick(4):
        plan.edit(rows, i, 'quantity', '2.5', defect_id='qty_decimal', goal='a',
                  expect='reject', note='Weighed goods entered as a fraction.')
    for i in plan.pick(3):
        plan.edit(rows, i, 'quantity', '1,200', defect_id='qty_thousands', goal='a',
                  expect='reject', note='Excel thousands formatting; must not become 1.')
    for i in plan.pick(3):
        plan.edit(rows, i, 'quantity', '', defect_id='qty_missing', goal='a',
                  expect='reject', expect_reason='Missing quantity')
    for i in plan.pick(3):
        plan.edit(rows, i, 'product', '', defect_id='product_missing', goal='a',
                  expect='reject', expect_reason='Missing product name')
    for i in plan.pick(3):
        plan.edit(rows, i, 'date', '', defect_id='date_missing', goal='a',
                  expect='reject', expect_reason='Missing date')
    for i in plan.pick(3):
        plan.edit(rows, i, 'date', '31/1/24', defect_id='date_two_digit_year', goal='a',
                  expect='reject',
                  note='Legacy POS 2-digit year. Rejected by the year>1000 guard, not read as 2024.')
    for i in plan.pick(3):
        plan.edit(rows, i, 'date', '03-05-2024', defect_id='date_ambiguous_numeric', goal='a',
                  expect='reject',
                  note='Both slots <=12 in an ISO file. recoverUnambiguousDate deliberately '
                       'refuses to re-guess this — the key anti-corruption assertion.')
    for i in plan.pick(2):
        plan.edit(rows, i, 'product', 'Assorted Sari-Sari Goods Bundle Pack Family Size '
                                      'Promotional Combo With Free Sachet Items Included Extra',
                  defect_id='product_too_long', goal='a', expect='reject',
                  expect_reason='Product name exceeds 100 characters',
                  note='105 chars. The one length rule PHP already enforced.')


def _inject_salvage(rows, plan):
    """Goal (b): messy but recoverable — must import correctly, no hand-cleaning."""
    for i in plan.pick(6):
        plan.edit(rows, i, 'product', '  ' + rows[i][COL['product']] + '  ',
                  defect_id='ws_padding', goal='b', expect='salvage',
                  expect_value=rows[i][COL['product']], note='Excel cell padding; plain trim().')
    for i in plan.pick(4):
        iso = rows[i][COL['date']]
        plan.edit(rows, i, 'date', iso + ' 14:30:00', defect_id='date_iso_timestamp',
                  goal='b', expect='salvage', expect_value=iso,
                  note='POS transaction time appended.')
    for i in plan.pick(4):
        d = datetime.strptime(rows[i][COL['date']], '%Y-%m-%d')
        plan.edit(rows, i, 'date', d.strftime('%-d-%b-%y') if os.name != 'nt'
                                   else d.strftime('%d-%b-%y').lstrip('0'),
                  defect_id='date_excel_dmmmyy', goal='b', expect='salvage',
                  expect_value=d.strftime('%Y-%m-%d'),
                  note="Excel's default date display, e.g. 5-Jan-24.")
    for i in plan.pick(3):
        d = datetime.strptime(rows[i][COL['date']], '%Y-%m-%d')
        plan.edit(rows, i, 'date', d.strftime('%B %d, %Y'), defect_id='date_named_month',
                  goal='b', expect='salvage', expect_value=d.strftime('%Y-%m-%d'),
                  note='Month name pins the meaning, so it is unambiguous.')
    for i in plan.pick(3, predicate=lambda i: int(rows[i][COL['date']][8:10]) > 12):
        d = datetime.strptime(rows[i][COL['date']], '%Y-%m-%d')
        plan.edit(rows, i, 'date', d.strftime('%d/%m/%Y'), defect_id='date_dmy_unambiguous',
                  goal='b', expect='salvage', expect_value=d.strftime('%Y-%m-%d'),
                  note='Day > 12 self-disambiguates even inside an ISO file.')
    for i in plan.pick(6):
        plan.edit(rows, i, 'product', rows[i][COL['product']].upper(),
                  defect_id='case_variant', goal='b', expect='aggregate',
                  note='Multi-cashier entry. Must merge with the existing product, '
                       'not create a second one.')


def _inject_must_not_corrupt(rows, plan):
    """Goal (c): the dangerous ones — these import "fine" but can be wrong."""
    # Money formatting. Before the importer learned to parse these they became
    # NULL silently — no error, no warning, the money simply vanished.
    for i in plan.pick(6):
        plan.edit(rows, i, 'cost_price', '₱' + rows[i][COL['cost_price']],
                  defect_id='money_peso_sign', goal='c', expect='salvage',
                  expect_value=rows[i][COL['cost_price']],
                  note='Excel currency formatting. Regression test for parseMoney().')
    for i in plan.pick(4):
        plan.edit(rows, i, 'selling_price', '1,250.00', defect_id='money_thousands',
                  goal='c', expect='salvage', expect_value='1250.00',
                  note='Thousands separator must resolve to 1250, not 1.')
    for i in plan.pick(4):
        plan.edit(rows, i, 'cost_price', '240,00', defect_id='money_euro_comma',
                  goal='c', expect='warn',
                  expect_reason='Not a number — left empty',
                  note='European decimal comma. Must NOT silently become 24000 — '
                       'left empty and flagged instead.')
    for i in plan.pick(4):
        plan.edit(rows, i, 'cost_price', 'N/A', defect_id='money_na', goal='c',
                  expect='warn', expect_reason='Not a number — left empty',
                  note='Broken Excel formula.')
    # DB-limit defects. With STRICT_TRANS_TABLES off these were silently
    # truncated/clamped by MySQL; they are now clamped in PHP and flagged.
    for i in plan.pick(4):
        plan.edit(rows, i, 'category',
                  'Grocery > Canned Goods > Fish > Sardines in Tomato Sauce',
                  defect_id='category_too_long', goal='c', expect='warn',
                  expect_reason='Shortened to 50 characters to fit',
                  note='56 chars vs VARCHAR(50). Previously truncated silently by MySQL.')
    for i in plan.pick(3):
        plan.edit(rows, i, 'sku', 'SUP-' + ('4821-' * 24), defect_id='sku_too_long',
                  goal='c', expect='warn',
                  expect_reason='Shortened to 100 characters to fit',
                  note='124 chars vs VARCHAR(100).')
    for i in plan.pick(3):
        plan.edit(rows, i, 'selling_price', '1234567890.00', defect_id='price_overflow',
                  goal='c', expect='warn',
                  expect_reason='Left empty — exceeds the maximum of 99,999,999.99',
                  note='Past DECIMAL(10,2). MySQL used to store this as 99999999.99 — '
                       'a fabricated but plausible price.')
    # Invisible characters — the quietest corruption of all.
    for i in plan.pick(20):
        plan.edit(rows, i, 'product', rows[i][COL['product']] + NBSP,
                  defect_id='product_nbsp_suffix', goal='c', expect='corrupts',
                  note='trim() does not strip U+00A0, so this silently becomes a SECOND '
                       'product and the series loses these days.')
    for i in plan.pick(2):
        plan.edit(rows, i, 'quantity', '1e3', defect_id='qty_scientific', goal='c',
                  expect='accept_surprising', expect_value='1000',
                  note='Excel scientific-notation cast. Imports as 1000.')
    for i in plan.pick(3):
        plan.edit(rows, i, 'sku', '4.80E+12', defect_id='sku_scientific', goal='c',
                  expect='accept_surprising',
                  note='Excel mangling a 13-digit barcode into scientific notation.')


def _inject_boundaries(rows, plan):
    """The exact edges of each rule. Bulk orders and stocktakes really do land on
    these, and an off-by-one here is the difference between a kept and a lost sale."""
    for i in plan.pick(2):
        plan.edit(rows, i, 'quantity', '1', defect_id='qty_boundary_min', goal='b',
                  expect='salvage', expect_value='1', note='Lower bound, valid.')
    for i in plan.pick(2):
        plan.edit(rows, i, 'quantity', '999999', defect_id='qty_boundary_max', goal='b',
                  expect='salvage', expect_value='999999', note='Upper bound, valid.')
    for i in plan.pick(2):
        plan.edit(rows, i, 'quantity', '1000000', defect_id='qty_over_max', goal='a',
                  expect='reject',
                  expect_reason='Quantity must be a whole number between 1 and 999,999 (got "1000000")',
                  note='One past the bound.')
    for i in plan.pick(3):
        plan.edit(rows, i, 'quantity', '5.0', defect_id='qty_decimal_zero', goal='b',
                  expect='salvage', expect_value='5',
                  note='Excel numeric cast. A whole number written as a decimal is valid.')
    for i in plan.pick(2):
        plan.edit(rows, i, 'date', '2023-02-29', defect_id='date_nonleap_feb29', goal='a',
                  expect='reject',
                  note='2023 is not a leap year. createFromFormat would roll this over to '
                       'Mar 1 — the warning check is what catches it.')
    # Same product, same date, split across two lines — a re-exported overlapping
    # period. preflight aggregates these, so the quantities must sum.
    for i in plan.pick(4):
        dup = list(rows[i])
        original_qty = int(rows[i][COL['quantity']])
        half = max(1, original_qty // 2)
        dup[COL['quantity']] = str(half)
        rows[i][COL['quantity']] = str(original_qty)
        plan.insert_before(i, dup, defect_id='duplicate_pair', goal='b',
                           expect='aggregate',
                           note=f'Duplicate of the next row; the pair must aggregate to '
                                f'{original_qty + half}, not create two rows or overwrite.')


def _inject_structural(rows, plan):
    """Goal (d): file-level chaos. The file must survive; bad lines are flagged."""
    width = len(HEADER)
    for i in plan.pick(3):
        plan.insert_before(i, RawLine(''), defect_id='blank_line', goal='d',
                           expect='reject',
                           expect_reason='Row has wrong number of columns',
                           note='Excel leaves these behind.')
    for i in plan.pick(3):
        short = ','.join(str(c) for c in rows[i][:width - 1])
        plan.insert_before(i, RawLine(short), defect_id='ragged_short', goal='d',
                           expect='reject',
                           expect_reason='Row has wrong number of columns',
                           note='Truncated export line.')
    for i in plan.pick(3):
        cells = [str(c) for c in rows[i]]
        cells[COL['product']] = 'Rice, Premium 5kg'   # deliberately unquoted
        plan.insert_before(i, RawLine(','.join(cells)), defect_id='ragged_long', goal='d',
                           expect='reject',
                           expect_reason='Row has wrong number of columns',
                           note='Unquoted comma inside a field shifts every later column.')
    for i in plan.pick(2):
        plan.insert_before(i, list(HEADER), defect_id='repeated_header', goal='d',
                           expect='reject',
                           note='Concatenated monthly exports. Must not import as data.')
    for i in plan.pick(1):
        total = [''] * width
        total[COL['product']]  = 'Total'
        total[COL['quantity']] = '18092'
        plan.insert_before(i, total, defect_id='excel_total_row', goal='d',
                           expect='reject', expect_reason='Missing date',
                           note='Excel subtotal row. Must not inflate quantities.')
    for i in plan.pick(1):
        cells = [str(c) for c in rows[i]]
        cells[COL['sku']] = 'Delivered 8am\nvia Ramon'
        plan.insert_before(i, cells, defect_id='quoted_newline', goal='d',
                           expect='salvage',
                           note='Quoted embedded newline is ONE record — validates that the '
                                'manifest row numbers line up with fgetcsv. Which column carries '
                                'it is incidental; this tests parsing, not content.')


SCATTER_INJECTORS = [
    _inject_rejects,
    _inject_salvage,
    _inject_boundaries,
    _inject_must_not_corrupt,
    _inject_structural,
]


def dirtify(rows, drng):
    plan = EditPlan(len(rows), drng, protect_before=SNIFF_WINDOW)
    for injector in SCATTER_INJECTORS:
        injector(rows, plan)
    return plan.apply(rows)


# ── Single-property fixture files ────────────────────────────────────────────
# These can't be scattered into the main file: each one changes how the *whole*
# file is interpreted (or used to abort it outright), so mixing them together
# would mean only the first is ever observed.

def _fixture_rows(n=12, start=date(2029, 1, 2)):
    """A small block of clean, unambiguous rows. In a fixture these are the
    instrument: they show whether the good rows survived alongside the defect."""
    out = []
    for i in range(n):
        name, sku, category, base, _, cost, price, _ = PRODUCTS[i % len(PRODUCTS)]
        d = start + timedelta(days=i)
        out.append([d.strftime('%Y-%m-%d'), name, sku, str(10 + i), category,
                    f'{cost:.2f}', f'{price:.2f}'])
    return out


def _manifest_file(path, tier, expect_file, entries, extra=None, purpose=''):
    with open(path, 'rb') as f:
        digest = hashlib.sha256(f.read()).hexdigest()
    entry = {
        'path':        os.path.relpath(path, os.path.dirname(os.path.dirname(path))).replace('\\', '/'),
        'sha256':      digest,
        'tier':        tier,
        'purpose':     purpose,
        'expect_file': expect_file,
        'defects':     entries,
    }
    if extra:
        entry['predicted'] = extra
    return entry


def build_fixture_files():
    """Write one file per whole-file property. Returns manifest entries."""
    files = []

    def emit(name, purpose, expect_file, rows, defects, **kw):
        p = os.path.join(DIRTY_DIR, name)
        write_csv(p, kw.pop('header', HEADER), rows, **kw)
        files.append(_manifest_file(p, 'fixture', expect_file, defects, purpose=purpose))

    # 1. Windows ANSI encoding — what Excel writes by default on Windows.
    rows = _fixture_rows()
    rows[3][COL['product']] = 'Nescafé 3-in-1 Original'
    emit('killer_encoding_cp1252.csv',
         'Non-UTF8 (Windows-1252) bytes, as Excel saves by default.',
         'imports', rows,
         [{'csv_row': 5, 'defect_id': 'encoding_cp1252', 'goal': 'c', 'field': 'product',
           'original': 'Nescafé 3-in-1 Original', 'dirty': '<cp1252 bytes>',
           'expect': 'salvage', 'expect_value': 'Nescafé 3-in-1 Original',
           'expect_reason': None,
           'note': 'Before the fix json_encode() returned false on these bytes, so detect.php '
                   'sent an EMPTY body and the wizard died with no message. Must now convert '
                   'on upload and report encoding_converted=true.'}],
         encoding='cp1252')

    # 2-4. The three DB-limit aborts. 11 clean rows each prove no rollback.
    for name, field, value, purpose, reason in [
        ('killer_category_length.csv', 'category',
         'Grocery > Canned Goods > Fish > Sardines in Tomato Sauce',
         'Category longer than VARCHAR(50).', 'Shortened to 50 characters to fit'),
        ('killer_sku_length.csv', 'sku', 'SUP-' + ('4821-' * 24),
         'SKU longer than VARCHAR(100).', 'Shortened to 100 characters to fit'),
        ('killer_price_overflow.csv', 'selling_price', '1234567890.00',
         'Price past DECIMAL(10,2).', 'Left empty — exceeds the maximum of 99,999,999.99'),
    ]:
        rows = _fixture_rows()
        original = rows[5][COL[field]]
        rows[5][COL[field]] = value
        emit(name, purpose, 'imports', rows,
             [{'csv_row': 7, 'defect_id': os.path.splitext(name)[0], 'goal': 'c',
               'field': field, 'original': original, 'dirty': value,
               'expect': 'warn', 'expect_value': None, 'expect_reason': reason,
               'note': 'All 12 rows must import. Previously MySQL silently truncated/clamped '
                       'this (STRICT_TRANS_TABLES off) or aborted the entire import (on).'}])

    # 5-7. Column auto-detection traps. Detection is substring, first-hit-wins.
    rows = _fixture_rows()
    emit('mapping_product_code_steals_product.csv',
         'A "Product Code" column claims the product slot before the name column.',
         'needs_manual_mapping',
         [[r[COL['date']], r[COL['sku']], r[COL['product']], r[COL['quantity']]] for r in rows],
         [{'csv_row': None, 'defect_id': 'mapping_product_code', 'goal': 'd', 'field': None,
           'original': None, 'dirty': 'Product Code,Item Description',
           'expect': 'warn', 'expect_value': None, 'expect_reason': None,
           'note': '"Product Code" contains "product" and is tested first, so the SKU column '
                   'is suggested as the product name. Must be visible and overridable.'}],
         header=['Date', 'Product Code', 'Item Description', 'Qty'])

    rows = _fixture_rows()
    # A plausible-looking subcategory value per row — the fixture only cares
    # about column ORDER stealing the mapping slot, not the value's realism.
    subcats = ['Small Pack', 'Family Size', 'Value Pack', 'Sachet']
    emit('mapping_subcategory_steals_category.csv',
         'A Subcategory column placed before Category steals the category slot.',
         'needs_manual_mapping',
         [[r[COL['date']], r[COL['product']], r[COL['quantity']], subcats[i % len(subcats)],
           r[COL['category']]] for i, r in enumerate(rows)],
         [{'csv_row': None, 'defect_id': 'mapping_subcategory_first', 'goal': 'd', 'field': None,
           'original': None, 'dirty': 'Subcategory before Category',
           'expect': 'warn', 'expect_value': None, 'expect_reason': None,
           'note': '"subcategory" contains "category" and the category test runs first.'}],
         header=['Date', 'Product', 'Qty', 'Subcategory', 'Category'])

    rows = _fixture_rows()
    emit('mapping_amount_steals_qty.csv',
         'An "Amount" line-total column can be picked as quantity.',
         'needs_manual_mapping',
         [[r[COL['date']], r[COL['product']], f'{int(r[COL["quantity"]]) * 60:.2f}',
           r[COL['quantity']]] for r in rows],
         [{'csv_row': None, 'defect_id': 'mapping_amount_steals_qty', 'goal': 'd', 'field': None,
           'original': None, 'dirty': 'Amount before Qty Sold',
           'expect': 'warn', 'expect_value': None, 'expect_reason': None,
           'note': '"amount" is a quantity keyword and the column is numeric, so peso totals '
                   'would be imported as units sold.'}],
         header=['Date', 'Product', 'Amount', 'Qty Sold'])

    # 8. Ambiguous dates with nothing to disambiguate them.
    rows = _fixture_rows()
    for i, r in enumerate(rows):
        d = datetime.strptime(r[COL['date']], '%Y-%m-%d')
        r[COL['date']] = f'0{(i % 9) + 1}/0{(i % 8) + 1}/2029'
    emit('sniff_ambiguous_dates.csv',
         'Every date has both slots <= 12, so DD/MM vs MM/DD cannot be resolved.',
         'imports_with_warning', rows,
         [{'csv_row': None, 'defect_id': 'sniff_ambiguous', 'goal': 'c', 'field': 'date',
           'original': None, 'dirty': 'dd/mm/yyyy, all slots <= 12',
           'expect': 'warn', 'expect_value': None, 'expect_reason': None,
           'note': 'date_format.ambiguous must be true AND the wizard must show the warning. '
                   'A missing warning here is a silent-corruption bug — every date could be '
                   'transposed.'}])

    # 9. European Excel writes semicolon-delimited CSV.
    emit('structural_semicolon.csv',
         'Semicolon delimiter, as European/PH Excel locales write.',
         'fails_mapping', _fixture_rows(),
         [{'csv_row': None, 'defect_id': 'structural_semicolon', 'goal': 'd', 'field': None,
           'original': None, 'dirty': '; delimiter',
           'expect': 'abort', 'expect_value': None,
           'expect_reason': 'detect: parses as a single column, mapping impossible',
           'note': 'fgetcsv is hard-coded to comma. Must give a clear "couldn\'t find your '
                   'columns" message, not a stack trace.'}],
         delimiter=';')

    # 10. Negative control — a BOM is what Excel's "CSV UTF-8" writes, and it works.
    emit('structural_bom.csv',
         'UTF-8 BOM (Excel "CSV UTF-8"). Negative control: must import cleanly.',
         'imports', _fixture_rows(),
         [{'csv_row': None, 'defect_id': 'structural_bom', 'goal': 'd', 'field': None,
           'original': None, 'dirty': 'UTF-8 BOM prefix',
           'expect': 'salvage', 'expect_value': None, 'expect_reason': None,
           'note': 'Already handled — stripped from headers[0]. Guards against regression.'}],
         bom=True)

    return files


def generate(dirty=False, out_path=None, seed=SEED, dirt_seed=DIRT_SEED,
             fixtures=True, manifest_path=None):
    rng = random.Random(seed)
    rows, stats = build_rows(rng)

    if not dirty:
        path = out_path or OUTPUT_FILE
        write_csv(path, HEADER, rows)
        print_summary(stats, path)
        return

    path = out_path or DIRTY_OUTPUT
    drng = random.Random(dirt_seed)
    items, entries = dirtify(rows, drng)
    write_csv(path, HEADER, items)

    files = [_manifest_file(path, 'scatter', 'imports', entries, extra={
        'csv_rows': len(items),
    })]
    if fixtures:
        os.makedirs(DIRTY_DIR, exist_ok=True)
        files.extend(build_fixture_files())

    mpath = manifest_path or os.path.splitext(path)[0] + '.manifest.json'
    with open(mpath, 'w', encoding='utf-8') as f:
        json.dump({
            'generator': {
                'script':        'python/generate_synthetic.py',
                'seed':          seed,
                'dirt_seed':     dirt_seed,
                'row_numbering': 'fgetcsv: header = row 1, first data row = 2. Blank and '
                                 'ragged lines consume a number. A quoted embedded newline '
                                 'is ONE record.',
                'expect_vocabulary': {
                    'reject':            'preflight marks the row invalid; expect_reason is the exact string',
                    'salvage':           'row imports; expect_value is the normalized result',
                    'aggregate':         'row merges with another; quantities sum',
                    'warn':              'row imports but is flagged for review (lossy auto-fix)',
                    'corrupts':          'must-not-corrupt assertion; a failure here is a bug',
                    'accept_surprising': 'accepted, but arguably should not be',
                    'abort':             'whole file fails; expect_reason names the stage',
                },
            },
            'files': files,
        }, f, indent=2, ensure_ascii=False)

    by_goal = {}
    for e in entries:
        by_goal.setdefault(e['goal'], []).append(e)
    goal_names = {'a': 'rejects cleanly', 'b': 'salvages', 'c': 'must not corrupt',
                  'd': 'survives structure'}

    print(f'Wrote {path}')
    print(f'  Rows:         {len(items):,} lines ({stats["row_count"]:,} clean + '
          f'{len(items) - stats["row_count"]:,} injected)')
    print(f'  Defects:      {len(entries)}')
    for g in sorted(by_goal):
        ids = {}
        for e in by_goal[g]:
            ids[e['defect_id']] = ids.get(e['defect_id'], 0) + 1
        print(f'    ({g}) {goal_names[g]:<20} {len(by_goal[g]):>3}  '
              + ', '.join(f'{k} x{v}' for k, v in sorted(ids.items())))
    if fixtures:
        print(f'  Fixtures:     {len(files) - 1} in {DIRTY_DIR}')
    print(f'  Manifest:     {mpath}')
    print(f'  Seeds:        data={seed}, dirt={dirt_seed}  (re-run is identical)')


def main():
    parser = argparse.ArgumentParser(
        description='Generate synthetic sari-sari store sales data.')
    parser.add_argument('--dirty', action='store_true',
                        help='emit the defect-injected variant plus fixtures and a manifest')
    parser.add_argument('--out', dest='out_path', default=None,
                        help='override the output CSV path')
    parser.add_argument('--manifest', dest='manifest_path', default=None,
                        help='override the manifest path (dirty mode only)')
    parser.add_argument('--no-fixtures', dest='fixtures', action='store_false',
                        help='skip the single-defect fixture files')
    parser.add_argument('--seed', type=int, default=SEED)
    parser.add_argument('--dirt-seed', type=int, default=DIRT_SEED)
    args = parser.parse_args()

    generate(dirty=args.dirty, out_path=args.out_path, seed=args.seed,
             dirt_seed=args.dirt_seed, fixtures=args.fixtures,
             manifest_path=args.manifest_path)


if __name__ == '__main__':
    main()
