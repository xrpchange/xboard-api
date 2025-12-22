# Email Server Setup для XBoard API

## Описание

Автоматическая настройка почтового сервера для верификации платежей в P2P-обменнике через проверку DKIM подписи пересланных писем от банков.

## Структура файлов

```
scripts/email-server/
├── install.sh                    # Автоматический скрипт установки
├── php-verifier.php              # PHP скрипт проверки DKIM и отправки ответов
├── configs/                       # Примеры конфигураций
│   ├── postfix-main.cf.example
│   ├── postfix-master.cf.example
│   ├── dovecot.conf.example
│   └── dovecot-10-imap.conf.example
└── README.md                      # Этот файл
```

## Быстрый старт

1. **Подключитесь к серверу:**
   ```bash
   ssh server@xrpchange.site
   ```

2. **Скопируйте файлы на сервер:**
   ```bash
   # Если репозиторий уже на сервере
   cd /path/to/xboard-api
   
   # Или скопируйте через scp
   scp -r scripts/email-server server@xrpchange.site:/path/to/xboard-api/
   ```

3. **Запустите установку:**
   ```bash
   cd /path/to/xboard-api/scripts/email-server
   chmod +x install.sh
   sudo ./install.sh
   ```

4. **Настройте DNS записи** (см. `docs/EMAIL_SERVER_SETUP.md`)

5. **Протестируйте** (см. раздел "Тестирование" в документации)

## Требования

- Ubuntu 24.04 LTS (или последняя LTS)
- Права root для установки
- Доступ к настройке DNS записей
- Домен xdexboard.com настроен и указывает на сервер

## Компоненты

- **Postfix** - SMTP сервер, приём писем
- **Dovecot** - IMAP сервер с IDLE поддержкой
- **OpenDKIM** - подпись исходящих писем
- **PHP 8.3** - скрипт проверки DKIM
- **Let's Encrypt** - SSL сертификаты

## Документация

Полная документация: [`docs/EMAIL_SERVER_SETUP.md`](../../docs/EMAIL_SERVER_SETUP.md)

## Поддержка

При проблемах проверьте:
1. Логи: `/var/log/xboard-email/verifier.log`
2. Статус сервисов: `systemctl status postfix dovecot opendkim`
3. DNS записи: `dig MX xdexboard.com`

