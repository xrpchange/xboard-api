# 🔒 Настройка Nginx с SSL сертификатами для XRPChangeCore

> Полная инструкция по настройке Nginx с автоматическими SSL сертификатами Let's Encrypt для XRPChangeCore API

## 📋 Содержание

- [Быстрый старт](#-быстрый-старт)
- [Установка компонентов](#1-установка-nginx-и-certbot)
- [Настройка Nginx (временная)](#2-настройка-nginx-временная-конфигурация)
- [Получение SSL сертификатов](#4-получение-ssl-сертификатов)
- [Замена на SSL конфигурацию](#5-замена-на-ssl-конфигурацию)
- [Автоматическое обновление](#6-настройка-автоматического-обновления-сертификатов)
- [Проверка работы](#7-проверка-работы)
- [Мониторинг](#9-мониторинг-и-логи)
- [Troubleshooting](#11-troubleshooting)

## 🚀 Быстрый старт

```bash
# 1. Скопировать конфигурации на сервер
scp nginx-core-temp.conf nginx-core.conf user@server:/path/to/core/

# 2. Настроить временную конфигурацию (без SSL)
sudo cp nginx-core-temp.conf /etc/nginx/sites-available/core
sudo ln -s /etc/nginx/sites-available/core /etc/nginx/sites-enabled/corecore
sudo nginx -t && sudo systemctl reload nginx

# 3. Получить SSL сертификаты
sudo certbot certonly --webroot \
  -w /var/www/certbot \
  -d sandbox.api.core.xrpchange.site \
  -d api.core.xrpchange.site \
  --email admin@xrpchange.site \
  --agree-tos \
  --non-interactive

# 4. Заменить на SSL конфигурацию
sudo cp nginx-core.conf /etc/nginx/sites-available/core
sudo nginx -t && sudo systemctl reload nginx

# 5. Проверить работу
curl https://sandbox.api.core.xrpchange.site/echo.php
```

---

## 1. Установка Nginx и Certbot

### 🛠️ Установка пакетов

```bash
# Обновление системы
sudo apt update && sudo apt upgrade -y

# Установка Nginx
sudo apt install nginx -y

# Установка Certbot и плагина для Nginx
sudo apt install certbot python3-certbot-nginx -y

# Запуск и включение Nginx
sudo systemctl start nginx
sudo systemctl enable nginx
```

### ✅ Проверка установки

```bash
# Проверка версий
nginx -v
certbot --version

# Проверка статуса Nginx
sudo systemctl status nginx
```

## 2. Настройка Nginx (временная конфигурация)

### 📁 Копирование временной конфигурации

```bash
# Копируем временную конфигурацию XRPChangeCore (без SSL)
sudo cp nginx-core-temp.conf /etc/nginx/sites-available/core

# Создаем символическую ссылку
sudo ln -s /etc/nginx/sites-available/core /etc/nginx/sites-enabled/core

# Удаляем дефолтную конфигурацию
sudo rm /etc/nginx/sites-enabled/default

# Проверяем конфигурацию
sudo nginx -t
```

> **⚠️ Важно:** Используем временную конфигурацию без SSL для получения сертификатов

### ⚠️ Важно: Настройка upstream

Если вы хотите использовать upstream для балансировки нагрузки, добавьте в `/etc/nginx/nginx.conf` в блок `http`:

```nginx
http {
    # ... existing configuration ...
    
    upstream core_backend {
        server 192.168.1.31:80;
        keepalive 32;
        keepalive_requests 100;
        keepalive_timeout 60s;
    }
    
    # ... rest of configuration ...
}
```

Затем в конфигурации XRPChangeCore замените `http://192.168.1.31:80` на `http://core_backend`.

### 📂 Создание директории для Certbot

```bash
# Создаем директорию для ACME challenge
sudo mkdir -p /var/www/certbot
sudo chown -R www-data:www-data /var/www/certbot
```

## 3. Запуск Nginx

### 🚀 Активация конфигурации

```bash
# Перезапускаем Nginx
sudo systemctl reload nginx

# Проверяем статус
sudo systemctl status nginx
```

## 4. Получение SSL сертификатов

### 🔐 Тестовая проверка (dry-run)

```bash
# Сначала тестируем получение сертификатов
sudo certbot certonly --webroot \
  -w /var/www/certbot \
  -d sandbox.api.core.xrpchange.site \
  -d api.core.xrpchange.site \
  --email admin@xrpchange.site \
  --agree-tos \
  --non-interactive \
  --dry-run
```

### 🔐 Получение реальных сертификатов

```bash
# Получаем сертификаты для sandbox и production доменов
sudo certbot certonly --webroot \
  -w /var/www/certbot \
  -d sandbox.api.core.xrpchange.site \
  -d api.core.xrpchange.site \
  --email admin@xrpchange.site \
  --agree-tos \
  --non-interactive
```

> **⚠️ Важно:** Замените `admin@xrpchange.site` на ваш реальный email

### ✅ Проверка сертификатов

```bash
# Проверяем, что сертификаты созданы
sudo ls -la /etc/letsencrypt/live/api.core.xrpchange.site/
sudo ls -la /etc/letsencrypt/live/sandbox.api.core.xrpchange.site/

# Проверяем информацию о сертификатах
sudo certbot certificates
```

## 5. Замена на SSL конфигурацию

### 🔄 Установка SSL конфигурации

```bash
# Заменяем временную конфигурацию на SSL версию
sudo cp nginx-core.conf /etc/nginx/sites-available/core

# Проверяем SSL конфигурацию
sudo nginx -t

# Перезапускаем Nginx с SSL
sudo systemctl reload nginx
```

> **✅ Теперь SSL сертификаты существуют, и Nginx может их загрузить!**

### 🔧 Особенности SSL конфигурации

**Полная конфигурация Nginx для XRPChangeCore API включает:**

#### 🌐 Домены и SSL
- **sandbox.api.core.xrpchange.site** - sandbox окружение
- **api.core.xrpchange.site** - production окружение  
- **Общий SSL сертификат** для обоих доменов (создан с флагом `--expand`)
- **Автоматический редирект** HTTP → HTTPS
- **HTTP/2 поддержка** (современный `http2 on;` синтаксис)

#### 🔒 Безопасность
- **TLS 1.2 и 1.3** протоколы
- **Современные шифры** (ECDHE, AES-GCM, ChaCha20-Poly1305)
- **HSTS заголовки** (принудительный HTTPS на 1 год)
- **X-Frame-Options: DENY** (защита от clickjacking)
- **X-Content-Type-Options: nosniff** (защита от MIME sniffing)
- **X-XSS-Protection** (защита от XSS атак)
- **Referrer-Policy** (контроль referrer информации)

#### 🚀 Производительность
- **Keepalive соединения** (32 соединения, 100 запросов, 60s таймаут)
- **HTTP/1.1 проксирование** с оптимизацией
- **Отключение буферизации** для real-time ответов
- **Таймауты 60 секунд** для всех операций

#### 🌍 CORS и API
- **CORS заголовки** для cross-origin запросов
- **Preflight обработка** (OPTIONS запросы)
- **Кэширование preflight** на 24 часа
- **Поддержка всех HTTP методов** (GET, POST, PUT, DELETE, OPTIONS)
- **Authorization заголовки** для Bearer токенов
- **X-Core-Key заголовки** для аутентификации ядра

#### 📡 Проксирование
- **Прямое проксирование** на 192.168.1.31:80
- **Правильные заголовки** (Host, X-Real-IP, X-Forwarded-*)
- **Сохранение схемы** (HTTP/HTTPS)
- **Обработка ошибок** с перехватом
- **Echo endpoint** для health checks

#### 📁 Структура файлов
```
/etc/letsencrypt/live/api.core.xrpchange.site/
├── fullchain.pem    # Полная цепочка сертификатов
├── privkey.pem      # Приватный ключ
├── cert.pem         # Сертификат сервера
└── chain.pem        # Промежуточные сертификаты
```

### 📋 Детальное описание конфигурации

#### 🔧 Основные блоки конфигурации

**1. HTTP редирект сервер (порт 80)**
- Обрабатывает все HTTP запросы
- Редиректит на HTTPS с сохранением домена
- Обслуживает ACME challenge для обновления сертификатов
- Поддерживает оба домена: sandbox и production

**2. HTTPS sandbox сервер (порт 443)**
- Обрабатывает HTTPS запросы для `sandbox.api.core.xrpchange.site`
- Использует общий SSL сертификат
- Проксирует запросы на XRPChangeCore (192.168.1.31:80)
- Специальная обработка API запросов с CORS
- Echo endpoint для health checks

**3. HTTPS production сервер (порт 443)**
- Обрабатывает HTTPS запросы для `api.core.xrpchange.site`
- Идентичная конфигурация с sandbox для консистентности
- Использует тот же SSL сертификат
- Проксирует на тот же XRPChangeCore сервер

#### 🛡️ Настройки безопасности

**SSL/TLS конфигурация:**
```nginx
ssl_protocols TLSv1.2 TLSv1.3;
ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:...;
ssl_prefer_server_ciphers off;
ssl_session_cache shared:SSL:10m;
ssl_session_timeout 10m;
ssl_session_tickets off;
```

**Заголовки безопасности:**
- `Strict-Transport-Security` - принудительный HTTPS
- `X-Frame-Options: DENY` - защита от iframe
- `X-Content-Type-Options: nosniff` - защита от MIME атак
- `X-XSS-Protection` - защита от XSS
- `Referrer-Policy` - контроль referrer

#### 🌐 CORS настройки

**Для API эндпоинтов (`/api/*`):**
- Разрешенные методы: GET, POST, PUT, DELETE, OPTIONS
- Разрешенные заголовки: Authorization, Content-Type, X-Requested-With, X-Core-Key
- Поддержка preflight запросов (OPTIONS)
- Кэширование preflight на 24 часа

#### ⚡ Оптимизация производительности

**Keepalive настройки:**
- 32 постоянных соединения
- 100 запросов на соединение
- 60 секунд таймаут

**Проксирование:**
- HTTP/1.1 с оптимизацией
- Отключение буферизации для real-time
- Таймауты 60 секунд для всех операций

### 🔄 Проверка работы

```bash
# Проверяем, что Nginx слушает нужные порты
sudo netstat -tlnp | grep nginx

# Должно показать:
# :80 (HTTP)
# :443 (HTTPS)
```

## 6. Настройка автоматического обновления сертификатов

### ⏰ Создание cron задачи

```bash
# Открываем crontab
sudo crontab -e

# Добавляем строку для автоматического обновления (каждый день в 2:30)
30 2 * * * /usr/bin/certbot renew --quiet && /usr/bin/systemctl reload nginx
```

### 🧪 Тестирование обновления

```bash
# Тестируем обновление сертификатов (не обновляет реально)
sudo certbot renew --dry-run

# Проверяем, что cron задача работает
sudo systemctl status cron
```

### 📅 Альтернативные способы обновления

```bash
# Ручное обновление сертификатов
sudo certbot renew

# Принудительное обновление (если до истечения >30 дней)
sudo certbot renew --force-renewal
```

## 7. Проверка работы

### 🔄 Проверка HTTP редиректа

```bash
# Проверяем, что HTTP редиректит на HTTPS
curl -I http://sandbox.api.core.xrpchange.site
curl -I http://api.core.xrpchange.site

# Ожидаемый результат:
# HTTP/1.1 301 Moved Permanently
# Location: https://sandbox.api.core.xrpchange.site/
```

### 🔒 Проверка HTTPS

```bash
# Проверяем HTTPS соединение
curl -I https://sandbox.api.core.xrpchange.site
curl -I https://api.core.xrpchange.site

# Ожидаемый результат:
# HTTP/2 200
# strict-transport-security: max-age=31536000; includeSubDomains
```

### 🌐 Проверка API

```bash
# Проверяем API эндпоинты
curl https://sandbox.api.core.xrpchange.site/echo.php
curl https://api.core.xrpchange.site/echo.php

# Ожидаемый результат:
# {"status":"ok","timestamp":...,"version":"1.0.0"}
```

### 🔐 Проверка SSL сертификата

```bash
# Проверяем SSL сертификат
openssl s_client -connect sandbox.api.core.xrpchange.site:443 -servername sandbox.api.core.xrpchange.site

# Проверяем срок действия сертификата
sudo certbot certificates
```

### 🧪 Комплексная проверка

```bash
# Скрипт для полной проверки
echo "=== Проверка HTTP редиректа ==="
curl -I http://sandbox.api.core.xrpchange.site 2>/dev/null | head -1
curl -I http://api.core.xrpchange.site 2>/dev/null | head -1

echo "=== Проверка HTTPS ==="
curl -I https://sandbox.api.core.xrpchange.site 2>/dev/null | head -1
curl -I https://api.core.xrpchange.site 2>/dev/null | head -1

echo "=== Проверка API ==="
curl -s https://sandbox.api.core.xrpchange.site/echo.php | jq . 2>/dev/null || echo "API не отвечает"
curl -s https://api.core.xrpchange.site/echo.php | jq . 2>/dev/null || echo "API не отвечает"

echo "=== Проверка SSL ==="
echo | openssl s_client -connect sandbox.api.core.xrpchange.site:443 -servername sandbox.api.core.xrpchange.site 2>/dev/null | openssl x509 -noout -dates
echo | openssl s_client -connect api.core.xrpchange.site:443 -servername api.core.xrpchange.site 2>/dev/null | openssl x509 -noout -dates

echo "✅ Проверка завершена!"
```

## 8. Настройка файрвола

### 🔥 Настройка UFW

```bash
# Разрешаем HTTP и HTTPS трафик
sudo ufw allow 'Nginx Full'
sudo ufw allow OpenSSH

# Включаем файрвол
sudo ufw enable

# Проверяем статус
sudo ufw status
```

### 📊 Мониторинг трафика

```bash
# Просмотр активных соединений
sudo netstat -tulpn | grep nginx

# Мониторинг трафика в реальном времени
sudo iftop -i eth0
```

## 9. Мониторинг и логи

### 📝 Просмотр логов Nginx

```bash
# Логи доступа (все запросы)
sudo tail -f /var/log/nginx/access.log

# Логи ошибок
sudo tail -f /var/log/nginx/error.log

# Логи только API запросов
sudo tail -f /var/log/nginx/access.log | grep "/api/"

# Статистика по кодам ответов
sudo awk '{print $9}' /var/log/nginx/access.log | sort | uniq -c | sort -nr
```

### 🔐 Мониторинг сертификатов

```bash
# Проверка срока действия сертификатов
sudo certbot certificates

# Проверка автоматического обновления
sudo systemctl status certbot.timer

# Скрипт проверки срока действия
echo "=== Проверка сертификатов ==="
for domain in sandbox.api.core.xrpchange.site api.core.xrpchange.site; do
    echo "Домен: $domain"
    echo | openssl s_client -connect $domain:443 -servername $domain 2>/dev/null | openssl x509 -noout -dates
    echo "---"
done
```

### 📈 Мониторинг производительности

```bash
# Статистика Nginx
sudo nginx -T | grep -E "(worker_processes|worker_connections)"

# Использование памяти
ps aux | grep nginx

# Количество активных соединений
sudo netstat -an | grep :443 | wc -l
```

## 10. Обновление конфигурации XBoard

После настройки HTTPS нужно обновить настройки в XBoard:

### 🔧 Обновление .env

```bash
# В файле .env изменить APP_URL
APP_URL=https://sandbox.api.xrpchange.site
```

### ⚙️ Обновление config/services.php

```php
'core' => [
    'url' => env('CORE_URL', 'https://sandbox.api.core.xrpchange.site'),
    'key' => env('CORE_KEY', ''),
    'timeout' => env('CORE_TIMEOUT', 10),
],

'core_production' => [
    'url' => env('CORE_PRODUCTION_URL', 'https://api.core.xrpchange.site'),
    'key' => env('CORE_PRODUCTION_KEY', ''),
    'timeout' => env('CORE_PRODUCTION_TIMEOUT', 10),
],
```

## 11. Troubleshooting

### ❌ Проблемы с сертификатами

```bash
# Проверка DNS записей
nslookup sandbox.api.core.xrpchange.site
nslookup api.core.xrpchange.site

# Проверка доступности доменов
ping sandbox.api.core.xrpchange.site
ping api.core.xrpchange.site

# Проверка ACME challenge
curl http://sandbox.api.core.xrpchange.site/.well-known/acme-challenge/test
```

### ⚠️ Проблемы с Nginx

```bash
# Проверка конфигурации
sudo nginx -t

# Перезапуск Nginx
sudo systemctl restart nginx

# Проверка статуса
sudo systemctl status nginx

# Просмотр ошибок
sudo journalctl -u nginx -f
```

### 🔄 Проблемы с проксированием

```bash
# Проверка доступности бэкенда
curl http://192.168.1.31:80/echo.php

# Проверка логов Nginx
sudo tail -f /var/log/nginx/error.log

# Проверка соединений
sudo netstat -an | grep 192.168.1.31
```

### 🐛 Частые проблемы

| Проблема | Решение |
|----------|---------|
| 502 Bad Gateway | Проверить доступность бэкенда |
| SSL ошибки | Проверить сертификаты: `certbot certificates` |
| 404 Not Found | Проверить конфигурацию location |
| Таймауты | Увеличить proxy_read_timeout |

## 12. Безопасность

### 🔒 Заголовки безопасности

В конфигурации Nginx уже добавлены:
- ✅ `Strict-Transport-Security` - принудительный HTTPS
- ✅ `X-Frame-Options: DENY` - защита от iframe
- ✅ `X-Content-Type-Options: nosniff` - защита от MIME атак
- ✅ `X-XSS-Protection` - защита от XSS
- ✅ `Referrer-Policy` - контроль referrer

### 🛡️ Дополнительные меры

```nginx
# Ограничение по IP (если нужно)
# В location / добавить:
# allow 192.168.1.0/24;
# deny all;

# Ограничение запросов
# В http блок добавить:
# limit_req_zone $binary_remote_addr zone=api:10m rate=10r/s;
# limit_req zone=api burst=20 nodelay;
```

## 13. Заключение

### ✅ Что настроено

- 🔒 **HTTPS** для всех доменов с автоматическим редиректом
- 🔐 **SSL сертификаты** Let's Encrypt с автообновлением
- 🌐 **Два домена** (sandbox и production) с общей конфигурацией
- 🚀 **HTTP/2** поддержка для лучшей производительности
- 🛡️ **Заголовки безопасности** для защиты от атак
- 🌍 **CORS** настройки для API доступа
- ⚡ **Оптимизация** с keepalive и буферизацией
- 📊 **Мониторинг** и логирование

### 🚀 Следующие шаги

1. **Тестирование API** - проверка всех эндпоинтов
2. **Интеграция с XBoard** - обновление URL ядра
3. **Мониторинг** - настройка алертов
4. **Резервное копирование** - конфигураций и сертификатов

### 📞 Поддержка

При возникновении проблем:
1. Проверьте логи: `sudo journalctl -u nginx -f`
2. Проверьте конфигурацию: `sudo nginx -t`
3. Проверьте сертификаты: `sudo certbot certificates`
4. Проверьте доступность бэкенда: `curl http://192.168.1.31:80/echo.php`

**XRPChangeCore API готов к использованию через HTTPS!** 🎉
