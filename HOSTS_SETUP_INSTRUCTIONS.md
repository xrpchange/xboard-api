# Инструкция по настройке hosts файла для тестирования

## 1. Открыть hosts файл

Открыть файл `C:\Windows\System32\drivers\etc\hosts` в текстовом редакторе **от имени администратора**.

## 2. Добавить записи

Добавить в конец файла следующие строки:

```
195.46.191.38 sandbox.api.core.xdexboard.com
195.46.191.38 api.core.xdexboard.com
```

## 3. Сохранить файл

Сохранить файл и закрыть редактор.

## 4. Очистить DNS кэш

Выполнить в командной строке от имени администратора:

```cmd
ipconfig /flushdns
```

## 5. Проверить работу

```bash
# Проверить echo.php
curl http://sandbox.api.core.xdexboard.com/echo.php

# Проверить API эндпоинт
curl -X POST -H "Content-Type: application/json" \
  -d '{"crypto_type":"xrp","escrow_token":"test"}' \
  http://sandbox.api.core.xdexboard.com/api/escrow-tokens/balance
```

## 6. Удалить записи после тестирования

После завершения тестирования удалить добавленные строки из hosts файла.

## Примечания

- IP адрес 195.46.191.38 должен быть доступен
- Домены будут работать только на этом компьютере
- Для продакшена нужна правильная настройка DNS
