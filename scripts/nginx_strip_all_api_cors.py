#!/usr/bin/env python3
"""Remove all Nginx add_header Access-Control-* and OPTIONS preflight blocks from config files."""
from __future__ import annotations

import re
import sys
from pathlib import Path

NOTE = "\n        # CORS: only upstream Apache (httpd-vhosts). Do not add_header Access-Control-* here.\n"


def strip_file(path: Path) -> int:
    raw = path.read_text(encoding="utf-8")
    backup = path.with_suffix(path.suffix + ".bak-cors2")
    backup.write_text(raw, encoding="utf-8")
    text = raw
    removed = 0

    block = re.compile(
        r"\n[ \t]*# CORS headers for cross-origin[^\n]*\n"
        r"(?:[ \t]*#[^\n]*\n)*"
        r"(?:[ \t]*add_header Access-Control[^\n]*\n)+"
        r"(?:[ \t]*#[^\n]*\n)*"
        r"[ \t]*if[ \t]*\(\$request_method[ \t]*=[ \t]*['\"]OPTIONS['\"]\)[ \t]*\{"
        r"(?:[ \t]*add_header[^\n]*\n)*"
        r"[ \t]*return 204[^\n]*\n"
        r"[ \t]*\}\n",
        re.MULTILINE,
    )
    text, n = block.subn(NOTE, text)
    removed += n

    text, n2 = re.subn(
        r"^[ \t]*add_header Access-Control[^\n]*\n",
        "",
        text,
        flags=re.MULTILINE,
    )
    removed += n2

    if text != raw:
        path.write_text(text, encoding="utf-8")
    left = len(re.findall(r"add_header Access-Control", text))
    print(f"{path}: patterns={removed}, add_header_left={left}, backup={backup}")
    return left


def main() -> int:
    paths = [Path(p) for p in sys.argv[1:]] or [
        Path("/etc/nginx/sites-available/core.conf"),
        Path("/etc/nginx/sites-available/xboard.conf"),
    ]
    bad = 0
    for p in paths:
        if not p.exists():
            print(f"skip missing {p}")
            continue
        bad += strip_file(p)
    return 1 if bad else 0


if __name__ == "__main__":
    raise SystemExit(main())
