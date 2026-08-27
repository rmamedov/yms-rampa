# store-service

Мікросервіс YMS «Рампа»: довідник магазинів (філій Сільпо) і всі їх налаштування.
Розділи SRS: **5 (адмін-панель)**, **10.2 (модель даних)**, **11.1 (інтеграція з MCP)**.

## Архітектура

```
src/
  Domain/          чиста доменна логіка — без Symfony, MongoDB і HTTP
    Shared/        Clock, Uuid, Timezone, доменні винятки (RFC 7807-сумісні)
    Branch/        Branch, McpData, GeoLocation, YmsStatus, BranchEligibility, BranchRepository
    Configuration/ StoreConfiguration, ReceivingWindow, TimeInterval, Ramp, SlotSize,
                   CalendarException, ReservedSlotRule, SlotBlock + інтерфейси репозиторіїв
    Sync/          BranchSource, BranchSynchronizer, SyncReport, SyncLogEntry
    Event/         канонічні події BranchSynced, StoreConfigChanged, SlotReleased
  Application/     прикладні сервіси і presenter'и JSON
  Infrastructure/
    InMemory/      реалізації репозиторіїв у памʼяті (dev + юніт-тести)
    Mongo/         реалізації на ext-mongodb (прод), мапери документів, індекси
    Fixture/       FixtureBranchSource / ArrayBranchSource
    Http/          RFC 7807 problem+json
  Controller/      /api/admin/v1/..., /api/supplier/v1/... і службові /internal/v1/...
  Command/         yms:branches:import, yms:branches:sync, yms:mongo:init
```

Репозиторії описані **інтерфейсами в домені**. У `dev`/`test` вони прив'язані до
InMemory-реалізацій (MongoDB може бути ще не піднято) у `config/services.yaml`,
у `prod` — до Mongo-реалізацій у `config/packages/prod/storage.yaml`. Відсутність
розширення `ext-mongodb` не ламає ні автозавантаження, ні контейнер, ні тести:
перевірка виконується в рантаймі через `MongoConnection::isAvailable()`.

### Прод-профіль сховища

`config/packages/prod/storage.yaml` — **єдине джерело правди** для звʼязування
доменних портів із MongoDB (`MONGODB_URL`, `MONGODB_DB`). Через порядок імпорту
в `MicroKernelTrait`:

```
config/packages/*.yaml → config/packages/prod/*.yaml → config/services.yaml → config/services_prod.yaml
```

`config/services.yaml` вантажиться **після** `config/packages/prod/`, тож його
аліаси на InMemory мовчки перетерли б прод-звʼязування. Тому `config/services_prod.yaml`
імпортує прод-профіль ще раз, останнім. Не переносьте ці id у кореневий блок
`services:` файлу `config/services.yaml` — прод знову опиниться на InMemory.

Перевірка звʼязування без запущеної MongoDB:

```bash
APP_ENV=prod php bin/console cache:clear --no-interaction
APP_ENV=prod php bin/console lint:container
```

## Команди

| Команда | Призначення |
|---|---|
| `php bin/console yms:branches:import [-f файл]` | Первинний імпорт довідника з фікстури MCP із правилами фільтрації |
| `php bin/console yms:branches:sync` | Планова синхронізація (INT-04: cron 03:00 Europe/Kyiv) |
| `php bin/console yms:mongo:init` | Створення індексів колекцій БД `stores` (10.2) |

## HTTP API

Контур **staff**:

```
GET    /api/admin/v1/stores                       список з фільтрами і пагінацією (STL-01..06)
GET    /api/admin/v1/stores/cities                довідник міст для фільтра
GET    /api/admin/v1/stores/{storeId}             картка магазину (STC-01, STC-02)
PATCH  /api/admin/v1/stores/{storeId}             оновлення YMS-полів (STC-02..04, STC-07)
POST   /api/admin/v1/stores/bulk/status           масова зміна статусу (UI-02)
GET    /api/admin/v1/stores/{id}/configurations           версії конфігурації
GET    /api/admin/v1/stores/{id}/configurations/current   чинна версія
POST   /api/admin/v1/stores/{id}/configurations           нова версія «з дати X» (STC-60, DATA-09)
GET|POST        /api/admin/v1/stores/{id}/reserved-slot-rules          резерви (STC-40..43)
PUT|PATCH|DELETE /api/admin/v1/stores/{id}/reserved-slot-rules/{ruleId}
GET|POST        /api/admin/v1/stores/{id}/slot-blocks                  блокування (STC-50..52)
POST            /api/admin/v1/stores/{id}/slot-blocks/{blockId}/release
GET    /api/admin/v1/sync/log                     журнал синхронізацій (SYNC-01)
POST   /api/admin/v1/sync/run                     ручний запуск (SYNC-02, INT-05)
```

Контур **partner** (лише `ymsStatus=active` І `visibleToSuppliers=true`, STC-04 / DATA-08):

```
GET /api/supplier/v1/cities
GET /api/supplier/v1/stores?city=...
GET /api/supplier/v1/stores/{storeId}
```

Службовий контур **internal** (виклики між мікросервісами):

```
GET /internal/v1/stores/{storeId}/settings   чинна конфігурація магазину для booking-service
```

Помилки — `application/problem+json` з розширеннями `code` і `requestId`.

### Службовий ендпоінт для booking-service

`GET /internal/v1/stores/{storeId}/settings` віддає booking-service усе, з чого той
будує сітку слотів і валідує бронювання. Ключі верхнього рівня:

```
storeId, ymsStatus, visibleToSuppliers, snapshot{externalId,displayName,city,address},
configVersion, effectiveFrom,
receivingWindows[{dayOfWeek,intervals[{from,to}]}], slotSizeMinutes,
ramps[{rampId,number,name,active}], maxVehicleWeightTons,
leadTimeMinutes, bookingHorizonDays, noShowGraceMinutes, holdMaxMinutes,
calendarExceptions[{date,closed,reason,intervals[{from,to}]}],
reservedSlotRules[{supplierId,rampId,slotStartTime,dayOfWeek,date,validFrom,validTo,active}],
slotBlocks[{rampIds,coversAllRamps,blockFrom,blockTo,reason}]
```

Форму диктує споживач — `booking-service/src/Infrastructure/Store/HttpStoreConfigProvider.php`,
тому вона відрізняється від адмінського `ConfigurationPresenter`:

- виняток календаря несе **булеве `closed`**, а не рядкове `type` (`closed|custom`);
- `validFrom`/`validTo` правила резерву — **локальні дати `Y-m-d`** (booking-service
  порівнює їх з датою слота рядково), а не мітки часу;
- `name` рампи ніколи не `null` — підставляється «Рампа N».

Резерви і блокування віддаються **чинні**: активні правила, чий період дії перетинає
горизонт бронювання, і незняті блокування в межах того ж горизонту.

Магазин, по якому сітки існувати не повинно, віддає **404**, а не порожню конфігурацію:

| Причина | Код |
|---|---|
| філії немає в довіднику | `STORE_NOT_FOUND` |
| `ymsStatus ≠ active` (STC-03..06) | `STORE_NOT_CONFIGURED` |
| немає чинної версії конфігурації | `STORE_NOT_CONFIGURED` |
| конфігурація неповна за STL-04 | `STORE_NOT_CONFIGURED` |

**Автентифікація.** Маршрути `/internal/` не проходять через `auth_request` і не
отримують заголовків ідентичності: внутрішній шлюз (`infra/nginx-yms-internal.conf`)
слухає лише `127.0.0.1:8081`, а публічні server-блоки префікс `/internal/` не
обслуговують. `ActorResolver` тут свідомо не викликається — інакше кожен виклик
booking-service отримає `403 ACCESS_DENIED`.

## Ідентичність і скоуп

Сервіс не перевіряє JWT: api-gateway (nginx `auth_request` → `GET /internal/v1/auth/verify`
identity-сервісу) підставляє в кожен запит службові заголовки єдиного контракту, які читає
`Infrastructure\Http\ActorResolver`:

```
X-User-Id      ідентифікатор користувача
X-User-Role    рівно одна роль (RBAC-04): super_admin | network_manager | store_manager |
               store_operator | analyst | supplier_admin | supplier_operator | driver
X-Supplier-Id  постачальник; порожній рядок, якщо не застосовно (staff-контур)
X-Store-Ids    магазини скоупу через кому без пробілів («S-01,S-02»); порожній, якщо не застосовно
X-Contour      staff | partner
```

Запит без ідентичності, з невідомою роллю, з роллю чужого контуру або з `X-Contour`, що
суперечить ролі, відхиляється (403 `ACCESS_DENIED`) — довіра «шлюз уже перевірив» заборонена
(RBAC-20).

Скоуп магазинів (`Domain\Access\Actor::storeScope()`) перевіряється повторно тут:

- **RBAC-13.** Для `store_manager` і `store_operator` **порожній** `X-Store-Ids` означає
  **нуль доступу** — жодного магазину. Це НЕ «усі магазини».
- **RBAC-16.** Скоуп «уся мережа» дає роль (`super_admin`, `network_manager`, `analyst`),
  а не перелік магазинів (у них він завжди порожній).
- **RBAC-17.** Скоуп застосовується предикатом вибірки (`BranchCriteria::scopedStoreIds`),
  а не пост-фільтрацією сторінки в памʼяті.
- **RBAC-18.** Колекції фільтруються мовчки; читання магазину поза скоупом — `404
  STORE_NOT_FOUND` (існування не розкривається); дія — `403 RBAC_SCOPE_VIOLATION`.
- **RBAC-14.** Роль партнерського контуру без `X-Supplier-Id` відхиляється. Для постачальника
  перелік магазинів (SUP-03) може лише **звузити** вибірку видимих філій; сам whitelist
  постачальника живе в partner-service і контрактом ідентичності не передається.

## Правила фільтрації довідника

Запис MCP не придатний до активації (`fixtures/README.md`), якщо: `externalId` починається
з `delete_`; порожні `city` або `address`; відсутні координати; координати поза bbox України
(lat 44.0–52.5, lon 22.0–40.5). Такі філії **все одно імпортуються** зі статусом
`not_configured` і збереженим переліком причин, але не активуються і невидимі постачальникам.
`hasPickup=null` нормалізується у `false`.

На реальній фікстурі (455 записів): придатних — 445, непридатних — 10.

## Тести

```bash
vendor/bin/phpunit                      # усі тести (без MongoDB і Redis)
vendor/bin/phpunit --exclude-group integration
```

Юніт-тести працюють на InMemory-реалізаціях. Тести з `#[Group('integration')]`
пропускаються (`markTestSkipped`), якщо немає розширення `ext-mongodb` або сервера MongoDB.
