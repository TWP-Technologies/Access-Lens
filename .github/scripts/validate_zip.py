#!/usr/bin/env python3

import os
import sys
import zipfile


def main() -> int:
    if len(sys.argv) != 4:
        print("Usage: validate_zip.py <build_dir> <slug> <asset_name>", file=sys.stderr)
        return 2

    build_dir, slug, asset = sys.argv[1:]
    zip_path = os.path.join(build_dir, asset)

    try:
        with zipfile.ZipFile(zip_path) as zf:
            names = [n for n in zf.namelist() if not n.endswith("/")]
            if not all(name.startswith(f"{slug}/") for name in names):
                print(f"Top-level folder mismatch in {asset}", file=sys.stderr)
                return 1
    except FileNotFoundError:
        print(f"Zip file not found: {zip_path}", file=sys.stderr)
        return 1
    except zipfile.BadZipFile:
        print(f"Invalid zip file: {zip_path}", file=sys.stderr)
        return 1

    print(f"Validated {asset}: entries start with {slug}/")
    return 0


if __name__ == "__main__":
    sys.exit(main())
