# python/compare_horizons.py
# One-off analysis script: re-runs the backtest endpoint for every product at
# multiple holdout horizons (7 / 30 / 60 days) and prints a comparison table.
# Used to empirically see how holdout length affects WAPE/MAE — the
# rationale for picking 30 days as the production default.
#
# Run:  python python/compare_horizons.py
# Prereqs: Flask running on localhost:5000, synthetic data imported.

import json
import urllib.request
import urllib.error

USER_ID  = 1
PRODUCTS = [
    (51, 'Rice 5kg'),
    (52, 'Cooking Oil 1L'),
    (53, 'Lucky Me Pancit Canton'),
    (54, 'Argentina Corned Beef 175g'),
    (55, 'Century Tuna Flakes in Oil'),
    (56, 'Nescafe 3-in-1 Original'),
    (57, 'San Miguel Pale Pilsen 330ml'),
    (58, 'Surf Detergent Powder 65g'),
    (59, 'Safeguard Soap 90g'),
    (60, 'Bear Brand Powdered Milk 320g'),
]
HORIZONS = [7, 30, 60]


def evaluate(product_id, horizon):
    body = json.dumps({
        'user_id': USER_ID,
        'product_id': product_id,
        'horizon_days': horizon,
    }).encode()
    req = urllib.request.Request(
        'http://localhost:5000/forecast/product/evaluate',
        data=body,
        headers={'Content-Type': 'application/json'},
    )
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            return json.loads(resp.read())
    except urllib.error.HTTPError as e:
        return {'error': f'HTTP {e.code}: {e.read().decode("utf-8", errors="ignore")}'}
    except Exception as e:
        return {'error': str(e)}


def main():
    print(f'\nComparing backtest horizons (7 / 30 / 60 days) across {len(PRODUCTS)} products')
    print(f'Each row = one product. Numbers = WAPE% | MAE units\n')

    # Header
    name_w = max(len(n) for _, n in PRODUCTS) + 2
    print(' ' * name_w + '|  7-day holdout | 30-day holdout | 60-day holdout')
    print(' ' * name_w + '|   WAPE   MAE   |   WAPE   MAE   |   WAPE   MAE')
    print('-' * (name_w + 48))

    totals = {h: {'wape': [], 'mae': []} for h in HORIZONS}

    for pid, name in PRODUCTS:
        cells = []
        for h in HORIZONS:
            d = evaluate(pid, h)
            if 'error' in d:
                cells.append('   err    '.center(16))
                continue
            wape, mae = d['wape'], d['mae']
            totals[h]['wape'].append(wape)
            totals[h]['mae'].append(mae)
            cells.append(f' {wape:>6.1f} {mae:>6.1f} ')
        print(f'{name:<{name_w}}|' + '|'.join(cells))

    # Simple (unweighted) averages — gives a feel for direction of change.
    print('-' * (name_w + 48))
    avg_cells = []
    for h in HORIZONS:
        if totals[h]['wape']:
            am = sum(totals[h]['wape']) / len(totals[h]['wape'])
            ae = sum(totals[h]['mae'])  / len(totals[h]['mae'])
            avg_cells.append(f' {am:>6.1f} {ae:>6.1f} ')
        else:
            avg_cells.append('    —     '.center(16))
    print(f'{"AVERAGE (simple)":<{name_w}}|' + '|'.join(avg_cells))
    print()


if __name__ == '__main__':
    main()
