# 👑 Admin API Documentation

## 🔐 Аутентификация

Все admin endpoints требуют:
1. **Bearer Token** с ролью `admin`
2. Header: `Authorization: Bearer <admin_token>`

## 📋 Admin Endpoints для управления узлами

### **GET /api/v1/admin/nodes**
Получить все узлы (включая неактивные)

**Response:**
```json
{
  "success": true,
  "data": [...]
}
```

---

### **POST /api/v1/admin/nodes**
Создать новый узел

**Request:**
```json
{
  "name": "AnotherExchange",
  "domain": "anotherexchange.com",
  "api_url": "https://api.anotherexchange.com",
  "country_code": "USA",
  "owner": "Another Exchange Inc.",
  "is_default": false,
  "is_required": false,
  "priority": 50,
  "description": "Another P2P exchange node",
  "endpoints": {
    "production_api": "https://api.anotherexchange.com",
    "sandbox_api": "https://sandbox.anotherexchange.com"
  },
  "endpoint_permissions": {
    "production_api": "public",
    "sandbox_api": "public"
  },
  "features": ["escrow_tokens", "exchange_lots"]
}
```

---

### **PUT /api/v1/admin/nodes/{id}**
Обновить узел

**Request:**
```json
{
  "name": "Updated Name",
  "is_active": true,
  "priority": 100
}
```

---

### **DELETE /api/v1/admin/nodes/{id}**
Удалить узел

**Note:** Нельзя удалить обязательный узел (`is_required = true`)

---

### **PUT /api/v1/admin/nodes/{id}/toggle**
Переключить активность узла

---

### **POST /api/v1/admin/nodes/{id}/set-default**
Установить узел как default

---

### **PUT /api/v1/admin/nodes/{id}/endpoints**
Обновить точки входа узла и их права доступа

**Request:**
```json
{
  "endpoints": {
    "production_api": "https://api.example.com",
    "sandbox_api": "https://sandbox.api.example.com",
    "dev_api": "https://dev.api.example.com"
  },
  "endpoint_permissions": {
    "production_api": "public",
    "sandbox_api": "public",
    "dev_api": "developer"
  }
}
```

## 🔑 Роли пользователей

### **public** (по умолчанию)
- Видит только публичные endpoints
- Не может управлять узлами
- Базовая функциональность

### **developer**
- Видит публичные + dev endpoints
- Не может управлять узлами
- Доступ к dev окружению

### **admin**
- Видит все endpoints
- Может управлять узлами
- Полный доступ к системе

## 🛡️ Как назначить роль пользователю

### Вариант 1: При создании escrow token (для админов)

Админ создает escrow token с специальными параметрами:

```bash
curl -X POST https://api.core.xrpchange.site/api/escrow-tokens/create \
  -H "Content-Type: application/json" \
  -d '{
    "crypto_type": "xrp",
    "gift_amount": 100.0,
    "special_conditions": true
  }'
```

Затем в `CollateralTokenService` при создании bearer token:

```php
// Определяем роль по special_conditions
$role = $specialConditions ? 'developer' : 'public';

UserToken::create([
    'token' => $bearerToken,
    'device_id' => $walletAddress,
    'collateral_token' => $collateralToken,
    'role' => $role,  // ← Устанавливаем роль
    'ip_address' => $ipAddress,
    'expires_at' => now()->addYear()
]);
```

### Вариант 2: Ручное назначение в БД

```sql
UPDATE user_tokens 
SET role = 'admin' 
WHERE token = 'specific_admin_token';
```

### Вариант 3: Через админ панель (будущее)

В будущем приложении XBoard Admin будет UI для управления ролями.

## 📱 Примеры использования

### Обычный пользователь:

**Request:**
```bash
curl https://dev.api.xrpchange.site/api/v1/nodes \
  -H "Authorization: Bearer <public_token>"
```

**Response:** (dev_api скрыт)
```json
{
  "name": "XRPChange",
  "endpoints": {
    "production_api": "https://api.xrpchange.site",
    "sandbox_api": "https://sandbox.api.xrpchange.site",
    "production_core": "https://api.core.xrpchange.site",
    "sandbox_core": "https://sandbox.api.core.xrpchange.site"
  }
}
```

### Разработчик:

**Request:**
```bash
curl https://dev.api.xrpchange.site/api/v1/nodes \
  -H "Authorization: Bearer <developer_token>"
```

**Response:** (dev_api виден)
```json
{
  "name": "XRPChange",
  "endpoints": {
    "production_api": "https://api.xrpchange.site",
    "sandbox_api": "https://sandbox.api.xrpchange.site",
    "dev_api": "https://dev.api.xrpchange.site",  ← Доступен!
    "production_core": "https://api.core.xrpchange.site",
    "sandbox_core": "https://sandbox.api.core.xrpchange.site"
  }
}
```

## 🚀 Деплой

### 1. Запустить миграции:
```bash
php artisan migrate
```

### 2. Заполнить узлы:
```bash
php artisan db:seed --class=NodeSeeder
```

### 3. Создать первого админа:
```sql
-- Создать escrow token для админа через Core API
-- Получить bearer token
-- Обновить роль в БД
UPDATE user_tokens SET role = 'admin' WHERE token = '<admin_bearer_token>';
```

---

**Результат:** Dev API защищен и доступен только разработчикам и админам!






