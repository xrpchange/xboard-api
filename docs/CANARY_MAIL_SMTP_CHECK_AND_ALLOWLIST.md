# Canary Mail: куда стучится при проверке SMTP и список для разрешения

**Дата:** 2026-02-28  
**Источник:** разбор `/var/log/mail.log` на mail.xdexboard.com.

## Итог по Canary Mail при проверке SMTP (регистрация/настройка)

Клиент с IP **195.46.191.38** (reverse: `static-a38.Togliatti.golden.ru`) при проверке работоспособности SMTP подключается к нашему серверу так:

| Куда стучится | Порт | Сервис Postfix | Режим TLS | Что видно в логах |
|---------------|------|-----------------|-----------|-------------------|
| **mail.xdexboard.com:587** | 587 | **submission** | implicit SSL | Успешная проверка: `connect from` → `ehlo=1 auth=1 noop=1` / `ehlo=1 auth=1 quit=1` |
| **mail.xdexboard.com:465** | 465 | **smtps** (submissions) | STARTTLS | Подключения есть; при неудачной настройке — быстрый disconnect |
| **mail.xdexboard.com:25**  | 25  | **smtpd** (обычный SMTP) | без TLS / STARTTLS | Подключения с `unknown=0/1 commands=0/1` (проверка без успешной отправки) |

Успешная проверка при регистрации в логах выглядит так:

```
postfix/submission/smtpd[...]: connect from static-a38.Togliatti.golden.ru[195.46.191.38]
postfix/submission/smtpd[...]: lost connection after NOOP from static-a38.Togliatti.golden.ru[195.46.191.38]
postfix/submission/smtpd[...]: disconnect from ... ehlo=1 auth=1 noop=1 commands=3
postfix/submission/smtpd[...]: connect from static-a38.Togliatti.golden.ru[195.46.191.38]
postfix/submission/smtpd[...]: disconnect from ... ehlo=1 auth=1 quit=1 commands=3
```

То есть Canary Mail при «проверке работоспособности SMTP» фактически:

1. Подключается к **порту 587** (submission) с **implicit SSL**.
2. Делает EHLO, проходить AUTH (логин/пароль), затем NOOP или QUIT.

Порты **465** и **25** он тоже опрашивает (видно по `smtps/smtpd` и `smtpd` с того же IP), но успешная проверка в логах зафиксирована на **587 (submission)**.

## Список разрешённых серверов для приёма и отправки

Для политики «разрешить получение и отправку только со списка серверов» нужно разделить:

### 1. Исходящая почта (клиенты подключаются к нам)

- Клиенты (Canary Mail и др.) подключаются **к** mail.xdexboard.com на порты **587** и **465**.
- «Разрешать» здесь — это либо:
  - оставить доступ с любых IP (как сейчас), либо  
  - ограничить **кто может подключаться** к нашим 587/465 (например, по IP или по сети).

Текущие подключения к submission/smtps в логах:

- **195.46.191.38** — ваш Canary Mail (успешная проверка на 587).
- 92.118.39.120, 45.144.212.177 — другие клиенты (в логах были SSL_accept ошибки).

Если будете ограничивать по IP, в список разрешённых для **подключения к нашему SMTP (587/465)** нужно включать IP клиентов/офисов, откуда разрешена отправка (в т.ч. 195.46.191.38, если хотите разрешить только этот).

### 2. Входящая почта (другие серверы подключаются к нам на порт 25)

- К нам на **порт 25** подключаются **внешние серверы** для доставки писем (MX).
- Для «списка разрешённых серверов» здесь имеет смысл список **разрешённых отправителей (IP или hostname)** для приёма почты.

Пример из логов за тот же день:

- **69.169.224.56** — `b224-56.smtp-out.eu-central-1.amazonses.com` (Amazon SES).

Список для разрешения приёма можно формировать по полю `connect from` в строках `postfix/smtpd[...]: connect from ...` (порт 25), отфильтровав известные sending-сервисы (SES, SendGrid, и т.д.).

## Как смотреть в логах дальше

На сервере:

```bash
# Все подключения к submission (587) и smtps (465)
sudo grep -E 'submission/smtpd|smtps/smtpd' /var/log/mail.log | grep -E 'connect from|disconnect from'

# Подключения с конкретного IP (например Canary Mail)
sudo grep '195.46.191.38' /var/log/mail.log | grep -E 'connect from|disconnect from|ehlo|auth'

# Входящие на порт 25 (кто стучится к нам для доставки)
sudo grep 'postfix/smtpd[' /var/log/mail.log | grep 'connect from' | grep -v submission
```

Так можно регулярно проверять, куда стучался Canary Mail (587/465/25) и какие серверы подключаются для приёма (25), и на основе этого вести список разрешённых серверов.
