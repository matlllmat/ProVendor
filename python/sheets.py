# python/sheets.py
# Google Sheets reader for the linked-sheet import.
#
# Registered as a blueprint on the Flask ML server (python/app.py), so PHP talks
# to it exactly like every other Python endpoint: POST http://localhost:5000/...
#
# The sheet is read with a SERVICE ACCOUNT (creds/service-account.json) rather
# than an interactive OAuth consent flow — the owner shares their sheet with the
# service account's email and ProVendor reads it server-side, including during
# the 5-minute background refresh when no browser is open. Read-only scopes are
# requested so a bug here can never write to somebody's spreadsheet.
#
# Every failure comes back as {'ok': False, 'code': ..., 'error': ...} with a
# stable machine code, so PHP/JS can render a specific, actionable message
# instead of a raw Google traceback.

import os
import re
import json

import gspread
import pandas as pd
from flask import Blueprint, request, jsonify
from gspread.utils import ValueRenderOption, DateTimeOption

sheets_bp = Blueprint('sheets', __name__)

# creds/ sits next to python/ in the project root. Kept out of git (.gitignore).
CREDS_PATH = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                          'creds', 'service-account.json')

# Read-only: ProVendor never writes back to the owner's spreadsheet.
SCOPES = [
    'https://www.googleapis.com/auth/spreadsheets.readonly',
    'https://www.googleapis.com/auth/drive.readonly',
]

# Guard rail on how much we'll pull into memory and hand to PHP in one response.
MAX_ROWS = 50000

# Matches the /spreadsheets/d/<id> segment of any Google Sheets URL, and also
# accepts a bare spreadsheet id pasted on its own.
_ID_IN_URL = re.compile(r'/spreadsheets/d/([a-zA-Z0-9-_]+)')
_BARE_ID   = re.compile(r'^[a-zA-Z0-9-_]{20,}$')
_GID_IN_URL = re.compile(r'[#&?]gid=(\d+)')


def _fail(code, error, status=200):
    return jsonify({'ok': False, 'code': code, 'error': error}), status


def parse_sheet_url(url):
    """Returns (spreadsheet_id, gid_or_None). spreadsheet_id is None if unparseable."""
    url = (url or '').strip()
    if not url:
        return None, None

    match = _ID_IN_URL.search(url)
    if match:
        gid = _GID_IN_URL.search(url)
        return match.group(1), (int(gid.group(1)) if gid else None)

    if _BARE_ID.match(url):
        return url, None

    return None, None


def _service_account_email():
    """The address owners must share their sheet with. None if creds are unreadable."""
    try:
        with open(CREDS_PATH, 'r', encoding='utf-8') as fh:
            return json.load(fh).get('client_email')
    except Exception:
        return None


def _api_error_status(err):
    """HTTP status behind a gspread APIError, or None if it can't be determined."""
    response = getattr(err, 'response', None)
    return getattr(response, 'status_code', None)


def _cell_to_text(v):
    """
    Renders one unformatted cell as the plain text the PHP importer expects.

    UNFORMATTED_VALUE hands back real Python types, not strings, so this is where
    they become text — and the float branch matters: a quantity of 12 arrives as
    12.0, and str() would make that "12.0", which the importer rejects for not
    being a whole number.
    """
    if v is None:
        return ''
    if isinstance(v, bool):                 # checkbox columns; before int, bool IS an int
        return 'TRUE' if v else 'FALSE'
    if isinstance(v, float):
        if v.is_integer():
            return str(int(v))
        # Trim binary-float noise (0.30000000000000004 → 0.3) without touching
        # any precision a real price would use.
        return repr(round(v, 10))
    if isinstance(v, int):
        return str(v)
    return str(v)


def _clean_frame(values):
    """
    Turns raw get_all_values() output into (headers, rows) via pandas.

    Google returns ragged rows and pads trailing blank ones, so this: trims the
    header row, names unlabelled columns, drops rows that are entirely empty,
    and pads/truncates every row to the header width.
    """
    header = [_cell_to_text(h).strip() for h in values[0]]

    # Trailing unnamed columns are Google's empty grid padding — drop them, but
    # keep any blank header that sits between two real ones (position matters
    # for mapping, so it gets a placeholder name instead).
    while header and header[-1] == '':
        header.pop()
    if not header:
        return [], []

    seen = {}
    headers = []
    for i, name in enumerate(header):
        if name == '':
            name = 'Column ' + str(i + 1)
        # Duplicate header names would collapse into one column downstream.
        if name in seen:
            seen[name] += 1
            name = name + ' (' + str(seen[name]) + ')'
        else:
            seen[name] = 0
        headers.append(name)

    width = len(headers)
    # Every cell becomes text here rather than inside pandas: a mixed int/float/
    # str column would otherwise coerce as a block, turning whole quantity
    # columns into "12.0".
    body  = [([_cell_to_text(c) for c in row] + [''] * width)[:width]
             for row in values[1:]]

    df = pd.DataFrame(body, columns=headers, dtype=str)
    df = df.apply(lambda col: col.str.strip())
    df = df[~(df == '').all(axis=1)]          # drop fully-blank rows

    return headers, df.values.tolist()


# ── Read a linked sheet ───────────────────────────────────────────────────────
# Input  (JSON): { url, gid (optional — overrides the #gid= in the url) }
# Output (JSON): { ok: true, spreadsheet_id, gid, title, worksheet,
#                  headers: [...], rows: [[...]], row_count }
#            or: { ok: false, code, error }
@sheets_bp.route('/sheets/read', methods=['POST'])
def sheets_read():
    body = request.get_json(silent=True) or {}

    spreadsheet_id, gid = parse_sheet_url(body.get('url'))
    if body.get('gid') not in (None, ''):
        try:
            gid = int(body['gid'])
        except (TypeError, ValueError):
            pass   # keep whatever the url carried

    if not spreadsheet_id:
        return _fail('bad_url',
                     'That does not look like a Google Sheets link. Open your sheet in the '
                     'browser and copy the full address from the address bar.')

    if not os.path.exists(CREDS_PATH):
        return _fail('creds_missing',
                     'The ProVendor service account credentials are missing on the server.')

    try:
        client = gspread.service_account(filename=CREDS_PATH, scopes=SCOPES)
    except Exception as e:
        return _fail('creds_invalid',
                     'The ProVendor service account credentials could not be loaded: %s' % e)

    share_with = _service_account_email() or 'the ProVendor service account'

    try:
        spreadsheet = client.open_by_key(spreadsheet_id)
        worksheet   = (spreadsheet.get_worksheet_by_id(gid) if gid is not None
                       else spreadsheet.sheet1)

        # Read the VALUES, not what the spreadsheet happens to be displaying.
        #
        # gspread's default is FORMATTED_VALUE, which renders each cell the way
        # the owner's screen would — so a column too narrow for its contents
        # comes back as "#######", a quantity formatted with a thousands
        # separator comes back as "1,234", and a price comes back as "₱25.00".
        # All three are then rejected by the importer as unparseable.
        #
        # UNFORMATTED_VALUE returns the underlying number instead, immune to
        # column width and display formatting. Dates still need to arrive as
        # readable strings though — as raw values they'd be bare serial numbers
        # (46235), indistinguishable from a quantity — so FORMATTED_STRING keeps
        # them in the sheet's own date format for the format sniffer to read.
        values = worksheet.get_all_values(
            value_render_option=ValueRenderOption.unformatted,
            date_time_render_option=DateTimeOption.formatted_string,
        )

    except gspread.exceptions.SpreadsheetNotFound:
        return _fail('not_found',
                     'No spreadsheet was found at that link. Check the link, and make sure the '
                     'sheet is shared with %s as a Viewer.' % share_with)

    except gspread.exceptions.WorksheetNotFound:
        return _fail('worksheet_missing',
                     'That tab no longer exists in the spreadsheet. Open the sheet, click the '
                     'tab holding your sales data, and copy the link again.')

    except gspread.exceptions.APIError as e:
        status = _api_error_status(e)
        if status == 403:
            return _fail('no_access',
                         'The sheet exists, but ProVendor cannot open it. Share it with %s and '
                         'give that address at least Viewer access.' % share_with)
        if status == 404:
            # Google returns 404 both for a wrong id and for a sheet that was
            # never shared, so the message has to cover both honestly.
            return _fail('not_found',
                         'That sheet could not be opened — either the link is wrong or it has '
                         'not been shared with %s yet.' % share_with)
        if status == 429:
            return _fail('rate_limit',
                         'Google is rate-limiting ProVendor right now. Wait a minute and try again.')
        return _fail('api_error', 'Google returned an error while reading the sheet: %s' % e)

    except Exception as e:
        return _fail('api_error', 'Could not read the sheet: %s' % e)

    if not values:
        return _fail('empty',
                     'That sheet is empty. Add a header row and your sales rows, then try again.')

    headers, rows = _clean_frame(values)

    if not headers:
        return _fail('no_headers',
                     'The first row of that sheet is blank. Row 1 must hold the column names '
                     '(for example: Date, Product, Quantity).')

    if not rows:
        return _fail('no_rows',
                     'That sheet has column names but no sales rows underneath them.')

    if len(rows) > MAX_ROWS:
        return _fail('too_large',
                     'That sheet has %s rows, which is over the %s row limit for a linked sheet.'
                     % (format(len(rows), ','), format(MAX_ROWS, ',')))

    return jsonify({
        'ok':             True,
        'spreadsheet_id': spreadsheet_id,
        'gid':            worksheet.id,
        'title':          spreadsheet.title,
        'worksheet':      worksheet.title,
        'headers':        headers,
        'rows':           rows,
        'row_count':      len(rows),
    })


# ── Which address owners must share their sheet with ──────────────────────────
# Output (JSON): { ok: true, email } | { ok: false, code, error }
@sheets_bp.route('/sheets/account', methods=['GET'])
def sheets_account():
    email = _service_account_email()
    if not email:
        return _fail('creds_missing',
                     'The ProVendor service account credentials are missing on the server.')
    return jsonify({'ok': True, 'email': email})
