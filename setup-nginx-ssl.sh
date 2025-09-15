#!/bin/bash

# Скрипт автоматической настройки Nginx с SSL для XBoard
# Запуск: sudo bash setup-nginx-ssl.sh

set -e

echo "🚀 Настройка Nginx с SSL сертификатами для XBoard..."

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Функция для вывода сообщений
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}"
}

warn() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING: $1${NC}"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR: $1${NC}"
    exit 1
}

# Проверка, что скрипт запущен от root
if [ "$EUID" -ne 0 ]; then
    error "Пожалуйста, запустите скрипт с правами root: sudo bash setup-nginx-ssl.sh"
fi

# 1. Обновление системы
log "Обновление системы..."
apt update && apt upgrade -y

# 2. Установка Nginx
log "Установка Nginx..."
apt install nginx -y

# 3. Установка Certbot
log "Установка Certbot..."
apt install certbot python3-certbot-nginx -y

# 4. Создание директории для Certbot
log "Создание директории для ACME challenge..."
mkdir -p /var/www/certbot
chown -R www-data:www-data /var/www/certbot

# 5. Копирование конфигурации Nginx
log "Настройка конфигурации Nginx..."
if [ -f "nginx-xboard.conf" ]; then
    cp nginx-xboard.conf /etc/nginx/sites-available/xboard
    ln -sf /etc/nginx/sites-available/xboard /etc/nginx/sites-enabled/
    rm -f /etc/nginx/sites-enabled/default
else
    error "Файл nginx-xboard.conf не найден!"
fi

# 6. Проверка конфигурации Nginx
log "Проверка конфигурации Nginx..."
if nginx -t; then
    log "Конфигурация Nginx корректна"
else
    error "Ошибка в конфигурации Nginx!"
fi

# 7. Запуск Nginx
log "Запуск Nginx..."
systemctl start nginx
systemctl enable nginx

# 8. Настройка файрвола
log "Настройка файрвола..."
ufw allow 'Nginx Full'
ufw allow OpenSSH
ufw --force enable

# 9. Получение SSL сертификатов
log "Получение SSL сертификатов..."
read -p "Введите email для Let's Encrypt: " email

if [ -z "$email" ]; then
    error "Email не может быть пустым!"
fi

log "Получение сертификатов для sandbox.api.xrpchange.site и api.xrpchange.site..."
certbot certonly --webroot \
    -w /var/www/certbot \
    -d sandbox.api.xrpchange.site \
    -d api.xrpchange.site \
    --email "$email" \
    --agree-tos \
    --non-interactive

# 10. Проверка сертификатов
log "Проверка созданных сертификатов..."
if [ -f "/etc/letsencrypt/live/sandbox.api.xrpchange.site/fullchain.pem" ] && \
   [ -f "/etc/letsencrypt/live/api.xrpchange.site/fullchain.pem" ]; then
    log "SSL сертификаты успешно созданы"
else
    error "Ошибка создания SSL сертификатов!"
fi

# 11. Перезапуск Nginx с SSL
log "Перезапуск Nginx с SSL конфигурацией..."
systemctl reload nginx

# 12. Настройка автоматического обновления сертификатов
log "Настройка автоматического обновления сертификатов..."
(crontab -l 2>/dev/null; echo "30 2 * * * /usr/bin/certbot renew --quiet && /usr/bin/systemctl reload nginx") | crontab -

# 13. Тестирование обновления сертификатов
log "Тестирование автоматического обновления сертификатов..."
certbot renew --dry-run

# 14. Проверка работы
log "Проверка работы сервисов..."
if systemctl is-active --quiet nginx; then
    log "Nginx работает"
else
    error "Nginx не запущен!"
fi

# 15. Финальная проверка
log "Финальная проверка..."
echo "Проверка HTTP редиректа:"
curl -I http://sandbox.api.xrpchange.site 2>/dev/null | head -1 || warn "Не удалось проверить HTTP редирект"
curl -I http://api.xrpchange.site 2>/dev/null | head -1 || warn "Не удалось проверить HTTP редирект"

echo "Проверка HTTPS:"
curl -I https://sandbox.api.xrpchange.site 2>/dev/null | head -1 || warn "Не удалось проверить HTTPS"
curl -I https://api.xrpchange.site 2>/dev/null | head -1 || warn "Не удалось проверить HTTPS"

# 16. Информация о сертификатах
log "Информация о сертификатах:"
certbot certificates

# 17. Завершение
log "✅ Настройка завершена!"
echo ""
echo "📋 Что было сделано:"
echo "  - Установлен и настроен Nginx"
echo "  - Установлен Certbot"
echo "  - Получены SSL сертификаты для обоих доменов"
echo "  - Настроен автоматический редирект HTTP → HTTPS"
echo "  - Настроено автоматическое обновление сертификатов"
echo "  - Настроен файрвол"
echo ""
echo "🔗 Доступные URL:"
echo "  - https://sandbox.api.xrpchange.site"
echo "  - https://api.xrpchange.site"
echo ""
echo "📊 Мониторинг:"
echo "  - Логи Nginx: /var/log/nginx/"
echo "  - Статус Nginx: systemctl status nginx"
echo "  - Статус сертификатов: certbot certificates"
echo ""
echo "⚠️  Не забудьте:"
echo "  - Обновить APP_URL в .env файле XBoard на https://sandbox.api.xrpchange.site"
echo "  - Обновить URL в config/services.php на https://"
echo "  - Проверить работу API после настройки"
