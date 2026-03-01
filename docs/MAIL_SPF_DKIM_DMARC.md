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

Значение (пример):

```
v=DMARC1; p=quarantine; rua=mailto:check@xdexboard.com; pct=100; adkim=r; aspf=r
```

- `p=quarantine` — письма, не прошедшие проверку, помещать в спам (можно начать с `p=none` для мониторинга).
- `rua=mailto:check@xdexboard.com` — отчёты о проверках на этот адрес.
- `adkim=r`, `aspf=r` — выравнивание relaxed (рекомендуется).

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

## 4. Связанные документы

- [EMAIL_SERVER_SETUP.md](EMAIL_SERVER_SETUP.md) — установка Postfix, Dovecot, OpenDKIM  
- [LOT_MAILBOX_AND_IMAP_CHAT.md](LOT_MAILBOX_AND_IMAP_CHAT.md) — ящики лотов и IMAP  
- [NOREPLY_MAILBOX_SETUP.md](NOREPLY_MAILBOX_SETUP.md) — учётная запись noreply@  
- Шаблон записей: `scripts/email-server/dns-records-xdexboard.example`
