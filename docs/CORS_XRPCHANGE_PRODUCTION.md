# CORS: `*.xrpchange.site` API (local / TLS front only)

> **Scope:** отладка CORS для **локальных** vhost `*.xrpchange.site` на XAMPP (имитация второго узла). **Не** документация боевого продукта xrpchange; production API — **`*.xdexboard.com`** (`docs/CORS_WEB_CLIENT.md`).

## Symptom

From `https://xdexboard.com` (or another web origin):

```text
Access-Control-Allow-Origin header contains multiple values '*, *', but only one is allowed.
```

Then the client shows `Fail to fetch` / `net::ERR_FAILED`. Service worker may also log `Failed to convert value to 'Response'` if it intercepts API fetches (fixed in web `sw.js` v22+).

## Cause

**Two layers** emit CORS on the same response:

1. **Apache** (`httpd-vhosts.conf` on the app host) — `Header always set Access-Control-Allow-Origin "*"` (correct, keep this).
2. **Nginx** in front (TLS terminator) — legacy `add_header Access-Control-*` in `location /api/` (remove this).

Local XAMPP `httpd-vhosts.conf` alone is not enough if public HTTPS goes through Nginx on the server.

## Verify (from any machine)

```bash
curl -sI "https://sandbox.api.xrpchange.site/api/v1/countries" \
  -H "Origin: https://xdexboard.com" | grep -i access-control
```

**Bad** (duplicate):

```http
Access-Control-Allow-Origin: *
...
Access-Control-Allow-Origin: *
```

**Good** (single block):

```http
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS
...
```

## Fix on the server

1. Find the active Nginx site for `sandbox.api.xrpchange.site` (often `/etc/nginx/sites-enabled/`).
2. In every `location /api/` (and any `if ($request_method = OPTIONS)` CORS block), **remove** all `add_header Access-Control-*` and OPTIONS `return 204` handlers.
3. Keep proxy_pass to Apache only. Reference: `nginx-xrpchange-api.conf` in this repo.
4. Or run: `python3 scripts/nginx_strip_duplicate_api_cors.py /etc/nginx/sites-enabled/your-site.conf`
5. `nginx -t && systemctl reload nginx`
6. Re-run the `curl` check above.

### Core API (`sandbox.api.core.xrpchange.site`)

On **`khasanov@xrp1`**, TLS for `*.core.xrpchange.site` is in **`/etc/nginx/sites-available/core.conf`** (symlink in `sites-enabled/`). A broken variant answered **OPTIONS with `204` and no `Access-Control-*`** (nginx intercepted `/api/` before Apache).

**Fix (Jun 2026):** remove both `location /api/ { … if OPTIONS return 204; }` blocks so preflight is proxied to XAMPP Apache (`httpd-vhosts.conf` for `api.core.xrpchange.site`). Helper:

```bash
scp scripts/fix-nginx-core-xrpchange-cors.py khasanov@xrp1:/tmp/
ssh khasanov@xrp1 'sudo cp /etc/nginx/sites-available/core.conf /etc/nginx/sites-available/core.conf.bak && sudo python3 /tmp/fix-nginx-core-xrpchange-cors.py /etc/nginx/sites-available/core.conf && sudo nginx -t && sudo systemctl reload nginx'
```

**Verify Core preflight:**

```bash
curl -sI -X OPTIONS "https://sandbox.api.core.xrpchange.site/api/escrow-tokens/get-encrypted-part" \
  -H "Origin: https://xdexboard.com" \
  -H "Access-Control-Request-Method: POST" | grep -i access-control
```

Expect **`Access-Control-Allow-Origin: *`** (from Apache), not a bare `204` from nginx.

Laravel must **not** add CORS (`HandleCors` removed in `bootstrap/app.php`). Do not re-enable `config/cors.php` while Apache sets headers.

## Apache (upstream)

On the machine that runs `xboard-api/public`, the vhost for `api.xrpchange.site` / `sandbox.api.xrpchange.site` should match `C:/xampp/apache/conf/extra/httpd-vhosts.conf` (Windows) or equivalent: one `Header always set Access-Control-*` block + OPTIONS rewrite to 200.

After editing Apache: reload Apache on that host.
