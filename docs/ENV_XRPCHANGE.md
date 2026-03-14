# Настройка окружения XRPChange (.env.xrpchange)

API поддерживает отдельную конфигурацию для домена `api.xrpchange.site` через файл `.env.xrpchange`.

## Как это работает

1. **Apache:** VirtualHost для `api.xrpchange.site` содержит `SetEnv APP_ENV xrpchange`.
2. **index.php:** Перед bootstrap Laravel проверяет `APP_ENV` (из Apache, `REDIRECT_APP_ENV` при mod_rewrite) и при значении `xrpchange` загружает `.env.xrpchange` вместо `.env`.
3. **PHP-FPM:** Воркеры переиспользуют `$_ENV` между запросами. Dotenv по умолчанию не перезаписывает уже установленные переменные. Поэтому перед `LoadEnvironmentVariables` переменные БД очищаются — иначе после запроса к xdexboard `DB_DATABASE` остаётся `xboard` и .env.xrpchange не может установить `xboard2`.
4. Все запросы к `api.xrpchange.site`, `sandbox.api.xrpchange.site` и т.п. используют конфигурацию из `.env.xrpchange`.

## Создание .env.xrpchange

1. Скопируйте `.env` в `.env.xrpchange`:
   ```bash
   cp .env .env.xrpchange
   ```

2. Измените переменные, которые должны отличаться для XRPChange (БД, Core URL и т.д.):

   | Переменная | Описание |
   |------------|----------|
   | `APP_ENV` | Должно быть `xrpchange` |
   | `APP_NAME` | Напр. `XBoard API (XRPChange)` |
   | `APP_URL` | `https://api.xrpchange.site` |
   | `DB_DATABASE` | База данных для XRPChange (может отличаться) |
   | `DB_USERNAME` | Пользователь БД |
   | `DB_PASSWORD` | Пароль БД |
   | `CORE_URL` | URL Core API для sandbox (напр. `https://sandbox.api.core.xrpchange.site`). Используется, когда в токене `environment` = sandbox. |
   | `CORE_KEY` | API-ключ Core (sandbox) |
   | `CORE_PRODUCTION_URL` | URL Core API для production. Используется, когда в токене `environment` = production/prod. |
   | `CORE_PRODUCTION_KEY` | API-ключ Core (production) |

   Выбор URL по полю `environment` токена описан в `docs/ESCROW_TOKEN_AUTHENTICATION.md` (§ Calling Core API: URL by token environment).
   | `PAYMENT_VERIFICATION_EMAIL` | Email для верификации платежей |

3. Файл `.env.xrpchange` не должен попадать в git (добавьте в `.gitignore` при необходимости).

## Apache VirtualHost (пример)

```apache
<VirtualHost *:80>
    DocumentRoot "C:/xampp/htdocs/Projects/xboard-api/public"
    ServerName api.xrpchange.site
    ServerAlias dev.api.xrpchange.site sandbox.api.xrpchange.site
    SetEnv APP_ENV xrpchange
    
    <Directory "C:/xampp/htdocs/Projects/xboard-api/public">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ...
</VirtualHost>
```

## Важно

**Fallback по хосту на сервере запрещён.** Переменная `APP_ENV` должна быть задана явно через Apache `SetEnv`. Не добавлять детекцию по `HTTP_HOST` или другие неявные методы на production.
