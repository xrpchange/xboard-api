# Ящик представительства admin@xdexboard.com

**Назначение:** переговоры и представительство (не `support@`, не `noreply@`, не `lot_mailboxes`).

| | `support@` | `admin@` |
|---|------------|----------|
| Локальное хранение (IMAP / Outlook) | Да | **Да** |
| Копия на внешний ящик (пуш) | Нет | **Да** (BCC, настраивается) |
| Исходящий SMTP | Пароль + `sender_login_maps` | То же |

## Outlook / IMAP

- **Сервер:** `mail.xdexboard.com`
- **Порт:** 993, SSL/TLS
- **Логин:** `admin@xdexboard.com`
- **Пароль:** в `/etc/dovecot/passwd.admin` на почтовом сервере (не в git)

Письма лежат в Maildir: `/var/mail/vhosts/xdexboard.com/admin/` (как у `support@`).

## Копия для пуша (Yandex и т.д.)

Это **не замена** ящика, а **дополнительная копия** (BCC): основное письмо остаётся на сервере для Outlook.

Файл: **`/etc/postfix/operator-push-bcc.map`**

```text
admin@xdexboard.com	tehnograny@yandex.ru
```

После смены адреса:

```bash
sudo postmap hash:/etc/postfix/operator-push-bcc.map
sudo postfix reload
```

Шаблон: `scripts/email-server/operator-push-bcc.map`.

## Безопасность SMTP

См. `docs/EMAIL_SPAM_INCIDENT_2026-04-01.md`: на 587/465 действуют `reject_sender_login_mismatch` и **`sender_login_maps`**. Отправить от `admin@` можно только с паролем этого ящика. **`admin@` не в `exempt_senders`**.

## Установка / исправление на сервере

```bash
sudo bash scripts/email-server/setup-admin-forward-mailbox.sh tehnograny@yandex.ru
```

Скрипт: `virtual_mailbox_maps`, `operator-push-bcc.map`, `exempt_recipients`, `sender_login_maps`, Dovecot. Пароль **не** перезаписывает, если `passwd.admin` уже есть.

## Postfix (чеклист)

| Карта | `admin@` |
|-------|----------|
| `virtual_mailbox_maps` | `xdexboard.com/admin/` |
| `operator-push-bcc.map` | BCC на внешний ящик |
| `exempt_recipients` | `OK` |
| `sender_login_maps` | `admin@` → `admin@` |
| `operator-forward.map` | **не** использовать для `admin@` (иначе не будет локального ящика) |

См. `docs/LOT_MAILBOX_AND_IMAP_CHAT.md` (ящик поддержки), `docs/NOREPLY_MAILBOX_SETUP.md`.
