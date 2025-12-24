# IMAP Monitoring Setup for Payment Verification

## Overview

IMAP monitoring allows the server to read original emails from user's inbox, preserving DKIM signatures for secure payment verification.

## Architecture

```
User's Email (Gmail/Outlook/etc)
    ↓
IMAP Connection (Server-side, encrypted credentials)
    ↓
Server reads original email (DKIM preserved)
    ↓
Pattern filtering (bank domain + payment patterns)
    ↓
DKIM verification (original email, not forwarded)
    ↓
Payment processing & transaction update
```

## Setup

### 0. Install PHP IMAP Extension

**On server:**
```bash
sudo apt-get install -y php8.3-imap
sudo systemctl restart php8.3-fpm  # If using FPM
```

**Verify:**
```bash
php -m | grep imap
```

### 1. Database Migration

```bash
php artisan migrate
```

This creates `email_accounts` table for storing encrypted IMAP credentials.

### 2. Add Email Account via API

**Endpoint:** `POST /api/v1/email-accounts`

**Request:**
```json
{
  "email": "user@gmail.com",
  "password": "app_password_or_imap_password",
  "imap_host": "imap.gmail.com",
  "imap_port": 993
}
```

**Common IMAP Hosts:**
- Gmail: `imap.gmail.com` (port 993, requires App Password)
- Outlook: `outlook.office365.com` (port 993)
- Yandex: `imap.yandex.ru` (port 993)
- Custom: user's IMAP server

### 3. Gmail App Password Setup

For Gmail, users need to create App Password:

1. Go to Google Account → Security
2. Enable 2-Step Verification
3. Generate App Password
4. Use App Password (not regular password) in API

### 4. Run Monitoring Command

**One-time check:**
```bash
php artisan email:monitor --once
```

**Continuous monitoring (cron):**
```bash
# Add to crontab
* * * * * cd /path/to/xboard-api && php artisan email:monitor --once
```

## How It Works

### 1. Email Filtering

Only processes emails that:
- ✅ From allowed bank domains (`sberbank.ru`, `tinkoff.ru`, etc.)
- ✅ Match payment patterns (subject/body regex)
- ✅ Have valid DKIM signature

### 2. Pattern Matching

Payment patterns are defined per bank:

```php
'sberbank.ru' => [
    'subject' => '/оплата|платеж|перевод|payment/i',
    'body' => '/сумма.*?(\d+[.,]\d+)|amount.*?(\d+[.,]\d+)/i',
]
```

### 3. DKIM Verification

- Reads **original email** from IMAP (not forwarded)
- DKIM signature preserved
- Verifies using `opendkim-testmsg`
- Stores proof for disputes

### 4. Transaction Update

After successful verification:
- Extracts payment data (amount, date, reference)
- Calls API to update transaction
- Stores encrypted email proof

## Security

### Credential Storage

- Passwords encrypted using Laravel's `Crypt` facade
- Uses `APP_KEY` from `.env`
- Never stored in plain text
- Accessible only via model accessor

### IMAP Connection

- SSL/TLS required (port 993)
- Credentials never logged
- Connections closed after use
- Error handling prevents credential leaks

## API Endpoints

### Add Email Account

```http
POST /api/v1/email-accounts
Authorization: Bearer {token}
Content-Type: application/json

{
  "email": "user@gmail.com",
  "password": "app_password",
  "imap_host": "imap.gmail.com",
  "imap_port": 993
}
```

### List Email Accounts

```http
GET /api/v1/email-accounts
Authorization: Bearer {token}
```

### Remove Email Account

```http
DELETE /api/v1/email-accounts/{id}
Authorization: Bearer {token}
```

## Monitoring

### Check Status

```bash
# View active accounts
php artisan tinker
>>> App\Models\EmailAccount::active()->get(['email', 'last_checked_at']);

# Check logs
tail -f storage/logs/laravel.log | grep "Email monitoring"
```

### Troubleshooting

**Connection fails:**
- Check IMAP host and port
- Verify credentials (use App Password for Gmail)
- Check firewall rules
- Test IMAP connection manually

**No emails processed:**
- Check if emails match bank domain
- Verify payment patterns match
- Check DKIM verification logs

## Advantages Over Forwarding

| Aspect | Forwarding | IMAP Monitoring |
|--------|-----------|-----------------|
| **DKIM Preservation** | ❌ Broken | ✅ Preserved |
| **User Action** | Required each time | Automatic |
| **Pattern Filtering** | Manual | Automatic |
| **Real-time** | No | Yes (IDLE) |
| **Security** | Good | Better (encrypted) |

## Next Steps

1. Test with real email account
2. Add more bank patterns
3. Implement transaction update API
4. Add email proof storage
5. Set up cron job for monitoring

