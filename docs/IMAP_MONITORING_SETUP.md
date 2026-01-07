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

### 1. Add Email Account via Client

Email accounts are stored only on the client side. The server does not store IMAP credentials.

**Common IMAP Hosts:**
- Gmail: `imap.gmail.com` (port 993, requires App Password)
- Outlook: `outlook.office365.com` (port 993)
- Yandex: `imap.yandex.com` (port 993, requires app password, IMAP must be enabled)
- Custom: user's IMAP server

### 3. Gmail App Password Setup

For Gmail, users need to create App Password:

1. Go to Google Account → Security
2. Enable 2-Step Verification
3. Generate App Password
4. Use App Password (not regular password) in API


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
| **User Action** | Required each time | Automatic (client-side) |
| **Pattern Filtering** | Manual | Automatic |
| **Real-time** | No | Yes (client-side) |
| **Security** | Good | Better (credentials on client only) |

