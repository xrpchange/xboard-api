# Web Push (deal chat, browser background)

Browser **Web Push** complements **IMAP IDLE** (active tab). The API sends push after `insertDealChatMessage` — same trigger as deal mail, independent of whether the client’s WSS IDLE is alive.

## Server setup

### Windows XAMPP (local API on this PC)

If Laravel log shows `[deal-web-push] batch failed` with **`Unable to create the local key`**, the cause is almost always **XAMPP Apache**, not the client:

- `C:\xampp\apache\conf\extra\httpd-xampp.conf` sets  
  `SetEnv OPENSSL_CONF "C:/xampp/apache/bin/openssl.cnf"`.
- On a default install that file is **missing** under `apache\bin\`. CLI PHP uses `php\extras\ssl\openssl.cnf` and works; **Apache** does not.
- `putenv()` from Laravel **cannot** override Apache `SetEnv` on Windows — fix XAMPP, then restart Apache.

**XAMPP `SetEnv OPENSSL_CONF` is not enough on Windows:** under `apache2handler`, `openssl_pkey_new()` often **ignores** the environment variable (`EC default env=fail` in diagnostics) while the same path with explicit `'config' => …` works (`EC with config …=ok`).

**Do both:**

1. **XAMPP:** keep a valid cnf (e.g. `SetEnv OPENSSL_CONF "C:/xampp/php/extras/ssl/openssl.cnf"` in `httpd-xampp.conf` and/or copy into `apache\bin\openssl.cnf`).
2. **API:** after `composer install`, `scripts/patch-web-push-openssl-config.php` patches `vendor/minishlink/web-push` so encryption passes `'config'` (runs on `post-autoload-dump`). Re-run if vendor was updated:  
   `php scripts/patch-web-push-openssl-config.php`

**Verify:** `https://sandbox.api.xdexboard.com/openssl-diag.php` — expect `OpenSslWindowsConfig::ecKeyWorks()=ok` and `web-push Encryption patched=yes`. Then send deal chat; log must show `[deal-web-push] sent`, not `batch failed`.

Push is **not** triggered by IMAP email arrival alone — only after deal chat is persisted via API. Recipient role: buyer message → push to **seller** subscriptions on that lot.

```bash
php artisan web-push:generate-vapid
# Add WEB_PUSH_VAPID_PUBLIC_KEY / WEB_PUSH_VAPID_PRIVATE_KEY to .env
php artisan migrate
```

| Env | Purpose |
|-----|---------|
| `WEB_PUSH_VAPID_PUBLIC_KEY` | Exposed to web client for `PushManager.subscribe` |
| `WEB_PUSH_VAPID_PRIVATE_KEY` | Server-only |
| `WEB_PUSH_VAPID_SUBJECT` | `mailto:…` or `https://…` (default `mailto:admin@xdexboard.com`) |
| `WEB_PUSH_ENABLED` | `false` disables subscribe + send |

## API

| Method | Path | Auth |
|--------|------|------|
| GET | `/api/v1/web-push/vapid-public-key` | Public |
| POST | `/api/v1/web-push/subscribe` | `X-Escrow-Token` |
| POST | `/api/v1/web-push/unsubscribe` | `X-Escrow-Token` |

Subscribe body: `client_id`, `subscription` (Push API object), `watches[]` with `lot_id`, `node_key`, `role` (`buyer` \| `seller`).

Push payload (fixed English, no server locale): `title`, `body`, `lot_id`, `node_key`, `url` → `/open-deal?…`.

Recipient: opposite of `sender_side` on chat write (`buyer` → notify `seller` subscriptions, etc.).

## Client

- `webApp/src/main/resources/push-sw.js` — dedicated worker (not unregistered by boot cache purge).
- Settings → enable Web Push (web only); sync watches for active deals.
- See **xboard-client** `docs/WEB_PUSH.md`.

## Registry

| ID | Rule |
|----|------|
| WEB-PUSH-001 | VAPID configured → subscribe persists rows; chat insert queues push to matching lot+role |
| WEB-PUSH-002 | VAPID empty → subscribe 503, notifier no-op |
