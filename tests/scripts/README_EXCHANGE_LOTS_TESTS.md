# Exchange Lots API Tests

## Описание

Скрипт для тестирования API обменника (Exchange Lots) с поддержкой фильтрации по валютам и Bearer token аутентификации.

## Использование

```bash
cd xboard-api
php tests/scripts/test_exchange_lots.php "<escrow_token>"
```

### Пример

```bash
php tests/scripts/test_exchange_lots.php '{"version":"1.0","wallet_address":"rBsk5RSxdK3ZVDNCqZTMvCSzkHAgbvug3","currency":"XRP","environment":"sandbox","node_domain":"xdexboard.com","token_hash":"cbdd419f4b7fded3b03a6b53e84afd1dba99106ea3a1fac2e62d3f838412e001"}'
```

## Что тестируется

1. **Получение Bearer токена** - обмен Escrow токена на Bearer токен
2. **Получение всех лотов** - запрос всех доступных лотов обмена
3. **Фильтрация по from_currency** - фильтрация лотов по валюте отправителя
4. **Фильтрация по to_currency** - фильтрация лотов по валюте получателя
5. **Комбинированная фильтрация** - фильтрация по обеим валютам одновременно
6. **Несуществующая валюта** - проверка обработки несуществующей валюты
7. **Без аутентификации** - проверка защиты эндпоинта (должен вернуть 401)

## Ожидаемые результаты

- ✅ Bearer token успешно получен
- ✅ Все лоты успешно загружены
- ✅ Фильтрация работает корректно
- ✅ Все отфильтрованные лоты соответствуют критериям
- ✅ Запрос без токена отклоняется (401)

## Формат ответа API

API возвращает лоты в следующем формате:

```json
{
  "success": true,
  "data": [
    {
      "id": "1",
      "node_id": "1",
      "from_currency": "BTC",
      "to_currency": "USD",
      "amount": 1.5,
      "rate": 45000.00,
      "min_amount": 0.15,
      "max_amount": 1.5,
      "status": "active",
      "created_at": "2025-10-07T10:00:00Z",
      "expires_at": "2025-10-08T10:00:00Z",
      "wallet_address": "bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh"
    }
  ]
}
```

## Параметры фильтрации

- `from_currency` - символ валюты отправителя (например, "BTC", "USD")
- `to_currency` - символ валюты получателя (например, "BTC", "USD")

Примеры запросов:

```bash
# Все лоты
GET /api/v1/exchange-lots

# Лоты с BTC
GET /api/v1/exchange-lots?from_currency=BTC

# Лоты в USD
GET /api/v1/exchange-lots?to_currency=USD

# Лоты BTC -> USD
GET /api/v1/exchange-lots?from_currency=BTC&to_currency=USD
```

## Примечания

- Скрипт использует sandbox API: `https://sandbox.api.xdexboard.com/api/v1`
- Для работы требуется валидный Escrow токен
- Bearer токен автоматически получается из Escrow токена
- Все запросы к `/exchange-lots` требуют Bearer token аутентификации

