# Database Seeding Guide

## Описание

Руководство по заполнению базы данных тестовыми данными для разработки и тестирования.

## Важно

⚠️ **Таблица `nodes` сохраняется** - она содержит актуальные данные об узлах обмена и не должна быть очищена.

Остальные таблицы можно безопасно очищать и заполнять заново.

## Запуск Seeders

### Полное заполнение (все таблицы)

```bash
cd xboard-api
php artisan db:seed
```

Это выполнит все seeders в правильном порядке:
1. `CountrySeeder` - страны
2. `CurrencySeeder` - валюты (фиатные и криптовалюты)
3. `BankSeeder` - банки
4. `NodeSeeder` - узлы обмена (⚠️ сохраняется)
5. `ExchangeLotSeeder` - лоты обмена

### Очистка и заполнение (кроме nodes)

```bash
# Очистить все таблицы (кроме nodes)
php artisan migrate:fresh --seed --seeder=DatabaseSeeder

# Или вручную очистить конкретные таблицы
php artisan tinker
>>> DB::table('exchange_lots')->truncate();
>>> DB::table('fiat_currencies')->truncate();
>>> DB::table('crypto_currencies')->truncate();
>>> DB::table('countries')->truncate();
>>> DB::table('banks')->truncate();
>>> exit
php artisan db:seed
```

### Только Exchange Lots

Если нужно обновить только лоты обмена:

```bash
php artisan db:seed --class=ExchangeLotSeeder
```

Или через tinker:

```bash
php artisan tinker
>>> DB::table('exchange_lots')->truncate();
>>> Artisan::call('db:seed', ['--class' => 'ExchangeLotSeeder']);
>>> exit
```

## Что создается

### ExchangeLotSeeder

Создает разнообразные тестовые лоты обмена:

#### Типы обмена:
- **Crypto → Fiat**: BTC→USD, ETH→USD, XRP→RUB, USDT→USD
- **Fiat → Crypto**: USD→BTC, EUR→ETH, RUB→XRP
- **Crypto → Crypto**: BTC→ETH, ETH→USDT

#### Статусы:
- ✅ **Active** - активные лоты (большинство)
- ⏰ **Expired** - истекшие лоты (для тестирования фильтрации)
- ❌ **Inactive** - неактивные лоты (для тестирования)

#### Тестовые данные:
- 🧪 **Test lots** (`is_test=true`, `test_group='sandbox'`) - для sandbox окружения
- 📊 **Production lots** (`is_test=false`) - для production окружения

#### Клиенты:
- 👤 **For individuals** - для физических лиц
- 🏢 **For legal entities** - для юридических лиц
- 👥 **For both** - для всех

#### Примеры лотов:

1. **BTC → USD** (Active, individuals)
   - Продажа: 1.5 BTC
   - Покупка: $67,500
   - Курс: ~$45,000/BTC
   - Срок: 7 дней

2. **ETH → USD** (Active, large amount)
   - Продажа: 10 ETH
   - Покупка: $35,000
   - Курс: ~$3,500/ETH
   - Срок: 10 дней

3. **XRP → RUB** (Active, individuals)
   - Продажа: 10,000 XRP
   - Покупка: 600,000 RUB
   - Курс: ~60 RUB/XRP
   - Срок: 3 дня

4. **BTC → ETH** (Crypto→Crypto)
   - Продажа: 0.1 BTC
   - Покупка: 12.85 ETH
   - Срок: 12 дней

5. **Test: BTC → USD** (Sandbox)
   - Продажа: 0.01 BTC
   - Покупка: $450
   - Тестовая группа: sandbox
   - Срок: 30 дней

## Структура данных

### Exchange Lots

Каждый лот содержит:
- `crypto_currency_id` - ID криптовалюты для коллатерального токена (обычно XRP)
- `wallet_address` - адрес кошелька коллатерального токена
- `sell_currency_type` - тип валюты продажи ('fiat' или 'crypto')
- `sell_currency_id` - ID валюты продажи
- `sell_amount` - количество валюты продажи
- `buy_currency_type` - тип валюты покупки ('fiat' или 'crypto')
- `buy_currency_id` - ID валюты покупки
- `buy_amount` - количество валюты покупки
- `country_id` - страна для фиатных операций
- `bank_id` - банк для фиатных операций
- `bank_account` - номер банковского счета
- `payment_purpose` - назначение платежа
- `is_for_individuals` - доступен для физических лиц
- `is_for_legal_entities` - доступен для юридических лиц
- `is_active` - активен ли лот
- `is_test` - тестовый ли лот
- `test_group` - группа тестовых данных
- `expires_at` - срок действия лота

## Проверка данных

После seeding можно проверить данные:

```bash
php artisan tinker
>>> \App\Models\ExchangeLot::count();
>>> \App\Models\ExchangeLot::where('is_active', true)->count();
>>> \App\Models\ExchangeLot::where('is_test', true)->count();
>>> \App\Models\ExchangeLot::where('expires_at', '<', now())->count();
>>> exit
```

## Обновление данных

Для обновления лотов с новыми курсами или условиями:

```bash
php artisan db:seed --class=ExchangeLotSeeder
```

Это пересоздаст все лоты с актуальными данными.

## Примечания

- Все лоты используют один и тот же `wallet_address` для коллатерального токена (тестовый адрес)
- Курсы валют приблизительные и используются только для тестирования
- Реальные курсы должны получаться из внешних API
- Тестовые лоты помечены `is_test=true` и `test_group='sandbox'`
- Production лоты имеют `is_test=false`

