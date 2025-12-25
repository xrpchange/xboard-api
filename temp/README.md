# Temporary Test Scripts

This directory contains temporary test scripts for development and debugging.

**Note:** These scripts are for testing purposes only and may be removed or modified at any time.

## Scripts

- `test_get_message_by_uid.php` - Test getting email message by UID using webklex/php-imap
- `test_send_raw_email.php` - Test sending raw email (legacy, use `scripts/test_email_forwarding.php` instead)
- `test_yandex_imap_oauth.php` - Test Yandex IMAP OAuth connection
- `test_yandex_mail_api.php` - Test Yandex Mail API

## Usage

Run scripts from project root:
```bash
php temp/test_get_message_by_uid.php <access_token> [email] [uid]
php temp/test_yandex_imap_oauth.php <access_token> [email]
```

