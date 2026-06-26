#!/usr/bin/env python3
"""One-shot: set PmaAbsoluteUri in phpMyAdmin config.inc.php on production."""
from pathlib import Path

path = Path("/usr/share/phpmyadmin/config.inc.php")
text = path.read_text(encoding="utf-8")

# Remove accidental broken append from a bad shell escape.
bad = "<?php\n$cfg[ ' PmaAbsoluteUri ' ] =  ' https://xrpchange.site/phpmyadmin/ ' ;\n"
text = text.replace(bad, "")

line = "$cfg['PmaAbsoluteUri'] = 'https://xdexboard.com/phpmyadmin/';\n"
if "xrpchange.site/phpmyadmin" in text:
    text = text.replace("https://xrpchange.site/phpmyadmin/", "https://xdexboard.com/phpmyadmin/")
if "PmaAbsoluteUri" not in text:
    text = text.rstrip() + "\n\n" + line

path.write_text(text, encoding="utf-8")
print("OK:", line.strip())
