# IP lookup URL template (`IP_LOOKUP_URL_TEMPLATE`)

Partners may prefer a **regional or branded public IP lookup** page instead of the default. The API advertises the template to mobile clients via **`GET /api/v1/nodes`** response **`meta.ip_lookup_url_template`**.

## `.env`

```dotenv
# Optional. Placeholder {ip} is replaced with percent-encoded IPv4/IPv6 (safe for path or query).
IP_LOOKUP_URL_TEMPLATE="https://ipinfo.io/{ip}"
```

- If unset, the default in `config/xboard.php` is used (`https://ipinfo.io/{ip}`).
- The value must be a single **HTTPS** URL template for UX and security (clients should only open HTTPS in normal operation).

## Placeholder

- **`{ip}`** — required for predictable substitution. The client replaces it with the buyer’s shared IP after **percent-encoding** characters that are not unreserved in URLs (so IPv6 and unusual forms stay safe).
- If the template does not contain `{ip}` (misconfiguration), the client falls back to appending the encoded IP as a query parameter (`?ip=` or `&ip=`).

## Examples

| Partner need | Example value |
|-------------|----------------|
| Default | `https://ipinfo.io/{ip}` |
| Query style | `https://example.net/geo?address={ip}` |
| Path segment | `https://regionsite.example/ip/{ip}` |

## Notes

- This is **not** server-side IP intelligence: the API only stores/forwards the buyer IP for deals (see product rules); the **seller** opens the URL in a browser.
- After changing `.env`, reload PHP/opcache as usual; clients pick up the new template on the **next successful** `/nodes` fetch (reference catalog).
