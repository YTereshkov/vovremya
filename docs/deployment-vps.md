# Production deployment на VPS

Production-контур рассчитан на один обычный Linux VPS с Docker Engine и Docker Compose. Он запускает Caddy, PHP-FPM, PostgreSQL 17, Redis 7.4, один Messenger worker и один Scheduler worker. PostgreSQL и Redis не публикуют host ports. Caddy принимает HTTP/HTTPS, автоматически получает TLS-сертификат и передаёт только backend routes в Symfony; остальные routes обслуживаются как React SPA.

## Подготовка сервера

1. Настройте DNS `A`/`AAAA` домена на VPS и откройте входящие TCP 80/443 и UDP 443. SSH и firewall настраиваются средствами сервера, не Compose.
2. Установите актуальные Docker Engine и Compose plugin. Добавьте системное ограничение Docker logs/BuildKit cache согласно политике конкретного VPS.
3. Клонируйте репозиторий в отдельный каталог, например `/srv/vovremya`.
4. Скопируйте `.env.prod.example` в `.env.prod.local`, замените placeholders и выполните `chmod 600 .env.prod.local`.
5. Генерируйте `APP_SECRET` и пароли криптографически стойким генератором. В `DATABASE_URL` пароль должен быть URL-encoded, а в `POSTGRES_PASSWORD` — исходным. Файл не коммитится.

`APP_DOMAIN` содержит домен без схемы. `DEFAULT_URI` содержит полный `https://` URL того же домена. `SYMFONY_TRUSTED_PROXIES=REMOTE_ADDR` доверяет forwarded headers только непосредственно подключённому Caddy. Provider tokens можно оставить пустыми только для ещё не подключённых каналов.

Проверьте конфигурацию без запуска:

```bash
bash bin/validate-production-env
VOVREMYA_IMAGE_TAG=config-check docker compose --env-file .env.prod.local -f compose.prod.yaml config --quiet
```

## Первый deploy и обновление

Deploy по умолчанию использует короткий SHA текущего Git commit как immutable image tag:

```bash
bash bin/deploy-production
```

Скрипт проверяет секреты, собирает PHP/frontend images, запускает one-shot Doctrine migrations, затем PHP, workers и Caddy. После запуска он выполняет Doctrine schema validation и HTTPS health check. Если migration завершилась с ошибкой, зависимые application containers не запускаются.

Первого администратора создайте интерактивно. Пароль скрыт и не передаётся через аргументы:

```bash
docker compose --env-file .env.prod.local -f compose.prod.yaml exec php php bin/console app:admin:create --env=prod
```

После deploy проверьте:

```bash
docker compose --env-file .env.prod.local -f compose.prod.yaml ps
docker compose --env-file .env.prod.local -f compose.prod.yaml logs --since=10m php messenger scheduler caddy
docker compose --env-file .env.prod.local -f compose.prod.yaml exec php php bin/console messenger:stats --env=prod
docker compose --env-file .env.prod.local -f compose.prod.yaml exec php php bin/console app:infrastructure:heartbeat --status --env=prod
```

Зарегистрируйте provider webhooks только на tenant-bound URL, созданные приложением. Не используйте общий или вручную составленный routing key.

## Backup и восстановление

PostgreSQL — авторитетный источник бизнес-данных. Делайте ежедневный backup во внешний каталог:

```bash
bash bin/backup-production /srv/vovremya-backups
```

Скрипт создаёт `pg_dump --format=custom` с mode 600. Сам VPS не является достаточным местом хранения: копируйте backups во внешнее защищённое хранилище, задайте retention и регулярно проверяйте восстановление на отдельной базе. Redis backup не заменяет PostgreSQL backup: очередь, scheduler/cache и временные данные должны восстанавливаться штатными retry/recovery процессами.

Восстановление является разрушительной операцией. Остановите application/workers, сохраните ещё один dump, проверьте выбранный файл через `pg_restore --list`, затем восстанавливайте в новую или явно очищенную PostgreSQL database. Не автоматизируйте `--clean` без проверки оператора.

## Rollback

Images сохраняются с Git SHA tags. Для отката application containers на уже существующий tag без изменения базы:

```bash
bash bin/rollback-production <previous-git-sha-tag>
```

Rollback не выполняет down migrations. Он безопасен только если уже применённая schema обратно совместима с выбранной версией приложения. При несовместимой migration используйте проверенный database restore/forward fix, а не автоматический откат schema.

## Эксплуатационные границы

- Caddy добавляет HSTS, clickjacking/MIME/referrer/permissions headers и ротацию stdout logs выполняет Docker `local` driver (`10m × 3`).
- PHP filesystem read-only; writable только ephemeral `/app/var` и `/tmp`. Cache прогревается при старте контейнера.
- Сессии текущей реализации хранятся локально и могут завершиться при рестарте PHP container; пользователь войдёт снова, бизнес-данные не теряются.
- Messenger worker перезапускается каждый час по `--time-limit`, имеет уникальный consumer name и graceful stop. Failed messages проверяйте через `messenger:failed:show`.
- Scheduler и handlers должны оставаться идемпотентными; Redis не является авторитетным business storage.
- Мониторинг снаружи должен проверять `GET /health`; дополнительно контролируйте container restarts, свободное место, срок TLS, PostgreSQL backup age, failed queue и scheduler heartbeat.
- Масштабирование за пределы одного VPS, orchestration/Kubernetes и автоматический multi-node failover в scope не входят.
