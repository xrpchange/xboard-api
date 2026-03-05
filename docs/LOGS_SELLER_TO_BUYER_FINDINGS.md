# Логи: сообщение продавец → покупатель (результаты проверки)

## Проверка на сервере (mail.xdexboard.com = 152.53.150.194)

### Postfix
- Доставка в mail.log есть: `to=<usdt_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm@xdexboard.com>`, `status=sent (delivered to maildir)`.

### Maildir
- Письма лежат на диске:
  ```bash
  sudo ls -la /var/mail/vhosts/xdexboard.com/usdt_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm/new/
  ```
  В `new/` 7 файлов (в т.ч. от 06:03, 06:07, 06:14).

### Dovecot
- Путь для ящика верный:
  ```bash
  sudo doveadm user usdt_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm@xdexboard.com
  ```
  → `home=/var/mail/vhosts/xdexboard.com/usdt_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm`, `mail=maildir:~/`.

- Количество писем в INBOX:
  ```bash
  sudo doveadm mailbox status -u usdt_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm@xdexboard.com messages INBOX
  ```
  → **INBOX messages=7**.

**Вывод:** На сервере письма есть, Dovecot их отдаёт. Хост один: mail.xdexboard.com = этот же сервер.

---

## Если в Канари и в приложении писем нет

Проблема на стороне клиента (подключение или отображение).

Проверить:

1. **Логин по IMAP**
   - Адрес: `usdt_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm@xdexboard.com` (без опечаток, именно этот ящик покупателя).
   - Пароль: хэш эскроу-токена покупателя (тот же, что возвращает API в конфиге ящика при start_deal).
   - Сервер: `mail.xdexboard.com`, порт 993, SSL.

2. **Приложение**
   - Ящик покупателя для этой сделки берётся из `buyer_lot_email` и конфига (email + password из токена). Убедиться, что на экране «Сделка в процессе» у покупателя подставляется именно этот ящик и пароль, а не другой аккаунт.
   - После появления писем на сервере нажать «Обновить» в чате.

3. **Канари**
   - Убедиться, что добавлен именно этот ящик (`usdt_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm@xdexboard.com`) и что папка — INBOX (не другой ящик и не фильтр).

---

## Где смотреть логи

| Что | Где |
|-----|-----|
| Postfix (доставка) | `server@xrpchange.site`: `sudo tail -f /var/log/mail.log` |
| Laravel (sendMessageToBuyer) | Хост с xboard-api: `storage/logs/laravel.log` |
| Проверка INBOX на сервере | `sudo doveadm mailbox status -u <email> messages INBOX` |

См. также: `docs/CHECK_LOGS_SELLER_TO_BUYER.md`.
