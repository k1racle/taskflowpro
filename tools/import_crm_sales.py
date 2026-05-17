import json
import re
import sys
from datetime import datetime
from pathlib import Path

import openpyxl


MONTHS = {
    'январ': 1,
    'феврал': 2,
    'март': 3,
    'апрел': 4,
    'май': 5,
    'июн': 6,
    'июл': 7,
    'август': 8,
    'сентябр': 9,
    'октябр': 10,
    'ноябр': 11,
    'декабр': 12,
}


def normalize_name(value):
    value = (value or '').strip().lower()
    value = value.replace('«', '').replace('»', '').replace('"', '')
    value = re.sub(r'\s+', ' ', value)
    return value.strip()


def parse_month(value):
    if value is None or value == '':
        return None
    if isinstance(value, datetime):
        return value.strftime('%Y-%m-01')
    text = str(value).strip()
    if not text or text.lower() == 'итого':
        return None
    iso_match = re.match(r'^(\d{4})-(\d{2})-(\d{2})', text)
    if iso_match:
        return f"{iso_match.group(1)}-{iso_match.group(2)}-01"
    lowered = text.lower()
    for prefix, month_number in MONTHS.items():
        if prefix in lowered:
            year_match = re.search(r'(20\d{2})', lowered)
            if year_match:
                return f"{int(year_match.group(1)):04d}-{month_number:02d}-01"
    return None


def money_to_float(value):
    if value is None:
        return 0.0
    if isinstance(value, (int, float)):
        return round(float(value), 2)
    text = str(value).strip()
    if not text or set(text) == {'#'}:
        return 0.0
    text = text.replace('\xa0', '').replace(' ', '').replace(',', '.')
    try:
        return round(float(text), 2)
    except ValueError:
        return 0.0


def resolve_file(path_arg=None):
    if path_arg:
        return Path(path_arg)
    matches = sorted(Path('old').glob('*.xlsx'))
    if len(matches) == 1:
        return matches[0]
    raise RuntimeError('Excel file not found or ambiguous in old/')


def inspect(file_path, sheet_name='База клиентов'):
    wb = openpyxl.load_workbook(file_path, data_only=True)
    ws = wb[sheet_name]
    rows = list(ws.iter_rows(values_only=True))
    month_columns = {}
    total_column = None
    for idx, value in enumerate(rows[0]):
        month = parse_month(value)
        if month:
            month_columns[idx] = month
        elif str(value).strip() == 'Итого':
            total_column = idx

    parsed_rows = []
    for row in rows[3:]:
        client_name = (row[1] or '').strip() if len(row) > 1 and row[1] else ''
        if not client_name or client_name.lower() == 'итого':
            continue
        manager_name = (row[0] or '').strip() if len(row) > 0 and row[0] else ''
        total_amount = money_to_float(row[total_column]) if total_column is not None and total_column < len(row) else 0.0
        months = []
        for col_idx, month in month_columns.items():
            amount = money_to_float(row[col_idx] if col_idx < len(row) else None)
            if amount > 0:
                months.append({'sale_month': month, 'amount': amount})
        parsed_rows.append({
            'manager_name': manager_name,
            'client_name': client_name,
            'total_amount': total_amount,
            'months': months,
        })

    return {
        'file': str(file_path),
        'sheet': sheet_name,
        'month_columns': list(month_columns.values()),
        'rows_count': len(parsed_rows),
        'sales_rows': sum(len(item['months']) for item in parsed_rows),
        'preview': parsed_rows[:10],
    }


def main(argv):
    file_path = None
    sheet_name = 'База клиентов'
    for arg in argv[1:]:
        if arg.startswith('--file='):
            file_path = arg.split('=', 1)[1]
        elif arg.startswith('--sheet='):
            sheet_name = arg.split('=', 1)[1]

    result = inspect(resolve_file(file_path), sheet_name)
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv))
