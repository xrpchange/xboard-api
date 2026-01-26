# OpenAPI 3.1 Migration для xboard-api

## 📋 Текущее состояние

- ✅ Использует `l5-swagger` для генерации OpenAPI из аннотаций
- ✅ OpenAPI 3.0.3 (нужно обновить до 3.1)
- ⚠️ Не все методы имеют `operationId`
- ⚠️ Некоторые схемы inline (нужно вынести в components)

## 🎯 Цель миграции

1. Обновить до OpenAPI 3.1
2. Добавить `operationId` ко всем методам
3. Вынести схемы в `components/schemas`
4. Добавить примеры (examples) в responses
5. Настроить MCP-сервер для Cursor AI

## 🔄 План миграции

### Шаг 1: Обновить конфигурацию l5-swagger

**Файл:** `config/l5-swagger.php`

```php
'open_api_spec_version' => env('L5_SWAGGER_OPEN_API_SPEC_VERSION', '3.1.0'),
```

Или через `.env`:
```env
L5_SWAGGER_OPEN_API_SPEC_VERSION=3.1.0
```

### Шаг 2: Добавить operationId ко всем методам

**Пример для AuthController:**

```php
/**
 * @OA\Post(
 *     path="/v1/auth/validate-token",
 *     operationId="validateEscrowToken",  // ← Добавить это
 *     summary="Validate escrow token",
 *     tags={"Authentication"},
 *     ...
 * )
 */
public function validateToken(Request $request): JsonResponse
```

### Шаг 3: Вынести схемы в components

**Создать файл:** `app/Http/Controllers/Api/OpenApiSchemas.php`

```php
/**
 * @OA\Schema(
 *     schema="ErrorResponse",
 *     type="object",
 *     required={"message", "error_code"},
 *     @OA\Property(property="message", type="string"),
 *     @OA\Property(property="error_code", type="string")
 * )
 */
```

**Использовать в контроллерах:**

```php
/**
 * @OA\Response(
 *     response=400,
 *     description="Validation error",
 *     @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
 * )
 */
```

### Шаг 4: Добавить examples

```php
/**
 * @OA\Response(
 *     response=200,
 *     description="Success",
 *     @OA\JsonContent(
 *         example={
 *             "success": true,
 *             "version": "1.0",
 *             "data": {...}
 *         }
 *     )
 * )
 */
```

### Шаг 5: Регенерировать спецификацию

```bash
php artisan l5-swagger:generate
```

## 📝 Контроллеры для обновления

- [ ] `AuthController.php` - добавить operationId
- [ ] `CountryController.php` - добавить operationId
- [ ] `CurrencyController.php` - добавить operationId
- [ ] `BankController.php` - добавить operationId
- [ ] `NodeController.php` - добавить operationId
- [ ] `EscrowTokenController.php` - добавить operationId
- [ ] `ExchangeLotController.php` - добавить operationId
- [ ] `BuyTriggerController.php` - добавить operationId
- [ ] `TransactionController.php` - добавить operationId
- [ ] `PaymentVerificationController.php` - добавить operationId
- [ ] `EmailProviderController.php` - добавить operationId

## 🔧 Инструменты

### Валидация спецификации

```bash
npx @redocly/cli lint docs/api_swagger.yaml
```

### Предпросмотр документации

```bash
npx @redocly/cli preview-docs docs/api_swagger.yaml
```

### MCP-сервер

```bash
npx @modelcontextprotocol/server-openapi --dir ./docs --port 8766
```

## 📊 Статус

- [ ] Конфигурация обновлена до 3.1
- [ ] Все operationId добавлены
- [ ] Схемы вынесены в components
- [ ] Examples добавлены
- [ ] Спецификация регенерирована
- [ ] Валидация пройдена
- [ ] MCP-сервер настроен

## 🎁 Преимущества после миграции

✅ Cursor AI будет понимать все endpoints  
✅ Автодополнение по operationId  
✅ Генерация типизированного кода  
✅ Валидация контрактов  
✅ Единая спецификация для всего workspace
