window.PROJECT_PROGRESS = {
  project: {
    name: 'Vovremya',
    state: 'Пачка 11 завершена',
    currentBatch: 12,
    currentPart: 28,
    currentItem: 'Communications foundation · ожидает старта',
    updatedAt: '2026-09-08',
  },
  statusLabels: {
    done: 'Выполнено',
    in_progress: 'В работе',
    pending: 'Ожидает',
    blocked: 'Заблокировано',
    review: 'Требует проверки',
  },
  current: {
    title: 'Пачка 12 · Часть 28',
    items: [
      'Пачка 11 завершена: регулярные правила управляются от формы до календаря.',
      'Следующий шаг: Communications foundation, шаблоны и подключения каналов.',
    ],
    description: 'Части 25–27 завершены: разные времена по дням, rolling materialization, ScheduleGenerationIssue, изменение и завершение правил.',
    outcome: 'Регулярные занятия создаются в конфигурируемом горизонте; конфликтные даты видимы, а история заменённых занятий сохраняется.',
  },
  nextPartNumbers: [28, 29, 30, 31, 32],
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
        { number: 28, title: 'Communications foundation', status: 'pending' },
        { number: 29, title: 'Шаблоны сообщений', status: 'pending' },
        { number: 30, title: 'Подключение каналов и capabilities', status: 'pending' },
      ],
    },
    { number: 13, title: 'MAX', parts: [{ number: 31, title: 'MAX adapter', status: 'pending' }] },
    {
      number: 14,
      title: 'Подтверждения и ответы клиента',
      parts: [
        { number: 32, title: 'Запросы подтверждения и фоновые сроки', status: 'pending' },
        { number: 33, title: 'Ответы клиента на подтверждение', status: 'pending' },
      ],
    },
    {
      number: 15,
      title: 'Результаты, отмены и FreeWindow',
      parts: [
        { number: 34, title: 'Результаты, история и поздняя отмена', status: 'pending' },
        { number: 35, title: 'Отмена отдельного занятия', status: 'pending' },
        { number: 36, title: 'FreeWindow core', status: 'pending' },
      ],
    },
    {
      number: 16,
      title: 'Отсутствие специалиста и клиента',
      parts: [
        { number: 37, title: 'Отсутствие специалиста', status: 'pending' },
        { number: 38, title: 'Отсутствие клиента', status: 'pending' },
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
      date: '2026-09-08',
      items: [
        'Завершена пачка 10: Calendar API, «Сегодня», карточка занятия и responsive day/week views.',
        'Реализованы Calendar API/read model, экран «Сегодня», карточка занятия и responsive day/week calendar.',
        'Добавлены specialist filter, fullscreen week и disposable E2E flow создания и открытия занятия.',
        'Добавлен scroll restoration при переходе из прокрученного календаря в карточку.',
        'Добавлен статический project progress dashboard и правило его обязательного сопровождения.',
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
