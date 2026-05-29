#!/usr/bin/env bash
# Hotfix mobile Compose boot timeout in deployed index.html (xdexboard.com).
set -euo pipefail
INDEX=/var/www/xdexboard-web/index.html
cp -a "$INDEX" "${INDEX}.bak-mobile-boot"
python3 << 'PY'
from pathlib import Path
p = Path("/var/www/xdexboard-web/index.html")
text = p.read_text(encoding="utf-8")
if "function isSlowBootClient()" in text:
    print("already patched")
    raise SystemExit(0)
anchor = "            var wasmTimeoutMs = 90000;"
if anchor not in text:
    raise SystemExit("anchor wasmTimeoutMs not found")
insert = """            function isSlowBootClient() {
                var ua = navigator.userAgent || '';
                var touch = 0;
                try { touch = navigator.maxTouchPoints || 0; } catch (e) {}
                if (/Android|webOS|iPhone|iPod|iPad|Mobile|Opera Mini/i.test(ua)) return true;
                if (/Macintosh/i.test(ua) && touch > 1) return true;
                return touch > 0 && window.innerWidth > 0 && window.innerWidth < 1200;
            }

            function composeBootWaitMs() {
                return isSlowBootClient() ? 180000 : 60000;
            }

            var wasmTimeoutMs = isSlowBootClient() ? 240000 : 120000;"""
text = text.replace(anchor, insert, 1)
old_onload = """                    script.onload = function () {
                        setBootStage('webApp.js loaded, waiting for Compose...');
                        clearComposeWatchdog();
                        var composeWaitMs = 15000;
                        composeWatchdogId = setTimeout(function () {
                            composeWatchdogId = null;
                            if (window.__xboardComposeStarted || window.__xboardBootFailed) return;
                            bootFail(
                                'Compose did not start within ' +
                                    composeWaitMs +
                                    ' ms (no xboard-compose-started event). Check the console (F12).'
                            );
                        }, composeWaitMs);
                    };"""
new_onload = """                    script.onload = function () {
                        var composeWaitMs = composeBootWaitMs();
                        setBootStage(
                            isSlowBootClient()
                                ? 'Starting Compose (on phone first launch can take 1–2 min)…'
                                : 'webApp.js loaded, waiting for Compose…'
                        );
                        clearComposeWatchdog();
                        composeWatchdogId = setTimeout(function () {
                            composeWatchdogId = null;
                            if (window.__xboardComposeStarted || window.__xboardBootFailed) return;
                            bootFail(
                                'Compose did not start within ' +
                                    composeWaitMs +
                                    ' ms (no xboard-compose-started event). On phone: wait on Wi‑Fi, clear site data once, reload.'
                            );
                        }, composeWaitMs);
                    };"""
if old_onload not in text:
    raise SystemExit("anchor script.onload not found")
text = text.replace(old_onload, new_onload, 1)
p.write_text(text, encoding="utf-8")
print("patched index.html")
PY
