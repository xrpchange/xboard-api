# Email Server Setup для XBoard API

## Описание

Автоматическая настройка почтового сервера для верификации платежей в P2P-обменнике через проверку DKIM подписи пересланных писем от банков.

## Структура файлов

```
scripts/email-server/
├── install.sh                      # Установка Postfix/Dovecot для верификации платежей
├── setup-mysql-users.sh            # Создание MySQL-пользователей postfix/dovecot (lot mailboxes)
├── setup-lot-mailboxes.sh          # Настройка Postfix/Dovecot для ящиков лотов (IMAP-чат)
├── php-verifier.php                # PHP скрипт проверки DKIM и отправки ответов
├── configs/                        # Примеры конфигураций
│   ├── postfix-main.cf.example
│   ├── postfix-master.cf.example
│   ├── postfix-mysql-lot-mailboxes.cf.example
│   ├── dovecot.conf.example
│   ├── dovecot-10-imap.conf.example
│   └── dovecot-sql-lot-mailboxes.conf.example
└── README.md
```

## Быстрый старт

1. **Подключитесь к серверу:**
   ```bash
   ssh server@xrpchange.site
   ```

2. **Скопируйте файлы на сервер:**
   ```bash
   # Если репозиторий уже на сервере
   cd /path/to/xboard-api
   
   # Или скопируйте через scp
   scp -r scripts/email-server server@xrpchange.site:/path/to/xboard-api/
   ```

3. **Запустите установку:**
   ```bash
   cd /path/to/xboard-api/scripts/email-server
   chmod +x install.sh
   sudo ./install.sh
   ```

4. **Настройте DNS записи (SPF, DKIM, DMARC)** — см. `docs/MAIL_SPF_DKIM_DMARC.md` и `docs/EMAIL_SERVER_SETUP.md`

5. **Протестируйте** (см. раздел "Тестирование" в документации)

## Требования

- Ubuntu 24.04 LTS (или последняя LTS)
- Права root для установки
- Доступ к настройке DNS записей
- Домен xdexboard.com настроен и указывает на сервер

## Компоненты

- **Postfix** - SMTP сервер, приём писем
- **Dovecot** - IMAP сервер с IDLE поддержкой
- **OpenDKIM** - подпись исходящих писем
- **PHP 8.3** - скрипт проверки DKIM
- **Let's Encrypt** - SSL сертификаты

## Ящики лотов (IMAP-чат)

Для чата между участниками сделки через IMAP:

1. Убедитесь, что миграция `lot_mailboxes` выполнена: `php artisan migrate`
2. На почтовом сервере установите пакеты: `apt-get install postfix-mysql dovecot-mysql`
3. Запустите скрипт (создаст MySQL-пользователей и настроит Postfix/Dovecot):
   ```bash
   cd scripts/email-server
   sed -i 's/\r$//' setup-mysql-users.sh setup-lot-mailboxes.sh  # при копировании с Windows
   sudo bash setup-mysql-users.sh
   ```

См. [`docs/LOT_MAILBOX_AND_IMAP_CHAT.md`](../../docs/LOT_MAILBOX_AND_IMAP_CHAT.md)

## Документация

- **Полная настройка сервера:** [`docs/EMAIL_SERVER_SETUP.md`](../../docs/EMAIL_SERVER_SETUP.md)
- **SPF, DKIM, DMARC (доставка, защита от спама):** [`docs/MAIL_SPF_DKIM_DMARC.md`](../../docs/MAIL_SPF_DKIM_DMARC.md)

## Типичные ошибки (Postfix / lot mailboxes)

- **Postfix не принимает письма, таймаут по 25/587** — см. раздел 4 в [`docs/CHECK_POSTFIX_DEAL_MESSAGE.md`](../../docs/CHECK_POSTFIX_DEAL_MESSAGE.md): пустой `dbname`, `hosts = localhost` (нужен `127.0.0.1` из‑за chroot), неверный путь в `query` (должен быть относительный).
- **В IMAP-клиенте (Canary) пустая папка у ящика лота** — Dovecot должен использовать Maildir в home. Два варианта: (1) в `user_query` в `/etc/dovecot/dovecot-sql-lot.conf` добавить `'maildir:~/' AS mail`; (2) если первым в конфиге идёт статический userdb с `home=/var/mail/vhosts/%d/%n`, в его `args` добавить `mail=maildir:~/`. См. раздел 4.4 в `CHECK_POSTFIX_DEAL_MESSAGE.md` и раздел «В IMAP-клиенте не видно писем» в [`docs/LOT_MAILBOX_AND_IMAP_CHAT.md`](../../docs/LOT_MAILBOX_AND_IMAP_CHAT.md).

## Поддержка

При проблемах проверьте:
1. Логи: `/var/log/xboard-email/verifier.log`
2. Статус сервисов: `systemctl status postfix dovecot opendkim`
3. DNS записи: `dig MX xdexboard.com`
4. **Yandex не принимает / Gmail в спам:** проверьте PTR: `dig -x <IP_сервера> +short` должен вернуть `mail.xdexboard.com`. Если PTR указывает на другое имя (например supersrv.de), в панели хостера задайте PTR = mail.xdexboard.com (см. раздел 4 в `docs/MAIL_SPF_DKIM_DMARC.md`).

