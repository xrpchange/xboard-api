#!/usr/bin/env python3
"""Remove nginx location /api/ OPTIONS handlers; Apache upstream sets CORS (single layer)."""
import re
import sys

path = sys.argv[1] if len(sys.argv) > 1 else "/etc/nginx/sites-available/core.conf"
with open(path, encoding="utf-8") as f:
    content = f.read()

pattern = (
    r"\n    # API specific settings[^\n]*\n"
    r"(?:    #[^\n]*\n)*"
    r"    location /api/ \{.*?\n    \}\n"
)
new_content, n = re.subn(pattern, "\n", content, count=0, flags=re.DOTALL)
if n < 1:
    print(f"no location /api/ blocks removed from {path}", file=sys.stderr)
    sys.exit(1)

with open(path, "w", encoding="utf-8") as f:
    f.write(new_content)
print(f"removed {n} location /api/ block(s) from {path}")
