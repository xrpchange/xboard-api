# Ограничение размера mail.log (не более 10 МБ)

Файл `logrotate-mail-10mb.conf` — конфиг logrotate: при достижении 10 МБ лог обрезается (copytruncate), старые копии не хранятся.

## Установка на сервер

1. Скопировать конфиг в каталог logrotate:
   ```bash
   sudo cp logrotate-mail-10mb.conf /etc/logrotate.d/mail-xboard
   ```

2. **Первый раз** (если `/var/log/mail.log` уже очень большой): освободить место без создания копии:
   ```bash
   sudo truncate -s 0 /var/log/mail.log
   ```

3. Проверить конфиг:
   ```bash
   sudo logrotate -d /etc/logrotate.d/mail-xboard
   ```

4. При необходимости принудительно выполнить ротацию:
   ```bash
   sudo logrotate -f /etc/logrotate.d/mail-xboard
   ```

Дальше logrotate по крону будет запускаться сам и держать размер лога не более 10 МБ.
