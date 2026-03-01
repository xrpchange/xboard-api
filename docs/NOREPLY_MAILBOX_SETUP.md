# Технический рассыльщик noreply@xdexboard.com

**Назначение:** Технический аккаунт **только для отправки** писем от API (рассыльщик). Через него планируется отправлять, в частности, **коды подтверждения на email пользователей при создании токенов**, а также другие служебные письма (например, From в верификации платежей).

- **В `lot_mailboxes` его не добавляют** — эта таблица только для ящиков чата по лотам (IMAP).
- **Учётные данные хранятся только в `.env`** проекта xboard-api (`C:\xampp\htdocs\Projects\xboard-api\.env` или на сервере — путь к .env приложения).

Физически ящик на почтовом сервере может быть создан отдельно (виртуальный пользователь вне `lot_mailboxes`), чтобы SMTP-авторизация работала. Настройки же, с которыми работает API, задаются в .env.

---

## Настройки в .env (xboard-api)

Все параметры рассыльщика задаются в `.env`:

```env
# Рассыльщик (noreply) — технический аккаунт для отправки писем от API
MAIL_MAILER=smtp
MAIL_HOST=mail.xdexboard.com
MAIL_PORT=587
MAIL_USERNAME=noreply@xdexboard.com
MAIL_PASSWORD=your_secret_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply
MAIL_FROM_NAME="${APP_NAME}"
```

- **MAIL_FROM_ADDRESS** — локальная часть адреса From (обычно `noreply`); полный адрес будет `noreply@<домен>` (домен задаётся на стороне сервера/сервиса).
- **MAIL_USERNAME** / **MAIL_PASSWORD** — учётные данные для SMTP (587/465). Если отправка идёт через внешний сервис (Postmark, Mailgun, Resend), указывают данные этого сервиса; **MAIL_FROM_ADDRESS** оставляют `noreply`, чтобы письма уходили от имени noreply@xdexboard.com (домен должен быть привязан в панели сервиса).

Пароль и другие секреты в .env не коммитить; на сервере .env должен быть защищён правами доступа.

---

## Создание учётки на почтовом сервере (если отправка через свой SMTP)

Если API отправляет письма через свой сервер (mail.xdexboard.com, порты 587/465), на сервере должен существовать виртуальный пользователь **noreply@xdexboard.com** с паролем для SASL. **Его не добавляют в `lot_mailboxes`** (там только ящики для чата по лотам).

### Реализованный вариант (Dovecot SQL, таблица send_only_accounts)

На сервере **xrpchange.site** учётная запись noreply работает так же, как ящики для лотов (та же схема Dovecot SQL):

1. **Таблица `send_only_accounts`** в БД xboard (миграция в xboard-api).  
   Поля: `local_part`, `domain`, `password_hash` (bcrypt).  
   Запись для noreply: `local_part=noreply`, `domain=xdexboard.com`, пароль хранится в хеше.

2. **Конфиг Dovecot `/etc/dovecot/dovecot-sql-send-only.conf`**  
   Те же параметры, что и для `dovecot-sql-lot.conf` (подключение к xboard, пользователь dovecot), но запросы к таблице `send_only_accounts`.  
   Пример: `scripts/email-server/configs/dovecot-sql-send-only.conf.example`.

3. **Конфиг Dovecot `/etc/dovecot/conf.d/98-send-only.conf`**  
   Подключает passdb и userdb по этому SQL-конфигу (драйвер `sql`).  
   Пример: `scripts/email-server/configs/dovecot-98-send-only.conf.example`.

4. **Пароль для noreply** задаётся при создании записи (например, `doveadm pw -s BLF-CRYPT -p "..."`), тот же пароль прописывается в `.env` проекта как `MAIL_PASSWORD`.

Проверка авторизации на сервере:  
`echo 'PASSWORD' | sudo doveadm auth test noreply@xdexboard.com`  
Проверка SMTP (если установлен swaks):  
`swaks --server 127.0.0.1:587 --auth LOGIN --auth-user 'noreply@xdexboard.com' --auth-password 'PASSWORD' --tls --quit-after AUTH`

В Postfix адрес уже указан в **exempt_senders** (см. `scripts/email-server/exempt_senders` и `docs/LOT_MAILBOX_AND_IMAP_CHAT.md`), чтобы с него разрешалась отправка. После правок на сервере:  
`sudo postmap /etc/postfix/exempt_senders && sudo postfix reload`.

---

## Кратко

| Что | Где |
|-----|-----|
| Логин, пароль, хост, порт, From | **Только в .env** (MAIL_*, MAIL_FROM_*) |
| Запись в БД (lot_mailboxes) | **Не делаем** — noreply туда не добавляем |
| Учётная запись на почтовом сервере | Создаётся отдельно (таблица/конфиг только для отправки), не в lot_mailboxes |
| exempt_senders | Уже есть noreply@xdexboard.com в репозитории; на сервере — postmap + postfix reload |

См. также: `config/mail.php` (from, smtp), `docs/LOT_MAILBOX_AND_IMAP_CHAT.md` (exempt_senders).
