# Postfix: открытый релей, очередь и обслуживание (mail.xdexboard.com)

Документ описывает типичную причину **огромной очереди** на SMTP-сервере XBoard, меры защиты и **самоочистку** очереди. Актуально для хоста вроде `server@xrpchange.site`, где Postfix принимает почту для домена `xdexboard.com`.

## 1. Откуда берётся «открытый релей» и мусорная очередь

### `compatibility_level` по умолчанию (0)

Если в `/etc/postfix/main.cf` **нет** строки `compatibility_level` (или она равна **0**), Postfix ведёт себя по **устаревшим** правилам: **`smtpd_relay_restrictions` фактически пустой**. Тогда внешний клиент может **ретранслировать** почту на чужие домены (классический **open relay**), если цепочка `smtpd_recipient_restrictions` настроена неаккуратно.

**Исправление:** задать современный уровень совместимости, например:

```text
compatibility_level = 3.6
```

После этого Postfix применяет нормальные ограничения релея (в т.ч. `defer_unauth_destination` в составе `smtpd_relay_restrictions`). Проверка:

```bash
sudo postconf compatibility_level smtpd_relay_restrictions
```

Подробнее: [Postfix COMPATIBILITY_README](http://www.postfix.org/COMPATIBILITY_README.html).

### Лишний `reject` в `smtpd_recipient_restrictions`

В одной из версий `main.cf` между проверками доменов отправителя и `reject_unauth_destination` стояла лишняя запись **`, reject,`**, из‑за чего логика ограничений работала непредсказуемо. Для продакшена цепочка должна заканчиваться **`reject_unauth_destination`** (и при необходимости `permit`), без «голого» `reject` посередине списка разрешений.

### Откуда взялись сотни тысяч писем

На практике очередь раздула **внешний спамер**, подключавшийся к порту 25, подставляя отправителя с вашего домена и унося рассылку наружу. В логах это выглядит как `client=unknown[<IP>]` и массовые получатели на бесплатных доменах. После включения `compatibility_level >= 2` такой трафик должен **отсекаться** на этапе релея.

## 2. Ограничение времени жизни недоставленной почты

Чтобы очередь **не копилась месяцами**, можно ограничить время хранения недоставленных сообщений и bounce:

```text
maximal_queue_lifetime = 10m
bounce_queue_lifetime = 10m
mailbox_size_limit = 102400
```

**Внимание:** 10 минут — агрессивное значение: при **greylisting** у получателя легитимное письмо может не уложиться в окно и будет возвращено отправителю как недоставленное. Меняйте значение под политику продукта (например `1h` / `1d`).

`mailbox_size_limit = 102400` в конфиге `server@xrpchange.site` задаёт **лимит размера ящика 100 КБ**. Это предохранитель против разрастания почтового хранилища (в т.ч. для ящиков лотов и служебных адресов) даже в случае, если фильтры или процессинг писем временно работают некорректно.

Дополнительно на этом же сервере настроена **регулярная физическая очистка писем старше 24 часов** через Dovecot:

```bash
sudo crontab -l
# ...
0 3 1 * * /usr/local/bin/renew_cert.sh
0 * * * * doveadm expunge -A mailbox "*" BEFORE 1d && doveadm purge -A
```

- `doveadm expunge ... BEFORE 1d` — помечает письма старше суток к удалению во всех ящиках.
- `doveadm purge -A` — вычищает их физически из стораджа.

Таким образом, даже при сбое логики приложения или фильтров письма старше 24 часов не копятся в файловой системе.

Проверка:

```bash
sudo postconf maximal_queue_lifetime bounce_queue_lifetime
```

## 3. Экстренная очистка очереди

Когда нужно **сразу** освободить диск и остановить доставку накопившегося мусора:

```bash
sudo postsuper -d ALL
sudo postqueue -p
```

`postsuper -d ALL` удаляет **все** сообщения в очереди — в том числе ещё не отправленные **легитимные**, если они там остались. Используйте осознанно.

## 4. Фаервол: блокировка известного злоупотребляющего IP

Если в `/var/log/mail.log` повторяется один и тот же источник (пример с инцидента: `77.83.39.89`), имеет смысл **заблокировать** его на границе:

```bash
sudo iptables -I INPUT 1 -s 77.83.39.89 -j DROP
sudo sh -c 'iptables-save > /etc/iptables/rules.v4'
```

(Команда сохранения актуальна, если правила загружаются из `/etc/iptables/rules.v4` при загрузке; иначе используйте механизм вашего дистрибутива.)

## 5. Предупреждения `postfix check` про `master.cf`

Если в `/etc/postfix/` случайно лежит **второй файл** с именем вроде `master.cf` с символами **CR** в конце имени (артефакт копирования с Windows), он может быть **writable** для обычного пользователя — тогда `postfix check` ругается на `master.cf`. Удалите такой файл (осторожно, проверьте имя через `ls -lb`), оставьте единственный настоящий `/etc/postfix/master.cf` с правами `root:root` и `0644`.

## 6. Чек-лист после правок

```bash
sudo postfix check
sudo systemctl reload postfix
# при смене compatibility_level надёжнее полный перезапуск:
# sudo systemctl restart postfix
sudo tail -n 50 /var/log/mail.log
```

## 7. Submission / submissions: не ломать sender_login_maps

Важно не превращать порты **587/465** (submission / submissions) в обход глобальных ограничений отправителя.

- **Нельзя** затирать `smtpd_sender_restrictions` в `master.cf` для `submission` / `submissions` пустым значением:

  ```text
  -o smtpd_sender_restrictions=
  ```

  В таком случае Postfix **перестаёт применять** `smtpd_sender_login_maps`, и любой успешно аутентифицированный клиент может слать почту с произвольным `From:` внутри домена.

- Для XBoard исходящий SMTP должен оставаться жёстким:

  - Допустимые внешние отправители задаются **только** через `smtpd_sender_login_maps`
    (в продакшене — `noreply@xdexboard.com` и, при необходимости, технический `check@xdexboard.com`).
  - Для `submission` / `submissions` нужно явно подставить такие же ограничения, как в `main.cf`:

    ```text
    # master.cf (фрагмент)
    submission inet n       -       y       -       -       smtpd
      ...
      -o smtpd_sender_restrictions=reject_sender_login_mismatch,permit_mynetworks,permit_sasl_authenticated
    submissions     inet  n       -       y       -       -       smtpd
      ...
      -o smtpd_sender_restrictions=reject_sender_login_mismatch,permit_mynetworks,permit_sasl_authenticated
    ```

  - Это гарантирует, что даже при валидной аутентификации **нельзя выбрать произвольный `MAIL FROM`**, которого нет в `sender_login_maps`
    (например, `information@xdexboard.com`, `orders@xdexboard.com` и т.п.).

### Мини‑чек‑лист, чтобы не открыть релей через submission

1. Проверить глобальные ограничения:

   ```bash
   sudo postconf smtpd_sender_restrictions smtpd_sender_login_maps
   ```

2. Убедиться, что `sender_login_maps` содержит только разрешённые внешние адреса (`noreply@xdexboard.com`, `check@xdexboard.com`).

3. Проверить блоки `submission` / `submissions` в `/etc/postfix/master.cf`:

   - там **нет** строки `-o smtpd_sender_restrictions=` без значения;
   - там есть строка с `reject_sender_login_mismatch,permit_mynetworks,permit_sasl_authenticated`.

4. После правок:

   ```bash
   sudo postfix check
   sudo systemctl reload postfix
   sudo tail -n 50 /var/log/mail.log
   ```

## 8. Рекомендованный деплой: внутренний 25 порт

В продакшене XBoard (и на боевых стендах) рекомендуется следующая базовая схема:

- **Все внутренние сервисы (Laravel `xboard-api`, скрипты, cron)** шлют почту **на локальный Postfix по порту 25**:

  ```env
  MAIL_MAILER=smtp
  MAIL_HOST=127.0.0.1
  MAIL_PORT=25
  MAIL_USERNAME=
  MAIL_PASSWORD=
  MAIL_ENCRYPTION=null
  ```

  - Соединение идёт только с localhost / доверенной внутренней сети.
  - Ограничения по допустимым `From:` (`noreply@xdexboard.com`, тех. адреса) остаются на уровне `smtpd_sender_login_maps` / `smtpd_sender_restrictions` для исходящего релея.

- **Порты 587/465 (submission / submissions)**:
  - в типовом деплое **не нужны для `xboard-api`**, поэтому:
    - либо закрываются фаерволом снаружи;
    - либо открываются только под конкретный сценарий (SMTP‑клиент вне сервера) с жёсткими `smtpd_sender_login_maps`.

Идея: Laravel и остальные внутренние компоненты всегда используют **один и тот же надёжный SMTP на 25 порту**, а 587/465 остаются опциональным интерфейсом, который по умолчанию не торчит наружу и не влияет на поведение `xboard-api`.

## 9. Ежедневный мониторинг блокировок (RBL / репутация)

Когда SMTP-конфиг уже ужесточён, важно **следить за тем, как внешние провайдеры видят IP сервера**.  
Для этого достаточно периодически (вручную) смотреть на блоки по Spamhaus / Abusix / Cloudmark и т.д. за последний день.

В репозитории есть вспомогательный скрипт:

- `scripts/email-server/check_rbl_blocks.py` — быстрый отчёт по RBL / репутационным блокам.

### Как использовать на сервере

1. Скопируйте (или выкатите через деплой) файл `scripts/email-server/check_rbl_blocks.py` на сервер (например, в `/opt/xboard/tools/`).
2. Запустите от root (или пользователя с доступом к `/var/log/mail.log`):

   ```bash
   python3 /opt/xboard/tools/check_rbl_blocks.py
   ```

   По умолчанию:

   - читает `/var/log/mail.log`;
   - смотрит окно **последние 24 часа**;
   - ищет типичные фразы вроде:
     - `blocked using Spamhaus`
     - `Spamhaus SBL`
     - `Abusix Mail Intelligence` / `IP blocked by Abusix`
     - `found on one or more DNSBLs`
     - `Cloudmark CSI-Global`
     - и т.п.

3. В выводе будет:

   - общее количество таких блоков за окно;
   - разбивка по доменам получателей (gmail.com, comcast.net, orange.fr и т.д.);
   - разбивка по удалённым MX (mx2c1.comcast.net, smtp-in.orange.fr и т.д.);
   - несколько примеров строк лога с точным текстом ошибки.

Примеры параметров:

```bash
# Локальный путь к логу и 6 часов назад
python3 check_rbl_blocks.py --log /var/log/mail.log --hours 6
```

Используйте этот отчет, чтобы:

- отслеживать момент, когда IP сняли с RBL (количество блоков уходит к нулю);
- видеть, какие провайдеры всё ещё режут письма (по доменам и MX);
- принимать решение, когда можно возвращать массовые отправки кодов подтверждения на чувствительные домены.

### 9.1 Проверка IP в Spamhaus ZEN с сервера

Локальный `dig` через stub (`127.0.0.53`) или часть публичных резолверов может вернуть для `*.zen.spamhaus.org` ответ **`127.255.255.254`** — это **служебный код Spamhaus** (ограничение/политика запроса), **не** признак листинга IP.

Надёжная быстрая проверка **с почтового сервера** (подставьте обратную запись своего IP; для `152.53.150.194` это `194.150.53.152`):

```bash
dig @208.67.222.222 +short 194.150.53.152.zen.spamhaus.org
```

- **Пустой вывод** при статусе **`NXDOMAIN`** в полном `dig` → IP **не** числится в ZEN.
- Ответ вида **`127.0.0.*`** (кроме диапазона **`127.255.255.*`**) → смотрите [коды возврата Spamhaus](https://www.spamhaus.org/resource-hub/dnsbl/spamhaus-dnsbl-return-codes-technical-update/) и [проверку репутации IP](https://www.spamhaus.org/ip-reputation/check/).

Сводная проверка по многим спискам: [MXToolbox Blacklist](https://mxtoolbox.com/blacklists.aspx) (ввести IP).

### 9.2 Делистинг (прямые ссылки)

Если IP попал в список, сначала устраните причину (релей, компрометация, всплеск спама), затем используйте официальные страницы:

| Список | Назначение |
|--------|------------|
| [PSBL — Remove an IP](https://psbl.org/remove) | Снятие IP с Passive Spam Block List (часто достаточно одной формы). |
| [PSBL — List query](https://psbl.org/listing) | Проверка статуса IP в PSBL. |
| [UCEPROTECT — Query Database](https://www.uceprotect.net/en/rblcheck.php) | Узнать уровень листинга (1 / 2 / 3). |
| [UCEPROTECT — Removal Policy](https://www.uceprotect.net/en/index.php?m=7&s=0) | Общая политика снятия. |
| [UCEPROTECT — removed from Level 1](https://www.uceprotect.net/en/index.php?m=7&s=6) | Инструкции по снятию с **Level 1** (отдельный IP). |
| [UCEPROTECT — removed from Level 2](https://www.uceprotect.net/en/index.php?m=7&s=7) | Снятие **Level 2** (сеть; обычно через провайдера). |
| [UCEPROTECT — removed from Level 3](https://www.uceprotect.net/en/index.php?m=7&s=8) | Снятие **Level 3** (ASN; для провайдера). |
| [Spamhaus — IP reputation check](https://www.spamhaus.org/ip-reputation/check/) | Проверка и контекст по Spamhaus (при листинге — сценарии снятия на стороне Spamhaus). |

**Примечание:** UCEPROTECT известен жёсткой политикой; даже после делистинга Level 1 имеет смысл мониторить логи и скрипт `check_rbl_blocks.py` (см. начало раздела 9).

### 9.3 PTR и имя сервера (доставляемость)

Если `dig -x <IP>` возвращает имя VPS (например `supersrv.de`), а не **`mail.xdexboard.com`**, крупные провайдеры могут занижать доверие к письмам независимо от RBL. Настройка **обратной DNS** только у владельца IP (хостер); пошагово: раздел **4** в [MAIL_SPF_DKIM_DMARC.md](MAIL_SPF_DKIM_DMARC.md).

## 10. Связанные файлы в репозитории

- Пример `main.cf`: `scripts/email-server/configs/postfix-main.cf.example` — `compatibility_level`, лимиты очереди.
- Опциональный скрипт на сервере: `scripts/email-server/apply-postfix-relay-hardening.sh` (см. комментарии внутри).

Общая установка почтового сервера: [EMAIL_SERVER_SETUP.md](EMAIL_SERVER_SETUP.md).
