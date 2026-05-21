# Web client origins (browser)

**Canonical doc:** `xboard-client/docs/WEB_CLIENT_ORIGINS.md` (keep in sync).

Pattern for any project domain `{domain}` (e.g. `xdexboard.com`, `xrpchange.site`):

| Web origin | When |
|------------|------|
| `https://{domain}` | Production web; also for **sandbox API** emails (no `sandbox.{domain}` web). |
| `https://dev.{domain}` | Development web (`dev.api.{domain}` requests). |

Deal emails: JSON **`client_web_origin`**, else **`App\Support\ClientWebOrigin::deriveFromApiHost()`** on the API request host. See `docs/DEAL_EMAIL_SUBJECT_TAG.md`.
