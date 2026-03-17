# Scheduled pruning (daily cleanup)

The API runs three Artisan commands daily to permanently delete old records and clean disk.

| Command | Target | Criteria |
|---------|--------|----------|
| `deal-chat:prune` | `deal_chat_messages` | `created_at` older than N days (default 15) |
| `transactions:prune` | `transactions` | `created_at` older than N days (default 15) |
| `email-attachments-tmp:prune` | `email_attachments/{date}/tmp` | `date` = two days ago (1-day buffer) |
| `email-attachments-dates:prune` | `email_attachments/{date}` | `date` older than N days (default 15) |

Both are registered in **`bootstrap/app.php`** and run once per day when the Laravel scheduler is executed.

### Manual run

```bash
php artisan deal-chat:prune              # delete old deal chat messages
php artisan deal-chat:prune --dry-run
php artisan transactions:prune           # delete old transactions
php artisan transactions:prune --dry-run
php artisan email-attachments-tmp:prune      # delete email_attachments/{date}/tmp for date = 2 days ago
php artisan email-attachments-tmp:prune --dry-run
php artisan email-attachments-dates:prune    # delete email_attachments/{date} older than 15 days
php artisan email-attachments-dates:prune --days=15 --dry-run
```

Options:

- `deal-chat:prune` / `transactions:prune`: `--days=15` — delete records older than this many days; `--dry-run` — show count only.
- `email-attachments-tmp:prune`: `--dry-run` — log target date only, do not delete.
- `email-attachments-dates:prune`: `--days=15` — delete date folders older than this many days; `--dry-run` — list only.

### Server setup

Ensure the Laravel scheduler runs every minute (cron), for example:

```bash
* * * * * cd /path-to-xboard-api && php artisan schedule:run >> /dev/null 2>&1
```

List scheduled tasks:

```bash
php artisan schedule:list
```

### See also

- **Deal chat table and pruning:** [DEAL_CHAT_TABLE_AND_SOFT_DELETE.md](DEAL_CHAT_TABLE_AND_SOFT_DELETE.md) (per-side soft delete and `deal-chat:prune`).
