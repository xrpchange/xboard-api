# Почтовые ящики для лотов и чат на основе IMAP

**Статус:** API + БД + клиент — реализовано. Сервер: требуется настройка Dovecot/Postfix с MySQL.  
**Дата:** 2026-02

**Быстрая настройка:** см. раздел «Переменные окружения» и скрипт `scripts/email-server/setup-lot-mailboxes.sh`.

## Описание

При размещении лота создаётся почтовый ящик на внутреннем сервере (mail.xdexboard.com). Ящик используется для чата между участниками сделки и уведомлений — вместо push-нотификаций. Пользователь не знает, что под капотом используется почта: клиент показывает обычный чат.

Решение нестандартное, поэтому документировано отдельно.

---

## Сервер (xrpchange.site, домен xdexboard.com)

### Текущее состояние

- Postfix, Dovecot, OpenDKIM
- Один ящик `check@xdexboard.com` для верификации платежей
- Виртуальные пользователи статичные, Dovecot с `nopassword`
- См. `docs/EMAIL_SERVER_SETUP.md`

### Требуемые изменения

#### 1. Хранение учёток в БД

- Логины и пароли — в MySQL (или БД, которую Postfix/Dovecot умеют использовать).
- Dovecot: `dovecot-sql` или passdb через MySQL.
- Postfix: virtual_mailbox_maps из БД (Lua, или postfix-mysql, или скрипт).

#### 2. Формат локальной части адреса

Риск коллизии адресов между разными криптовалютами. Поэтому:

```
{currency}_{normalized_address}@xdexboard.com
```

- `currency` — из эскроу-токена (xrp, xmr, usdt_stellar и т.п.)
- `normalized_address` — нормализованный адрес:
  - XMR: только `primary` из JSON
  - Другие (USDT/Tron, XRP и т.п.): поле `wallet_address` или `address` из JSON, иначе строка как есть
- Нормализация: безопасные символы для local part (обычно в адресах крипты спецсимволов нет, но нормализуем на всякий случай).

#### 3. Где хранятся письма и ручная очистка

Письма лежат в Maildir на диске:

```
/var/mail/vhosts/xdexboard.com/
├── usdt_tron_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm/   ← одна папка на ящик
│   ├── cur/
│   ├── new/
│   └── tmp/
├── xmr_4abc123...
│   ├── cur/
│   ├── new/
│   └── tmp/
```

**Сколько папок стирать:** по одной на каждый ящик. Количество папок = количество записей в `lot_mailboxes`. Каждая папка — это `{local_part}` (например `usdt_tron_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm`).

**Ручное удаление — нормально для Postfix/Dovecot.** Можно:
- удалить содержимое `cur/`, `new/`, `tmp/` (оставить пустые папки) — письма пропадут, ящик останется;
- удалить папку ящика целиком — и письма, и сам ящик. Новые письма на этот адрес могут не доставляться, пока папка не будет создана снова (через API при новом лоте или вручную).

#### 4. Создание ящика при размещении лота

1. Из открытой части эскроу-токена: `wallet_address`, `token_hash`, `currency`
2. XMR: из `wallet_address` (JSON) взять только `primary`
3. Сформировать `local_part = normalize(currency + "_" + address)`
4. Проверить в БД: есть ли ящик с таким логином
5. Если нет — создать:
   - запись в БД (логин, хеш пароля = token_hash),
   - Maildir `/var/mail/vhosts/xdexboard.com/{local_part}/`,
   - обновить virtual_mailbox_maps (если не читается из БД напрямую)
6. Вернуть клиенту: `email`, `password` (token_hash), `imap_host`, `imap_port`, `imap_ssl`

---

## API (xboard-api)

### При создании лота

После успешного сохранения лота:

1. Вызвать сервис создания/получения ящика (wallet_address, token_hash, currency из токена)
2. Сервис возвращает данные для IMAP
3. Добавить в ответ API поле `mailbox`:
   - `email` — полный адрес
   - `imap_host` — mail.xdexboard.com
   - `imap_port` — 993
   - `imap_ssl` — true
   - `password` — только при первом создании ящика (token_hash)

Пароль клиент уже знает (token_hash в токене), но сервер подтверждает, что ящик создан и пароль установлен.

### Переменные окружения (.env)

Для работы `LotMailboxService` в xboard-api укажите:

| Переменная | Описание | Пример |
|------------|----------|--------|
| `LOT_MAILBOX_DOMAIN` | Домен для адресов ящиков | `xdexboard.com` |
| `LOT_MAILBOX_IMAP_HOST` | IMAP-хост для клиентов | `mail.xdexboard.com` |
| `LOT_MAILBOX_IMAP_PORT` | IMAP-порт (обычно 993) | `993` |
| `LOT_MAILBOX_IMAP_SSL` | SSL для IMAP | `true` |
| `LOT_MAILBOX_MAILDIR_BASE` | Базовый путь для Maildir (создание папок) | `/var/mail/vhosts` |

Настройки берутся из `config/services.php` → `lot_mailbox`.

---

## Клиент (xboard-client)

### Архитектура

- Абстракция: «канал сообщений» — сегодня IMAP, завтра возможен другой backend
- Пользователь видит только чат, без понятия «почта» или «IMAP»
- Встраиваемый лёгкий клиент: только сообщения, без вложений
- Подключение напрямую к IMAP (mail.xdexboard.com:993)
- Отправка только в рамках сервиса (внутренние адреса xdexboard.com)
- Никакой отправки вовне

### Хранение

- Привязка «лот ↔ данные для чата» хранится в приложении (клиент)
- Данные: email (логин), пароль, imap_host, imap_port — в защищённом хранилище (ключ/профиль)

### Функциональность

- Отображение сообщений чата
- Отправка сообщений в рамках сделки
- Уведомления через IMAP IDLE / polling вместо push
- Без вложений и без экспорта «наружу»

---

## Реализация

### API (сделано)

- Миграция `lot_mailboxes` — таблица учёток для Dovecot
- `LotMailboxService::ensureMailbox()` — создание/получение ящика по wallet_address, token_hash, currency
- При создании лота вызывается сервис; в ответ добавляется `mailbox`: `{email, imap_host, imap_port, imap_ssl, password}`
- Конфиг: `LOT_MAILBOX_DOMAIN`, `LOT_MAILBOX_IMAP_HOST`, `LOT_MAILBOX_IMAP_PORT`, `LOT_MAILBOX_IMAP_SSL`, `LOT_MAILBOX_MAILDIR_BASE`

### Сервер (требуется настройка)

1. Запустить миграцию: `php artisan migrate` (создаёт `lot_mailboxes`)
2. Dovecot: добавить passdb/userdb из MySQL — см. `scripts/email-server/configs/dovecot-sql-lot-mailboxes.conf.example`
3. Postfix: добавить virtual_mailbox_maps из MySQL — см. `scripts/email-server/configs/postfix-mysql-lot-mailboxes.cf.example`
4. Maildir: API создаёт папки при `LOT_MAILBOX_MAILDIR_BASE`; иначе Dovecot создаёт при первом входе (если настроен)

### Удаление при удалении токена

При удалении эскроу-токена клиент вызывает `POST /v1/lot-mailbox/delete` (best effort). API удаляет запись из `lot_mailboxes`. Maildir на диске не удаляется (не критично).

### Очистка писем после завершения сделки

**Вызывать только после успешной финализации сделки.**

`LotMailboxService::cleanupMailbox(string $walletAddress, string $currency): bool`

- Удаляет все письма из Maildir (cur/, new/, tmp/).
- Запись в `lot_mailboxes` не трогается — ящик остаётся, но пустой.
- Пример вызова:

```php
$this->lotMailboxService->cleanupMailbox($lot->wallet_address, $lot->cryptoCurrency?->code ?? 'crypto');
```

**Примечание:** В `wallet_address` лота хранится JSON (public part эскроу-токена). Передавайте его как есть — сервис извлечёт адрес внутри.

### Клиент (сделано)

- `CreateLotResult`, `LotMailboxDto` — модели для ответа API
- `RemoteDataSource.createExchangeLot` — возвращает лот + опционально `mailbox`
- `AppSettings.getLotMailboxJson` / `setLotMailboxJson` — хранение данных ящика по `lotId` и `nodeKey`
- `CreateLotViewModel` — при успешном создании лота сохраняет `mailbox` в AppSettings

### Сервер: пошаговая настройка

1. **Миграция БД** (на сервере, где крутится API):
   ```bash
   php artisan migrate
   ```
   Создаёт таблицу `lot_mailboxes`.

2. **Установка пакетов** (Ubuntu/Debian на почтовом сервере):
   ```bash
   sudo apt-get install -y postfix-mysql dovecot-core dovecot-imapd dovecot-mysql
   ```

3. **MySQL-пользователи** для Postfix и Dovecot (доступ только к таблице `lot_mailboxes`):
   ```sql
   CREATE USER IF NOT EXISTS 'postfix'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_1';
   GRANT SELECT ON xboard.lot_mailboxes TO 'postfix'@'localhost';

   CREATE USER IF NOT EXISTS 'dovecot'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_2';
   GRANT SELECT ON xboard.lot_mailboxes TO 'dovecot'@'localhost';

   FLUSH PRIVILEGES;
   ```

4. **Конфиги Postfix/Dovecot** — см. `scripts/email-server/configs/`:
   - `postfix-mysql-lot-mailboxes.cf.example` → `/etc/postfix/mysql-virtual-mailbox-lot.cf`
   - `dovecot-sql-lot-mailboxes.conf.example` → `/etc/dovecot/dovecot-sql-lot.conf`

5. **Автоматическая настройка**:
   ```bash
   sudo ./scripts/email-server/setup-lot-mailboxes.sh
   ```

---

## Связанные документы

- `docs/EMAIL_SERVER_SETUP.md` — текущая настройка почтового сервера
- `docs/IMAP_MONITORING_SETUP.md` — IMAP для верификации платежей
