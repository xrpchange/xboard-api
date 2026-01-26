# OpenAPI Documentation для xboard-api

## 📋 Обзор

xboard-api использует **l5-swagger** для автоматической генерации OpenAPI спецификации из PHP аннотаций в контроллерах.

## 📁 Файлы

- `docs/api_swagger.yaml` - Сгенерированная OpenAPI 3.0.3 спецификация
- `storage/api-docs/api-docs.json` - JSON версия (для Swagger UI)
- `storage/api-docs/api-docs.yaml` - YAML версия

## 🔄 Генерация спецификации

### Автоматическая генерация

```bash
php artisan l5-swagger:generate
```

Этот команда:
1. Сканирует все `@OA\*` аннотации в `app/Http/Controllers/`
2. Генерирует OpenAPI спецификацию
3. Сохраняет в `storage/api-docs/` и копирует в `docs/api_swagger.yaml`

### Настройка

**Файл:** `config/l5-swagger.php`

```php
'open_api_spec_version' => env('L5_SWAGGER_OPEN_API_SPEC_VERSION', '3.1.0'),
```

**Environment:**
```env
L5_SWAGGER_OPEN_API_SPEC_VERSION=3.1.0
```

## 📝 Добавление аннотаций

### Пример контроллера

```php
/**
 * @OA\Post(
 *     path="/v1/auth/validate-token",
 *     operationId="validateEscrowToken",  // ← Важно для Cursor AI
 *     summary="Validate escrow token",
 *     tags={"Authentication"},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"escrow_token"},
 *             @OA\Property(property="escrow_token", type="string")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Token validated",
 *         @OA\JsonContent(ref="#/components/schemas/TokenValidationResponse")
 *     )
 * )
 */
public function validateToken(Request $request): JsonResponse
{
    // ...
}
```

## 🎯 Best Practices

### 1. Всегда добавляйте operationId

```php
operationId="validateEscrowToken"  // camelCase, описательное имя
```

### 2. Используйте ссылки на схемы

```php
@OA\JsonContent(ref="#/components/schemas/ErrorResponse")
```

### 3. Добавляйте примеры

```php
@OA\JsonContent(
    example={"success": true, "data": {...}}
)
```

### 4. Регенерируйте после изменений

```bash
php artisan l5-swagger:generate
```

## 🔍 Просмотр документации

### Swagger UI

Откройте в браузере:
```
http://localhost:8000/api/documentation
```

### Redoc CLI

```bash
npx @redocly/cli preview-docs docs/api_swagger.yaml
```

## ✅ Валидация

```bash
npx @redocly/cli lint docs/api_swagger.yaml
```

## 🚀 Cursor AI Integration

См. [CURSOR_AI_INTEGRATION.md](./CURSOR_AI_INTEGRATION.md)

## 📚 Миграция на OpenAPI 3.1

См. [OPENAPI_3.1_MIGRATION.md](./OPENAPI_3.1_MIGRATION.md)

## 🔗 Ресурсы

- [L5-Swagger Documentation](https://github.com/DarkaOnLine/L5-Swagger)
- [OpenAPI 3.1 Specification](https://spec.openapis.org/oas/v3.1.0)
- [Swagger PHP Annotations](https://zircote.github.io/swagger-php/)
