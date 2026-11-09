# python/xlsx_to_csv.py
# Converts an uploaded .xlsx workbook into the CSV the import steps read, so Excel
# files go through the same checks and review screen as CSV files.
#
# usage: xlsx_to_csv.py <input.xlsx> <output.csv>
# Prints JSON: {"sheet", "sheets", "header_row"} or {"error"}.

import csv
import datetime
import json
import sys
import zipfile

from openpyxl import load_workbook

MAX_CSV_BYTES = 10 * 1024 * 1024     # the same limit CSV uploads have
MAX_UNZIPPED_BYTES = 300 * 1024 * 1024
HEADER_SEARCH_ROWS = 30              # title rows can sit above the real header


class ConvertError(Exception):
    pass


def cell_text(value):
    if value is None:
        return ''
    if isinstance(value, bool):
        return 'TRUE' if value else 'FALSE'
    if isinstance(value, datetime.datetime):
        if value.time() == datetime.time(0, 0):
            return value.date().isoformat()
        return value.isoformat(sep=' ', timespec='seconds')
    if isinstance(value, datetime.date):
        return value.isoformat()
    if isinstance(value, float):
        return str(int(value)) if value.is_integer() else repr(round(value, 10))
    return str(value)


def find_header(sheet):
    """Row number and cells of the header: the first row with 3+ filled cells."""
    first_filled = None
    for number, row in enumerate(sheet.iter_rows(max_row=HEADER_SEARCH_ROWS, values_only=True), start=1):
        cells = [cell_text(c).strip() for c in row]
        filled = sum(1 for c in cells if c)
        if filled >= 3:
            return number, cells
        if filled and first_filled is None:
            first_filled = (number, cells)
    return first_filled


def clean_headers(cells):
    while cells and cells[-1] == '':
        cells = cells[:-1]
    return [c if c else 'Column %d' % (i + 1) for i, c in enumerate(cells)]


def convert(source, target):
    try:
        with zipfile.ZipFile(source) as archive:
            if sum(item.file_size for item in archive.infolist()) > MAX_UNZIPPED_BYTES:
                raise ConvertError('This Excel file is too large to read.')
    except zipfile.BadZipFile:
        raise ConvertError('This file could not be read as an Excel workbook. '
                           'If it is password-protected, remove the password and try again.')

    workbook = load_workbook(source, read_only=True, data_only=True)
    try:
        chosen = None
        used = []
        for sheet in workbook.worksheets:
            if sheet.sheet_state != 'visible':
                continue
            found = find_header(sheet)
            if found is None:
                continue
            used.append(sheet.title)
            if chosen is None:
                chosen = (sheet, found)

        if chosen is None:
            raise ConvertError('This Excel file has no data in it.')

        sheet, (header_row, header_cells) = chosen
        headers = clean_headers(header_cells)
        width = len(headers)

        with open(target, 'w', newline='', encoding='utf-8') as out:
            writer = csv.writer(out)
            writer.writerow(headers)
            blanks = 0
            for row in sheet.iter_rows(min_row=header_row + 1, values_only=True):
                cells = [cell_text(c) for c in row[:width]]
                cells += [''] * (width - len(cells))
                if not any(c.strip() for c in cells):
                    blanks += 1      # only written if a later row has data
                    continue
                for _ in range(blanks):
                    writer.writerow([''] * width)
                blanks = 0
                writer.writerow(cells)
                if out.tell() > MAX_CSV_BYTES:
                    raise ConvertError('This Excel file holds more data than the 10 MB limit.')
    finally:
        workbook.close()

    return {'sheet': sheet.title, 'sheets': used, 'header_row': header_row}


def main():
    try:
        result = convert(sys.argv[1], sys.argv[2])
    except ConvertError as e:
        result = {'error': str(e)}
    except Exception:
        result = {'error': 'This file could not be read as an Excel workbook.'}
    print(json.dumps(result))


if __name__ == '__main__':
    main()
