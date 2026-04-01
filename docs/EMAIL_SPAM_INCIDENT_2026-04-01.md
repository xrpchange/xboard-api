## Инцидент spam-рассылки через SMTP (2026-04-01)

### Кратко

- Источник: внешние клиенты, подключавшиеся к `mail.xdexboard.com` по портам **587/465** (submission/submissions).
- Аутентификация: `sasl_method=LOGIN, sasl_username=information@xdexboard.com` (фиктивная учётка, не предусмотренная продуктом).
- Причина: в `master.cf` для `submission`/`submissions` было обнулено
  `smtpd_sender_restrictions=`, поэтому **не работал `smtpd_sender_login_maps`** и не проверялось соответствие логина и `MAIL FROM`.
- Результат: массовая рассылка спама от имени `information@xdexboard.com` на внешние домены, включая провайдеров из нашей таблицы `email_providers`, и занесение IP 152.53.150.194 в множество RBL (Abusix, Spamhaus, Cloudmark, локальные блок-листы крупных провайдеров).

### Провайдеры из `email_providers`, которые видели спам

Сравнение:

- список провайдеров из `database/seeders/EmailProviderSeeder.php` (целевые провайдеры для интеграции);
- уникальные домены получателей из `/var/log/mail.log` за время инцидента.

Провайдеры, которые одновременно есть в `email_providers` и фигурируют в логах как получатели спам-рассылки (Yandex исключён намеренно — использовался для тестов):

- **Gmail** — `gmail.com`
- **Yahoo Mail** — `yahoo.com`, а также поддомены (`yahoo.com.*`)
- **Hotmail / Outlook** — `hotmail.com`, `outlook.com`
- **Comcast/Xfinity** — `comcast.net`
- **GMX** — `gmx.com`, `gmx.de`, `gmx.net`
- **Web.de** — `web.de`
- **Orange** — `orange.fr`
- **T‑Online** — `t-online.de`
- **Laposte.net** — `laposte.net`
- **Bluewin (Swisscom)** — `bluewin.ch`
- **Virgin Media** — `virginmedia.com`
- **Juno** — `juno.com`

Это означает, что **часть ключевых целевых провайдеров для наших пользователей уже видела исходящий спам с нашего IP**, что напрямую влияет на репутацию и доставляемость легитимных писем (подтверждение токенов, уведомления и т.д.).

### Технические детали по логам

- Типичные записи в `/var/log/mail.log`:

  - Аутентификация:

    ```text
    postfix/submissions/smtpd[...]: ...: client=unknown[...], sasl_method=LOGIN, sasl_username=information@xdexboard.com
    ```

  - Постановка в очередь:

    ```text
    postfix/qmgr[...]: ...: from=<information@xdexboard.com>, size=..., nrcpt=15 (queue active)
    ```

  - Ошибки доставки к целевым провайдерам:

    ```text
    host mxin10.virginmedia.com[...] refused to talk to me:
      550 ... Your IP 152.53.150.194 is in RBL. Please see ...

    host smtp-in.orange.fr[...] refused to talk to me:
      550 ... IP blocked by Abusix ...

    host mx01.emig.gmx.net[...] refused to talk to me:
      554 ... IP address is block listed ...

    host mx04.earthlink-vadesecure.net[...] refused to talk to me:
      550 5.7.1 Connection refused - [152.53.150.194] blocked by Spamhaus SBL ...

    host mx1h1.comcast.net[...] refused to talk to me:
      554 ... 152.53.150.194 found on one or more DNSBLs ...
    ```

### Исправление на SMTP-сервере

1. **Жёсткая привязка отправителя к логину на submission/submissions**

   В `/etc/postfix/master.cf` для сервисов `submission` и `submissions`:

   ```text
   submission inet n       -       y       -       -       smtpd
     ...
     -o smtpd_sender_restrictions=reject_sender_login_mismatch,permit_mynetworks,permit_sasl_authenticated
     -o smtpd_relay_restrictions=$mua_relay_restrictions
     -o smtpd_recipient_restrictions=$mua_recipient_restrictions

   submissions     inet  n       -       y       -       -       smtpd
     ...
     -o smtpd_sender_restrictions=reject_sender_login_mismatch,permit_mynetworks,permit_sasl_authenticated
     -o smtpd_relay_restrictions=$mua_relay_restrictions
     -o smtpd_recipient_restrictions=$mua_recipient_restrictions
   ```

   Это гарантирует:

   - `smtpd_sender_login_maps` продолжает применяться и на портах 587/465;
   - разрешённые исходящие отправители ограничены записями в `sender_login_maps`
     (в продакшене — `noreply@xdexboard.com` и, при необходимости, `check@xdexboard.com`);
   - `information@xdexboard.com` и любые другие произвольные `@xdexboard.com` **никогда не проходят проверку**.

2. **Очистка очереди**

   После ужесточения настроек была выполнена экстренная очистка очереди:

   ```bash
   sudo postsuper -d ALL
   sudo postqueue -p
   ```

   На момент завершения работ очередь пустая (`0` сообщений).

3. **Мониторинг после исправления**

   Хвост `/var/log/mail.log` после внесения правок показывает:

   - попытки с произвольных `from=<...@xdexboard.com>` на 25‑м порту завершаются `454 4.7.1 Relay access denied`;
   - новых записей с `sasl_username=information@xdexboard.com` и постановкой писем в очередь нет;
   - старые сообщения от `information@xdexboard.com` постепенно переходят в статус `status=expired, returned to sender` и больше не висят в очереди.

### План восстановления репутации и дальнейшие шаги

1. **Полная остановка спама (сделано)**

   - Жёсткие `smtpd_sender_restrictions` на `submission`/`submissions` включены.
   - `sender_login_maps` ограничен разрешёнными адресами (`noreply@xdexboard.com`, технический `check@xdexboard.com`).
   - Очередь очищена, новые сообщения от `information@xdexboard.com` не появляются.

2. **Блокировка особо шумных источников на firewall (частично сделано)**

   - На момент инцидента в логах постоянно фигурировал IP `185.196.9.195` с попытками шлать от `adminteam@xdexboard.com`, `orders@xdexboard.com` и т.п.
   - На сервере добавлено правило:

     ```bash
     sudo iptables -I INPUT 1 -s 185.196.9.195 -j DROP
     sudo iptables-save | grep 185.196.9.195
     ```

   - При необходимости аналогично можно заблокировать другие IP, если они продолжают активно атаковать SMTP.

3. **Выдержка без массовых рассылок**

   - Пока проект не запущен в продакшене, **не отправлять массовые письма в мир** с IP `152.53.150.194`.
   - Для тестов использовать:
     - адреса разработчика (Yandex и т.п.);
     - минимальный объём отправок (1–2 письма в день), только с корректным `From: noreply@xdexboard.com`.

4. **Очистка RBL / репутационных списков (делается вручную по чек-листу)**

   - Через формы RBL‑провайдеров (по ссылкам из логов):
     - Spamhaus SBL: `https://check.spamhaus.org/results?query=152.53.150.194`
     - Abusix: `https://lookup.abusix.com/search?q=152.53.150.194`
     - Cloudmark CSI: `https://csi.cloudmark.com/en/reset?ip=152.53.150.194`
   - В каждой заявке описать:
     - коротко причину инцидента (скомпрометированный/фиктивный SMTP‑логин + ошибка в `submission` конфиге);
     - конкретные исправления (см. раздел выше);
     - факт, что новые спам‑отправки прекращены, а сервер используется только для транзакционных писем.

5. **Работа с Gmail и крупными провайдерами**

   - Gmail:
     - опираться на рекомендации по ссылкам из ответов 5.7.1:
       - `https://support.google.com/mail/?p=UnsolicitedMessageError`
       - `https://support.google.com/mail/answer/188131`
     - после очистки очереди и остановки спама отправить заявку на пересмотр репутации IP.
   - Аналогично — для провайдеров, которые явно ссылаются на свои формы (Comcast, Orange, т.д.) в ответах RBL.

6. **Постепенный возврат отправки на «чувствительные» домены**

   - После подтверждения delist / улучшения репутации:
     - сначала отправлять единичные реальные транзакционные письма (коды подтверждения) на свои тестовые адреса на Gmail/Outlook;
     - следить, чтобы:
       - не появлялись новые 5.7.1/блокировки;
       - письма не оказывались в спаме.
   - До запуска проекта в прод — не включать никакие маркетинговые/массовые рассылки.

7. **Долгосрочно (к моменту запуска продукта)**

   - Рассмотреть использование выделенного transactional‑email сервиса (Postmark/Mailgun/SendGrid/Resend) для отправки кодов и уведомлений.
   - Либо держать отдельный «чистый» IP для исходящей почты, не совмещая его с IMAP/POP/разработческим трафиком.

### Что важно запомнить при следующих изменениях

- **Нельзя**:
  - добавлять или оставлять фиктивные учётки вида `information@xdexboard.com` в продакшене;
  - затирать `smtpd_sender_restrictions` для `submission`/`submissions` пустым значением;
  - расширять `sender_login_maps` до `*@xdexboard.com` — список должен оставаться минимальным (`noreply@...`, тех. адреса).

- **Нужно**:
  - любые изменения SMTP-конфига сопровождать прогоном чек-листа из `docs/POSTFIX_RELAY_AND_QUEUE.md`
    (см. раздел про submission/submissions);
  - при появлении очереди и массовых deferred/blocked по RBL — сначала проверить, не ушёл ли снова трафик от имени
    неразрешённых отправителей на провайдеров из `email_providers`.

