# python/generate_slow_movers.py
# Generates a slow-mover test dataset in TWO formats from the SAME underlying
# demand, so the effect of recording zero-sales days can be measured directly:
#
#   synthetic_slow_sparse.csv      — zero-sales days omitted (a typical SME
#                                    notebook or POS export: only sales appear)
#   synthetic_slow_zerofilled.csv  — zero-sales days written as qty 0 (what a
#                                    well-kept POS export can provide)
#
# Both files describe the identical store. The only difference is whether the
# days with no sale are recorded. That isolates the variable under test.
#
# The catalogue spans a deliberate gradient, from products that sell nearly
# every day down to ones that sell a few times a month, so the point where
# forecast quality degrades can be located empirically rather than guessed.
#
# Pattern machinery (weekly cycle, payday, holidays, trend, Poisson noise) is
# imported from generate_synthetic.py — same signal, different volumes.
#
# Run:  python python/generate_slow_movers.py

import csv
import os
import random
from datetime import timedelta

from generate_synthetic import (
    SEED, START_DATE, END_DATE,
    _weekly, _monthly, _yearly, _trend, _poisson,
    NOISE_STD_LONGTAIL, ANOMALY_PROB, ANOMALY_LO, ANOMALY_HI, YEAR_DRIFT_STD,
)

SPARSE_FILE = os.path.join(os.path.dirname(__file__), 'synthetic_slow_sparse.csv')
FILLED_FILE = os.path.join(os.path.dirname(__file__), 'synthetic_slow_zerofilled.csv')

# ── Catalogue ────────────────────────────────────────────────────────────────
# (name, category, base_demand_per_day, trend_total_pct, cost, price, sensitivity)
#
# base_demand is the average units per day. The two controls at the top sell
# every day and should behave identically in both formats — they are the proof
# that any difference seen further down is caused by the missing zero days and
# not by the change in file format itself.
PRODUCTS = [
    # ── Controls: fast movers, present for comparison ──
    ('Cooking Oil 1L',              'Pantry',       30.0,  0.05, 60.00, 75.00, 1.0),
    ('Bear Brand Powdered Milk 320g', 'Dairy',      14.0, -0.10, 70.00, 90.00, 0.7),

    # ── The gradient: progressively slower ──
    ('Alaska Evap Milk 370ml',      'Dairy',         4.0,  0.00, 32.00, 42.00, 0.8),
    ('Ligo Sardines 155g',          'Canned Goods',  2.0,  0.05, 18.00, 25.00, 1.0),
    ('Sunsilk Shampoo Sachet',      'Body Care',     1.0,  0.00,  5.00,  8.00, 0.4),
    ('Biogesic 500mg Tablet',       'Medicine',      0.5,  0.00,  4.00,  7.00, 0.3),
    ('AA Batteries (pair)',         'Household',     0.25, 0.00, 35.00, 55.00, 0.3),
    ('Sewing Needle Pack',          'Household',     0.10, 0.00, 12.00, 25.00, 0.2),
]


def generate():
    rng = random.Random(SEED)

    year_drift = {
        y: max(0.5, rng.gauss(1.0, YEAR_DRIFT_STD))
        for y in range(START_DATE.year, END_DATE.year + 1)
    }

    # Build the full ground-truth series in memory first. Both CSVs are written
    # from this same list, so they cannot drift apart.
    records = []   # (date, name, qty, category, cost, price)
    current = START_DATE
    while current <= END_DATE:
        for name, category, base, trend_pct, cost, price, sens in PRODUCTS:
            expected = (base
                        * _weekly(current, sens)
                        * _monthly(current, sens)
                        * _yearly(current, sens)
                        * _trend(current, trend_pct)
                        * year_drift[current.year])

            expected *= max(0.3, rng.gauss(1.0, NOISE_STD_LONGTAIL))

            if rng.random() < ANOMALY_PROB:
                expected *= rng.uniform(ANOMALY_LO, ANOMALY_HI)

            # No forced stockouts here. A stockout zero and a no-demand zero are
            # different things, and mixing them would muddy exactly the question
            # this dataset exists to answer.
            qty = _poisson(expected, rng)
            records.append((current, name, qty, category, cost, price))
        current += timedelta(days=1)

    header = ['date', 'product', 'quantity', 'category', 'cost_price', 'selling_price']

    def write(path, include_zeros):
        n = 0
        with open(path, 'w', newline='', encoding='utf-8') as f:
            w = csv.writer(f)
            w.writerow(header)
            for d, name, qty, category, cost, price in records:
                if qty == 0 and not include_zeros:
                    continue
                w.writerow([d.strftime('%Y-%m-%d'), name, qty, category,
                            f'{cost:.2f}', f'{price:.2f}'])
                n += 1
        return n

    sparse_rows = write(SPARSE_FILE, include_zeros=False)
    filled_rows = write(FILLED_FILE, include_zeros=True)

    total_days = (END_DATE - START_DATE).days + 1

    print(f'Range:    {START_DATE} -> {END_DATE} ({total_days} days)')
    print(f'Sparse:   {SPARSE_FILE}  ({sparse_rows:,} rows)')
    print(f'Filled:   {FILLED_FILE}  ({filled_rows:,} rows)')
    print(f'Seed:     {SEED}\n')
    print(f'{"Product":<32} {"units/day":>10} {"sale days":>10} {"% of days":>10}')
    print('-' * 66)
    for name, _c, base, *_rest in PRODUCTS:
        rows    = [r for r in records if r[1] == name]
        selling = sum(1 for r in rows if r[2] > 0)
        avg     = sum(r[2] for r in rows) / len(rows)
        print(f'{name:<32} {avg:>10.2f} {selling:>10,} {100*selling/len(rows):>9.0f}%')


if __name__ == '__main__':
    generate()
