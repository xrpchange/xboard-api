# Быстрый старт - Применение на сервере

## Применение на server@xrpchange.site

### Вариант 1: Автоматический deploy (рекомендуется)

```bash
# На локальной машине
cd /path/to/xboard-api/scripts/email-server
chmod +x deploy.sh
./deploy.sh
```

Скрипт автоматически:
- Скопирует файлы на сервер
- Запустит установку
- Покажет инструкции по настройке DNS

### Вариант 2: Ручная установка

```bash
# 1. Подключитесь к серверу
ssh server@xrpchange.site

# 2. Скопируйте файлы (с локальной машины)
scp -r scripts/email-server server@xrpchange.site:/opt/xboard-api/

# 3. На сервере: запустите установку
ssh server@xrpchange.site
cd /opt/xboard-api/scripts/email-server
chmod +x install.sh
sudo ./install.sh
```

## После установки

### 1. Получите SSL сертификат (если еще не получен)

```bash
ssh server@xrpchange.site
sudo certbot certonly --standalone -d xdexboard.com -d mail.xdexboard.com
```

Или если Nginx уже настроен:
```bash
sudo certbot --nginx -d xdexboard.com -d mail.xdexboard.com
```

### 2. Настройте DNS записи

Добавьте в DNS провайдера следующие записи:

#### MX запись
```
xdexboard.com    MX    10    mail.xdexboard.com
```

#### A запись
```
mail.xdexboard.com    A    YOUR_SERVER_IP
```

#### SPF (TXT)
```
xdexboard.com    TXT    "v=spf1 mx a ip4:YOUR_SERVER_IP ~all"
```

#### DKIM (TXT)
Получите ключ на сервере:
```bash
cat /etc/opendkim/keys/xdexboard.com/mail.txt
```

Добавьте TXT запись:
```
mail._domainkey.xdexboard.com    TXT    "v=DKIM1; k=rsa; p=YOUR_PUBLIC_KEY"
```

#### DMARC (TXT)
```
_dmarc.xdexboard.com    TXT    "v=DMARC1; p=quarantine; rua=mailto:check@xdexboard.com"
```

### 3. Перезапустите сервисы

```bash
ssh server@xrpchange.site
sudo systemctl restart postfix
sudo systemctl restart dovecot
sudo systemctl restart opendkim
```

### 4. Проверьте статус

```bash
sudo systemctl status postfix
sudo systemctl status dovecot
sudo systemctl status opendkim
```

## Тестирование

### Тест 1: Отправка письма

```bash
# На сервере
echo "Test email" | mail -s "Test" check@xdexboard.com

# Проверьте логи
tail -f /var/log/mail.log
tail -f /var/log/xboard-email/verifier.log
```

### Тест 2: Пересылка письма от банка

1. Получите реальное письмо от банка (уведомление о переводе)
2. Перешлите его на `check@xdexboard.com`
3. **ВАЖНО:** Не добавляйте текст при пересылке!
4. Проверьте ответное письмо и логи

### Тест 3: Проверка DKIM

```bash
# Проверка подписи
opendkim-testkey -d xdexboard.com -s mail -k /etc/opendkim/keys/xdexboard.com/mail.private

# Проверка DNS
dig TXT mail._domainkey.xdexboard.com
```

## Мониторинг

### Просмотр логов

```bash
# Postfix
tail -f /var/log/mail.log

# PHP verifier
tail -f /var/log/xboard-email/verifier.log

# Dovecot
tail -f /var/log/dovecot.log

# OpenDKIM
journalctl -u opendkim -f
```

### Проверка статуса

```bash
sudo systemctl status postfix dovecot opendkim
```

## Устранение проблем

### Письма не доставляются

1. Проверьте статус Postfix: `sudo systemctl status postfix`
2. Проверьте логи: `tail -f /var/log/mail.log`
3. Проверьте DNS: `dig MX xdexboard.com`

### PHP скрипт не работает

1. Проверьте права: `ls -la /opt/xboard-api/scripts/email-server/php-verifier.php`
2. Проверьте путь в master.cf: `grep php-verifier /etc/postfix/master.cf`
3. Проверьте логи: `tail -f /var/log/mail.log | grep php-verifier`

### DKIM не работает

1. Проверьте OpenDKIM: `sudo systemctl status opendkim`
2. Проверьте DNS: `dig TXT mail._domainkey.xdexboard.com`
3. Проверьте ключ: `opendkim-testkey -d xdexboard.com -s mail`

## Дополнительная информация

Полная документация: [`docs/EMAIL_SERVER_SETUP.md`](../../docs/EMAIL_SERVER_SETUP.md)

Очередь Postfix раздулась / подозрение на open relay: [`docs/POSTFIX_RELAY_AND_QUEUE.md`](../../docs/POSTFIX_RELAY_AND_QUEUE.md), скрипт `apply-postfix-relay-hardening.sh`.

