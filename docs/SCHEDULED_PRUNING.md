# Scheduled pruning (daily cleanup)

The API runs two Artisan commands daily to permanently delete old records and keep tables small.

| Command | Table | Criteria | Default age |
|---------|--------|----------|-------------|
| `deal-chat:prune` | `deal_chat_messages` | `created_at` older than N days | 15 days |
| `transactions:prune` | `transactions` | `created_at` older than N days | 15 days |

Both are registered in **`bootstrap/app.php`** and run once per day when the Laravel scheduler is executed.

### Manual run

```bash
php artisan deal-chat:prune          # delete old deal chat messages
php artisan deal-chat:prune --dry-run
php artisan transactions:prune       # delete old transactions
php artisan transactions:prune --dry-run
```

Options (both commands):

- `--days=15` — delete records older than this many days (default: 15).
- `--dry-run` — only show how many rows would be deleted; do not delete.

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
