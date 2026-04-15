# SPF, DKIM и DMARC для mail.xdexboard.com

Чтобы исходящая почта с домена **xdexboard.com** (noreply@, ящики лотов, check@) не отклонялась получателями (Yandex, Gmail и др.) как спам, должны быть настроены:

1. **SPF** — разрешённые серверы для отправки от имени домена  
2. **DKIM** — подпись писем (уже генерируется на сервере, нужна только DNS-запись)  
3. **DMARC** (рекомендуется) — политика проверки SPF/DKIM и отчёты

**Сервер:** mail.xdexboard.com (IP: 152.53.150.194)

---

## 1. Что сделано на сервере

- **OpenDKIM** подписывает исходящие письма от `*@xdexboard.com` (селектор `mail`).
- OpenDKIM слушает **inet:8891@localhost**; Postfix подключается к нему как к milter (`smtpd_milters` и `non_smtpd_milters`).
- **InternalHosts** в OpenDKIM: `127.0.0.1, ::1, 152.53.150.194` — почта с этих адресов подписывается.
- Ключ DKIM: `/etc/opendkim/keys/xdexboard.com/mail.private` (публичная часть в `mail.txt`).

Проверка после изменений:

```bash
sudo systemctl status opendkim   # active (running)
ss -tlnp | grep 8891             # 127.0.0.1:8891
# В логах при отправке не должно быть: "connect to Milter service inet:localhost:8891: Connection refused"
```

---

## 2. DNS-записи (настроить у регистратора/хостинга DNS)

Добавьте записи для домена **xdexboard.com**.

### 2.1. SPF (TXT для корня домена)

Одна запись **TXT** для **xdexboard.com** (имя хоста может быть `@` или пусто, в зависимости от панели):

```
v=spf1 mx a:mail.xdexboard.com ip4:152.53.150.194 -all
```

- `mx` — разрешены серверы из MX домена  
- `a:mail.xdexboard.com` — разрешён хост mail.xdexboard.com по его A-записи  
- `ip4:152.53.150.194` — явно разрешён IP сервера  
- `-all` — все остальные запрещены (fail)

Если уже есть другая TXT с `v=spf1`, замените её на эту или объедините механизмы (оставьте один `v=spf1 ... -all`).

### 2.2. DKIM (TXT для селектора)

Имя хоста:

```
mail._domainkey.xdexboard.com
```

Значение — **одна** TXT-строка (содержимое из файла на сервере):

```bash
# На сервере получить готовую строку:
sudo cat /etc/opendkim/keys/xdexboard.com/mail.txt
```

Скопируйте всё содержимое в одну строку (в панели DNS часто можно вставить многострочное значение — тогда кавычки и переносы допустимы, либо вставьте как одну строку без переносов между частями в кавычках).

Формат значения:

```
v=DKIM1; h=sha256; k=rsa; t=y; p=<длинный_ключ_base64>
```

`p=` — публичный ключ из `mail.txt` (все части `p=...` склеить в один блок без пробелов).

### 2.3. DMARC (TXT для _dmarc)

Имя хоста:

```
_dmarc.xdexboard.com
```

Значение (пример без `rua`):

```
v=DMARC1; p=quarantine; pct=100; adkim=s; aspf=s; fo=1
```

- `p=quarantine` — письма, не прошедшие проверку, помещать в спам (можно начать с `p=none` для мониторинга).
- `adkim=s`, `aspf=s` — более строгое выравнивание From/SPF/DKIM.
- `fo=1` — форензик-отчёт при любой ошибке аутентификации (если принимающая сторона его отправляет).

После настройки DNS подождите распространения (5–60 минут, иногда дольше).

---

## 3. Проверка

### SPF

```bash
dig TXT xdexboard.com +short
# Должна быть строка с v=spf1 и ip4:152.53.150.194 или a:mail.xdexboard.com
```

Или: [mxtoolbox.com/SPF](https://mxtoolbox.com/spf.aspx) — ввести `xdexboard.com`.

### DKIM

```bash
dig TXT mail._domainkey.xdexboard.com +short
# Должна быть строка v=DKIM1; k=rsa; p=...
```

На сервере:

```bash
sudo opendkim-testkey -d xdexboard.com -s mail -vvv
# Key record valid
```

### Исходящие письма

Отправьте тестовое письмо с noreply@xdexboard.com на свой ящик (Yandex, Gmail). В заголовках письма должна быть подпись:

```
DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/simple; d=xdexboard.com; s=mail; ...
```

Если Yandex или другой провайдер возвращает **554 5.7.1 Message rejected under suspicion of SPAM** — проверьте, что все три записи (SPF, DKIM, DMARC) есть в DNS и соответствуют примеру выше; после исправления подождите обновления кэша DNS и повторите отправку.

---

## 4. Yandex не принимает / Gmail в спам — что проверить

### 4.1. Обратная запись DNS (PTR) — обязательно для Yandex

Yandex и многие другие проверяют **PTR** (reverse DNS) для IP сервера. Без правильного PTR письма часто отклоняются.

- У **IP вашего сервера** (152.53.150.194) у хостера/провайдера должна быть настроена **PTR-запись** на имя **mail.xdexboard.com** (то же, что `myhostname` в Postfix).
- Типичная проблема: PTR показывает **внутреннее имя VPS** провайдера (например `supersrv.de.`), а не `mail.xdexboard.com` — это нужно исправить в панели хостера (Reverse DNS для этого IP).
- Проверка с любого хоста: `dig -x 152.53.150.194 +short` — должен вернуться `mail.xdexboard.com.`
- Настройка PTR делается в панели VPS/хостинга (Reverse DNS / PTR record для этого IP). На обычном DNS у регистратора домена PTR не настраивается — только у того, кто выдаёт IP.

#### Типичная ловушка: локальный "ложный PTR" из `/etc/hosts`

Даже после правильной настройки PTR у хостера сервер может показывать старое имя (например `supersrv.de`) из-за локальной строки в `/etc/hosts`.

Пример проблемной строки:

```text
152.53.150.194 supersrv.de supersrv
```

Из-за неё `systemd-resolved` может вернуть "синтетический" reverse-ответ, который не совпадает с публичным DNS.

Проверяйте и внешне, и локально:

```bash
# Публичный DNS (источник истины)
dig @1.1.1.1 +short -x 152.53.150.194

# Локальный stub на сервере
dig @127.0.0.53 +short -x 152.53.150.194
```

Если локально не совпадает, исправьте `/etc/hosts` на:

```text
152.53.150.194 mail.xdexboard.com
```

и очистите кэш:

```bash
sudo resolvectl flush-caches
```

### 4.2. Совпадение PTR и myhostname (HELO)

В `/etc/postfix/main.cf` должно быть:

```ini
myhostname = mail.xdexboard.com
```

Имя в **PTR** и значение **myhostname** должны совпадать. Иначе получатели могут помечать письмо как подозрительное.

### 4.3. FCrDNS (Forward-Confirmed Reverse DNS)

- По имени из PTR (mail.xdexboard.com) должна быть **A-запись** в DNS, указывающая обратно на ваш IP (152.53.150.194).
- Проверка: `dig +short A mail.xdexboard.com` — должен вернуться 152.53.150.194.
- Если A-записи нет или она указывает на другой IP, доставляемость ухудшается (Gmail/Yandex учитывают FCrDNS).

### 4.4. Проверка с сервера по SSH

Подключитесь к серверу и выполните:

```bash
# PTR (должен быть mail.xdexboard.com)
dig -x 152.53.150.194 +short

# A-запись для myhostname
dig +short A mail.xdexboard.com

# SPF, DKIM
dig +short TXT xdexboard.com
dig +short TXT mail._domainkey.xdexboard.com

# Postfix и OpenDKIM
postconf myhostname smtp_tls_security_level
systemctl status opendkim
```

### 4.5. Исходящий TLS

В `main.cf` для отправки в сторону внешних MX (Yandex, Gmail) желательно включить TLS:

```ini
smtp_tls_security_level = may
smtp_tls_note_starttls_offer = yes
```

Так Postfix будет использовать STARTTLS при возможности; это улучшает доверие и снижает риск попадания в спам.

### 4.6. Краткий чеклист

| Проверка | Действие |
|----------|----------|
| PTR для 152.53.150.194 | В панели хостера: PTR = mail.xdexboard.com |
| A для mail.xdexboard.com | В DNS домена: A mail.xdexboard.com → 152.53.150.194 |
| SPF | TXT xdexboard.com: `v=spf1 mx a:mail.xdexboard.com ip4:152.53.150.194 -all` |
| DKIM | TXT mail._domainkey.xdexboard.com — значение из `/etc/opendkim/keys/xdexboard.com/mail.txt` |
| DMARC | TXT _dmarc.xdexboard.com: `v=DMARC1; p=quarantine; adkim=s; aspf=s; fo=1` |
| myhostname | В main.cf: `myhostname = mail.xdexboard.com` |
| OpenDKIM | `systemctl status opendkim`, порт 8891 слушает |
| После правок DNS | Подождать 5–60 мин, затем `sudo systemctl restart postfix opendkim` |

---

## 5. Связанные документы

- [EMAIL_SERVER_SETUP.md](EMAIL_SERVER_SETUP.md) — установка Postfix, Dovecot, OpenDKIM  
- [LOT_MAILBOX_AND_IMAP_CHAT.md](LOT_MAILBOX_AND_IMAP_CHAT.md) — ящики лотов и IMAP  
- [NOREPLY_MAILBOX_SETUP.md](NOREPLY_MAILBOX_SETUP.md) — учётная запись noreply@  
- Шаблон записей: `scripts/email-server/dns-records-xdexboard.example`
