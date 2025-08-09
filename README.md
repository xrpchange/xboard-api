# XBoard - P2P Криптобиржа с Залоговой Гарантией

<p align="center">
<img src="https://img.shields.io/badge/Laravel-10.x-red.svg" alt="Laravel Version">
<img src="https://img.shields.io/badge/PHP-8.1+-blue.svg" alt="PHP Version">
<img src="https://img.shields.io/badge/MySQL-8.0+-orange.svg" alt="MySQL Version">
<img src="https://img.shields.io/badge/License-MIT-green.svg" alt="License">
</p>

## Описание проекта

XBoard - это P2P криптобиржа (доска объявлений) с залоговой гарантией, использующая Ripple (XRP) для залоговых токенов с возможностью расширения на другие блокчейны.

### Основные принципы работы

1. **Залоговые токены как идентификаторы** - сам залоговый токен является токеном доступа к доске объявлений, никакой регистрации пользователя нет
2. **Песочница** - режим тестирования, переключаемый налету в телефонном приложении
3. **Внешние транзакции** - транзакции проводятся не на платформе, информация о них поступает от пользователей
4. **Безопасность** - специальные комиссии и резервирования хранятся в отдельной БД на физически отдельном сервере

## Быстрый старт

### Требования
- PHP 8.1+
- MySQL 8.0+
- Composer
- Node.js (для сборки фронтенда)

### Установка

1. **Клонирование репозитория**
```bash
git clone https://github.com/your-username/xboard.git
cd xboard
```

2. **Установка зависимостей**
```bash
composer install
npm install
```

3. **Настройка окружения**
```bash
cp .env.example .env
php artisan key:generate
```

4. **Настройка базы данных**
```bash
# Отредактируйте .env файл с вашими настройками БД
php artisan migrate
php artisan db:seed
```

5. **Запуск сервера**
```bash
php artisan serve
```

Приложение будет доступно по адресу: http://localhost:8000

## API Документация

### Swagger UI
После запуска сервера документация доступна по адресу:
- **API**: http://localhost:8000/api/documentation
- **Core API**: http://localhost:8000/docs/core_swagger.yaml

### API Документация:
- **Sandbox** (для тестирования): http://sandbox.api.xrpchange.site/documentation
- **Production**: http://api.xrpchange.site/documentation
- **Local**: http://localhost:8000/api/documentation

### Основные эндпоинты

#### Аутентификация
- `POST /api/v1/auth/bearer-token` - Получение Bearer токена
- `GET /api/v1/auth/validate` - Проверка токена

#### Справочники (публичные)
- `GET /api/v1/countries` - Список стран
- `GET /api/v1/currencies/fiat` - Фиатные валюты
- `GET /api/v1/currencies/crypto` - Криптовалюты
- `GET /api/v1/banks` - Список банков

#### Системные (публичные)
- `GET /api/v1/core/health` — проверка доступности узлов ядра в текущем окружении
- `GET /api/v1/core/health?env=sandbox` — проверка доступности узлов ядра в окружении `sandbox`

Пример:

```bash
curl -s "http://localhost:8000/api/v1/core/health?env=sandbox"
```

#### Доска объявлений (защищенные)
- `GET /api/v1/exchange-lots` - Список лотов
- `POST /api/v1/exchange-lots` - Создание лота
- `PUT /api/v1/exchange-lots/{id}` - Обновление лота
- `DELETE /api/v1/exchange-lots/{id}` - Удаление лота

#### Триггеры покупки (защищенные)
- `GET /api/v1/buy-triggers` - Список триггеров
- `POST /api/v1/buy-triggers` - Создание триггера
- `PUT /api/v1/buy-triggers/{id}` - Обновление триггера
- `DELETE /api/v1/buy-triggers/{id}` - Удаление триггера

#### Транзакции (защищенные)
- `GET /api/v1/transactions` - Список транзакций
- `GET /api/v1/transactions/stats` - Статистика транзакций
- `POST /api/v1/transactions` - Создание транзакции
- `PUT /api/v1/transactions/{id}` - Обновление транзакции

## Архитектура

### Backend
- **Framework**: Laravel 10.x
- **База данных**: MySQL 8.0+
- **API**: REST API с Bearer-токенами
- **Документация**: Swagger/OpenAPI 3.0

### Frontend (планируется)
- **Android**: Kotlin
- **iOS**: Swift
- **Веб**: Vue.js/React

### Ядро системы
- **Отдельный сервис**: XRPChangeCore
- **Блокчейн**: XRP Ledger
- **Безопасность**: Физически отдельное оборудование

#### Проверка доступности ядра (в самом ядре)
В `XRPChangeCore/public/echo.php` должен быть эхо-эндпоинт (по умолчанию `/echo.php`), на который ходит проверка здоровья из XBoard. Путь можно переопределить через переменную окружения `CORE_HEALTH_PATH`.

## Структура базы данных

### Справочники
- `countries` - Страны (ISO 3166-1 alpha-3)
- `fiat_currencies` - Фиатные валюты (ISO 4217)
- `crypto_currencies` - Криптовалюты
- `banks` - Банки (привязаны к странам)

### Основные таблицы
- `exchange_lots` - Доска объявлений
- `buy_triggers` - Триггеры покупки
- `transactions` - Внешние транзакции

### Особенности
- Поддержка тестового режима (`is_test`, `test_group`)
- Индексы для быстрого поиска
- Шифрование критических данных

## Тестирование

### Запуск тестов
```bash
php artisan test
```

### Тестовый режим
В тестовом режиме все запросы должны содержать `test_group` для изоляции данных.

## Безопасность

### Залоговые токены
- Используют XRP для быстрых транзакций
- Содержат зашифрованную информацию
- Хранятся только в ядре системы

### API Безопасность
- Bearer-токены для аутентификации
- Валидация всех входных данных
- Защита от DDoS атак

## Разработка

### Команды Artisan
```bash
# Миграции
php artisan migrate
php artisan migrate:fresh --seed

# Очистка кэша
php artisan cache:clear
php artisan config:clear
php artisan route:clear

# Генерация документации
php artisan l5-swagger:generate
```

### Структура проекта
```
xboard/
├── app/
│   ├── Http/Controllers/Api/    # API контроллеры
│   └── Models/                  # Eloquent модели
├── database/
│   ├── migrations/              # Миграции БД
│   └── seeders/                # Сидеры данных
├── docs/                       # Документация
│   ├── api_swagger.yaml        # API документация
│   └── core_swagger.yaml       # Ядро документация
└── routes/
    └── api.php                 # API роуты
```

## Лицензия

Этот проект лицензирован под MIT License - см. файл [LICENSE](LICENSE) для деталей.

## Поддержка

- **Документация**: [docs/](docs/)
- **API**: [docs/api_swagger.yaml](docs/api_swagger.yaml)
- **Задачи**: [docs/task.md](docs/task.md)

## Вклад в проект

1. Форкните репозиторий
2. Создайте ветку для новой функции (`git checkout -b feature/amazing-feature`)
3. Зафиксируйте изменения (`git commit -m 'Add amazing feature'`)
4. Отправьте в ветку (`git push origin feature/amazing-feature`)
5. Откройте Pull Request

## Доступ к ядру (CoreClient)

Универсальный клиент `CoreClient` обеспечивает отказоустойчивый доступ к ядру (XRPChangeCore):

- Случайный выбор узла из `config('services.core.servers')` любой структуры
- Кэш индекса последнего удачного узла (TTL настраивается)
- Повторные попытки и таймауты настраиваются
- Health-check всех узлов окружения по настраиваемому пути
- Универсальный метод `requestJson()` и специализированные обёртки (например, `checkTokenBalance()`)

### Конфигурация ядра

`config/services.php` → секция `core`:

- `servers`: список узлов по окружениям; конечные узлы содержат `url` и (опционально) `key`
- `default_environment`: окружение по умолчанию (`development|sandbox|production`)
- `timeout`: таймаут HTTP-запросов (секунды)
- `retry_attempts`: число попыток на каждом узле
- `health_path`: путь для проверки доступности узла (по умолчанию `/echo.php`)
- `cache_ttl`: TTL кэша индекса (секунды)
- `cache_store`: стор кэша (например, `redis`). Если стор не найден — используется драйвер по умолчанию

ENV-пример:

```env
CORE_DEFAULT_ENV=development
CORE_TIMEOUT=10
CORE_RETRY_ATTEMPTS=3
CORE_HEALTH_PATH=/echo.php
CORE_CACHE_TTL=86400
# Опционально, чтобы принудительно указать стор кэша
CORE_CACHE_STORE=redis
```

### Health-check в ядре (XRPChangeCore)

В `XRPChangeCore/public/echo.php` должен быть публичный эхо-эндпоинт (по умолчанию `/echo.php`):

```php
<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode([
  'ok' => true,
  'service' => 'XRPChangeCore',
  'time' => gmdate('c'),
  'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
]);
```

Путь можно переопределить через `CORE_HEALTH_PATH`.

### Публичный эндпоинт статуса узлов

- `GET /api/v1/core/health` — проверка узлов текущего окружения
- `GET /api/v1/core/health?env=sandbox` — проверка узлов окружения `sandbox`

Пример:

```bash
curl -s "http://localhost:8000/api/v1/core/health?env=sandbox"
```

### Кэширование (Redis опционально)

- Если `CORE_CACHE_STORE` указывает на несуществующий стор, клиент откатывается на драйвер кэша по умолчанию (обычно `file`)
- Для Redis:
  - Установите и запустите Redis
  - В `.env`: `CACHE_DRIVER=redis` или `CORE_CACHE_STORE=redis`, плюс `REDIS_HOST`, `REDIS_PORT`, `REDIS_CLIENT`
  - Проверьте `config/cache.php` → `stores.redis`

### Примеры использования

```php
use App\Services\CoreClient;

public function example(CoreClient $coreClient): array
{
    $data = $coreClient->requestJson('POST', '/api/anything', [ 'foo' => 'bar' ]);
    $balance = $coreClient->checkTokenBalance($collateralToken);
    $statuses = $coreClient->healthCheck('sandbox');
    return compact('data', 'balance', 'statuses');
}
```
