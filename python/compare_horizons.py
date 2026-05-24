# python/compare_horizons.py
# One-off analysis script: re-runs the backtest endpoint for every product at
# multiple holdout horizons (7 / 30 / 60 days) and prints a comparison table.
# Used to empirically see how holdout length affects MAPE/MAE/RMSE — the
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
    print(f'Each row = one product. Numbers = MAPE% | MAE units | RMSE units\n')

    # Header
    name_w = max(len(n) for _, n in PRODUCTS) + 2
    print(' ' * name_w + '|   7-day holdout   |  30-day holdout   |  60-day holdout')
    print(' ' * name_w + '|  MAPE  MAE  RMSE  |  MAPE  MAE  RMSE  |  MAPE  MAE  RMSE')
    print('-' * (name_w + 60))

    totals = {h: {'mape': [], 'mae': [], 'rmse': []} for h in HORIZONS}

    for pid, name in PRODUCTS:
        cells = []
        for h in HORIZONS:
            d = evaluate(pid, h)
            if 'error' in d:
                cells.append('     err     '.center(19))
                continue
            mape, mae, rmse = d['mape'], d['mae'], d['rmse']
            totals[h]['mape'].append(mape)
            totals[h]['mae'].append(mae)
            totals[h]['rmse'].append(rmse)
            cells.append(f' {mape:>5.1f} {mae:>5.1f} {rmse:>5.1f} ')
        print(f'{name:<{name_w}}|' + '|'.join(cells))

    # Simple (unweighted) averages — gives a feel for direction of change.
    print('-' * (name_w + 60))
    avg_cells = []
    for h in HORIZONS:
        if totals[h]['mape']:
            am = sum(totals[h]['mape']) / len(totals[h]['mape'])
            ae = sum(totals[h]['mae'])  / len(totals[h]['mae'])
            ar = sum(totals[h]['rmse']) / len(totals[h]['rmse'])
            avg_cells.append(f' {am:>5.1f} {ae:>5.1f} {ar:>5.1f} ')
        else:
            avg_cells.append('     —       '.center(19))
    print(f'{"AVERAGE (simple)":<{name_w}}|' + '|'.join(avg_cells))
    print()


if __name__ == '__main__':
    main()
