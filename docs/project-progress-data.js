window.PROJECT_PROGRESS = {
  project: {
    name: 'Vovremya',
    state: 'Пачка 16 завершена',
    currentBatch: 16,
    currentPart: 38,
    currentItem: 'Отсутствия специалиста и клиента завершены',
    updatedAt: '2026-09-10',
  },
  statusLabels: {
    done: 'Выполнено',
    in_progress: 'В работе',
    pending: 'Ожидает',
    blocked: 'Заблокировано',
    review: 'Требует проверки',
  },
  current: {
    title: 'Пачка 16 · Части 37–38',
    items: [
      'Отсутствие специалиста — hard conflict для ручной записи и materialization.',
      'Затронутые занятия специалиста отменяются атомарно без FreeWindow; регулярные правила сохраняются.',
      'KEEP_PERMANENT_PLACE сохраняет расписание клиента и может создать разовые FreeWindow.',
      'RELEASE_PERMANENT_PLACE завершает активные регулярные правила без удаления клиента или истории.',
      'Подтверждённые прошлые результаты не переписываются; pending reminders по отменённым занятиям подавляются.',
    ],
    description: 'Отсутствия специалиста и клиента работают сквозным tenant-scoped контуром.',
    outcome: 'Appointment, allocations, reminders, FreeWindow и regular schedule lifecycle изменяются согласно выбранному режиму без потери истории.',
  },
  nextPartNumbers: [39, 40, 41, 42],
  batches: [
    {
      number: 1,
      title: 'Локальная среда и Symfony',
      parts: [
        { number: 1, title: 'Docker Compose: базовая среда', status: 'done', completedAt: '2026-09-03', result: 'Подняты PHP 8.4, Node.js, PostgreSQL и Redis с health checks.' },
        { number: 2, title: 'Symfony 7.4 backend foundation', status: 'done', completedAt: '2026-09-03', result: 'Создан Symfony backend, HTTP entrypoint, console и health endpoint.' },
      ],
    },
    {
      number: 2,
      title: 'Modular monolith и PostgreSQL',
      parts: [
        { number: 3, title: 'Структура modular monolith', status: 'done', completedAt: '2026-09-03', result: 'Зафиксированы модули, слои и направления зависимостей.' },
        { number: 4, title: 'PostgreSQL и Doctrine foundation', status: 'done', completedAt: '2026-09-03', result: 'Настроены Doctrine ORM, DBAL, migrations, ULID и PostgreSQL integration tests.' },
      ],
    },
    {
      number: 3,
      title: 'Redis, Messenger и Scheduler',
      parts: [
        { number: 5, title: 'Redis foundation', status: 'done', completedAt: '2026-09-03', result: 'Разделены namespaced Redis pools для cache, rate limit и технических данных.' },
        { number: 6, title: 'Symfony Messenger foundation', status: 'done', completedAt: '2026-09-03', result: 'Добавлены async/failure transports, worker и retry policy.' },
        { number: 7, title: 'Symfony Scheduler foundation', status: 'done', completedAt: '2026-09-03', result: 'Настроены периодические задачи и отдельный scheduler process.' },
      ],
    },
    {
      number: 4,
      title: 'React, UI shell и тестовая инфраструктура',
      parts: [
        { number: 8, title: 'React 19, TypeScript и Vite', status: 'done', completedAt: '2026-09-04', result: 'Создана React SPA со strict TypeScript, Router, Query и Vite.' },
        { number: 9, title: 'Tailwind, shadcn/ui и application shell', status: 'done', completedAt: '2026-09-04', result: 'Реализован единый responsive shell для mobile и desktop.' },
        { number: 10, title: 'PHPUnit, Playwright и базовые команды', status: 'done', completedAt: '2026-09-04', result: 'Добавлены backend, build и E2E команды с изолированной test database.' },
      ],
    },
    {
      number: 5,
      title: 'Organization, admin, auth и tenant isolation',
      parts: [
        { number: 11, title: 'Organization и AdministratorAccount', status: 'done', completedAt: '2026-09-04', result: 'Добавлены модели и безопасная интерактивная команда создания администратора.' },
        { number: 12, title: 'Минимальная authentication', status: 'done', completedAt: '2026-09-07', result: 'Реализованы session login/logout, CSRF и закрытый React shell.' },
        { number: 13, title: 'Tenant isolation', status: 'done', completedAt: '2026-09-07', result: 'Добавлены tenant context, scoped stores, voters и составные tenant foreign keys.' },
      ],
    },
    {
      number: 6,
      title: 'Специалисты и рабочая доступность',
      parts: [
        { number: 14, title: 'Специалисты', status: 'done', completedAt: '2026-09-07', result: 'Реализованы Specialist, tenant-scoped CRUD API, список и карточка.' },
        { number: 15, title: 'Рабочие часы, обед и дополнительные рабочие дни', status: 'done', completedAt: '2026-09-07', result: 'Добавлены недельные часы, обед и дополнительные date-specific интервалы.' },
      ],
    },
    {
      number: 7,
      title: 'Услуги, клиенты, ContactPerson и каналы',
      parts: [
        { number: 16, title: 'Услуги', status: 'done', completedAt: '2026-09-07', result: 'Добавлены duration constraints, CRUD и внутренний soft delete.' },
        { number: 17, title: 'Клиенты', status: 'done', completedAt: '2026-09-07', result: 'Реализованы CRUD, поиск и карточка самостоятельного клиента.' },
        { number: 18, title: 'ContactPerson и базовые каналы клиента', status: 'done', completedAt: '2026-09-07', result: 'Добавлены опциональные контакты, channel metadata и primary recipient.' },
      ],
    },
    {
      number: 8,
      title: 'Allocations и hard availability',
      parts: [
        { number: 19, title: 'ScheduleAllocation и защита от пересечений', status: 'done', completedAt: '2026-09-07', result: 'PostgreSQL exclusion constraint гарантирует отсутствие двойного бронирования.' },
        { number: 20, title: 'Availability: hard conflicts', status: 'done', completedAt: '2026-09-07', result: 'Backend проверяет рабочие интервалы и занятость; hard conflicts возвращаются через HTTP 409.' },
      ],
    },
    {
      number: 9,
      title: 'Разовое занятие и soft warnings',
      parts: [
        { number: 21, title: 'Разовое занятие end-to-end', status: 'done', completedAt: '2026-09-07', result: 'Реализованы Appointment, snapshot услуги, allocation, API и форма создания.' },
        { number: 22, title: 'Availability: soft warnings', status: 'done', completedAt: '2026-09-07', result: 'Обед и короткий перерыв требуют явного подтверждения и пишутся в audit event.' },
      ],
    },
    {
      number: 10,
      title: 'Календарь и карточка занятия',
      parts: [
        { number: 23, title: '«Сегодня», календарь дня и карточка занятия', status: 'done', completedAt: '2026-09-08', result: 'Реализованы tenant-scoped Calendar API, реальный экран «Сегодня» и карточка занятия со snapshot услуги.' },
        { number: 24, title: 'Неделя, desktop-календарь и несколько специалистов', status: 'done', completedAt: '2026-09-08', result: 'Работают mobile agenda, desktop day/week grids, specialist filter, fullscreen и scroll restoration.' },
      ],
    },
    {
      number: 11,
      title: 'Регулярное расписание и materialization',
      parts: [
        { number: 25, title: 'Регулярное расписание', status: 'done', completedAt: '2026-09-08', result: 'Добавлены правила с отдельным временем и длительностью по дням, API и responsive UI.' },
        { number: 26, title: 'Materialization и конфигурируемый rolling horizon', status: 'done', completedAt: '2026-09-08', result: 'Занятия и allocations создаются до REGULAR_SCHEDULE_HORIZON_DAYS и ежедневно продлеваются через Scheduler/Messenger.' },
        { number: 27, title: 'ScheduleGenerationIssue и изменение регулярных правил', status: 'done', completedAt: '2026-09-08', result: 'Конфликтные даты не пропускаются молча; правила изменяются версионно, старые занятия остаются историей.' },
      ],
    },
    {
      number: 12,
      title: 'Communications foundation, шаблоны и подключения',
      parts: [
        { number: 28, title: 'Communications foundation', status: 'done', completedAt: '2026-09-08', result: 'Добавлены notification intents, transactional outbox, конкурентно-идемпотентный webhook inbox, authenticated provider contracts, processing leases и scheduled recovery.' },
        { number: 29, title: 'Шаблоны сообщений', status: 'done', completedAt: '2026-09-09', result: 'Добавлены общие шаблоны, утверждённые {variable} placeholders, preview и сервисный override подтверждения.' },
        { number: 30, title: 'Подключение каналов и capabilities', status: 'done', completedAt: '2026-09-09', result: 'Добавлены default channel, recipient-bound ChannelConnection lifecycle, одноразовая MAX activation и capabilities-driven UI.' },
      ],
    },
    { number: 13, title: 'MAX', parts: [{ number: 31, title: 'MAX adapter', status: 'done', completedAt: '2026-09-09', result: 'MAX outbound/inbound flow использует tenant-bound connection, stable event ids, secure outbox и атомарную normalized-event обработку.' }] },
    {
      number: 14,
      title: 'Подтверждения и ответы клиента',
      parts: [
        { number: 32, title: 'Запросы подтверждения и фоновые сроки', status: 'done', completedAt: '2026-09-10', result: 'Добавлены tenant-настройки, Scheduler, идемпотентные запросы и повторные напоминания через outbox.' },
        { number: 33, title: 'Ответы клиента на подтверждение', status: 'done', completedAt: '2026-09-10', result: 'Opaque single-use callbacks безопасно меняют независимый confirmation status; статусы видны в календаре и UI.' },
      ],
    },
    {
      number: 15,
      title: 'Результаты, отмены и FreeWindow',
      parts: [
        { number: 34, title: 'Результаты, история и поздняя отмена', status: 'done', completedAt: '2026-09-10', result: 'Добавлены независимый result state, tenant-порог поздней отмены и append-only история занятия.' },
        { number: 35, title: 'Отмена отдельного занятия', status: 'done', completedAt: '2026-09-10', result: 'Отмена освобождает allocation и pending reminders, сохраняя Appointment и регулярное правило.' },
        { number: 36, title: 'FreeWindow core', status: 'done', completedAt: '2026-09-10', result: 'Явная отмена клиентом создаёт одно управляемое окно без reservation; список повторно проверяет availability.' },
      ],
    },
    {
      number: 16,
      title: 'Отсутствие специалиста и клиента',
      parts: [
        { number: 37, title: 'Отсутствие специалиста', status: 'done', completedAt: '2026-09-10', result: 'Период становится hard conflict, занятия отменяются без FreeWindow, а регулярные правила сохраняются.' },
        { number: 38, title: 'Отсутствие клиента', status: 'done', completedAt: '2026-09-10', result: 'Режимы сохранения и освобождения постоянного места изменяют только нужные Appointment, FreeWindow и RegularSchedule.' },
      ],
    },
    {
      number: 17,
      title: 'Перенос без резервирования',
      parts: [
        { number: 39, title: 'TransferRequest и TransferOption', status: 'pending' },
        { number: 40, title: 'Выбор варианта и конкурентный перенос', status: 'pending' },
      ],
    },
    {
      number: 18,
      title: 'Waiting List и matching',
      parts: [
        { number: 41, title: 'Waiting List', status: 'pending' },
        { number: 42, title: 'Matching и сдвиг позднего клиента раньше', status: 'pending' },
      ],
    },
    { number: 19, title: 'Предложения разовых окон', parts: [{ number: 43, title: 'Offers для FreeWindow', status: 'pending' }] },
    {
      number: 20,
      title: 'Постоянные места и их предложения',
      parts: [
        { number: 44, title: 'PermanentPlace и комплекты', status: 'pending' },
        { number: 45, title: 'Offers для PermanentPlace', status: 'pending' },
      ],
    },
    { number: 21, title: 'Уведомления и контроль доставки', parts: [{ number: 46, title: 'Уведомления и контроль доставки', status: 'pending' }] },
    {
      number: 22,
      title: 'Telegram и WhatsApp',
      parts: [
        { number: 47, title: 'Telegram adapter', status: 'pending' },
        { number: 48, title: 'WhatsApp adapter', status: 'pending' },
      ],
    },
    { number: 23, title: 'Статистика', parts: [{ number: 49, title: 'Статистика', status: 'pending' }] },
    {
      number: 24,
      title: 'PWA и offline',
      parts: [
        { number: 50, title: 'Installable PWA', status: 'pending' },
        { number: 51, title: 'Read-only offline schedule', status: 'pending' },
      ],
    },
    { number: 25, title: 'Production hardening', parts: [{ number: 52, title: 'Production hardening и VPS deployment', status: 'pending' }] },
  ],
  changes: [
    {
      date: '2026-09-10',
      items: [
        'Завершена пачка 16: отсутствия специалиста и клиента.',
        'Отсутствие специалиста блокирует booking/materialization, но не удаляет регулярные правила.',
        'Отсутствие клиента поддерживает сохранение или освобождение постоянного места.',
        'Responsive UI и E2E покрывают оба сценария на desktop и mobile.',
      ],
    },
    {
      date: '2026-09-10',
      items: [
        'Завершена пачка 15: результаты, история, отдельная отмена и FreeWindow core.',
        'Результат остаётся независимым от confirmation; история хранит системные, клиентские и административные события.',
        'Отмена освобождает allocation и pending уведомления, но сохраняет Appointment и регулярное правило.',
        'Responsive UI добавляет форму результата, историю и список доступных разовых окон.',
      ],
    },
    {
      date: '2026-09-10',
      items: [
        'Завершена пачка 14: запросы подтверждения, фоновые сроки и ответы клиента.',
        'Добавлены tenant-настройки времени запроса, no-response cutoff, повторного напоминания и quiet hours.',
        'Scheduler и transactional outbox обеспечивают восстановление и идемпотентность повторных запусков.',
        'Календарь, карточка занятия и экран уведомлений показывают реальные confirmation statuses.',
      ],
    },
    {
      date: '2026-09-08',
      items: [
        'Завершена пачка 10: Calendar API, «Сегодня», карточка занятия и responsive day/week views.',
        'Реализованы Calendar API/read model, экран «Сегодня», карточка занятия и responsive day/week calendar.',
        'Добавлены specialist filter, fullscreen week и disposable E2E flow создания и открытия занятия.',
        'Добавлен scroll restoration при переходе из прокрученного календаря в карточку.',
        'Добавлен статический project progress dashboard и правило его обязательного сопровождения.',
        'Завершена часть 28: Communications foundation с outbox/inbox, tenant-scoped Messenger handlers и provider-neutral adapters.',
      ],
    },
    {
      date: '2026-09-07',
      items: [
        'Завершена пачка 9: разовые занятия, snapshot услуги и soft warnings с audit events.',
        'Завершена пачка 8: ScheduleAllocation, PostgreSQL overlap protection и hard availability.',
        'Завершена пачка 7: услуги, клиенты, ContactPerson и каналы.',
        'Завершена пачка 6: специалисты, рабочие часы, обеды и дополнительные дни.',
        'Реализованы session authentication и tenant isolation.',
      ],
    },
    {
      date: '2026-09-04',
      items: [
        'Добавлены React/Vite frontend, responsive application shell и PHPUnit/Playwright infrastructure.',
        'Реализованы Organization, AdministratorAccount и безопасное CLI provisioning.',
      ],
    },
    {
      date: '2026-09-03',
      items: [
        'Создан foundation modular monolith на Symfony 7.4 и PHP 8.4.',
        'Настроены Docker Compose, PostgreSQL, Redis, Messenger и Scheduler.',
      ],
    },
  ],
  decisions: [
    { date: '2026-09-10', title: 'Отсутствие специалиста не создаёт FreeWindow', text: 'Затронутые Appointment отменяются как CANCELLED_BY_SPECIALIST, allocations освобождаются, но свободные окна не создаются.' },
    { date: '2026-09-10', title: 'Режим отсутствия клиента определяет lifecycle места', text: 'KEEP_PERMANENT_PLACE сохраняет RegularSchedule и может создать FreeWindow; RELEASE_PERMANENT_PLACE завершает активные правила без удаления клиента и истории.' },
    { date: '2026-09-10', title: 'FreeWindow не равен календарному пробелу', text: 'Окно создаётся только явным действием при будущей отмене клиентом; один Appointment имеет максимум одно окно, а текущая availability проверяется при чтении.' },
    { date: '2026-09-10', title: 'FreeWindow не резервирует интервал', text: 'Создание FreeWindow не создаёт ScheduleAllocation; только будущий Offer для FreeWindow/PermanentPlace вправе создать OFFER_RESERVATION.' },
    { date: '2026-09-10', title: 'Отмена occurrence не меняет регулярное правило', text: 'Result пишется в конкретный Appointment, allocation освобождается, но RegularSchedule и RegularScheduleDay продолжают lifecycle независимо.' },
    { date: '2026-09-10', title: 'Граница поздней отмены строгая', text: 'Отмена клиентом считается поздней только если до начала осталось строго меньше tenant-настройки часов; точное равенство порогу не считается поздним.' },
    { date: '2026-09-10', title: 'Confirmation и результат занятия независимы', text: 'CONFIRMED, CANNOT_ATTEND и NO_RESPONSE не изменяют planning/result state Appointment; отмена и перенос остаются отдельными последующими use cases.' },
    { date: '2026-09-10', title: 'Confirmation callback связан с исходным каналом', text: 'Действие хранится только как SHA-256 hash и принимается один раз при совпадении tenant, ChannelConnection и opaque token; обычный текст не запускает действие.' },
    { date: '2026-09-10', title: 'NO_RESPONSE не завершает возможность ответа', text: 'После операционной отметки «нет ответа» проверенный callback всё ещё принимается до начала занятия; автоматического срока действия action нет.' },
    { date: '2026-09-09', title: 'Шаблоны используют утверждённые placeholders', text: 'Поддерживаются {date}, {time}, {service}, {client_name}, {contact_name}; неизвестные переменные отклоняются, а шаблон услуги имеет приоритет только для подтверждения.' },
    { date: '2026-09-09', title: 'Активация канала одноразовая и tenant-bound', text: 'В БД хранится только SHA-256 activation token с expiry; bot_started атомарно активирует ровно одну scoped connection, повторное и конкурентное использование невозможно.' },
    { date: '2026-09-09', title: 'Capabilities приходят от активного adapter', text: 'Организационный default channel можно выбрать только среди настроенных providers, а UI не предполагает неподтверждённые возможности доставки, чтения или редактирования.' },
    { date: '2026-09-08', title: 'Зависшая обработка восстанавливается по lease', text: 'Outbound и webhook claims имеют пятиминутный processing lease; Scheduler повторно публикует необработанные и просроченные записи без потери tenant scope.' },
    { date: '2026-09-08', title: 'Webhook аутентифицируется до сохранения', text: 'Настроенный provider проверяет raw body и headers до JSON decode и записи inbox; endpoint ограничен rate limit и размером 256 KiB.' },
    { date: '2026-09-08', title: 'Сообщения проходят через transactional outbox', text: 'NotificationIntent и OutboundMessage сохраняются одной транзакцией; scheduler публикует pending-записи, а Redis/Messenger не являются источником истины.' },
    { date: '2026-09-08', title: 'Webhook inbox идемпотентен', text: 'Повтор одного provider event определяется уникальным (organization_id, provider, external_event_id) и возвращает исходную запись без дубля.' },
    { date: '2026-09-08', title: 'MAX adapter отвечает через общий контракт', text: 'MAX REST transport, callback keyboard и webhook secret validation изолированы в MaxChannelProvider; обычный текст не становится бизнес-командой.' },
    { date: '2026-09-08', title: 'MAX event id и capabilities проверяются по документации', text: 'Webhook inbox использует callback/update id вместо hash envelope, normalized events дедуплицируются на уровне БД, а неподдерживаемое редактирование и delivery/read статусы не объявляются capabilities; exactly-once отправка MAX не заявляется.' },
    { date: '2026-09-09', title: 'MAX callback требует подтверждённого получателя', text: 'Actionable callback сохраняется только при совпадении MAX user_id с tenant-owned ChannelConnection; новые connections проходят pending/verified lifecycle через webhook secret и bot_started.' },
    { date: '2026-09-09', title: 'Normalized events имеют durable consumer lifecycle', text: 'Полный provider parser выполняется только worker-ом; normalized event получает claim/retry/processed статус и передаётся общему Messenger consumer с восстановлением через Scheduler.' },
    { date: '2026-09-09', title: 'Normalized consumer commit атомарен', text: 'Consumer mutations и переход event в PROCESSED фиксируются одной DB-транзакцией; ошибка откатывает mutations и возвращает event в retry lifecycle, а PostgreSQL CHECK и tenant-first индексы закрепляют допустимые состояния и recovery.' },
    { date: '2026-09-09', title: 'Outbox metadata ограничен allowlist', text: 'В durable outbox допускается только безопасный source marker; вложенные и переименованные credentials, recipient IDs и неизвестные поля отклоняются, разрешённое поле передаётся adapter без потери.' },
    { date: '2026-09-08', title: 'Провайдеры подключаются через общий контракт', text: 'ChannelProvider и registry изолируют бизнес-логику от MAX, Telegram и WhatsApp; capability flags не выдумывают delivery/read статусы.' },
    { date: '2026-09-08', title: 'Календарь читает snapshot занятия', text: 'Calendar query одним tenant-scoped JOIN получает клиента и специалиста, но название и параметры услуги берёт из неизменяемого Appointment snapshot.' },
    { date: '2026-09-08', title: 'Границы календаря задаёт timezone организации', text: 'Локальные from/to преобразуются в UTC instants на backend; browser timezone не определяет состав календарного дня.' },
    { date: '2026-09-07', title: 'Database-level защита расписания', text: 'Availability check остаётся advisory. Финальную защиту от конкурентного двойного бронирования обеспечивает PostgreSQL exclusion constraint; дополнительные specialist/date locks не вводятся.' },
    { date: '2026-09-07', title: 'Исторические данные услуги', text: 'Appointment хранит неизменяемый snapshot названия и длительности услуги. Переименование или soft delete услуги не меняет историю занятия.' },
    { date: '2026-09-07', title: 'Soft warnings требуют нового подтверждения', text: 'Backend пересчитывает предупреждения при каждой попытке. Принятые предупреждения фиксируются отдельным audit event.' },
    { date: '2026-09-07', title: 'Дополнительный рабочий день дополняет неделю', text: 'Date-specific интервал специалиста добавляется к недельным часам, а не заменяет их.' },
    { date: '2026-09-07', title: 'Tenant ownership защищён на двух уровнях', text: 'Application stores всегда scoped текущей организацией; составные foreign keys с organization_id запрещают cross-tenant связи в PostgreSQL.' },
  ],
  blockers: [],
}
