# Scheduled pruning (daily cleanup)

The API runs daily Artisan commands to permanently delete old records and clean disk.

| Command | Target | Criteria |
|---------|--------|----------|
| `deal-chat:prune` | `deal_chat_messages` | `created_at` older than N days (default 15) |
| `receipt-checksums:prune` | `receipt_file_checksums` | `created_at` older than N days (default 15, same TTL as deal chat) |
| `transactions:prune` | `transactions` | `created_at` older than N days (default 15) |
| `email-attachments-tmp:prune` | `email_attachments/{date}/tmp` | `date` = two days ago (1-day buffer) |
| `email-attachments-dates:prune` | `email_attachments/{date}` | `date` older than N days (default 15) |
| `deal-crypto-payout:process` | `deal_crypto_payout_jobs` | queued seller crypto payouts (retry every 5 min; XMR unlocked-balance deferral) |

Prune commands run once per day; **`deal-crypto-payout:process`** runs **every 5 minutes** when the Laravel scheduler is executed.

### `transactions:prune` and lot accounting (`sold_crypto_amount`)

Old `transactions` rows are deleted in small batches with per-model `delete()`, so model cleanup (for example receipt file deletion) still runs. **`exchange_lots.sold_crypto_amount` and `completed_deals_count` are accumulated when a `sale` transaction becomes `completed`, not during pruning.** Pruning only removes old transaction rows after their amounts were already reflected on the lot. **`completed_deals_count` / `bad_deals_count` are not decremented when old transactions are pruned** — see `docs/EXCHANGE_LOT_DEAL_COUNTERS.md`. **Remaining crypto on a partially sold lot** should therefore be derived from the lot field directly (for example `sell_amount - sold_crypto_amount`, per your product rules).

### Manual run

```bash
php artisan deal-chat:prune              # delete old deal chat messages
php artisan deal-chat:prune --dry-run
php artisan receipt-checksums:prune      # delete old receipt checksum rows
php artisan receipt-checksums:prune --dry-run
php artisan transactions:prune           # delete old transactions
php artisan transactions:prune --dry-run
php artisan email-attachments-tmp:prune      # delete email_attachments/{date}/tmp for date = 2 days ago
php artisan email-attachments-tmp:prune --dry-run
php artisan email-attachments-dates:prune    # delete email_attachments/{date} older than 15 days
php artisan email-attachments-dates:prune --days=15 --dry-run
php artisan deal-crypto-payout:process           # retry queued seller crypto payouts
php artisan deal-crypto-payout:process --limit=20
```

Options:

- `deal-chat:prune` / `receipt-checksums:prune` / `transactions:prune`: `--days=15` — delete records older than this many days; `--dry-run` — show count only.
- `email-attachments-tmp:prune`: `--dry-run` — log target date only, do not delete.
- `email-attachments-dates:prune`: `--days=15` — delete date folders older than this many days; `--dry-run` — list only.
- `deal-crypto-payout:process`: `--limit=50` — max jobs per run (default from config).

Seller payout queue: when sync `seller-crypto-payout-to-buyer` fails with Monero **unlocked balance insufficient**, API enqueues a job and returns HTTP **202**. The worker retries until funds unlock or `max_attempts` is reached. Client polls `GET /exchange-lots/{lot}/seller-crypto-payout-status`.

### Server setup

The scheduler must run **every minute**. On production (`server@xdexboard.com`, app in `/var/www/xboard-api`) use the **`www-data`** user — same as PHP-FPM (`.env`, `storage/`, uploaded attachments).

One-time install on the server:

```bash
sudo bash /var/www/xboard-api/scripts/install-laravel-scheduler-cron.sh
```

Equivalent crontab line (`sudo crontab -u www-data -e`):

```cron
# xboard-api Laravel schedule:run (see bootstrap/app.php)
* * * * * cd /var/www/xboard-api && /usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

Verify:

```bash
sudo crontab -u www-data -l
sudo -u www-data bash -c 'cd /var/www/xboard-api && php artisan schedule:list'
```

List scheduled tasks:

```bash
php artisan schedule:list
```

### See also

- **Deal chat table and pruning:** [DEAL_CHAT_TABLE_AND_SOFT_DELETE.md](DEAL_CHAT_TABLE_AND_SOFT_DELETE.md) (per-side soft delete and `deal-chat:prune`).
