# CORS for the web client (browser)

The Compose web app calls this API from **another origin** (e.g. `https://dev.xdexboard.com` → `https://dev.api.xdexboard.com`). Browsers require a valid CORS policy on API responses.

## Laravel (this repo)

**Laravel must not emit CORS headers** for this API. In Laravel 11+, `\Illuminate\Http\Middleware\HandleCors` is part of the **default global middleware** and reads **`vendor/laravel/framework/config/cors.php`** (paths include `api/*`, `allowed_origins` `*`) even if you deleted the project’s `config/cors.php`. That duplicates whatever Apache/Nginx already sends.

We explicitly **`$middleware->remove(\Illuminate\Http\Middleware\HandleCors::class)`** in `bootstrap/app.php`. CORS is configured **outside** PHP, at the web server (or TLS terminator) in front of `public/index.php`.

## XAMPP / Apache (local)

CORS for `api.xdexboard.com`, `dev.api.xdexboard.com`, `sandbox.api.xdexboard.com` (and the `*.xrpchange.site` API vhost) is defined in **`httpd-vhosts.conf`** on the Apache machine: `mod_headers` sets `Access-Control-Allow-*`, and `mod_rewrite` answers **OPTIONS** with `200` so preflight does not depend on Laravel.

After editing vhosts: reload Apache (e.g. XAMPP control panel). Ensure **`mod_headers`** and **`mod_rewrite`** are enabled.

## Nginx (sample configs in this repo)

`nginx-xboard.conf` / `nginx-dev.conf` reverse-proxy to the upstream that runs Laravel. **Add CORS at only one layer:** if the upstream Apache already sends `Access-Control-*` (as in the XAMPP vhosts above), **do not** duplicate `add_header Access-Control-*` in Nginx for the same responses — Chromium will reject duplicate `Access-Control-Allow-Origin`.

If the upstream does **not** send CORS (e.g. PHP-FPM only), add a single CORS policy in the `location /api/` block (including handling `OPTIONS`).

**If a server config was created before this rule:** remove legacy `add_header Access-Control-*` / `if ($request_method = OPTIONS)` blocks from `location /api/`, or run `scripts/nginx_strip_duplicate_api_cors.py` on the host (targets `/etc/nginx/sites-enabled/xdexboard.conf` by default; creates `*.bak-cors`, then `nginx -t` and reload).

## Duplicate `Access-Control-Allow-Origin`

If the browser reports multiple values (e.g. `*, *` or `*, *, *`), more than one layer is still emitting CORS (typical stack: **Apache + Laravel HandleCors + Nginx `add_header`**). Keep exactly **one** layer; ensure deployed Nginx matches the samples in this repo (no `add_header Access-Control-*` when upstream Apache already sets them).

## Client symptom

`Failed to fetch` / `Load failed` on cross-origin API calls: check DevTools → Network → failed request → Headers, and verify only one source adds CORS.
