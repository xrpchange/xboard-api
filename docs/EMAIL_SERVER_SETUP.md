# Настройка почтового сервера для автоматической верификации платежей

## Описание

Почтовый сервер для автоматической проверки DKIM подписи пересланных писем от банков и отправки ответов покупателям в P2P-обменнике.

**Домен:** xdexboard.com  
**Email для верификации:** check@xdexboard.com  
**ОС:** Ubuntu 24.04 LTS

## Архитектура

- **Postfix** — приём SMTP писем и pipe в PHP-скрипт
- **Dovecot** — IMAP сервер с поддержкой IDLE для реал-тайм уведомлений
- **OpenDKIM** — подпись исходящих писем
- **PHP 8.3** — скрипт проверки DKIM и отправки ответов
- **Let's Encrypt** — SSL/TLS сертификаты

## Быстрая установка

### 1. Подготовка сервера

```bash
# Подключитесь к серверу
ssh server@xrpchange.site

# Обновите систему
sudo apt-get update && sudo apt-get upgrade -y
```

### 2. Запуск скрипта установки

```bash
# Клонируйте репозиторий или скопируйте файлы
cd /path/to/xboard-api

# Сделайте скрипт исполняемым
chmod +x scripts/email-server/install.sh

# Запустите установку (требуются права root)
sudo ./scripts/email-server/install.sh
```

Скрипт автоматически:
- Установит все необходимые пакеты
- Настроит Postfix, Dovecot, OpenDKIM
- Создаст виртуальных пользователей
- Настроит pipe для PHP-скрипта
- Настроит SSL (если сертификат уже есть)

### 3. Получение SSL сертификата

Если SSL сертификат ещё не получен:

```bash
# Вариант 1: Standalone (временно остановит веб-сервер)
sudo certbot certonly --standalone -d xdexboard.com -d mail.xdexboard.com

# Вариант 2: Через Nginx (если уже настроен)
sudo certbot --nginx -d xdexboard.com -d mail.xdexboard.com
```

### 4. Настройка DNS записей (SPF, DKIM, DMARC)

Без корректных SPF, DKIM и DMARC исходящая почта (noreply@, ящики лотов) может отклоняться получателями (Yandex, Gmail и др.) как спам.

**Подробно:** [MAIL_SPF_DKIM_DMARC.md](MAIL_SPF_DKIM_DMARC.md) — точные записи, проверка, устранение ошибок.

Кратко для домена `xdexboard.com`:

#### MX запись
```
xdexboard.com    MX    10    mail.xdexboard.com
```

#### A запись для mail
```
mail.xdexboard.com    A    <IP вашего сервера>
```

#### SPF (TXT для xdexboard.com)
```
v=spf1 mx a:mail.xdexboard.com ip4:<IP_СЕРВЕРА> -all
```

#### DKIM (TXT для mail._domainkey.xdexboard.com)
Содержимое взять с сервера: `sudo cat /etc/opendkim/keys/xdexboard.com/mail.txt`

#### DMARC (TXT для _dmarc.xdexboard.com)
```
v=DMARC1; p=quarantine; rua=mailto:check@xdexboard.com; adkim=r; aspf=r
```

### 5. Перезапуск сервисов

```bash
sudo systemctl restart postfix
sudo systemctl restart dovecot
sudo systemctl restart opendkim
```

## Ручная настройка (если скрипт не подходит)

### Postfix

#### /etc/postfix/main.cf

См. пример: `scripts/email-server/configs/postfix-main.cf.example`

#### /etc/postfix/master.cf

Добавьте в конец файла:
```
check@xdexboard.com    unix - n n - - pipe
  flags=F user=vmail argv=php /path/to/xboard-api/scripts/email-server/php-verifier.php
```

#### /etc/postfix/transport

```
check@xdexboard.com    check@xdexboard.com:
```

Затем:
```bash
sudo postmap /etc/postfix/transport
```

#### Виртуальные пользователи

- **Статические ящики** (check@xdexboard.com) — через hash-файлы.
- **Ящики лотов (lot_mailboxes)** — через MySQL. См. [docs/LOT_MAILBOX_AND_IMAP_CHAT.md](LOT_MAILBOX_AND_IMAP_CHAT.md) и `scripts/email-server/setup-lot-mailboxes.sh`.

```bash
# Создайте файлы для статических ящиков
echo "xdexboard.com OK" | sudo tee /etc/postfix/virtual-mailbox-domains
echo "check@xdexboard.com xdexboard.com/check/" | sudo tee /etc/postfix/virtual-mailbox-maps
echo "check@xdexboard.com check@xdexboard.com" | sudo tee /etc/postfix/virtual-alias-maps

# Создайте hash файлы
sudo postmap /etc/postfix/virtual-mailbox-domains
sudo postmap /etc/postfix/virtual-mailbox-maps
sudo postmap /etc/postfix/virtual-alias-maps
```

Чтобы добавить ящики лотов из MySQL, см. [LOT_MAILBOX_AND_IMAP_CHAT.md](LOT_MAILBOX_AND_IMAP_CHAT.md) и конфиги `postfix-mysql-lot-mailboxes.cf.example` / `dovecot-sql-lot-mailboxes.conf.example`.

### Dovecot

См. примеры конфигов в `scripts/email-server/configs/`:
- `dovecot.conf.example`
- `dovecot-10-imap.conf.example`

### OpenDKIM

- **Сокет:** Postfix подключается к OpenDKIM по TCP. В `/etc/opendkim.conf` должен быть:  
  `Socket inet:8891@localhost`  
  (не Unix-сокет, иначе в логах будет «Connection refused» к milter.)
- **InternalHosts:** чтобы исходящая почта подписывалась, укажите:  
  `InternalHosts 127.0.0.1, ::1, <IP_СЕРВЕРА>`  
  (подставьте внешний IP сервера mail.xdexboard.com.)

Генерация ключа (если ещё нет):

```bash
sudo mkdir -p /etc/opendkim/keys/xdexboard.com
sudo opendkim-genkey -t -s mail -d xdexboard.com -D /etc/opendkim/keys/xdexboard.com/
sudo chown -R opendkim:opendkim /etc/opendkim/keys
sudo chmod 600 /etc/opendkim/keys/xdexboard.com/mail.private
```

KeyTable/SigningTable для домена xdexboard.com и селектора `mail` должны указывать на этот ключ. Подробнее: [MAIL_SPF_DKIM_DMARC.md](MAIL_SPF_DKIM_DMARC.md).

## PHP скрипт проверки

### Расположение

`scripts/email-server/php-verifier.php`

### Функциональность

1. **Чтение письма из STDIN** (pipe от Postfix)
2. **Проверка отправителя** — письмо должно прийти с адреса банка
3. **Проверка DKIM подписи** — подпись должна быть валидной
4. **Отправка ответа:**
   - При успехе — подтверждение платежа
   - При ошибке DKIM — просьба переслать письмо заново
   - При неверном отправителе — ошибка

### Настройка списка банков

Отредактируйте константу `ALLOWED_BANK_DOMAINS` в `php-verifier.php`:

```php
const ALLOWED_BANK_DOMAINS = [
    'sberbank.ru',
    'tinkoff.ru',
    'alfabank.ru',
    // Добавьте другие банки
];
```

### Логи

Логи скрипта сохраняются в:
```
/var/log/xboard-email/verifier.log
```

Просмотр логов:
```bash
tail -f /var/log/xboard-email/verifier.log
```

## Тестирование

### 1. Тест отправки письма

```bash
# Отправьте тестовое письмо
echo "Test email" | mail -s "Test" check@xdexboard.com

# Проверьте логи
tail -f /var/log/mail.log
tail -f /var/log/xboard-email/verifier.log
```

### 2. Тест пересылки письма от банка

1. Получите реальное письмо от банка (например, уведомление о переводе)
2. Перешлите его на `check@xdexboard.com`
3. **ВАЖНО:** Не добавляйте никакого текста при пересылке!
4. Проверьте логи и ответное письмо

### 3. Тест IMAP с IDLE

Используйте почтовый клиент с поддержкой IMAP IDLE (например, Thunderbird, Apple Mail) или библиотеку для вашего приложения.

Подключение:
- **Сервер:** mail.xdexboard.com
- **Порт:** 993 (IMAPS)
- **Протокол:** SSL/TLS
- **Пользователь:** check@xdexboard.com
- **Пароль:** (не требуется для виртуальных пользователей, но может быть настроен)

### 4. Проверка DKIM подписи

```bash
# Проверьте подпись исходящих писем
opendkim-testkey -d xdexboard.com -s mail -k /etc/opendkim/keys/xdexboard.com/mail.private

# Проверьте DNS запись
dig TXT mail._domainkey.xdexboard.com
```

## Мониторинг

### Статус сервисов

```bash
sudo systemctl status postfix
sudo systemctl status dovecot
sudo systemctl status opendkim
```

### Логи

```bash
# Postfix
tail -f /var/log/mail.log

# Dovecot
tail -f /var/log/dovecot.log

# PHP verifier
tail -f /var/log/xboard-email/verifier.log

# OpenDKIM
journalctl -u opendkim -f
```

## Интеграция с API

### Планируемые эндпоинты

В будущем можно добавить API эндпоинты для:

- Получения статуса проверки письма
- Списка проверенных платежей
- Настройки списка банков
- Статистики проверок

### Текущая интеграция

PHP скрипт может отправлять HTTP запросы к Laravel API для обновления статуса платежей. Добавьте код в функцию `processEmail()` после успешной проверки:

```php
// Пример интеграции с API
$apiUrl = 'https://api.xdexboard.com/api/v1/payments/verify';
$response = file_get_contents($apiUrl, false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/json',
        'content' => json_encode([
            'email' => $replyTo,
            'sender' => $senderCheck['from'],
            'dkim_domain' => $dkimCheck['domain'],
            'status' => 'verified',
        ]),
    ],
]));
```

## Безопасность

### Рекомендации

1. **Firewall:** Откройте только необходимые порты:
   - 25 (SMTP)
   - 587 (SMTP submission)
   - 993 (IMAPS)
   - 80, 443 (для Let's Encrypt)

2. **Fail2ban:** Настройте защиту от брутфорса:
```bash
sudo apt-get install fail2ban
```

3. **Регулярные обновления:**
```bash
sudo apt-get update && sudo apt-get upgrade -y
```

4. **Мониторинг логов:** Регулярно проверяйте логи на подозрительную активность

## Устранение проблем

### Письма не доставляются

1. Проверьте статус Postfix:
```bash
sudo systemctl status postfix
```

2. Проверьте логи:
```bash
tail -f /var/log/mail.log
```

3. Проверьте DNS записи:
```bash
dig MX xdexboard.com
dig A mail.xdexboard.com
```

### PHP скрипт не запускается

1. Проверьте права:
```bash
ls -la /path/to/php-verifier.php
sudo chmod +x /path/to/php-verifier.php
```

2. Проверьте путь в master.cf:
```bash
sudo cat /etc/postfix/master.cf | grep php-verifier
```

3. Проверьте логи:
```bash
tail -f /var/log/mail.log | grep php-verifier
```

### DKIM проверка не работает

1. Проверьте, что OpenDKIM запущен:
```bash
sudo systemctl status opendkim
```

2. Проверьте DNS запись:
```bash
dig TXT mail._domainkey.xdexboard.com
```

3. Проверьте конфиг OpenDKIM:
```bash
sudo opendkim-testkey -d xdexboard.com -s mail
```

### IMAP IDLE не работает

1. Проверьте конфиг Dovecot:
```bash
sudo doveconf | grep imap_idle
```

2. Проверьте логи:
```bash
tail -f /var/log/dovecot.log
```

## Postfix: релей, очередь и безопасность

Если на сервере **раздувается очередь** (`postqueue -p`), в логах появляются предупреждения про пустой `smtpd_relay_restrictions` или внешние клиенты массово отправляют почту наружу — см. отдельный документ **[POSTFIX_RELAY_AND_QUEUE.md](POSTFIX_RELAY_AND_QUEUE.md)** (уровень `compatibility_level`, лимиты `maximal_queue_lifetime`, очистка `postsuper`, фаервол, артефакты `master.cf`).

## Дополнительные ресурсы

- [Postfix Documentation](http://www.postfix.org/documentation.html)
- [Dovecot Documentation](https://doc.dovecot.org/)
- [OpenDKIM Documentation](http://www.opendkim.org/)
- [Let's Encrypt Documentation](https://letsencrypt.org/docs/)

## Поддержка

При возникновении проблем:
1. Проверьте логи (см. раздел "Мониторинг")
2. Проверьте конфигурацию
3. Убедитесь, что все DNS записи настроены правильно
4. Проверьте статус всех сервисов

