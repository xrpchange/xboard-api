# Настройка Nginx с SSL сертификатами для XBoard

## 1. Установка Nginx и Certbot

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

## 2. Настройка Nginx

### 2.1. Копирование конфигурации

```bash
# Копируем конфигурацию XBoard
sudo cp nginx-xboard.conf /etc/nginx/sites-available/xboard

# Создаем символическую ссылку
sudo ln -s /etc/nginx/sites-available/xboard /etc/nginx/sites-enabled/

# Удаляем дефолтную конфигурацию
sudo rm /etc/nginx/sites-enabled/default

# Проверяем конфигурацию
sudo nginx -t
```

### 2.2. Создание директории для Certbot

```bash
# Создаем директорию для ACME challenge
sudo mkdir -p /var/www/certbot
sudo chown -R www-data:www-data /var/www/certbot
```

## 3. Получение SSL сертификатов

### 3.1. Получение сертификатов для обоих доменов

```bash
# Получаем сертификаты для sandbox и production доменов
sudo certbot certonly --webroot \
  -w /var/www/certbot \
  -d sandbox.api.xrpchange.site \
  -d api.xrpchange.site \
  --email admin@xrpchange.site \
  --agree-tos \
  --non-interactive
```

### 3.2. Проверка сертификатов

```bash
# Проверяем, что сертификаты созданы
sudo ls -la /etc/letsencrypt/live/sandbox.api.xrpchange.site/
sudo ls -la /etc/letsencrypt/live/api.xrpchange.site/
```

## 4. Запуск Nginx

```bash
# Перезапускаем Nginx
sudo systemctl reload nginx

# Проверяем статус
sudo systemctl status nginx
```

## 5. Настройка автоматического обновления сертификатов

### 5.1. Создание cron задачи

```bash
# Открываем crontab
sudo crontab -e

# Добавляем строку для автоматического обновления (каждый день в 2:30)
30 2 * * * /usr/bin/certbot renew --quiet && /usr/bin/systemctl reload nginx
```

### 5.2. Тестирование обновления

```bash
# Тестируем обновление сертификатов
sudo certbot renew --dry-run
```

## 6. Проверка работы

### 6.1. Проверка HTTP редиректа

```bash
# Проверяем, что HTTP редиректит на HTTPS
curl -I http://sandbox.api.xrpchange.site
curl -I http://api.xrpchange.site
```

### 6.2. Проверка HTTPS

```bash
# Проверяем HTTPS соединение
curl -I https://sandbox.api.xrpchange.site
curl -I https://api.xrpchange.site

# Проверяем API
curl https://sandbox.api.xrpchange.site/api/v1/countries
curl https://api.xrpchange.site/api/v1/countries
```

### 6.3. Проверка SSL сертификата

```bash
# Проверяем SSL сертификат
openssl s_client -connect sandbox.api.xrpchange.site:443 -servername sandbox.api.xrpchange.site
openssl s_client -connect api.xrpchange.site:443 -servername api.xrpchange.site
```

## 7. Настройка файрвола

```bash
# Разрешаем HTTP и HTTPS трафик
sudo ufw allow 'Nginx Full'
sudo ufw allow OpenSSH
sudo ufw enable

# Проверяем статус
sudo ufw status
```

## 8. Мониторинг и логи

### 8.1. Просмотр логов Nginx

```bash
# Логи доступа
sudo tail -f /var/log/nginx/access.log

# Логи ошибок
sudo tail -f /var/log/nginx/error.log
```

### 8.2. Мониторинг сертификатов

```bash
# Проверка срока действия сертификатов
sudo certbot certificates

# Проверка автоматического обновления
sudo systemctl status certbot.timer
```

## 9. Обновление конфигурации XBoard

После настройки HTTPS нужно обновить настройки в XBoard:

### 9.1. Обновление .env

```bash
# В файле .env изменить APP_URL
APP_URL=https://sandbox.api.xrpchange.site
```

### 9.2. Обновление config/services.php

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

## 10. Troubleshooting

### 10.1. Проблемы с сертификатами

```bash
# Проверка DNS записей
nslookup sandbox.api.xrpchange.site
nslookup api.xrpchange.site

# Проверка доступности доменов
ping sandbox.api.xrpchange.site
ping api.xrpchange.site
```

### 10.2. Проблемы с Nginx

```bash
# Проверка конфигурации
sudo nginx -t

# Перезапуск Nginx
sudo systemctl restart nginx

# Проверка статуса
sudo systemctl status nginx
```

### 10.3. Проблемы с проксированием

```bash
# Проверка доступности бэкенда
curl http://192.168.1.31/api/v1/countries

# Проверка логов Nginx
sudo tail -f /var/log/nginx/error.log
```

## 11. Безопасность

### 11.1. Дополнительные заголовки безопасности

В конфигурации Nginx уже добавлены:
- `Strict-Transport-Security`
- `X-Frame-Options`
- `X-Content-Type-Options`
- `X-XSS-Protection`
- `Referrer-Policy`

### 11.2. Ограничение доступа

```bash
# Ограничение по IP (если нужно)
# В location / добавить:
# allow 192.168.1.0/24;
# deny all;
```

## 12. Мониторинг производительности

### 12.1. Настройка логирования

```bash
# В /etc/nginx/nginx.conf добавить в http блок:
log_format detailed '$remote_addr - $remote_user [$time_local] '
                   '"$request" $status $body_bytes_sent '
                   '"$http_referer" "$http_user_agent" '
                   'rt=$request_time uct="$upstream_connect_time" '
                   'uht="$upstream_header_time" urt="$upstream_response_time"';

access_log /var/log/nginx/access.log detailed;
```

### 12.2. Мониторинг сертификатов

```bash
# Скрипт для проверки срока действия сертификатов
#!/bin/bash
echo "Checking SSL certificates..."
for domain in sandbox.api.xrpchange.site api.xrpchange.site; do
    expiry=$(echo | openssl s_client -servername $domain -connect $domain:443 2>/dev/null | openssl x509 -noout -dates | grep notAfter | cut -d= -f2)
    echo "$domain expires: $expiry"
done
```
