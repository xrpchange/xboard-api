# 🔄 Настройка Cron для автоматического обновления SSL сертификатов

> Инструкция по настройке автоматического обновления SSL сертификатов Let's Encrypt через Cron

## 📋 Содержание

- [Быстрый старт](#-быстрый-старт)
- [Проверка текущих задач](#1-проверка-текущих-задач-cron)
- [Добавление SSL задач](#2-добавление-ssl-задач)
- [Тестирование](#3-тестирование-обновления)
- [Мониторинг](#4-мониторинг-логов)
- [Troubleshooting](#5-troubleshooting)

## 🚀 Быстрый старт

```bash
# 1. Проверить текущие задачи Cron
crontab -l

# 2. Добавить SSL задачи
crontab -e

# 3. Добавить следующие строки:
# SSL Certificate Auto-renewal
0 2 * * * /usr/bin/certbot renew --quiet --nginx

# 4. Сохранить и выйти (Ctrl+X, Y, Enter в nano)
```

## 1. Проверка текущих задач Cron

```bash
# Просмотр всех задач текущего пользователя
crontab -l

# Просмотр задач root (если нужно)
sudo crontab -l
```

**Текущие задачи:**
```
*/5 * * * * /usr/local/bin/monitor_monero.sh
*/5 * * * * /usr/local/bin/monitor_monero.sh
*/5 * * * * /usr/local/bin/monitor_monero.sh
*/5 * * * * /usr/local/bin/monitor_monero.sh
```

## 2. Добавление SSL задач

### 🔧 Редактирование crontab

```bash
# Открыть редактор crontab
crontab -e

# Добавить следующие строки в конец файла:
```

### 📝 SSL задачи для добавления

```bash
# SSL Certificate Auto-renewal
# Проверка и обновление сертификатов каждый день в 2:00
# certbot renew --nginx автоматически перезагружает Nginx
0 2 * * * /usr/bin/certbot renew --quiet --nginx
```

### 🕐 Расписание задач

| Время | Задача | Описание |
|-------|--------|----------|
| `0 2 * * *` | `certbot renew --nginx` | Ежедневно в 2:00 - проверка и обновление сертификатов с автоматической перезагрузкой Nginx |

### 📁 Логирование (опционально)

```bash
# Если нужны логи, добавить в crontab:
# 0 2 * * * /usr/bin/certbot renew --quiet --nginx >> /var/log/ssl-renewal.log 2>&1

# Создать лог-файл (если используете логирование)
sudo touch /var/log/ssl-renewal.log
sudo chmod 644 /var/log/ssl-renewal.log
```

## 3. Тестирование обновления

### 🧪 Тестовый запуск

```bash
# Тестовый запуск обновления (без изменений)
sudo certbot renew --dry-run

# Проверка статуса сертификатов
sudo certbot certificates

# Ручной запуск обновления
sudo certbot renew --nginx
```

### ✅ Проверка работы Cron

```bash
# Проверить, что задачи добавлены
crontab -l

# Проверить статус службы Cron
sudo systemctl status cron

# Просмотр логов Cron
sudo tail -f /var/log/cron.log
```

## 4. Мониторинг (опционально)

### 📊 Просмотр логов (если используете логирование)

```bash
# Логи обновления SSL
tail -f /var/log/ssl-renewal.log

# Логи Cron
tail -f /var/log/cron.log

# Логи Nginx
tail -f /var/log/nginx/error.log
tail -f /var/log/nginx/access.log
```

## 5. Troubleshooting

### ❌ Частые проблемы

#### 1. Certbot не найден
```bash
# Проверить путь к certbot
which certbot

# Если не найден, установить
sudo apt update
sudo apt install certbot python3-certbot-nginx
```

#### 2. Права доступа
```bash
# Проверить права на сертификаты
sudo ls -la /etc/letsencrypt/live/

# Исправить права (если нужно)
sudo chown -R root:root /etc/letsencrypt/
sudo chmod -R 755 /etc/letsencrypt/
```

#### 3. Nginx не перезагружается
```bash
# Проверить конфигурацию Nginx
sudo nginx -t

# Проверить статус Nginx
sudo systemctl status nginx

# Перезапустить Nginx
sudo systemctl restart nginx
```

#### 4. Cron не работает
```bash
# Проверить статус службы Cron
sudo systemctl status cron

# Запустить службу Cron
sudo systemctl start cron

# Включить автозапуск
sudo systemctl enable cron
```

### 🔧 Дополнительные настройки

#### Уведомления по email
```bash
# Добавить в crontab для отправки уведомлений
0 2 * * * /usr/bin/certbot renew --quiet --nginx && echo "SSL certificates renewed successfully" | mail -s "SSL Renewal Success" admin@xdexboard.com
```

#### Резервное копирование сертификатов
```bash
# Добавить в crontab для резервного копирования
0 1 * * * /bin/tar -czf /backup/ssl-certificates-$(date +\%Y\%m\%d).tar.gz /etc/letsencrypt/
```

## 📋 Итоговая конфигурация Cron

После настройки в `crontab -l` должно быть:

```bash
# Мониторинг Monero
*/5 * * * * /usr/local/bin/monitor_monero.sh

# SSL Certificate Auto-renewal
0 2 * * * /usr/bin/certbot renew --quiet --nginx
```

## ✅ Проверка работы

1. **Добавить задачи в crontab**
2. **Проверить `crontab -l`**
3. **Запустить тест `sudo certbot renew --dry-run`**

---

> **💡 Совет:** Сертификаты Let's Encrypt обновляются автоматически за 30 дней до истечения, поэтому ежедневная проверка безопасна и не создает лишней нагрузки.
