# Endpoint создания лота обмена (POST /v1/exchange-lots)

Документ описывает, что API ожидает от приложения при сохранении (создании) лота обмена.

---

## Метод и авторизация

- **Метод:** `POST`
- **Путь:** `/api/v1/exchange-lots` (или `{baseUrl}/v1/exchange-lots` при обращении к узлу)
- **Авторизация:** Bearer-токен и при обращении к API узла — заголовки с эскроу-токеном (см. [ESCROW_TOKEN_AUTHENTICATION.md](ESCROW_TOKEN_AUTHENTICATION.md)).
- **Content-Type:** `application/json`

---

## Тело запроса (JSON)

Приложение должно отправить JSON со следующими полями.

### Обязательные поля

| Поле | Тип | Правила | Описание |
|------|-----|--------|----------|
| `node_id` | integer | обязательно, должен существовать в таблице `nodes` | ID узла, к которому привязан лот. Без него API не знает, куда сохранять лот; отправка в «узел по умолчанию» не предусмотрена. |
| `from_currency` | string | обязательно, до 20 символов | Код валюты, которую отдаём (например `USD`, `XRP`). API приводит к верхнему регистру. |
| `to_currency` | string | обязательно, до 20 символов | Код валюты, которую получаем (например `XRP`, `BTC`). API приводит к верхнему регистру. |
| `sell_amount` | number | обязательно, ≥ 0 | Сумма, которую отдаём (одним числом). |
| `buy_amounts` | array of number | обязательно, массив чисел ≥ 0 | Суммы, которые хотим получить (продажа частями). Должен содержать хотя бы одно положительное значение. Элементы приводятся к float; нули отбрасываются. |
| `crypto_currency_id` | integer | обязательно, должен существовать в `crypto_currencies` | ID криптовалюты лота из справочника API (например из `GET /v1/currencies` / типа crypto). |
| `wallet_address` | string | обязательно | Полная открытая часть (public part) эскроу-токена в виде JSON. Должна содержать поле `token_hash`. В `wallet_hash` сохраняется значение `token_hash` из public part; поиск лотов — по этим хэшам. |

### Необязательные поля

| Поле | Тип | Правила | Описание |
|------|-----|--------|----------|
| `bank_id` | integer | nullable, должен существовать в `banks` | ID банка (для фиатных операций). |
| `bank_account` | string | nullable, до 100 символов | Номер банковского счёта. |
| `payment_purpose` | string | nullable, до 255 символов | Назначение платежа. |
| `contract_url` | string | nullable, URL, до 255 символов | Ссылка на договор. |
| `is_for_individuals` | boolean | — | Доступно для физлиц (по умолчанию может быть true). |
| `is_for_legal_entities` | boolean | — | Доступно для юрлиц. |
| `is_test` | boolean | — | Тестовый лот. |
| `test_group` | string | nullable, до 128 символов | Группа тестового режима (sandbox). Клиент передаёт текст; API сохраняет SHA256-хэш (64 hex). При отсутствии — null. |
| `main_group` | string | nullable, до 128 символов | Группа production. Клиент передаёт текст; API сохраняет SHA256-хэш (64 hex). При отсутствии — null. |
| `expires_at` | string | nullable, формат даты/date-time | Срок действия лота. |

---

## Пример тела запроса

```json
{
  "node_id": 1,
  "from_currency": "USD",
  "to_currency": "XMR",
  "sell_amount": 1000,
  "buy_amounts": [0.5, 0.3, 0.2],
  "crypto_currency_id": 2,
  "wallet_address": "{\"version\":1,\"wallet_address\":\"4AdkPJoxn7JCvAqX1K...\",\"currency\":\"XMR\",\"token_hash\":\"abc123...\"}",
  "bank_id": 1,
  "payment_purpose": "P2P exchange",
  "is_for_individuals": true,
  "is_for_legal_entities": false
}
```

---

## Ответы

- **201 Created** — лот создан. В теле возвращается объект лота (например через `ExchangeLotResource`).
- **401 Unauthorized** — не передан или неверный токен.
- **422 Unprocessable Entity** — ошибка валидации; в теле приходят `success: false` и объект `errors` с полями и сообщениями (например `node_id`, `buy_amounts`).

Дополнительная проверка на стороне API: если после фильтрации в `buy_amounts` не осталось ни одного положительного числа, возвращается 422 с ошибкой по полю `buy_amounts` («At least one positive amount is required.»).

---

## Кратко для приложения

1. Всегда передавать **`node_id`** — ID выбранного пользователем узла.
2. Валюты — кодами в **`from_currency`** и **`to_currency`** (строки).
3. **`sell_amount`** — одно число (сколько отдаём).
4. **`buy_amounts`** — JSON-массив чисел (сколько хотим получить по частям); минимум одно число > 0.
5. **`crypto_currency_id`** — из Core; **`wallet_address`** — полная открытая часть (public part) эскроу-токена в виде JSON. При поиске лотов: параметр `wallet_address` (или `wallet_address[]`) — один или несколько public part; API извлекает `token_hash` из каждого и фильтрует по `wallet_hash IN (...)` (в колонке хранится `token_hash`).

См. также: [NODES_API_DOCUMENTATION.md](NODES_API_DOCUMENTATION.md), [ESCROW_TOKEN_AUTHENTICATION.md](ESCROW_TOKEN_AUTHENTICATION.md).
