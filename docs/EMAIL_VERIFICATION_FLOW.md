# Email Verification Flow

## Overview

The email verification system automatically checks DKIM signatures of forwarded emails from banks and sends responses to buyers in the P2P exchange.

## How It Works

### 1. User Flow

```
┌─────────────┐
│   Buyer     │
│  (User)     │
└──────┬──────┘
       │
       │ 1. Receives payment notification from bank
       │
       ▼
┌─────────────────────┐
│  Bank Email         │
│  (with DKIM)        │
└──────┬──────────────┘
       │
       │ 2. Forwards email to check@xdexboard.com
       │    (without adding text)
       │
       ▼
┌─────────────────────┐
│  Postfix            │
│  (SMTP Server)      │
└──────┬──────────────┘
       │
       │ 3. Pipes email to PHP script
       │
       ▼
┌─────────────────────┐
│  php-verifier.php   │
│  (DKIM Checker)     │
└──────┬──────────────┘
       │
       │ 4. Verifies:
       │    - DKIM signature
       │    - Sender domain (bank)
       │
       ├─── Success ────┐
       │                │
       ▼                ▼
┌─────────────┐  ┌─────────────┐
│  Success    │  │   Error     │
│  Response   │  │   Response  │
└─────────────┘  └─────────────┘
       │                │
       └────────┬───────┘
                │
                │ 5. Sends response email
                │
                ▼
         ┌─────────────┐
         │   Buyer     │
         │  (User)     │
         └─────────────┘
```

### 2. Email Processing Steps

1. **Email Reception**
   - Email arrives at `check@xdexboard.com`
   - Postfix receives it via SMTP (port 25)
   - Postfix pipes email to PHP script via `/etc/postfix/master.cf`

2. **DKIM Verification**
   - PHP script reads email from STDIN
   - Extracts DKIM-Signature header
   - Verifies signature using `opendkim-testmsg`
   - Checks if signature domain matches allowed bank domains

3. **Sender Verification**
   - Extracts From header
   - Checks if sender domain is in `ALLOWED_BANK_DOMAINS` list
   - Validates email format

4. **Response Generation**
   - Extracts reply-to address (buyer's email)
   - Sends appropriate response based on verification result
   - Logs all actions

### 3. Response Types

#### Success Response
```
Subject: Платеж подтвержден

Здравствуйте!

Ваш платеж успешно подтвержден. DKIM подпись проверена, 
письмо от банка получено и обработано.

Спасибо за использование XBoard!

--
XBoard P2P Exchange
```

#### DKIM Failed Response
```
Subject: Ошибка проверки платежа

Здравствуйте!

К сожалению, не удалось проверить подлинность письма от банка. 
DKIM подпись сломалась при пересылке.

Что делать:
1. Перешлите письмо от банка заново
2. НЕ добавляйте никакого текста при пересылке
3. Используйте функцию "Переслать как вложение" 
   (Forward as Attachment), если доступна

Если проблема повторяется, свяжитесь с поддержкой.

--
XBoard P2P Exchange
```

#### Invalid Sender Response
```
Subject: Ошибка проверки платежа

Здравствуйте!

Письмо не может быть обработано, так как оно не пришло 
с адреса банка или было модифицировано.

Пожалуйста, перешлите оригинальное письмо от банка без изменений.

--
XBoard P2P Exchange
```

## Technical Details

### Allowed Bank Domains

The system checks emails from these bank domains:

- `sberbank.ru`
- `tinkoff.ru`
- `alfabank.ru`
- `vtb.ru`
- `gazprombank.ru`
- `raiffeisen.ru`
- `rosbank.ru`
- `otpbank.ru`
- `uralsib.ru`
- `banki.ru`

To add more banks, edit `ALLOWED_BANK_DOMAINS` in `php-verifier.php`.

### Logging

All actions are logged to `/var/log/xboard-email/verifier.log`:

```
[2025-12-22 10:33:07] [INFO] Received email of size 817 bytes
[2025-12-22 10:33:07] [INFO] Starting email processing
[2025-12-22 10:33:07] [INFO] Reply-to address: buyer@example.com
[2025-12-22 10:33:07] [INFO] Sender verified: noreply@sberbank.ru
[2025-12-22 10:33:07] [INFO] DKIM verification passed via opendkim for domain: sberbank.ru
[2025-12-22 10:33:07] [INFO] Email successfully verified. DKIM: sberbank.ru, Sender: noreply@sberbank.ru
[2025-12-22 10:33:07] [INFO] Email sent: buyer@example.com - Платеж подтвержден
[2025-12-22 10:33:07] [INFO] Email processing completed successfully
```

### Postfix Configuration

Email routing is configured in `/etc/postfix/master.cf`:

```
check@xdexboard.com    unix - n n - - pipe
  flags=F user=vmail argv=php /opt/xboard-api/scripts/email-server/php-verifier.php
```

### DKIM Verification Process

1. Extract DKIM-Signature header from email
2. Parse signature to get domain (`d=` parameter)
3. Check if domain matches allowed bank domains
4. Run `opendkim-testmsg` to verify signature cryptographically
5. If verification passes, proceed to sender check

## Testing

### Test 1: Send Test Email

```bash
ssh server@xrpchange.site
echo "Test email body" | mail -s "Test Subject" check@xdexboard.com
sudo tail -f /var/log/xboard-email/verifier.log
```

### Test 2: Forward Real Bank Email

1. Get a real payment notification email from a bank
2. Forward it to `check@xdexboard.com`
3. **Important:** Don't add any text when forwarding!
4. Check the response email and logs

### Test 3: Check Logs

```bash
# Real-time log monitoring
sudo tail -f /var/log/xboard-email/verifier.log

# Postfix logs
sudo tail -f /var/log/mail.log

# Check email queue
sudo postqueue -p
```

## Monitoring

### Service Status

```bash
sudo systemctl status postfix dovecot opendkim
```

### Log Locations

- PHP Verifier: `/var/log/xboard-email/verifier.log`
- Postfix: `/var/log/mail.log`
- Dovecot: `/var/log/dovecot.log`
- OpenDKIM: `journalctl -u opendkim -f`

### Common Issues

1. **DKIM verification fails**
   - Check if email was forwarded correctly (no text added)
   - Verify bank domain is in allowed list
   - Check OpenDKIM service status

2. **Reply-to address not found**
   - Email might not have proper From/Reply-To headers
   - Check email format in logs

3. **Email not processed**
   - Check Postfix logs for errors
   - Verify PHP script permissions
   - Check if script path is correct in master.cf

## Future Enhancements

- API integration to update payment status in database
- Webhook notifications for verified payments
- Statistics dashboard
- Support for more email formats
- Automatic retry mechanism for failed verifications

