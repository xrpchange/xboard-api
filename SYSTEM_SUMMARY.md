# 📋 Резюме системы XBoard - Узлы и права доступа

## 🎯 Ключевые концепции

### 1. **Узел (Node) = Доска объявлений P2P обменов**
- Сейчас: **1 узел** (XRPChange)
- Будущее: **Множество узлов** от разных провайдеров
- Клиент работает со **всеми узлами** одновременно

### 2. **Точки входа (Endpoints) = API URLs одного узла**
Узел XRPChange имеет 5 точек входа:
- `api.xdexboard.com` - production API
- `sandbox.api.xdexboard.com` - sandbox API  
- `dev.api.xdexboard.com` - dev API
- `api.core.xdexboard.com` - production Core
- `sandbox.api.core.xdexboard.com` - sandbox Core

**ВСЕ это части ОДНОГО узла!**

### 3. **Роли пользователей**
- `public` - обычные пользователи
- `developer` - видят dev endpoints
- `admin` - полный доступ + управление узлами

## 📊 Структура БД

### Таблица `nodes`:
```sql
- name, domain, api_url
- endpoints (JSON) - ВСЕ точки входа узла
- endpoint_permissions (JSON) - права для каждой точки
- is_required, is_default, is_active
- priority, description, features
```

### Таблица `user_tokens`:
```sql
- token, device_id, collateral_token
- role (public/developer/admin) ← НОВОЕ ПОЛЕ
- ip_address, expires_at
```

## 🚀 API Endpoints

### Публичные (без auth):
- `GET /api/v1/nodes` - список узлов (endpoints фильтруются по роли)
- `GET /api/v1/nodes/required` - обязательные узлы
- `GET /api/v1/nodes/{id}` - конкретный узел

### Admin (требуют role=admin):
- `POST /api/v1/admin/nodes` - создать узел
- `PUT /api/v1/admin/nodes/{id}` - обновить узел
- `DELETE /api/v1/admin/nodes/{id}` - удалить узел
- `PUT /api/v1/admin/nodes/{id}/endpoints` - обновить endpoints

## 🔐 Безопасность

### Dev API защищен:
1. В `endpoint_permissions`: `"dev_api": "developer"`
2. Обычные пользователи не видят dev_api в ответе API
3. Только developer/admin видят dev endpoints

### Escrow tokens:
- `gift_amount` и `special_conditions` - НЕОБЯЗАТЕЛЬНЫЕ параметры
- Обычные клиенты создают без них (дефолт: 0.0 и false)
- Админы могут указать через админ панель

## 📱 Работа клиента

### При запуске:
1. Загрузить узлы: `GET /api/v1/nodes`
2. Получить только доступные endpoints (по роли)
3. Подключить обязательные узлы
4. Показать опциональные для выбора

### Текущий пользователь видит:
```json
{
  "name": "XRPChange",
  "endpoints": {
    "production_api": "https://api.xdexboard.com",
    "sandbox_api": "https://sandbox.api.xdexboard.com",
    "production_core": "https://api.core.xdexboard.com",
    "sandbox_core": "https://sandbox.api.core.xdexboard.com"
  }
}
```
**dev_api скрыт!**

## 🛠️ Миграции

```bash
# В xboard-api:
php artisan migrate
php artisan db:seed --class=NodeSeeder

# Создать первого админа:
UPDATE user_tokens SET role = 'admin' WHERE token = '<token>';
```

## 📚 Документация

- `NODES_ARCHITECTURE.md` - архитектура узлов
- `NODES_CONCEPT.md` - концепция (аналогия с почтой)
- `ENDPOINT_PERMISSIONS.md` - система прав доступа
- `ADMIN_API.md` - админские endpoints

---

**Готово к использованию!** 🎯






