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
  Controller/      /api/admin/v1/... і /api/supplier/v1/...
  Command/         yms:branches:import, yms:branches:sync, yms:mongo:init
```

Репозиторії описані **інтерфейсами в домені**. У `dev`/`test` вони прив'язані до
InMemory-реалізацій (MongoDB може бути ще не піднято), у `prod` — до Mongo-реалізацій
(`config/services.yaml`, секція `when@prod`). Відсутність розширення `ext-mongodb`
не ламає ні автозавантаження, ні контейнер, ні тести: перевірка виконується в рантаймі
через `MongoConnection::isAvailable()`.

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

Помилки — `application/problem+json` з розширеннями `code` і `requestId`.

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
