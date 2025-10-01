# 🔐 Система прав доступа к точкам входа узлов

## 🎯 Проблема

**Dev API** (`dev.api.xrpchange.site`) не должен быть доступен обычным пользователям - только разработчикам.

## 💡 Решение

### Уровни доступа к точкам входа:

| Роль | Описание | Доступ |
|------|----------|--------|
| `public` | Обычные пользователи | Production API, Sandbox API, Core API |
| `developer` | Разработчики | + Dev API |
| `admin` | Администраторы | Все endpoints |

## 📊 Структура прав

### В таблице `nodes`:

Поле `endpoint_permissions` содержит права для каждой точки входа:

```json
{
  "endpoint_permissions": {
    "production_api": "public",      // Доступен всем
    "sandbox_api": "public",         // Доступен всем
    "dev_api": "developer",          // Только разработчики и админы
    "production_core": "public",     // Доступен всем
    "sandbox_core": "public"         // Доступен всем
  }
}
```

## 🔑 Определение роли пользователя

### Вариант 1: Через Bearer Token (рекомендуется)

При создании bearer token в таблице `user_tokens` добавить поле `role`:

```sql
ALTER TABLE `user_tokens` ADD COLUMN `role` ENUM('public', 'developer', 'admin') DEFAULT 'public';
```

При валидации токена в middleware определяется роль:

```php
$userToken = UserToken::where('token', $bearerToken)->first();
$userRole = $userToken->role ?? 'public';
```

### Вариант 2: Через заголовок (временно для разработки)

```http
GET /api/v1/nodes
X-User-Role: developer
```

### Вариант 3: Через специальный escrow token

При создании escrow token с `special_conditions = true` пользователь автоматически получает роль `developer`.

## 🛡️ Реализация на сервере

### В NodeController:

```php
public function index(Request $request): JsonResponse
{
    $nodes = Node::getActive();
    
    // Определяем роль пользователя
    $userRole = $this->getUserRole($request); // 'public', 'developer', или 'admin'
    
    // Фильтруем endpoints по правам доступа
    $filteredNodes = $nodes->map(function ($node) use ($userRole) {
        $nodeArray = $node->toArray();
        $nodeArray['endpoints'] = $node->getAccessibleEndpoints($userRole);
        return $nodeArray;
    });
    
    return $this->successResponse($filteredNodes);
}

private function getUserRole(Request $request): string
{
    // Способ 1: Из bearer token
    $bearerToken = $request->bearerToken();
    if ($bearerToken) {
        $userToken = UserToken::where('token', $bearerToken)->first();
        return $userToken->role ?? 'public';
    }
    
    // Способ 2: Из заголовка (для разработки)
    return $request->header('X-User-Role', 'public');
}
```

### В модели Node:

```php
public function getAccessibleEndpoints(string $userRole = 'public'): array
{
    $accessible = [];
    
    foreach ($this->endpoints as $key => $url) {
        $permission = $this->endpoint_permissions[$key] ?? 'public';
        
        // Админы видят всё
        if ($userRole === 'admin') {
            $accessible[$key] = $url;
            continue;
        }
        
        // Разработчики видят public + developer endpoints
        if ($userRole === 'developer' && in_array($permission, ['public', 'developer'])) {
            $accessible[$key] = $url;
            continue;
        }
        
        // Обычные пользователи видят только public
        if ($permission === 'public') {
            $accessible[$key] = $url;
        }
    }
    
    return $accessible;
}
```

## 📱 На клиенте

### Обычный пользователь получит:

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

### Разработчик получит:

```json
{
  "name": "XRPChange",
  "endpoints": {
    "production_api": "https://api.xrpchange.site",
    "sandbox_api": "https://sandbox.api.xrpchange.site",
    "dev_api": "https://dev.api.xrpchange.site",  ← Дополнительно!
    "production_core": "https://api.core.xrpchange.site",
    "sandbox_core": "https://sandbox.api.core.xrpchange.site"
  }
}
```

## 🔧 Миграция для ролей пользователей

```php
// database/migrations/2025_10_01_000002_add_role_to_user_tokens.php

public function up(): void
{
    Schema::table('user_tokens', function (Blueprint $table) {
        $table->enum('role', ['public', 'developer', 'admin'])
              ->default('public')
              ->after('collateral_token');
    });
}
```

## 🚀 Использование

### Для обычного пользователя:
- Создает escrow token с дефолтными настройками
- Получает bearer token с ролью `public`
- Видит только публичные endpoints

### Для разработчика:
- Создает escrow token с `special_conditions = true` (через админ панель)
- Получает bearer token с ролью `developer`
- Видит dev endpoints

### Для администратора:
- Создает специальный escrow token
- Получает bearer token с ролью `admin`
- Видит все endpoints включая закрытые

## 🔒 Безопасность

1. **По умолчанию** все пользователи = `public`
2. **Роль developer** выдается только через админ панель
3. **Dev endpoints** скрыты от обычных клиентов
4. **Фильтрация** происходит на сервере, клиент не может обойти
5. **Логирование** всех запросов к dev endpoints

---

**Результат:** Dev API защищен, обычные пользователи его не видят!






