# Test send to buyer — параметры из логов Laravel

Два тестовых эндпоинта (доступны только при `APP_ENV != production`):

| Эндпоинт | Метод | Параметры | Откуда брать |
|----------|--------|-----------|--------------|
| `/api/v1/test/send-to-buyer` | POST | `X-Escrow-Token` (заголовок), `message`, `lot_id` (опц.) | Токен **в логах не пишется** (только превью `token_hash`, длина). Нужен с клиента покупателя. |
| `/api/v1/test/send-to-buyer-by-email` | POST | `buyer_email`, `message`, `lot_id` (опц.) в body | **Все параметры есть в логах** (см. ниже). |

---

## Что искать в `storage/logs/laravel.log`

### 1. `buyer_lot_email` и `lot_id` (для send-to-buyer-by-email)

При входе покупателя в сделку (start deal) пишется:

```
[exchange-lots] sendMessageToSeller: buyer_lot_email set
```

**Ключи в контексте лога:** `lot_id`, `buyer_lot_email`.

Пример (значения в JSON-контексте строки лога):

- `lot_id` — ID лота (число).
- `buyer_lot_email` — ящик покупателя, например `usdt_tdjputgwmqx6mcfd5ax9yerzkyca2hpltm@xdexboard.com`.

**Поиск в файле:**

```bash
grep "buyer_lot_email set" storage/logs/laravel.log
```

или по контексту:

```bash
grep "sendMessageToSeller" storage/logs/laravel.log | grep "buyer_lot_email"
```

Из найденной строки достаёте `buyer_lot_email` и при необходимости `lot_id`.

### 2. Другие полезные записи (без полного токена)

| Строка в логе | Что даёт |
|---------------|----------|
| `sendMessageToSeller: attempt` | `lot_id`, `seller_email`, `currency`, `wallet_address` (лот) |
| `sendMessageToSeller: buyer_lot_email set` | **`lot_id`, `buyer_lot_email`** — достаточно для send-to-buyer-by-email |
| `sendMessageToBuyer: start` | `lot_id`, данные по лоту (без токена) |
| `ValidateEscrowToken middleware: Token found` | только превью `token_hash` (первые 16 символов) и `token_length` — **полный X-Escrow-Token в логах не пишется** |

Полный JSON токена покупателя (X-Escrow-Token) в логах **не логируется** по соображениям безопасности. Для эндпоинта с токеном его нужно брать с клиента (устройство покупателя).

---

## Примеры вызова

### По email (все параметры из логов)

```bash
# buyer_email и lot_id из строки "buyer_lot_email set" в laravel.log
curl -X POST "https://sandbox.api.xdexboard.com/api/v1/test/send-to-buyer-by-email" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "buyer_email=usdt_XXXXXXXX@xdexboard.com&message=Test&lot_id=2"
```

### По токену (токен с клиента покупателя)

```bash
curl -X POST "https://sandbox.api.xdexboard.com/api/v1/test/send-to-buyer" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -H "X-Escrow-Token: <JSON publicPart токена покупателя>" \
  -d "message=Test&lot_id=2"
```

---

## Итог

- **Параметры, которые можно взять из логов:** `lot_id`, `buyer_lot_email`.
- **Эндпоинт по логам:** `POST /api/v1/test/send-to-buyer-by-email` с телом `buyer_email`, `message`, опционально `lot_id`.
- **Токен покупателя** в логах не сохраняется; для `POST /api/v1/test/send-to-buyer` его нужно передавать с клиента.
