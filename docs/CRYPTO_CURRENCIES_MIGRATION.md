# Миграция справочника crypto_currencies (синхронизация с Core)

## Назначение

Справочник `crypto_currencies` в API приведён к схеме, совместимой с Core (GET `/info`): идентификаторы и коды криптовалют синхронизированы с конфигом Core `[crypto_currency_ids]`.

## Миграции

1. **Создание таблицы** — `2024_01_01_000003_create_crypto_currencies_table.php`  
   Поля: `id`, `code` (VARCHAR 32, unique), `name`, `is_native`, `network`, `is_active`, `created_at`, `updated_at`.

2. **Рефакторинг существующей таблицы** — `2025_02_09_000001_refactor_crypto_currencies_for_core_sync.php`  
   Для уже развёрнутых БД, где таблица была создана старой миграцией:
   - удаляется колонка `is_stablecoin`;
   - добавляются колонки `is_native`, `network`;
   - при необходимости расширяется `code` до VARCHAR(32);
   - добавляются индексы по `is_native` и `network`.  
   Миграция идемпотентна: если таблица уже в новой схеме, лишние действия не выполняются.

## Выполнение

Обычный запуск всех миграций:

```bash
php artisan migrate
```

Только миграция по справочнику (например, для отката/повторного запуска):

```bash
php artisan migrate --path=database/migrations/2025_02_09_000001_refactor_crypto_currencies_for_core_sync.php
```

**Требования:** пользователь БД должен иметь право `ALTER` на таблицу `crypto_currencies`. При ошибке `ALTER command denied` выдайте права:

```sql
GRANT ALTER ON your_database.crypto_currencies TO 'your_user'@'%';
FLUSH PRIVILEGES;
```

## Сидер

`CurrencySeeder` заполняет фиат статическим списком; **криптовалюты загружаются из Core по API** (GET `/api/info` → `data.crypto_structure.networks`). Хардкода списка крипты в сидере нет.

После миграции выполните:

```bash
php artisan db:seed --class=CurrencySeeder
```

**Требования:** в `.env` должен быть задан `CORE_URL` (по умолчанию используется sandbox Core). Для заполнения из production Core при запуске сидера укажите в `.env` production-URL Core. Заголовок `X-Core-Key` берётся из `CORE_KEY` при необходимости.

## Связь с Core

- В Core в конфигах задана секция `[crypto_currency_ids]` (код = id); GET `/api/info` отдаёт `crypto_structure` с полями `id`, `name` и разбивкой по сетям.
- Сидер вызывает Core GET `/api/info`, разбирает `crypto_structure.networks` и синхронизирует таблицу `crypto_currencies` (native → `network` = null, `is_native` = true; остальные сети — `network` = имя сети, `is_native` = false).
- API использует эти id при работе с лотами (`crypto_currency_id` в `exchange_lots`).

Подробнее: в репозитории xboard-core — `docs/CRYPTO_NETWORKS_CONFIGURATION.md`.
