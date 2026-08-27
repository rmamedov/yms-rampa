# store-web — модуль магазину YMS «Рампа»

Робочий інструмент персоналу магазину: дошка прибуттів на дату, статусні
переходи, walk-in, затримки, переведення на іншу рампу, журнал дій, розклад
тижня. Реалізує розділ 9 SRS (STW-01…STW-42).

## Запуск

```bash
npx nx serve store-web            # dev-сервер
npx nx build store-web --configuration=production
npx nx test store-web --watch=false
npx nx lint store-web
```

## Мок-режим

Бекенд ще не запущено, тому `environment.useMocks = true`. У цьому режимі
`AuthGateway` і `StoreGateway` підмінюються in-memory реалізаціями
(`core/data/mock.gateways.ts` → `core/data/mock-backend.service.ts`), які
відтворюють доменні правила booking-service: допустимі переходи, 409 на
конкурентні зміни, 422-валідації, журнал дій.

Дані магазинів і міст згенеровано з `/fixtures/silpo-branches.json` та
`/fixtures/cities.json` у `core/fixtures/branches.fixture.ts`.

Демо-користувачі (пароль будь-який):

| E-mail | Роль | Магазинів |
|---|---|---|
| `operator@silpo.ua` | store_operator | 2 (є перемикач) |
| `manager@silpo.ua` | store_manager | 3 |
| `single@silpo.ua` | store_operator | 1 (перемикач прихований) |
| `admin@silpo.ua` | admin | 0 → екран «Доступ заборонено» |

Щоб перейти на реальний бекенд, достатньо виставити `useMocks: false` —
активуються `HttpAuthGateway` / `HttpStoreGateway` з базовим шляхом
`/api/store/v1`.

## Структура

```
src/app/
  core/
    api/        ApiClient, authInterceptor (Bearer + refresh на 401), RFC 7807
    auth/       AuthService (RBAC, вибір магазину), guards, TokenStorage
    data/       контракти-гейтвеї, HTTP- і mock-реалізації, BoardStore
    fixtures/   філії Сільпо, демо-користувачі, генератор дня
    i18n/       I18nService + український словник + пайп `| t`
    models/     Booking, StoreConfig, Slot, ProblemDetails, AuditEntry
    util/       правила дій, фільтри/статистика/ризики, час Europe/Kyiv
  features/     login, today (дошка + таймлайн + діалоги), walk-in, week
  shared/       картка бронювання, бейдж статусу, модалка, фільтри, статистика
```

Уся розмітка — без сторонніх UI-бібліотек; стилі — SCSS зі спільними токенами
(`src/styles/_tokens.scss`).
