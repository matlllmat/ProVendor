# python/compare_zero_handling.py
# Measures what recording zero-sales days does to forecast quality, across a
# gradient from daily-selling to rarely-selling products.
#
# Both formats are trained on the same calendar range and evaluated against the
# same 30-day holdout, so the only variable is whether no-sale days are present.
# Prophet settings and the Newsvendor computation mirror python/app.py exactly.
#
# Run:  python python/compare_zero_handling.py

import warnings, logging, math, os
warnings.filterwarnings('ignore')
for _n in ('prophet', 'cmdstanpy', 'prophet.plot'):
    logging.getLogger(_n).setLevel(logging.CRITICAL)

import pandas as pd
from prophet import Prophet
from scipy.stats import norm

HERE    = os.path.dirname(__file__)
SPARSE  = os.path.join(HERE, 'synthetic_slow_sparse.csv')
FILLED  = os.path.join(HERE, 'synthetic_slow_zerofilled.csv')
HOLDOUT = 30


def load(path):
    df = pd.read_csv(path, parse_dates=['date'])
    return df


def fit_forecast(train, horizon):
    m = Prophet(yearly_seasonality=True, weekly_seasonality=True, daily_seasonality=False)
    m.fit(train)
    fc = m.predict(m.make_future_dataframe(periods=horizon))
    return fc[fc['ds'] > train['ds'].max()].head(horizon)


def newsvendor(fc, cost, price):
    """app.py /optimize, with rho=0 and no stock on hand."""
    mu  = fc['yhat'].clip(lower=0).sum()
    var = (((fc['yhat_upper'] - fc['yhat_lower']) / (2 * 1.96)) ** 2).sum()
    sd  = math.sqrt(var) if var > 0 else mu * 0.2
    z   = norm.ppf((price - cost) / price)
    return max(0, round(mu + z * sd)), mu


def mape(actual, pred):
    return float((abs(actual - pred) / actual.clip(lower=1)).mean() * 100)


def wape(actual, pred):
    return float(abs(actual - pred).sum() / max(actual.sum(), 1) * 100)


sparse_df, filled_df = load(SPARSE), load(FILLED)
cutoff = filled_df['date'].max() - pd.Timedelta(days=HOLDOUT)

products = filled_df['product'].unique()
rows = []

for name in products:
    truth = filled_df[filled_df['product'] == name].sort_values('date')
    cost  = float(truth['cost_price'].iloc[0])
    price = float(truth['selling_price'].iloc[0])

    test_truth = truth[truth['date'] > cutoff].head(HOLDOUT)
    actual     = test_truth['quantity'].astype(float).reset_index(drop=True)
    pct_days   = 100 * (truth['quantity'] > 0).mean()

    result = {'product': name, 'pct_days': pct_days, 'truth_units': actual.sum()}

    for tag, src in (('sparse', sparse_df), ('filled', filled_df)):
        hist  = src[(src['product'] == name) & (src['date'] <= cutoff)]
        train = hist[['date', 'quantity']].rename(columns={'date': 'ds', 'quantity': 'y'})
        train['y'] = train['y'].astype(float)
        if len(train) < 20:
            result[tag] = None
            continue

        fc = fit_forecast(train, HOLDOUT)
        # Align the forecast to the holdout calendar dates.
        fc = fc.set_index(fc['ds'].dt.normalize())
        pred = pd.Series(
            [float(fc.loc[d, 'yhat']) if d in fc.index else 0.0
             for d in test_truth['date'].dt.normalize()]
        ).clip(lower=0).reset_index(drop=True)

        order, mu = newsvendor(fc, cost, price)
        sold_mask = actual > 0

        result[tag] = {
            'forecast':  mu,
            'order':     order,
            'mape_all':  mape(actual, pred),
            'mape_sold': mape(actual[sold_mask], pred[sold_mask]) if sold_mask.any() else float('nan'),
            'wape':      wape(actual, pred),
            'mae':       float(abs(actual - pred).mean()),
        }
    rows.append(result)

print('\nORDERING — what the store owner is told to buy for the next 30 days')
print('=' * 88)
print(f'{"Product":<30}{"%days":>7}{"actually sold":>15}{"SPARSE order":>15}{"FILLED order":>15}')
print('-' * 88)
for r in rows:
    s, f = r['sparse'], r['filled']
    so = f"{s['order']:,}" if s else 'n/a'
    fo = f"{f['order']:,}" if f else 'n/a'
    print(f'{r["product"]:<30}{r["pct_days"]:>6.0f}%{r["truth_units"]:>15,.0f}{so:>15}{fo:>15}')

print('\n\nOVER-ORDERING — how many units too many, as a multiple of real demand')
print('=' * 88)
print(f'{"Product":<30}{"%days":>7}{"SPARSE":>14}{"FILLED":>14}')
print('-' * 88)
for r in rows:
    s, f = r['sparse'], r['filled']
    t = max(r['truth_units'], 1)
    sx = f"{s['order']/t:.1f}x" if s else 'n/a'
    fx = f"{f['order']/t:.1f}x" if f else 'n/a'
    print(f'{r["product"]:<30}{r["pct_days"]:>6.0f}%{sx:>14}{fx:>14}')

print('\n\nMEASUREMENT — MAPE vs WAPE, sparse pipeline')
print('=' * 88)
print(f'{"Product":<30}{"%days":>7}{"MAPE shown":>13}{"MAPE honest":>13}{"WAPE":>10}{"MAE":>9}')
print('-' * 88)
for r in rows:
    s = r['sparse']
    if not s:
        continue
    print(f'{r["product"]:<30}{r["pct_days"]:>6.0f}%{s["mape_sold"]:>12.1f}%'
          f'{s["mape_all"]:>12.1f}%{s["wape"]:>9.1f}%{s["mae"]:>9.2f}')

print('\n\nMEASUREMENT — zero-filled pipeline')
print('=' * 88)
print(f'{"Product":<30}{"%days":>7}{"MAPE":>10}{"WAPE":>10}{"MAE":>9}')
print('-' * 88)
for r in rows:
    f = r['filled']
    if not f:
        continue
    print(f'{r["product"]:<30}{r["pct_days"]:>6.0f}%{f["mape_all"]:>9.1f}%{f["wape"]:>9.1f}%{f["mae"]:>9.2f}')
print()
