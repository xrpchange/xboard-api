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

## Проверка доступа к IMAP извне

Сообщение «Не удалось достучаться до IMAP сервера» в почтовом клиенте (Canary Mail, Outlook и т.д.) может относиться:
- к **нашему** ящику лота (`mail.xdexboard.com`, данные из подсказки «Настройте IMAP чат»), или
- к **другому** аккаунту в том же клиенте (Gmail, Yandex и т.п.). В Canary Mail смотрите, для какого аккаунта подсвечена ошибка.

### Проверка нашего IMAP (mail.xdexboard.com:993)

Хост и порт для лотов: **mail.xdexboard.com**, порт **993** (IMAPS). Убедитесь, что к серверу можно подключиться с интернета.

**1. С вашей машины (извне):**

```bash
# Проверка, что порт 993 открыт и отвечает (IMAPS)
openssl s_client -connect mail.xdexboard.com:993 -brief
# или
nc -zv mail.xdexboard.com 993
```

Если соединение таймаутит или «Connection refused» — снаружи до сервера не достучаться (фаервол, не проброшен порт, или Dovecot не слушает на 0.0.0.0).

**2. На сервере (ssh server@xrpchange.site):**

```bash
# Dovecot слушает ли на 993
sudo ss -tlnp | grep 993
# или
sudo netstat -tlnp | grep 993

# Локальная проверка IMAP (после ввода логина/пароля вручную можно прервать Ctrl+C)
openssl s_client -connect 127.0.0.1:993
```

Если на сервере `127.0.0.1:993` подключается, а снаружи до `mail.xdexboard.com:993` — нет, причина во входящем доступе (firewall, NAT, неверный A-запись для mail.xdexboard.com).

**3. Фаервол (на сервере):**

```bash
sudo ufw status
# При необходимости открыть 993 для входящих:
sudo ufw allow 993/tcp
sudo ufw reload
```

После изменений снова проверьте снаружи `openssl s_client -connect mail.xdexboard.com:993`.

---

## Порт 587 (SMTP submission) — постоянная настройка

Чтобы почтовые клиенты могли отправлять письма с ящиков лотов, на сервере (mail.xdexboard.com) должны быть включены submission и SASL. Изменения хранятся в конфигах в `/etc` и сохраняются после перезагрузки.

**1. Postfix** — раскомментировать блок submission в `/etc/postfix/master.cf`:
- Строку `#submission inet n ...` заменить на `submission inet n ...`.
- Раскомментировать все строки параметров блока (от `-o syslog_name=postfix/submission` до `-o milter_macro_daemon_name=ORIGINATING`).
- После строки `-o smtpd_sasl_auth_enable=yes` добавить:
  ```
    -o smtpd_sasl_type=dovecot
    -o smtpd_sasl_path=private/auth
  ```

**2. Dovecot** — в `/etc/dovecot/conf.d/10-master.conf` в блоке `service auth` раскомментировать:
```
  unix_listener /var/spool/postfix/private/auth {
    mode = 0666
  }
```

**3. Применить:**
```bash
sudo postfix reload
sudo systemctl restart dovecot
sudo ss -tlnp | grep 587   # должен слушаться
```

**4. Тест отправки** — скрипт `temp/send_from_lot_mailbox_standalone.php` можно скопировать в document root (например `/var/www/game1-db.xrpchange.site/`) и запускать на сервере: `php send_from_lot_mailbox_standalone.php`.

**5. Ограничение размера mail.log (не более 10 МБ):**

Конфиг `scripts/email-server/logrotate-mail-10mb.conf`: при достижении 10 МБ лог обрезается (copytruncate, rotate 0). Установка на сервер:
```bash
sudo cp logrotate-mail-10mb.conf /etc/logrotate.d/mail-xboard
```
Первый раз (если лог уже огромный) — освободить место без копирования:  
`sudo truncate -s 0 /var/log/mail.log`  
Дальше logrotate по крону будет держать лог ≤ 10 МБ.

**6. Логи (если подключение к 587 или отправка не работают):**

Postfix пишет в `/var/log/mail.log`. Подключения к submission/smtpd и ошибки AUTH видны там же.

- **Почта (Postfix):** `/var/log/mail.log` или `/var/log/maillog`  
  Подключения к submission и ошибки smtpd:  
  `sudo grep -E 'submission|smtpd' /var/log/mail.log | tail -100`
- **Системный лог:**  
  `sudo journalctl -u postfix -n 50 --no-pager`
- **Проверить, что порт 587 слушается:**  
  `sudo ss -tlnp | grep 587`
- **Dovecot (auth для submission):**  
  `sudo journalctl -u dovecot -n 50 --no-pager`  
  или в mail.log строки с `dovecot`.

По логам видно, принимает ли Postfix подключения на 587, отдаёт ли 220, и на каком шаге (AUTH, DATA) происходит отказ.

---

## Устранение неполадок SMTP (порт 587)

### 1. SASL: Connect to Dovecot auth socket 'private/auth' failed / no SASL authentication mechanisms

Postfix не может подключиться к сокету Dovecot для проверки логина/пароля. Сокет `/var/spool/postfix/private/auth` должен создаваться Dovecot при старте.

**Причины и действия:**

- **Главный конфиг не подключает conf.d.** Если в `/etc/dovecot/dovecot.conf` нет строки `!include_try conf.d/*.conf`, то файлы из `conf.d/` (в т.ч. `10-master.conf` с unix_listener для Postfix) не загружаются, и сокет не создаётся. Добавить в конец `dovecot.conf`:
  ```
  !include_try conf.d/*.conf
  ```
  Затем `sudo systemctl restart dovecot` и проверить `ls -la /var/spool/postfix/private/auth`.

- **Dovecot не создаёт сокет из‑за ошибки конфига.** В логах Dovecot (`/var/log/dovecot.log`, `journalctl -u dovecot`) не должно быть ошибок разбора конфига. Частая причина — устаревший параметр `ssl_protocols = !SSLv2 !SSLv3` в `/etc/dovecot/dovecot.conf`. Заменить на:
  ```ini
  ssl_min_protocol = TLSv1.2
  ```
  (удалить или заменить обе строки с `ssl_protocols`.)

- **Права на каталог.** Dovecot должен иметь право на запись в `/var/spool/postfix/private/`. Пример:
  ```bash
  sudo chown postfix:postfix /var/spool/postfix/private
  sudo chmod 730 /var/spool/postfix/private
  sudo usermod -a -G postfix dovecot
  ```
  После смены группы перезапустить Dovecot: `sudo systemctl restart dovecot`.

- **Владелец сокета.** В `/etc/dovecot/conf.d/10-master.conf` в блоке `service auth` для сокета Postfix указать:
  ```
  unix_listener /var/spool/postfix/private/auth {
    mode = 0666
    user = postfix
    group = postfix
  }
  ```

- **AppArmor (Ubuntu).** Частая причина: профиль AppArmor для Dovecot не разрешает создание сокета в `/var/spool/postfix/private/`. Проверка:
  ```bash
  sudo systemctl status apparmor
  sudo aa-status | grep -i dovecot
  sudo dmesg | grep -i apparmor | grep -i denied
  ```
  Если профиль Dovecot в режиме enforce и в логах есть denied для `dovecot` и `/var/spool/postfix/private/auth` — временный тест: `sudo aa-complain /usr/lib/dovecot/auth` (или `/usr/sbin/dovecot`), перезапуск Dovecot, проверка появления сокета. Постоянное исправление: в профиль Dovecot (например `/etc/apparmor.d/usr.lib.dovecot.auth`) добавить внутри блока: ` /var/spool/postfix/private/auth rw,` или ` /var/spool/postfix/private/** rw,`; затем `sudo apparmor_parser -r /etc/apparmor.d/usr.lib.dovecot.auth`, `sudo aa-enforce /usr/lib/dovecot/auth`, перезапуск Dovecot и Postfix. Если AppArmor не установлен или сервис не активен, эта причина не применима.

- **Chroot и прочее.** Убедиться, что в `service auth { }` нет `chroot =`, путь сокета — абсолютный (`/var/spool/postfix/private/auth`). Проверить: `doveconf -n | grep unix_listener`, `ps aux | grep dovecot` (процесс auth запущен).

После правок: `sudo systemctl restart dovecot`, проверить `ls -la /var/spool/postfix/private/auth`, затем `sudo postfix reload`.

### 2. STARTTLS на 587 не работает (SSL_accept error / Connection reset by peer)

В логах при использовании порта 587 и STARTTLS:
- `SSL_accept error from ... Connection reset by peer`
- `lost connection after STARTTLS from ...`
- `ehlo=1 starttls=0/1 commands=1/2`

Рукопожатие TLS после команды STARTTLS обрывается. В этом случае используют **SSL (implicit TLS)** на порту **465** (SMTPS): клиент сразу поднимает TLS, без STARTTLS. Настройка сервера — см. п. 3 ниже.

### 3. Порты 587 и 465: SSL и STARTTLS (текущая схема)

На сервере настроено **наоборот** стандарту RFC, чтобы избежать ошибок с implicit SSL на 465:
- **587 (submission):** **implicit SSL** — клиент сразу поднимает TLS (`smtpd_tls_wrappermode=yes`). В клиенте: порт **587**, безопасность **SSL (implicit TLS)**.
- **465 (submissions):** **STARTTLS** — клиент подключается по plain, затем отправляет STARTTLS (`smtpd_tls_wrappermode=no`). В клиенте: порт **465**, безопасность **STARTTLS** (или «TLS»).

В логах при неверном выборе порта/безопасности:
- На 465 с «SSL»: ошибка (сервер ждёт plain и STARTTLS).
- На 587 с «STARTTLS»: ошибка (сервер ждёт сразу TLS).

**Текущая конфигурация в master.cf:** submission (587) с `smtpd_tls_wrappermode=yes`; submissions (465) с `smtpd_tls_wrappermode=no`, SASL и те же ограничения. После правок: `sudo postfix reload`, проверка: `sudo ss -tlnp | grep -E '465|587'`.

### 4. Postfix слушает только localhost (внешние клиенты не подключаются)

Postfix может быть настроен принимать подключения **только с самого сервера** (127.0.0.1). Тогда снаружи (Canary Mail, приложения, другой хост) подключиться к SMTP (25, 587, 465) нельзя — соединение таймаутит или отказ.

**Проверка на сервере:**

```bash
# Какие интерфейсы слушает Postfix (главный smtpd и submission/smtps)
postconf inet_interfaces

# На каких адресах висят порты (должно быть 0.0.0.0 или конкретный IP, не только 127.0.0.1)
sudo ss -tlnp | grep -E ':25 |:587 |:465 '
```

- Если **inet_interfaces = localhost** (или только 127.0.0.1) — Postfix слушает только loopback; внешние клиенты не смогут подключиться.
- Если в `ss` порты 25/587/465 показывают **127.0.0.1** — то же самое.

**Исправление (принять подключения со всех интерфейсов):**

В `/etc/postfix/main.cf` должно быть:

```ini
inet_interfaces = all
```

Если там указано `inet_interfaces = localhost`, заменить на `all`, затем:

```bash
sudo systemctl restart postfix
sudo ss -tlnp | grep -E ':25 |:587 |:465 '
```

После этого порты должны слушаться на `0.0.0.0` (или на всех интерфейсах). Дополнительно проверить firewall (ufw/iptables), чтобы порты 25, 587, 465 были открыты для входящих.

### 5. Разрешённые домены: получатели и отправители

В репозитории есть шаблоны (скопировать на сервер в `/etc/postfix/`):

| Файл | Назначение |
|------|------------|
| `allowed_recipient_domains` | Домены, **на которые** разрешено отправлять (587, 465). |
| `allowed_sender_domains`    | Домены, **с которых** разрешено принимать письма (порт 25). |
| **`exempt_recipients`**     | **Ящики, на которые вообще никакие ограничения не распространяются:** всегда разрешены и как получатели (587/465), и при приёме (порт 25) — почту на них принимают от любого отправителя. |
| **`exempt_senders`**       | **Ящики, с которых разрешено отсылать без ограничения по домену** (порт 25). Письма **от** этих адресов принимаются всегда; у этих адресов **нет приёма почты** (только отправка, например noreply@). |

> **⚠️ Важно: после любого изменения списков нужно пересобрать карты и перезагрузить Postfix.**  
> Иначе изменения не начнут работать. На сервере:
> ```bash
> sudo postmap /etc/postfix/allowed_recipient_domains   # если меняли домены получателей
> sudo postmap /etc/postfix/allowed_sender_domains      # если меняли домены отправителей
> sudo postmap /etc/postfix/exempt_recipients           # если меняли исключения по получателям
> sudo postmap /etc/postfix/exempt_senders              # если меняли исключения по отправителям
> sudo postfix reload
> ```

**Структура:** в файлах доменов — одна строка на домен, формат `домен<TAB>OK`. В `exempt_recipients` и `exempt_senders` — полный адрес, формат `адрес<TAB>OK`. В шаблонах доменов указаны `xdexboard.com` и `mail.xdexboard.com`:
- **xdexboard.com** — основной домен ящиков (user@xdexboard.com).
- **mail.xdexboard.com** — хост сервера; если у вас есть ящики вида user@mail.xdexboard.com, они тоже будут разрешены.

Добавлять другие домены (gmail.com, yandex.ru и т.д.) — по необходимости в соответствующий файл.

**Установка на сервер:**

```bash
# Скопировать файлы (из корня xboard-api)
sudo cp scripts/email-server/allowed_recipient_domains /etc/postfix/
sudo cp scripts/email-server/allowed_sender_domains   /etc/postfix/
sudo cp scripts/email-server/exempt_recipients         /etc/postfix/
sudo cp scripts/email-server/exempt_senders            /etc/postfix/

# Собрать хеш-карты
sudo postmap /etc/postfix/allowed_recipient_domains
sudo postmap /etc/postfix/allowed_sender_domains
sudo postmap /etc/postfix/exempt_recipients
sudo postmap /etc/postfix/exempt_senders
```

**Подключение в Postfix:**

- **Получатели (587, 465):** в `master.cf` в блоках submission и smtps — сначала проверка исключений, затем домены:
  ```
  -o smtpd_recipient_restrictions=permit_mynetworks,permit_sasl_authenticated,check_recipient_access hash:/etc/postfix/exempt_recipients,check_recipient_access hash:/etc/postfix/allowed_recipient_domains,reject
  ```
  Адреса из `exempt_recipients` разрешены всегда; остальные — только если домен в `allowed_recipient_domains`.

- **Приём (порт 25):** на ящики из `exempt_recipients` почту принимают от любого отправителя (никаких ограничений). Для этого проверку отправителя делают в `smtpd_recipient_restrictions` при RCPT TO: сначала «получатель в exempt_recipients» → разрешить; иначе — проверка отправителя по картам. В `main.cf`:
  - **smtpd_sender_restrictions** — без проверки по доменам: `permit_mynetworks, permit_sasl_authenticated`
  - **smtpd_recipient_restrictions** — в начале: проверка exempt_recipients, затем отправители, затем остальное:
  ```
  smtpd_sender_restrictions = permit_mynetworks, permit_sasl_authenticated
  smtpd_recipient_restrictions = permit_mynetworks, permit_sasl_authenticated, check_recipient_access hash:/etc/postfix/exempt_recipients, check_sender_access hash:/etc/postfix/exempt_senders, check_sender_access hash:/etc/postfix/allowed_sender_domains, reject, reject_non_fqdn_recipient, reject_unknown_recipient_domain, reject_unauth_destination, permit
  ```

**После добавления или удаления домена/адреса в любом из этих файлов — обязательно:** `postmap` по изменённому файлу и `postfix reload`; иначе изменения не применятся.

---

## Связанные документы

- `docs/EMAIL_SERVER_SETUP.md` — текущая настройка почтового сервера
- `docs/IMAP_MONITORING_SETUP.md` — IMAP для верификации платежей
