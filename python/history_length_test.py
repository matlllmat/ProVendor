# python/history_length_test.py
# How much sales history does the system actually need?
#
# Holds the test window fixed (the final 30 days) and varies only how much
# history the model is trained on. Everything else — product, Prophet settings,
# metrics — is identical across runs, so any difference is attributable to the
# length of history alone. Supports the "one year minimum, two years
# recommended" claim in the Scope with measurement rather than assertion.
#
# Run:  python python/history_length_test.py

import warnings, logging, math, os
warnings.filterwarnings('ignore')
for _n in ('prophet', 'cmdstanpy', 'prophet.plot'):
    logging.getLogger(_n).setLevel(logging.CRITICAL)

import pandas as pd
from prophet import Prophet

CSV     = os.path.join(os.path.dirname(__file__), 'synthetic_sales.csv')
HOLDOUT = 30
LENGTHS = [365, 730, 1095, 1460, 1795]   # 1 to ~5 years of training history
PRODUCTS = ['Lucky Me Pancit Canton', 'Cooking Oil 1L',
            'Bear Brand Powdered Milk 320g', 'Safeguard Soap 90g']

df = pd.read_csv(CSV, parse_dates=['date'])
cutoff = df['date'].max() - pd.Timedelta(days=HOLDOUT)

print(f'Test window: final {HOLDOUT} days, identical for every run')
print(f'{"Product":<32}{"train days":>12}{"MAPE":>9}{"MAE":>8}{"RMSE":>8}')
print('-' * 69)

for name in PRODUCTS:
    series = df[df['product'] == name][['date', 'quantity']].sort_values('date')
    series.columns = ['ds', 'y']
    series['y'] = series['y'].astype(float)

    test  = series[series['ds'] > cutoff].head(HOLDOUT)
    actual = test['y'].values

    for days in LENGTHS:
        start = cutoff - pd.Timedelta(days=days)
        train = series[(series['ds'] > start) & (series['ds'] <= cutoff)]
        if len(train) < 60:
            continue

        m = Prophet(yearly_seasonality=True, weekly_seasonality=True,
                    daily_seasonality=False)
        m.fit(train)
        fc = m.predict(pd.DataFrame({'ds': test['ds'].values}))
        pred = fc['yhat'].clip(lower=0).values

        err  = actual - pred
        mape = float((abs(err) / pd.Series(actual).clip(lower=1).values).mean() * 100)
        mae  = float(abs(err).mean())
        rmse = float(math.sqrt((err ** 2).mean()))
        yrs  = days / 365
        print(f'{name:<32}{days:>7} ({yrs:.1f}y){mape:>8.1f}%{mae:>8.2f}{rmse:>8.2f}')
    print()
