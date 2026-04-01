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
```

**Внимание:** 10 минут — агрессивное значение: при **greylisting** у получателя легитимное письмо может не уложиться в окно и будет возвращено отправителю как недоставленное. Меняйте значение под политику продукта (например `1h` / `1d`).

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

## 8. Связанные файлы в репозитории

- Пример `main.cf`: `scripts/email-server/configs/postfix-main.cf.example` — `compatibility_level`, лимиты очереди.
- Опциональный скрипт на сервере: `scripts/email-server/apply-postfix-relay-hardening.sh` (см. комментарии внутри).

Общая установка почтового сервера: [EMAIL_SERVER_SETUP.md](EMAIL_SERVER_SETUP.md).
