#!/usr/bin/env python3
"""
Remove duplicate CORS add_header blocks from Nginx location /api/ when upstream
Apache already sends Access-Control-* (see docs/CORS_WEB_CLIENT.md).

Safe to run multiple times: patterns only match the legacy block shape.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

DEFAULT_PATH = Path("/etc/nginx/sites-enabled/xdexboard.conf")

NOTE = """
        # CORS: only upstream Apache (httpd-vhosts). Do not add_header Access-Control-* here.
"""

PATTERN_WITH_EXTRA_COMMENTS = re.compile(
    r"""
\n[ \t]*\#[ \t]*CORS[ \t]+headers[ \t]+for[ \t]+cross-origin[ \t]+API[ \t]+access\n
[ \t]*\#[ \t]*Allows[ \t]+web[ \t]+applications[ \t]+to[ \t]+make[ \t]+API[ \t]+calls[ \t]+from[ \t]+different[ \t]+domains\n
[ \t]*add_header[ \t]+Access-Control-Allow-Origin[ \t]+"\*"[ \t]+always;\n
[ \t]*add_header[ \t]+Access-Control-Allow-Methods[ \t]+"GET,[ \t]+POST,[ \t]+PUT,[ \t]+DELETE,[ \t]+OPTIONS"[ \t]+always;\n
[ \t]*add_header[ \t]+Access-Control-Allow-Headers[ \t]+"[^"]+"[ \t]+always;\n
\n[ \t]*\#[ \t]*Handle[ \t]+CORS[ \t]+preflight[ \t]+requests[ \t]+\(OPTIONS[ \t]+method\)\n
[ \t]*\#[ \t]*Browsers[ \t]+send[ \t]+this[ \t]+before[ \t]+actual[ \t]+API[ \t]+requests[ \t]+to[ \t]+check[ \t]+permissions\n
[ \t]*if[ \t]+\(\$request_method[ \t]=[ \t]'OPTIONS'\)[ \t]\{\n
(?:[ \t]*add_header[^\n]+\n)+
[ \t]*return[ \t]+204;[^\n]*\n
[ \t]*\}
""",
    re.VERBOSE,
)

PATTERN_COMPACT = re.compile(
    r"""
\n[ \t]*\#[ \t]*CORS[ \t]+headers[ \t]+for[ \t]+cross-origin[ \t]+API[ \t]+access\n
[ \t]*add_header[ \t]+Access-Control-Allow-Origin[ \t]+"\*"[ \t]+always;\n
[ \t]*add_header[ \t]+Access-Control-Allow-Methods[ \t]+"GET,[ \t]+POST,[ \t]+PUT,[ \t]+DELETE,[ \t]+OPTIONS"[ \t]+always;\n
[ \t]*add_header[ \t]+Access-Control-Allow-Headers[ \t]+"[^"]+"[ \t]+always;\n
\n[ \t]*\#[ \t]*Handle[ \t]+CORS[ \t]+preflight[ \t]+requests[ \t]+\(OPTIONS[ \t]+method\)\n
[ \t]*if[ \t]+\(\$request_method[ \t]=[ \t]'OPTIONS'\)[ \t]\{\n
(?:[ \t]*add_header[^\n]+\n)+
[ \t]*return[ \t]+204;[^\n]*\n
[ \t]*\}
""",
    re.VERBOSE,
)


def main() -> int:
    path = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_PATH
    text = path.read_text(encoding="utf-8")
    backup = path.with_suffix(path.suffix + ".bak-cors")
    backup.write_text(text, encoding="utf-8")

    text, n1 = PATTERN_WITH_EXTRA_COMMENTS.subn(NOTE, text)
    text, n2 = PATTERN_COMPACT.subn(NOTE, text)
    total = n1 + n2
    if total == 0:
        print("No matching CORS blocks found; left file unchanged (backup still written).", file=sys.stderr)
        return 1

    path.write_text(text, encoding="utf-8")
    print(f"OK: removed {total} duplicate CORS block(s) ({n1} verbose, {n2} compact). Backup: {backup}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
