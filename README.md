# Работа Бандита — Railway + PostgreSQL (PHP)

Полностью PHP-версия для Railway. Python, Node.js и npm не используются.

## Railway Variables

Обязательная:
- `BOT_TOKEN` — токен Telegram-бота.

Для автоматической установки webhook рекомендуется добавить:
- `WEBHOOK_URL` — полный адрес Railway-сервиса, например `https://ВАШ-ДОМЕН.up.railway.app`.

Если `WEBHOOK_URL` не задан, проект попробует использовать `RAILWAY_PUBLIC_DOMAIN` автоматически.

PostgreSQL должен быть подключён к сервису бота. Railway передаёт `DATABASE_URL` (или PGHOST/PGPORT/PGUSER/PGPASSWORD/PGDATABASE).

## Запуск

`start.sh`:
1. проверяет PHP, PDO PostgreSQL и cURL;
2. запускает PHP на `$PORT`;
3. автоматически регистрирует Telegram webhook;
4. оставляет PHP-сервер главным процессом.

При GET-запросе (Railway health check) `/` отвечает `Работа Бандита: OK`, поэтому health check не получает пустой ответ.

## База

`schema.sql` выполняется автоматически при старте приложения. Таблицы создаются через `CREATE TABLE IF NOT EXISTS`.

## Важно

Если в Railway уже был создан старый webhook на другой адрес, новый `setWebhook` его заменит.
