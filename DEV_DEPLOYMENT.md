# 🚀 Развертывание XBoard API на dev.api.xdexboard.com

## 📋 Проблема
- ✅ Сервер dev.api.xdexboard.com работает (возвращает 200)
- ❌ API эндпоинты не настроены (все возвращают 404)
- ❌ Laravel API не развернут или неправильно настроен

## 🔧 Решение

### 1. Настройка Nginx для dev.api.xdexboard.com

```bash
# 1. Копируем конфигурацию на сервер
sudo cp nginx-dev.conf /etc/nginx/sites-available/xboard-dev

# 2. Создаем символическую ссылку
sudo ln -s /etc/nginx/sites-available/xboard-dev /etc/nginx/sites-enabled/

# 3. Проверяем конфигурацию
sudo nginx -t

# 4. Перезапускаем Nginx
sudo systemctl reload nginx
```

### 2. Получение SSL сертификата для dev.api.xdexboard.com

```bash
# Создаем директорию для ACME challenge
sudo mkdir -p /var/www/certbot
sudo chown -R www-data:www-data /var/www/certbot

# Получаем SSL сертификат
sudo certbot certonly --webroot \
  -w /var/www/certbot \
  -d dev.api.xdexboard.com \
  --email admin@xdexboard.com \
  --agree-tos \
  --non-interactive
```

### 3. Проверка работы

```bash
# Проверяем HTTP редирект
curl -I http://dev.api.xdexboard.com

# Проверяем HTTPS
curl -I https://dev.api.xdexboard.com

# Проверяем API
curl https://dev.api.xdexboard.com/api/v1/countries
```

## 🔍 Диагностика

### Проверка текущего состояния

```bash
# Проверяем, что слушает nginx
sudo netstat -tlnp | grep nginx

# Проверяем конфигурацию
sudo nginx -t

# Проверяем логи
sudo tail -f /var/log/nginx/error.log
```

### Проверка доступности бэкенда

```bash
# Проверяем, что XBoard приложение доступно
curl http://192.168.1.31:80/api/v1/countries

# Проверяем статус Laravel
curl http://192.168.1.31:80/up
```

## 📝 Примечания

- Dev сервер должен проксировать запросы на тот же бэкенд (192.168.1.31:80)
- SSL сертификат будет создан для dev.api.xdexboard.com
- Конфигурация аналогична sandbox и production, но для dev домена
- После настройки все API эндпоинты должны работать через HTTPS

## 🚨 Troubleshooting

Если API все еще возвращает 404:

1. **Проверьте, что Laravel приложение запущено на 192.168.1.31:80**
2. **Проверьте, что nginx проксирует запросы правильно**
3. **Проверьте логи nginx: `sudo tail -f /var/log/nginx/error.log`**
4. **Проверьте, что SSL сертификат создан: `sudo certbot certificates`**
