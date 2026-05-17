import json
import sys
from pathlib import Path
import zipfile


def resolve_path(arg_list):
    if len(arg_list) >= 2:
        return Path(arg_list[1])

    old_dir = Path('old')
    matches = sorted([p for p in old_dir.iterdir() if p.is_file() and p.suffix.lower() in {'.xlsx', '.xls', '.xlsm'}])
    if len(matches) == 1:
        return matches[0]
    raise FileNotFoundError('Excel file not found or ambiguous in old/')


def main() -> int:
    try:
        path = resolve_path(sys.argv)
    except Exception as exc:
        print(json.dumps({"error": str(exc)}, ensure_ascii=False))
        return 1

    if not path.exists():
        print(json.dumps({"error": "file not found", "path": str(path)}, ensure_ascii=False))
        return 1

    with path.open('rb') as fh:
        head = fh.read(16)

    result = {
        "path": str(path),
        "suffix": path.suffix,
        "size": path.stat().st_size,
        "header_hex": head.hex(),
        "is_zip": zipfile.is_zipfile(path),
    }

    if head.startswith(b'PK'):
        result["signature"] = "zip"
    elif head.startswith(bytes.fromhex('d0cf11e0a1b11ae1')):
        result["signature"] = "ole"
    elif head.startswith(b'<'):
        result["signature"] = "xml_or_html"
    else:
        result["signature"] = "unknown"

    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
