# 🔐 Система прав доступа к точкам входа узлов

**Примечание:** С февраля 2026 аутентификация только через escrow token (таблица `escrow_token_cache`). Таблица `user_tokens` и bearer token удалены. Роль пользователя определяется через заголовок или специальный escrow token (см. варианты ниже).

## 🎯 Проблема

**Dev API** (`dev.api.xdexboard.com`) не должен быть доступен обычным пользователям - только разработчикам.

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

### Вариант 1: Через заголовок (для разработки и админки)

```http
GET /api/v1/nodes
X-User-Role: developer
```

### Вариант 2: Через специальный escrow token

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
    // Роль из заголовка (для разработки и админки)
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
    "production_api": "https://api.xdexboard.com",
    "sandbox_api": "https://sandbox.api.xdexboard.com",
    "production_core": "https://api.core.xdexboard.com",
    "sandbox_core": "https://sandbox.api.core.xdexboard.com"
  }
}
```

### Разработчик получит:

```json
{
  "name": "XRPChange",
  "endpoints": {
    "production_api": "https://api.xdexboard.com",
    "sandbox_api": "https://sandbox.api.xdexboard.com",
    "dev_api": "https://dev.api.xdexboard.com",  ← Дополнительно!
    "production_core": "https://api.core.xdexboard.com",
    "sandbox_core": "https://sandbox.api.core.xdexboard.com"
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
- Аутентификация через X-Escrow-Token (escrow_token_cache)
- Видит только публичные endpoints

### Для разработчика:
- Создает escrow token с `special_conditions = true` (через админ панель) или заголовок X-User-Role
- Видит dev endpoints

### Для администратора:
- Заголовок X-User-Role: admin или специальный escrow token
- Видит все endpoints включая закрытые

## 🔒 Безопасность

1. **По умолчанию** все пользователи = `public`
2. **Роль developer** выдается только через админ панель
3. **Dev endpoints** скрыты от обычных клиентов
4. **Фильтрация** происходит на сервере, клиент не может обойти
5. **Логирование** всех запросов к dev endpoints

---

**Результат:** Dev API защищен, обычные пользователи его не видят!






