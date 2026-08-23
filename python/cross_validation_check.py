# python/cross_validation_check.py
# One-off robustness check, run offline — NOT part of the running system.
#
# The system reports accuracy from a single holdout on the most recent 30 days.
# That answers "how well does it predict the immediate future from everything we
# know", which matches how the system is used. It does not reveal whether that
# particular window happened to be easy or hard.
#
# This re-tests the same model across many historical windows using Prophet's
# rolling-origin cross_validation, so the single-holdout figure can be compared
# against the average across folds. `initial` is set to two years because the
# history-length test showed that is the point at which accuracy stabilises —
# shorter initial windows would handicap the early folds and understate the model.
#
# Run:  python python/cross_validation_check.py

import warnings, logging, math, os
warnings.filterwarnings('ignore')
for _n in ('prophet', 'cmdstanpy', 'prophet.plot'):
    logging.getLogger(_n).setLevel(logging.CRITICAL)

import pandas as pd
from prophet import Prophet
from prophet.diagnostics import cross_validation

CSV      = os.path.join(os.path.dirname(__file__), 'synthetic_sales.csv')
INITIAL  = '730 days'   # 2 years — the plateau found by history_length_test.py
PERIOD   = '90 days'    # new fold every 3 months
HORIZON  = '30 days'    # same forecast window the system uses
PRODUCTS = ['Lucky Me Pancit Canton', 'Cooking Oil 1L',
            'Bear Brand Powdered Milk 320g', 'Safeguard Soap 90g']

def metrics(actual, pred):
    err = actual - pred
    return (float((abs(err) / pd.Series(actual).clip(lower=1).values).mean() * 100),
            float(abs(err).mean()),
            float(math.sqrt((err ** 2).mean())))

df = pd.read_csv(CSV, parse_dates=['date'])
cut = df['date'].max() - pd.Timedelta(days=30)

print(f'initial={INITIAL}  period={PERIOD}  horizon={HORIZON}\n')
print(f'{"Product":<32}{"single holdout":>16}{"CV average":>13}{"folds":>7}{"CV range":>18}')
print('-' * 86)

for name in PRODUCTS:
    s = df[df['product'] == name][['date', 'quantity']].sort_values('date')
    s.columns = ['ds', 'y']; s['y'] = s['y'].astype(float)

    # --- single holdout, exactly what the system reports ---
    train, test = s[s['ds'] <= cut], s[s['ds'] > cut].head(30)
    m = Prophet(yearly_seasonality=True, weekly_seasonality=True, daily_seasonality=False)
    m.fit(train)
    fc = m.predict(pd.DataFrame({'ds': test['ds'].values}))
    single_mape, _, _ = metrics(test['y'].values, fc['yhat'].clip(lower=0).values)

    # --- rolling-origin cross-validation over the full series ---
    m2 = Prophet(yearly_seasonality=True, weekly_seasonality=True, daily_seasonality=False)
    m2.fit(s)
    cv = cross_validation(m2, initial=INITIAL, period=PERIOD, horizon=HORIZON,
                          disable_tqdm=True)
    cv['yhat'] = cv['yhat'].clip(lower=0)

    per_fold = []
    for _c, grp in cv.groupby('cutoff'):
        mp, _a, _b = metrics(grp['y'].values, grp['yhat'].values)
        per_fold.append(mp)

    avg = sum(per_fold) / len(per_fold)
    print(f'{name:<32}{single_mape:>15.1f}%{avg:>12.1f}%{len(per_fold):>7}'
          f'{min(per_fold):>10.1f}% - {max(per_fold):.1f}%')
