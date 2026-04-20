# python/app.py
# ProVendor Flask ML server.
# Start with: python python/app.py  (run from the ProVendor root)
# Runs on http://localhost:5000

from flask import Flask, request, jsonify
import pandas as pd
from prophet import Prophet
from prophet.utilities import regressor_coefficients
import pymysql
import logging
import math
import calendar as cal_lib
from scipy.stats import norm
from datetime import date as date_cls

app = Flask(__name__)

# Suppress Prophet's verbose Stan output and optional Plotly import notice
logging.getLogger('prophet').setLevel(logging.WARNING)
logging.getLogger('cmdstanpy').setLevel(logging.WARNING)
logging.getLogger('prophet.plot').setLevel(logging.ERROR)

# ── Database credentials (matches with config/db.php) ───────────────────────────
DB = {
    'host':        'localhost',
    'user':        'root',
    'password':    '',
    'database':    'provendor',
    'cursorclass': pymysql.cursors.DictCursor,
}

def get_db():
    return pymysql.connect(**DB)

# ── Health check ──────────────────────────────────────────────────────────────
@app.route('/health', methods=['GET'])
def health():
    return jsonify({'status': 'ok'})

# ── Category-level forecast ───────────────────────────────────────────────────
# Input  (JSON): { user_id, category (optional), days (default 30) }
# Output (JSON): { historical: [{date, actual}], forecast: [{date, predicted, lower, upper}] }
@app.route('/forecast/category', methods=['POST'])
def forecast_category():
    body     = request.get_json()
    user_id  = body.get('user_id')
    category = body.get('category')   # None means all categories combined
    days     = int(body.get('days', 30))

    conn = get_db()
    try:
        with conn.cursor() as cur:
            if category:
                cur.execute("""
                    SELECT s.sale_date AS ds, SUM(s.quantity_sold) AS y
                    FROM sales s
                    JOIN products p ON p.id = s.product_id
                    WHERE p.user_id = %s AND p.category = %s
                    GROUP BY s.sale_date
                    ORDER BY s.sale_date
                """, (user_id, category))
            else:
                cur.execute("""
                    SELECT s.sale_date AS ds, SUM(s.quantity_sold) AS y
                    FROM sales s
                    JOIN products p ON p.id = s.product_id
                    WHERE p.user_id = %s
                    GROUP BY s.sale_date
                    ORDER BY s.sale_date
                """, (user_id,))
            rows = cur.fetchall()
    finally:
        conn.close()

    if not rows:
        return jsonify({'error': 'No sales data found.'}), 404

    df       = pd.DataFrame(rows)
    df['ds'] = pd.to_datetime(df['ds'])
    df['y']  = df['y'].astype(float)

    model = Prophet(
        yearly_seasonality  = True,
        weekly_seasonality  = True,
        daily_seasonality   = False,
    )
    model.fit(df)

    future   = model.make_future_dataframe(periods=days)
    forecast = model.predict(future)

    last_actual = df['ds'].max()

    # Historical — actual sales per date
    historical = df[['ds', 'y']].copy()
    historical.columns = ['date', 'actual']
    historical['date'] = historical['date'].dt.strftime('%Y-%m-%d')

    # Forecast — only the future portion, clipped to non-negative
    future_rows = forecast[forecast['ds'] > last_actual][['ds', 'yhat', 'yhat_lower', 'yhat_upper']].copy()
    future_rows.columns = ['date', 'predicted', 'lower', 'upper']
    future_rows['date']      = future_rows['date'].dt.strftime('%Y-%m-%d')
    future_rows['predicted'] = future_rows['predicted'].clip(lower=0).round(2)
    future_rows['lower']     = future_rows['lower'].clip(lower=0).round(2)
    future_rows['upper']     = future_rows['upper'].round(2)

    return jsonify({
        'historical': historical.to_dict(orient='records'),
        'forecast':   future_rows.to_dict(orient='records'),
    })


# ── Event regressor helpers ───────────────────────────────────────────────────

def get_event_active_series(event, date_index):
    """Return a float 0.0/1.0 Series — 1.0 on days when the event is active.

    Mirrors the PHP expandEvents() logic so that regressors align with what the
    events page shows as occurrences.
    """
    base_start = pd.to_datetime(event['event_start'])
    end_str    = event['event_end'] if event['event_end'] else event['event_start']
    base_end   = pd.to_datetime(end_str)
    duration   = max(0, (base_end - base_start).days)

    date_set = set(date_index)
    active   = set()
    rec      = event['recurrence']

    if rec == 'none':
        for offset in range(duration + 1):
            d = base_start + pd.Timedelta(days=offset)
            if d in date_set:
                active.add(d)

    elif rec == 'yearly':
        min_yr = date_index.min().year
        max_yr = date_index.max().year
        for yr in range(min_yr, max_yr + 1):
            try:
                y_start = base_start.replace(year=yr)
            except ValueError:
                continue  # Feb 29 on non-leap year
            for offset in range(duration + 1):
                d = y_start + pd.Timedelta(days=offset)
                if d in date_set:
                    active.add(d)

    elif rec == 'monthly':
        cursor = pd.Timestamp(date_index.min().year, date_index.min().month, 1)
        max_dt = date_index.max()
        while cursor <= max_dt:
            if event['is_last_day']:
                last    = cal_lib.monthrange(cursor.year, cursor.month)[1]
                m_start = pd.Timestamp(cursor.year, cursor.month, last)
            else:
                day     = base_start.day
                max_day = cal_lib.monthrange(cursor.year, cursor.month)[1]
                if day > max_day:
                    cursor += pd.offsets.MonthBegin(1)
                    continue
                m_start = pd.Timestamp(cursor.year, cursor.month, day)
            for offset in range(duration + 1):
                d = m_start + pd.Timedelta(days=offset)
                if d in date_set:
                    active.add(d)
            cursor += pd.offsets.MonthBegin(1)

    values = [1.0 if d in active else 0.0 for d in date_index]
    return pd.Series(values, index=date_index, dtype=float)


def count_event_instances(event, train_start, train_end):
    """Count how many discrete instances of the event fall within [train_start, train_end]."""
    base_start = pd.to_datetime(event['event_start'])
    rec        = event['recurrence']
    count      = 0

    if rec == 'none':
        if train_start <= base_start <= train_end:
            count = 1

    elif rec == 'yearly':
        for yr in range(train_start.year, train_end.year + 1):
            try:
                y_date = base_start.replace(year=yr)
                if train_start <= y_date <= train_end:
                    count += 1
            except ValueError:
                pass

    elif rec == 'monthly':
        cursor = pd.Timestamp(train_start.year, train_start.month, 1)
        while cursor <= train_end:
            if event['is_last_day']:
                last   = cal_lib.monthrange(cursor.year, cursor.month)[1]
                m_date = pd.Timestamp(cursor.year, cursor.month, last)
            else:
                day     = base_start.day
                max_day = cal_lib.monthrange(cursor.year, cursor.month)[1]
                if day > max_day:
                    cursor += pd.offsets.MonthBegin(1)
                    continue
                m_date = pd.Timestamp(cursor.year, cursor.month, day)
            if train_start <= m_date <= train_end:
                count += 1
            cursor += pd.offsets.MonthBegin(1)

    return count


# ── Product-level forecast ─────────────────────────────────────────────────────
# Input  (JSON): { user_id, product_id, days (default 30) }
# Output (JSON): { historical: [{date, actual}], forecast: [{date, predicted, lower, upper}] }
@app.route('/forecast/product', methods=['POST'])
def forecast_product():
    body       = request.get_json()
    user_id    = body.get('user_id')
    product_id = body.get('product_id')
    from_date  = body.get('from_date')   # YYYY-MM-DD — first day to include in output
    to_date    = body.get('to_date')     # YYYY-MM-DD — last day to include in output

    conn = get_db()
    try:
        with conn.cursor() as cur:
            # Sales history for this product
            cur.execute("""
                SELECT s.sale_date AS ds, s.quantity_sold AS y
                FROM sales s
                JOIN products p ON p.id = s.product_id
                WHERE s.product_id = %s AND p.user_id = %s
                ORDER BY s.sale_date
            """, (product_id, user_id))
            rows = cur.fetchall()

            # Events visible to this user (global presets + own, excluding hidden)
            cur.execute("""
                SELECT id, name, event_start, event_end, recurrence, is_last_day
                FROM seasonal_events
                WHERE (user_id IS NULL OR user_id = %s)
                  AND id NOT IN (
                      SELECT event_id FROM user_hidden_events WHERE user_id = %s
                  )
            """, (user_id, user_id))
            events = cur.fetchall()
    finally:
        conn.close()

    if not rows:
        return jsonify({'error': 'No sales data found for this product.'}), 404

    df       = pd.DataFrame(rows)
    df['ds'] = pd.to_datetime(df['ds'])
    df['y']  = df['y'].astype(float)

    last_actual = df['ds'].max()

    # Determine how many days Prophet needs to project to reach to_date.
    if from_date and to_date:
        to_dt = pd.to_datetime(to_date)
        days  = (to_dt - last_actual).days + 1
        if days <= 0:
            return jsonify({'error': 'The selected date range must be entirely after your last sale date (' +
                            last_actual.strftime('%Y-%m-%d') + ').'}), 400
    else:
        days = int(body.get('days', 30))

    # ── Build Prophet model with event regressors ─────────────────────────────
    model = Prophet(
        yearly_seasonality = True,
        weekly_seasonality = True,
        daily_seasonality  = False,
    )

    # Map event_id → column name; add regressor to model + column to training df.
    event_col_map  = {}   # {event_id: col_name}
    train_date_idx = pd.DatetimeIndex(df['ds'])

    for ev in events:
        col    = f'ev_{ev["id"]}'
        series = get_event_active_series(ev, train_date_idx)
        df[col] = series.values
        model.add_regressor(col, mode='additive')
        event_col_map[ev['id']] = col

    model.fit(df)

    # Build future df and fill in regressor columns for the forecast window.
    future          = model.make_future_dataframe(periods=days)
    future_date_idx = pd.DatetimeIndex(future['ds'])

    for ev in events:
        col    = event_col_map[ev['id']]
        series = get_event_active_series(ev, future_date_idx)
        future[col] = series.values

    forecast = model.predict(future)

    # ── Extract Prophet regressor coefficients ────────────────────────────────
    # Returned as event_coefficients in the JSON response so the PHP caller can
    # persist them to event_impact_cache without a second round-trip.
    event_coefficients = []
    if events:
        try:
            coef_df          = regressor_coefficients(model)
            mean_daily_sales = float(df['y'].mean())

            for ev in events:
                col = event_col_map[ev['id']]
                row = coef_df[coef_df['regressor'] == col]
                if row.empty:
                    continue
                coef      = float(row['coef'].iloc[0])
                occ_count = count_event_instances(ev, df['ds'].min(), df['ds'].max())
                if occ_count == 0:
                    continue   # event never appeared in training data — skip
                impact_pct = round(coef / mean_daily_sales * 100, 1) if mean_daily_sales > 0 else 0.0
                event_coefficients.append({
                    'event_id':         ev['id'],
                    'coefficient':      round(coef, 4),
                    'mean_daily_sales': round(mean_daily_sales, 4),
                    'occurrence_count': occ_count,
                    'impact_pct':       impact_pct,
                })
        except Exception as exc:
            logging.warning(f'Could not extract regressor coefficients: {exc}')

    # ── Build historical / forecast output ────────────────────────────────────
    historical = df[['ds', 'y']].copy()
    historical.columns = ['date', 'actual']
    historical['date'] = historical['date'].dt.strftime('%Y-%m-%d')

    future_rows = forecast[forecast['ds'] > last_actual][['ds', 'yhat', 'yhat_lower', 'yhat_upper']].copy()
    future_rows.columns = ['date', 'predicted', 'lower', 'upper']
    future_rows['date']      = future_rows['date'].dt.strftime('%Y-%m-%d')
    future_rows['predicted'] = future_rows['predicted'].clip(lower=0).round(2)
    future_rows['lower']     = future_rows['lower'].clip(lower=0).round(2)
    future_rows['upper']     = future_rows['upper'].round(2)

    # Trim to the requested window (from_date → to_date).
    # Prophet always forecasts from last_actual forward; we just hide rows outside the window.
    if from_date and to_date:
        future_rows = future_rows[
            (future_rows['date'] >= from_date) &
            (future_rows['date'] <= to_date)
        ]
        if future_rows.empty:
            return jsonify({'error': 'No forecast data falls within the selected date range.'}), 400

    result = {
        'historical': historical.to_dict(orient='records'),
        'forecast':   future_rows.to_dict(orient='records'),
    }
    if event_coefficients:
        result['event_coefficients'] = event_coefficients

    return jsonify(result)


# ── Newsvendor optimization ────────────────────────────────────────────────────
# Input  (JSON): { forecast: [{date, predicted, lower, upper}], cost_price, selling_price, current_stock }
# Output (JSON): { total_predicted, restock_qty, order_qty, est_profit }
@app.route('/optimize', methods=['POST'])
def optimize():
    body          = request.get_json()
    forecast_data = body.get('forecast', [])
    cost_price    = float(body.get('cost_price', 0))
    selling_price = float(body.get('selling_price', 0))
    current_stock = int(body.get('current_stock', 0))

    if selling_price <= cost_price:
        return jsonify({'error': 'Selling price must be greater than cost price.'}), 400

    if not forecast_data:
        return jsonify({'error': 'No forecast data provided.'}), 400

    # Total mean demand over the forecast horizon
    total_predicted = sum(r['predicted'] for r in forecast_data)

    # Estimate total std dev from Prophet confidence intervals.
    # Each day's CI is ~95%, so σ_day ≈ (upper - lower) / (2 * 1.96).
    # Assuming daily demand is independent: total variance = sum of daily variances.
    daily_var_sum = sum(((r['upper'] - r['lower']) / (2 * 1.96)) ** 2 for r in forecast_data)
    total_std     = math.sqrt(daily_var_sum) if daily_var_sum > 0 else total_predicted * 0.2

    # Newsvendor critical ratio: CR = (p - c) / p
    # Underage cost Cu = p - c  (lost profit per unit short)
    # Overage cost  Co = c      (cost per unsold unit)
    critical_ratio = (selling_price - cost_price) / selling_price
    z_star         = norm.ppf(critical_ratio)

    # Optimal total supply level and actual order quantity
    optimal_total = max(0, round(total_predicted + z_star * total_std))
    order_qty     = max(0, optimal_total - current_stock)

    # Estimated profit: revenue from expected sales minus cost of new stock ordered
    total_supply   = current_stock + order_qty
    expected_sales = min(total_supply, total_predicted)
    est_profit     = round(selling_price * expected_sales - cost_price * order_qty, 2)

    return jsonify({
        'total_predicted': round(total_predicted, 2),
        'total_std':       round(total_std, 2),
        'restock_qty':     order_qty,
        'optimal_total':   optimal_total,
        'est_profit':      est_profit,
    })


# ── Pattern detection ─────────────────────────────────────────────────────────
# Input  (JSON): { user_id, existing_events: [{recurrence, event_start, event_end, is_last_day}] }
# Output (JSON): { suggestions, weekly_insights, data_summary }
@app.route('/detect_patterns', methods=['POST'])
def detect_patterns():
    body            = request.get_json()
    user_id         = body.get('user_id')
    existing_events = body.get('existing_events', [])

    conn = get_db()
    try:
        with conn.cursor() as cur:
            cur.execute("""
                SELECT s.sale_date AS ds, SUM(s.quantity_sold) AS y
                FROM sales s
                JOIN products p ON p.id = s.product_id
                WHERE p.user_id = %s
                GROUP BY s.sale_date
                ORDER BY s.sale_date
            """, (user_id,))
            rows = cur.fetchall()
    finally:
        conn.close()

    if len(rows) < 60:
        return jsonify({
            'suggestions':     [],
            'weekly_insights': [],
            'data_summary':    {},
            'message':         'Need at least 60 days of sales data for pattern detection.',
        })

    df           = pd.DataFrame(rows)
    df['ds']     = pd.to_datetime(df['ds'])
    df['y']      = df['y'].astype(float)
    overall_mean = df['y'].mean()
    years_count  = df['ds'].dt.year.nunique()
    total_days   = len(df)
    threshold    = overall_mean * 1.5   # 50 % above average to be a candidate

    # ── Build suppression sets from existing events ────────────────────────────
    yearly_covered  = set()   # 'MM-DD' strings (with ±3-day buffer)
    monthly_covered = set()   # int day-of-month, or -1 for last day

    for ev in existing_events:
        rec = ev.get('recurrence', 'none')
        if rec == 'yearly':
            start_str = ev.get('event_start') or ''
            end_str   = ev.get('event_end')   or start_str
            if start_str:
                try:
                    s = pd.to_datetime(start_str)
                    e = pd.to_datetime(end_str)
                    curr = s
                    while curr <= e:
                        yearly_covered.add(curr.strftime('%m-%d'))
                        curr += pd.Timedelta(days=1)
                    # ±3-day buffer so nearby spikes are also suppressed
                    for delta in range(-3, 4):
                        yearly_covered.add((s + pd.Timedelta(days=delta)).strftime('%m-%d'))
                        yearly_covered.add((e + pd.Timedelta(days=delta)).strftime('%m-%d'))
                except Exception:
                    pass
        elif rec == 'monthly':
            if ev.get('is_last_day'):
                monthly_covered.add(-1)
            elif ev.get('event_start'):
                try:
                    monthly_covered.add(pd.to_datetime(ev['event_start']).day)
                except Exception:
                    pass

    suggestions = []

    # ── Yearly pattern detection ───────────────────────────────────────────────
    df['month_day'] = df['ds'].dt.strftime('%m-%d')
    df['year']      = df['ds'].dt.year

    md_stats = df.groupby('month_day').agg(
        mean_y     = ('y', 'mean'),
        year_count = ('year', 'nunique'),
    ).reset_index()

    yearly_spikes = md_stats[
        (md_stats['mean_y'] >= threshold) &
        (md_stats['year_count'] >= 2) &
        (~md_stats['month_day'].isin(yearly_covered))
    ].copy()

    if not yearly_spikes.empty:
        def md_to_doy(md):
            try:
                return pd.to_datetime('2024-' + md).timetuple().tm_yday
            except Exception:
                return 0

        yearly_spikes['doy'] = yearly_spikes['month_day'].apply(md_to_doy)
        yearly_spikes = yearly_spikes.sort_values('doy').reset_index(drop=True)

        # Greedy cluster: group dates within 7 days of each other
        clusters = [[yearly_spikes.iloc[0].to_dict()]]
        for i in range(1, len(yearly_spikes)):
            gap = yearly_spikes.iloc[i]['doy'] - yearly_spikes.iloc[i - 1]['doy']
            if gap > 180:
                gap = 366 - gap   # year-wrap (Dec → Jan)
            if gap <= 7:
                clusters[-1].append(yearly_spikes.iloc[i].to_dict())
            else:
                clusters.append([yearly_spikes.iloc[i].to_dict()])

        for cl in clusters:
            mds       = [r['month_day'] for r in cl]
            max_mean  = max(r['mean_y']     for r in cl)
            max_years = max(int(r['year_count']) for r in cl)

            impact_pct = round((max_mean - overall_mean) / overall_mean * 100, 1)
            conf       = 'strong' if max_years >= 4 else 'moderate'
            conf_label = conf.capitalize()

            try:
                s_dt = pd.to_datetime('2024-' + mds[0])
                e_dt = pd.to_datetime('2024-' + mds[-1])
                s_str = s_dt.strftime('%b') + ' ' + str(s_dt.day)
                e_str = e_dt.strftime('%b') + ' ' + str(e_dt.day)
                suggested_name = (s_str if mds[0] == mds[-1] else s_str + '–' + e_str) + ' spike'
            except Exception:
                suggested_name = mds[0] + ' spike'

            suggestions.append({
                'recurrence':        'yearly',
                'event_start':       '2024-' + mds[0],
                'event_end':         ('2024-' + mds[-1]) if mds[-1] != mds[0] else None,
                'is_last_day':       0,
                'impact_pct':        impact_pct,
                'occurrence_count':  max_years,
                'confidence':        conf,
                'confidence_label':  conf_label,
                'confidence_detail': f'Seen in {max_years} of {years_count} year(s) in your data',
                'suggested_name':    suggested_name,
            })

    # ── Monthly pattern detection ──────────────────────────────────────────────
    df['dom']        = df['ds'].dt.day
    df['month_year'] = df['ds'].dt.to_period('M').astype(str)
    df['is_last']    = (df['ds'] == df['ds'].dt.to_period('M').dt.to_timestamp('M'))

    dom_stats = df.groupby('dom').agg(
        mean_y      = ('y', 'mean'),
        month_count = ('month_year', 'nunique'),
    ).reset_index()

    monthly_spikes = dom_stats[
        (dom_stats['mean_y'] >= threshold) &
        (dom_stats['month_count'] >= 6) &
        (~dom_stats['dom'].isin(monthly_covered))
    ]

    for _, row in monthly_spikes.iterrows():
        dom = int(row['dom'])
        mc  = int(row['month_count'])
        impact_pct = round((row['mean_y'] - overall_mean) / overall_mean * 100, 1)
        conf = 'strong' if mc >= 12 else 'moderate'
        sfx_map = {1: 'st', 2: 'nd', 3: 'rd'}
        sfx = sfx_map.get(dom % 10 if dom not in [11, 12, 13] else 0, 'th')
        try:
            sample_date = date_cls(2024, 1, min(dom, 28)).strftime('%Y-%m-%d')
        except ValueError:
            sample_date = '2024-01-15'
        suggestions.append({
            'recurrence':        'monthly',
            'event_start':       sample_date,
            'event_end':         None,
            'is_last_day':       0,
            'impact_pct':        impact_pct,
            'occurrence_count':  mc,
            'confidence':        conf,
            'confidence_label':  conf.capitalize(),
            'confidence_detail': f'Seen in {mc} months in your data',
            'suggested_name':    f'{dom}{sfx} of month spike',
        })

    # Last-day-of-month check
    if -1 not in monthly_covered:
        last_df = df[df['is_last']]
        if len(last_df) >= 6:
            last_mean = last_df['y'].mean()
            last_mc   = last_df['month_year'].nunique()
            if last_mean >= threshold:
                impact_pct = round((last_mean - overall_mean) / overall_mean * 100, 1)
                conf = 'strong' if last_mc >= 12 else 'moderate'
                suggestions.append({
                    'recurrence':        'monthly',
                    'event_start':       '2024-01-31',
                    'event_end':         None,
                    'is_last_day':       1,
                    'impact_pct':        impact_pct,
                    'occurrence_count':  int(last_mc),
                    'confidence':        conf,
                    'confidence_label':  conf.capitalize(),
                    'confidence_detail': f'Seen in {last_mc} months in your data',
                    'suggested_name':    'Last day of month spike',
                })

    # ── Weekly insights (informational only — handled by Prophet automatically) ──
    df['dow']   = df['ds'].dt.day_name()
    dow_means   = df.groupby('dow')['y'].mean()
    dow_order   = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']
    weekly_insights = []
    for day in dow_order:
        if day in dow_means.index:
            pct = round((dow_means[day] - overall_mean) / overall_mean * 100, 1)
            if abs(pct) >= 10:
                weekly_insights.append({'day': day, 'impact_pct': pct})

    return jsonify({
        'suggestions':     suggestions,
        'weekly_insights': weekly_insights,
        'data_summary': {
            'total_days':  total_days,
            'years_count': years_count,
            'date_from':   df['ds'].min().strftime('%Y-%m-%d'),
            'date_to':     df['ds'].max().strftime('%Y-%m-%d'),
        },
    })


if __name__ == '__main__':
    app.run(host='localhost', port=5000, debug=True)
