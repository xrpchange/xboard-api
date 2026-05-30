# Локальная имитация второго обменника (`.env.xrpchange`)

**Не боевой стек.** `*.xrpchange.site` на этой машине — только **разработка**: второй виртуальный API/узел рядом с **xdexboard** (`api.xdexboard.com` + `.env`). На production-серверах xrpchange **не** целевой продукт.

Файл **`.env.xrpchange` в git не коммитится** (см. `.gitignore`). Шаблон без секретов: **`.env.xrpchange.example`** → скопировать в `.env.xrpchange` и заполнить локально.

## Как это работает (только local XAMPP)

1. **Apache:** VirtualHost для `api.xrpchange.site` с `SetEnv APP_ENV xrpchange`.
2. **`public/index.php`:** при `APP_ENV=xrpchange` загружается `.env.xrpchange` вместо `.env`.
3. **PHP-FPM:** перед `LoadEnvironmentVariables` сбрасываются переменные БД, иначе после запроса к xdexboard остаётся `DB_DATABASE=xboard` и второй env не применится.
4. Запросы к `api.xrpchange.site`, `sandbox.api.xrpchange.site` и т.п. на **этом ПК** читают `.env.xrpchange`.

## Создание `.env.xrpchange`

```bash
cp .env.xrpchange.example .env.xrpchange
# отредактировать: отдельная БД (напр. xboard2), CORE_* для *.xrpchange.site
```

| Переменная | Назначение |
|------------|------------|
| `APP_ENV` | `xrpchange` |
| `APP_URL` | `https://api.xrpchange.site` (локальный vhost) |
| `DB_DATABASE` | Отдельная схема для «второго» узла (напр. `xboard2`) |
| `CORE_URL` / `CORE_PRODUCTION_URL` | Core для sandbox/prod на xrpchange-хостах |

Остальные ключи — по необходимости, как в основном `.env`. Выбор Core URL по `environment` токена: `docs/ESCROW_TOKEN_AUTHENTICATION.md`.

## Apache (пример, local)

```apache
<VirtualHost *:80>
    DocumentRoot "C:/xampp/htdocs/Projects/xboard-api/public"
    ServerName api.xrpchange.site
    ServerAlias dev.api.xrpchange.site sandbox.api.xrpchange.site
    SetEnv APP_ENV xrpchange
    ...
</VirtualHost>
```

См. также `nginx-xrpchange-api.conf` (справочный сниппет), **не** production-деплой.

## CORS

Только **один** слой `Access-Control-*` (Apache vhost или Nginx, не оба). Для локальной отладки дублирования: `docs/CORS_XRPCHANGE_PRODUCTION.md` (историческое имя; относится к связке vhost + TLS, не к «боевому xrpchange» как продукту).

## Важно

- **Не коммитить** `.env.xrpchange` (пароли, ключи).
- **Не** подразумевать `server@xrpchange.site` / supersrv как цель фич — рабочий API: **`*.xdexboard.com`** + `.env`.
- **Без fallback по `HTTP_HOST`:** `APP_ENV` только через `SetEnv` в vhost.
