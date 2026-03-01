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

## Поддержка

При проблемах проверьте:
1. Логи: `/var/log/xboard-email/verifier.log`
2. Статус сервисов: `systemctl status postfix dovecot opendkim`
3. DNS записи: `dig MX xdexboard.com`

