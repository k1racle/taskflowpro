import json
import math
import sys
from pathlib import Path


def normalize_value(value):
    if value is None:
        return None
    if isinstance(value, float) and math.isnan(value):
        return None
    if hasattr(value, "isoformat"):
        try:
            return value.isoformat()
        except Exception:
            pass
    return value


def load_with_pandas(path: Path):
    import pandas as pd

    workbook = pd.ExcelFile(path)
    result = {}
    for sheet_name in workbook.sheet_names:
        frame = workbook.parse(sheet_name=sheet_name, header=None)
        rows = []
        for row in frame.itertuples(index=False):
            rows.append([normalize_value(value) for value in row])
        result[sheet_name] = rows
    return result


def load_with_xlrd(path: Path):
    import xlrd

    workbook = xlrd.open_workbook(path)
    result = {}
    for sheet_name in workbook.sheet_names():
        sheet = workbook.sheet_by_name(sheet_name)
        rows = []
        for row_index in range(sheet.nrows):
            rows.append([normalize_value(sheet.cell_value(row_index, col_index)) for col_index in range(sheet.ncols)])
        result[sheet_name] = rows
    return result


def load_with_win32com(path: Path):
    import pythoncom
    import win32com.client

    pythoncom.CoInitialize()
    excel = win32com.client.Dispatch("Excel.Application")
    excel.Visible = False
    excel.DisplayAlerts = False
    workbook = None
    try:
        workbook = excel.Workbooks.Open(str(path.resolve()), 0, True)
        result = {}
        for worksheet in workbook.Worksheets:
            used_range = worksheet.UsedRange
            row_count = int(used_range.Rows.Count)
            col_count = int(used_range.Columns.Count)
            rows = []
            for row_index in range(1, row_count + 1):
                row = []
                for col_index in range(1, col_count + 1):
                    value = used_range.Cells(row_index, col_index).Value
                    row.append(normalize_value(value))
                rows.append(row)
            result[str(worksheet.Name)] = rows
        return result
    finally:
        if workbook is not None:
            workbook.Close(False)
        excel.Quit()
        pythoncom.CoUninitialize()


def main() -> int:
    if len(sys.argv) < 2:
        print(json.dumps({"error": "file argument required"}, ensure_ascii=False))
        return 1

    path = Path(sys.argv[1])
    if not path.exists():
        print(json.dumps({"error": f"file not found: {path}"}, ensure_ascii=False))
        return 1

    errors = []
    data = None
    for loader in (load_with_pandas, load_with_xlrd, load_with_win32com):
        try:
            data = loader(path)
            break
        except Exception as exc:
            errors.append(f"{loader.__name__}: {exc}")

    if data is None:
        print(json.dumps({"error": "failed to read workbook", "details": errors}, ensure_ascii=False))
        return 1

    print(json.dumps(data, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
