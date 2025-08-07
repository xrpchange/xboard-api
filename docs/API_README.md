# XBoard API - Краткая документация

## Обзор

XBoard API предоставляет REST API для P2P криптобиржи с залоговой гарантией. API использует Bearer-токены для аутентификации и поддерживает тестовый режим.

## Базовый URL

- **Разработка**: `http://localhost:8000/api/v1`
- **Продакшн**: `https://api.xboard.com/v1`

## Аутентификация

### Получение Bearer токена
```http
POST /auth/bearer-token
Content-Type: application/json

{
  "escrow_token": "your_escrow_token_here",
  "test_group": "optional_test_group"
}
```

### Проверка токена
```http
GET /auth/validate
Authorization: Bearer your_bearer_token_here
```

## Справочники (публичные)

### Страны
```http
GET /countries
GET /countries/{country_id}
GET /countries/{country_id}/banks
```

### Валюты
```http
GET /currencies/fiat
GET /currencies/crypto
GET /currencies/all
```

### Банки
```http
GET /banks
GET /banks/{bank_id}
GET /banks/country/{country_code}
```

## Доска объявлений (защищенные)

### Получение лотов
```http
GET /exchange-lots
Authorization: Bearer your_token
```

**Параметры:**
- `is_test` - фильтр тестового режима
- `test_group` - группа тестового режима
- `sell_currency_type` - тип продаваемой валюты (fiat/crypto)
- `sell_currency_id` - ID продаваемой валюты
- `buy_currency_type` - тип покупаемой валюты (fiat/crypto)
- `buy_currency_id` - ID покупаемой валюты

### Создание лота
```http
POST /exchange-lots
Authorization: Bearer your_token
Content-Type: application/json

{
  "sell_currency_type": "fiat",
  "sell_currency_id": 1,
  "sell_amount": 1000.00,
  "buy_currency_type": "crypto",
  "buy_currency_id": 1,
  "buy_amount": 0.5,
  "country_id": 1,
  "bank_id": 1,
  "bank_account": "1234567890",
  "payment_purpose": "Оплата услуг",
  "contract_url": "https://example.com/contract.pdf",
  "is_for_individuals": true,
  "is_for_legal_entities": false,
  "is_test": false,
  "test_group": "group1"
}
```

## Триггеры покупки (защищенные)

### Получение триггеров
```http
GET /buy-triggers
Authorization: Bearer your_token
```

### Создание триггера
```http
POST /buy-triggers
Authorization: Bearer your_token
Content-Type: application/json

{
  "sell_currency_type": "crypto",
  "sell_currency_id": 1,
  "sell_amount": 0.1,
  "buy_currency_type": "fiat",
  "buy_currency_id": 1,
  "buy_amount": 200.00,
  "country_id": 1,
  "bank_id": 1,
  "bank_account": "1234567890",
  "is_for_individuals": true,
  "is_for_legal_entities": false,
  "is_test": false,
  "test_group": "group1"
}
```

## Транзакции (защищенные)

### Получение транзакций
```http
GET /transactions
Authorization: Bearer your_token
```

### Статистика транзакций
```http
GET /transactions/stats
Authorization: Bearer your_token
```

### Создание транзакции
```http
POST /transactions
Authorization: Bearer your_token
Content-Type: application/json

{
  "exchange_lot_id": 1,
  "buy_trigger_id": null,
  "transaction_hash": "0x1234567890abcdef",
  "amount": 1000.00,
  "status": "pending",
  "is_test": false,
  "test_group": "group1"
}
```

## Тестовый режим

В тестовом режиме все запросы должны содержать параметр `test_group` для изоляции данных:

```json
{
  "is_test": true,
  "test_group": "my_test_group"
}
```

## Коды ответов

- `200` - Успешный запрос
- `201` - Ресурс создан
- `400` - Ошибка валидации
- `401` - Неавторизованный доступ
- `404` - Ресурс не найден
- `422` - Ошибка валидации данных
- `500` - Внутренняя ошибка сервера

## Примеры использования

### Получение всех валют
```bash
curl -X GET "http://localhost:8000/api/v1/currencies/all"
```

### Создание лота (с аутентификацией)
```bash
curl -X POST "http://localhost:8000/api/v1/exchange-lots" \
  -H "Authorization: Bearer your_token" \
  -H "Content-Type: application/json" \
  -d '{
    "sell_currency_type": "fiat",
    "sell_currency_id": 1,
    "sell_amount": 1000.00,
    "buy_currency_type": "crypto",
    "buy_currency_id": 1,
    "buy_amount": 0.5,
    "country_id": 1,
    "bank_id": 1,
    "bank_account": "1234567890",
    "payment_purpose": "Оплата услуг",
    "is_for_individuals": true,
    "is_for_legal_entities": false
  }'
```

## Полная документация

Для полной документации с примерами и схемами данных см. [api_swagger.yaml](api_swagger.yaml) 