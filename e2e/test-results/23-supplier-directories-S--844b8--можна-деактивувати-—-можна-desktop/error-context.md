# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 23-supplier-directories.spec.ts >> S-09 Довідник авто >> S-09.8 авто з активним бронюванням видалити не можна, деактивувати — можна
- Location: tests/23-supplier-directories.spec.ts:220:7

# Error details

```
Error: expect(locator).toContainText(expected) failed

Locator: locator('.toast__text').first()
Expected substring: "Авто привʼязане до активних бронювань — доступна лише деактивація"
Timeout: 15000ms
Error: element(s) not found

Call log:
  - Expect "toContainText" with timeout 15000ms
  - waiting for locator('.toast__text').first()
    14 × locator resolved to <span class="toast__text" _ngcontent-ng-c1778349596="">Сервіс бронювань тимчасово недоступний, тому пере…</span>
       - unexpected value "Сервіс бронювань тимчасово недоступний, тому перевірити історію поставок неможливо: авто не видалено (HTTP 404). Спробуйте ще раз за кілька хвилин."

```

```yaml
- complementary:
  - link "Рампа Кабінет постачальника":
    - /url: /home
    - strong: Рампа
    - text: Кабінет постачальника
  - navigation "Головна навігація":
    - link "Головна":
      - /url: /home
    - link "Нове бронювання":
      - /url: /booking/cities
    - link "Маршрутні листи":
      - /url: /route-sheets
    - link "Мої авто":
      - /url: /vehicles
    - link "Водії":
      - /url: /drivers
  - text: supplier@rampa.ua Адміністратор постачальника
  - button "Вийти"
- main:
  - heading "Мої авто" [level=1]
  - paragraph: Довідник машин вашого підприємства
  - button "Додати авто"
  - searchbox "Пошук за держномером або маркою"
  - table:
    - rowgroup:
      - row "Держномер Марка / модель Вантажопідйомність, т Статус":
        - columnheader "Держномер"
        - columnheader "Марка / модель"
        - columnheader "Вантажопідйомність, т"
        - columnheader "Статус"
        - columnheader
    - rowgroup:
      - row "UT1078XX UITEST Scania 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1078XX":
          - strong: UT1078XX
        - cell "UITEST Scania"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT1090XX UITEST Renault 1090 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1090XX":
          - strong: UT1090XX
        - cell "UITEST Renault 1090"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT1211XX UITEST новий 18.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1211XX":
          - strong: UT1211XX
        - cell "UITEST новий"
        - cell "18.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT1244XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1244XX":
          - strong: UT1244XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT1332XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1332XX":
          - strong: UT1332XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT1349XX UITEST 4 т Активне Редагувати Деактивувати Видалити":
        - cell "UT1349XX":
          - strong: UT1349XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Активне"
        - cell "Редагувати Деактивувати Видалити":
          - button "Редагувати"
          - button "Деактивувати"
          - button "Видалити"
      - row "UT1360XX UITEST DAF 12 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1360XX":
          - strong: UT1360XX
        - cell "UITEST DAF"
        - cell "12 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT1374XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1374XX":
          - strong: UT1374XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT1406XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1406XX":
          - strong: UT1406XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT1424XX UITEST перенесення 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1424XX":
          - strong: UT1424XX
        - cell "UITEST перенесення"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT1541XX UITEST 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1541XX":
          - strong: UT1541XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT1601XX UITEST Scania 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1601XX":
          - strong: UT1601XX
        - cell "UITEST Scania"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT1689XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1689XX":
          - strong: UT1689XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT1724XX UITEST новий 18.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1724XX":
          - strong: UT1724XX
        - cell "UITEST новий"
        - cell "18.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT1789XX — 3 т Активне Редагувати Деактивувати Видалити":
        - cell "UT1789XX":
          - strong: UT1789XX
        - cell "—"
        - cell "3 т"
        - cell "Активне"
        - cell "Редагувати Деактивувати Видалити":
          - button "Редагувати"
          - button "Деактивувати"
          - button "Видалити"
      - row "UT1856XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT1856XX":
          - strong: UT1856XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT2115XX UITEST 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT2115XX":
          - strong: UT2115XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT2137XX UITEST DAF 12 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT2137XX":
          - strong: UT2137XX
        - cell "UITEST DAF"
        - cell "12 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT2483XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT2483XX":
          - strong: UT2483XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT2631XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT2631XX":
          - strong: UT2631XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT2634XX UITEST 3 т Активне Редагувати Деактивувати Видалити":
        - cell "UT2634XX":
          - strong: UT2634XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Активне"
        - cell "Редагувати Деактивувати Видалити":
          - button "Редагувати"
          - button "Деактивувати"
          - button "Видалити"
      - row "UT2769XX UITEST перенесення 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT2769XX":
          - strong: UT2769XX
        - cell "UITEST перенесення"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT2805XX UITEST Scania 4 т Активне Редагувати Деактивувати Видалити":
        - cell "UT2805XX":
          - strong: UT2805XX
        - cell "UITEST Scania"
        - cell "4 т"
        - cell "Активне"
        - cell "Редагувати Деактивувати Видалити":
          - button "Редагувати"
          - button "Деактивувати"
          - button "Видалити"
      - row "UT3030XX UITEST новий 18.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT3030XX":
          - strong: UT3030XX
        - cell "UITEST новий"
        - cell "18.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT3071XX UITEST MAN TGX 6.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT3071XX":
          - strong: UT3071XX
        - cell "UITEST MAN TGX"
        - cell "6.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT3205XX UITEST Volvo FH 5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT3205XX":
          - strong: UT3205XX
        - cell "UITEST Volvo FH"
        - cell "5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT3226XX UITEST DAF 12 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT3226XX":
          - strong: UT3226XX
        - cell "UITEST DAF"
        - cell "12 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT3308XX UITEST Renault 3308 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT3308XX":
          - strong: UT3308XX
        - cell "UITEST Renault 3308"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT3395XX UITEST 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT3395XX":
          - strong: UT3395XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT3434XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT3434XX":
          - strong: UT3434XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT3493XX UITEST 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT3493XX":
          - strong: UT3493XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT3604XX Renault 3604 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT3604XX":
          - strong: UT3604XX
        - cell "Renault 3604"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT3801XX UITEST Renault 3801 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT3801XX":
          - strong: UT3801XX
        - cell "UITEST Renault 3801"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT3819XX UITEST MAN TGX 6.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT3819XX":
          - strong: UT3819XX
        - cell "UITEST MAN TGX"
        - cell "6.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT3873XX UITEST MAN TGX 6.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT3873XX":
          - strong: UT3873XX
        - cell "UITEST MAN TGX"
        - cell "6.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT3874XX UITEST Renault 3874 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT3874XX":
          - strong: UT3874XX
        - cell "UITEST Renault 3874"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4032XX UITEST 9 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4032XX":
          - strong: UT4032XX
        - cell "UITEST"
        - cell "9 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4085XX UITEST DAF 12 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4085XX":
          - strong: UT4085XX
        - cell "UITEST DAF"
        - cell "12 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4091XX UITEST Scania 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4091XX":
          - strong: UT4091XX
        - cell "UITEST Scania"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4115XX UITEST MAN TGX 6.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4115XX":
          - strong: UT4115XX
        - cell "UITEST MAN TGX"
        - cell "6.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4146XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4146XX":
          - strong: UT4146XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4167XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4167XX":
          - strong: UT4167XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4233XX — 3 т Активне Редагувати Деактивувати Видалити":
        - cell "UT4233XX":
          - strong: UT4233XX
        - cell "—"
        - cell "3 т"
        - cell "Активне"
        - cell "Редагувати Деактивувати Видалити":
          - button "Редагувати"
          - button "Деактивувати"
          - button "Видалити"
      - row "UT4321XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4321XX":
          - strong: UT4321XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4415XX UITEST 9 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4415XX":
          - strong: UT4415XX
        - cell "UITEST"
        - cell "9 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4424XX UITEST новий 18.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4424XX":
          - strong: UT4424XX
        - cell "UITEST новий"
        - cell "18.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4439XX Renault 4439 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4439XX":
          - strong: UT4439XX
        - cell "Renault 4439"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4480XX UITEST Renault 4480 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4480XX":
          - strong: UT4480XX
        - cell "UITEST Renault 4480"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4544XX UITEST Volvo FH 5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4544XX":
          - strong: UT4544XX
        - cell "UITEST Volvo FH"
        - cell "5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4715XX UITEST Scania 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4715XX":
          - strong: UT4715XX
        - cell "UITEST Scania"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4718XX UITEST новий 18.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4718XX":
          - strong: UT4718XX
        - cell "UITEST новий"
        - cell "18.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4859XX UITEST Volvo FH 5 т Активне Редагувати Деактивувати Видалити":
        - cell "UT4859XX":
          - strong: UT4859XX
        - cell "UITEST Volvo FH"
        - cell "5 т"
        - cell "Активне"
        - cell "Редагувати Деактивувати Видалити":
          - button "Редагувати"
          - button "Деактивувати"
          - button "Видалити"
      - row "UT4944XX UITEST новий 18.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4944XX":
          - strong: UT4944XX
        - cell "UITEST новий"
        - cell "18.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT4982XX UITEST перенесення 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT4982XX":
          - strong: UT4982XX
        - cell "UITEST перенесення"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT5042XX UITEST 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT5042XX":
          - strong: UT5042XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT5267XX — 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT5267XX":
          - strong: UT5267XX
        - cell "—"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT5314XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT5314XX":
          - strong: UT5314XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT5317XX UITEST 9 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT5317XX":
          - strong: UT5317XX
        - cell "UITEST"
        - cell "9 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT5438XX UITEST перенесення 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT5438XX":
          - strong: UT5438XX
        - cell "UITEST перенесення"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT5444XX UITEST 9 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT5444XX":
          - strong: UT5444XX
        - cell "UITEST"
        - cell "9 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT5560XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT5560XX":
          - strong: UT5560XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT5644XX UITEST Scania 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT5644XX":
          - strong: UT5644XX
        - cell "UITEST Scania"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT5764XX UITEST 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT5764XX":
          - strong: UT5764XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT5782XX UITEST Scania 4 т Активне Редагувати Деактивувати Видалити":
        - cell "UT5782XX":
          - strong: UT5782XX
        - cell "UITEST Scania"
        - cell "4 т"
        - cell "Активне"
        - cell "Редагувати Деактивувати Видалити":
          - button "Редагувати"
          - button "Деактивувати"
          - button "Видалити"
      - row "UT5844XX UITEST 9 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT5844XX":
          - strong: UT5844XX
        - cell "UITEST"
        - cell "9 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT5894XX UITEST 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT5894XX":
          - strong: UT5894XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT5950XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT5950XX":
          - strong: UT5950XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT5993XX UITEST перенесення 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT5993XX":
          - strong: UT5993XX
        - cell "UITEST перенесення"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT6020XX Renault 6020 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT6020XX":
          - strong: UT6020XX
        - cell "Renault 6020"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT6094XX UITEST DAF 12 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT6094XX":
          - strong: UT6094XX
        - cell "UITEST DAF"
        - cell "12 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT6176XX Renault 6176 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT6176XX":
          - strong: UT6176XX
        - cell "Renault 6176"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT6180XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT6180XX":
          - strong: UT6180XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT6182XX — 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT6182XX":
          - strong: UT6182XX
        - cell "—"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT6220XX UITEST 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT6220XX":
          - strong: UT6220XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT6297XX UITEST MAN TGX 6.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT6297XX":
          - strong: UT6297XX
        - cell "UITEST MAN TGX"
        - cell "6.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT6446XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT6446XX":
          - strong: UT6446XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT6481XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT6481XX":
          - strong: UT6481XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT6522XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT6522XX":
          - strong: UT6522XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT6551XX — 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT6551XX":
          - strong: UT6551XX
        - cell "—"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT6762XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT6762XX":
          - strong: UT6762XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT7154XX UITEST 9 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT7154XX":
          - strong: UT7154XX
        - cell "UITEST"
        - cell "9 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT7226XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT7226XX":
          - strong: UT7226XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT7492XX UITEST MAN TGX 6.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT7492XX":
          - strong: UT7492XX
        - cell "UITEST MAN TGX"
        - cell "6.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT7582XX UITEST Renault 7582 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT7582XX":
          - strong: UT7582XX
        - cell "UITEST Renault 7582"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT7630XX UITEST перенесення 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT7630XX":
          - strong: UT7630XX
        - cell "UITEST перенесення"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT7641XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT7641XX":
          - strong: UT7641XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT7675XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT7675XX":
          - strong: UT7675XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT7694XX Renault 7694 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT7694XX":
          - strong: UT7694XX
        - cell "Renault 7694"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT7753XX UITEST перенесення 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT7753XX":
          - strong: UT7753XX
        - cell "UITEST перенесення"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT7892XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT7892XX":
          - strong: UT7892XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT7991XX UITEST 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT7991XX":
          - strong: UT7991XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT8007XX UITEST 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT8007XX":
          - strong: UT8007XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT8131XX — 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT8131XX":
          - strong: UT8131XX
        - cell "—"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT8262XX UITEST Volvo FH 5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT8262XX":
          - strong: UT8262XX
        - cell "UITEST Volvo FH"
        - cell "5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT8349XX UITEST DAF 12 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT8349XX":
          - strong: UT8349XX
        - cell "UITEST DAF"
        - cell "12 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT8364XX UITEST перенесення 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT8364XX":
          - strong: UT8364XX
        - cell "UITEST перенесення"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT8376XX UITEST перенесення 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT8376XX":
          - strong: UT8376XX
        - cell "UITEST перенесення"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT8502XX UITEST Renault 8502 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT8502XX":
          - strong: UT8502XX
        - cell "UITEST Renault 8502"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT8512XX Renault 8512 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT8512XX":
          - strong: UT8512XX
        - cell "Renault 8512"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT8628XX UITEST 3 т Активне Редагувати Деактивувати Видалити":
        - cell "UT8628XX":
          - strong: UT8628XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Активне"
        - cell "Редагувати Деактивувати Видалити":
          - button "Редагувати"
          - button "Деактивувати"
          - button "Видалити"
      - row "UT8681XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT8681XX":
          - strong: UT8681XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT8685XX UITEST 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT8685XX":
          - strong: UT8685XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT8731XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT8731XX":
          - strong: UT8731XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT8794XX UITEST MAN TGX 6.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT8794XX":
          - strong: UT8794XX
        - cell "UITEST MAN TGX"
        - cell "6.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT8888XX UITEST probe 3 т Активне Редагувати Деактивувати Видалити":
        - cell "UT8888XX":
          - strong: UT8888XX
        - cell "UITEST probe"
        - cell "3 т"
        - cell "Активне"
        - cell "Редагувати Деактивувати Видалити":
          - button "Редагувати"
          - button "Деактивувати"
          - button "Видалити"
      - row "UT9028XX UITEST 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT9028XX":
          - strong: UT9028XX
        - cell "UITEST"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT9079XX UITEST 9 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT9079XX":
          - strong: UT9079XX
        - cell "UITEST"
        - cell "9 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT9096XX UITEST новий 18.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT9096XX":
          - strong: UT9096XX
        - cell "UITEST новий"
        - cell "18.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT9147XX UITEST Renault 9147 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT9147XX":
          - strong: UT9147XX
        - cell "UITEST Renault 9147"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT9222XX UITEST 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT9222XX":
          - strong: UT9222XX
        - cell "UITEST"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT9316XX UITEST перенесення 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT9316XX":
          - strong: UT9316XX
        - cell "UITEST перенесення"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT9359XX UITEST Renault 9359 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT9359XX":
          - strong: UT9359XX
        - cell "UITEST Renault 9359"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT9395XX — 3 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT9395XX":
          - strong: UT9395XX
        - cell "—"
        - cell "3 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT9600XX UITEST MAN TGX 6.5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT9600XX":
          - strong: UT9600XX
        - cell "UITEST MAN TGX"
        - cell "6.5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT9795XX Renault 9795 4 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT9795XX":
          - strong: UT9795XX
        - cell "Renault 9795"
        - cell "4 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT9823XX UITEST Scania 4 т Активне Редагувати Деактивувати Видалити":
        - cell "UT9823XX":
          - strong: UT9823XX
        - cell "UITEST Scania"
        - cell "4 т"
        - cell "Активне"
        - cell "Редагувати Деактивувати Видалити":
          - button "Редагувати"
          - button "Деактивувати"
          - button "Видалити"
      - row "UT9955XX UITEST Volvo FH 5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT9955XX":
          - strong: UT9955XX
        - cell "UITEST Volvo FH"
        - cell "5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT9979XX UITEST Volvo FH 5 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT9979XX":
          - strong: UT9979XX
        - cell "UITEST Volvo FH"
        - cell "5 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
      - row "UT9997XX UITEST DAF 12 т Деактивоване Редагувати Активувати Видалити":
        - cell "UT9997XX":
          - strong: UT9997XX
        - cell "UITEST DAF"
        - cell "12 т"
        - cell "Деактивоване"
        - cell "Редагувати Активувати Видалити":
          - button "Редагувати"
          - button "Активувати"
          - button "Видалити"
- status
```

# Test source

```ts
  146 |     await expect(page.locator('.modal__window')).toContainText('Авто з таким номером уже є у вашому довіднику');
  147 |     await expect(page.locator('.modal__window button:has-text("Зберегти")')).toBeDisabled();
  148 | 
  149 |     // Той самий номер у нижньому регістрі — теж дублікат.
  150 |     await saveVehicleForm(page, { plate: plate.toLowerCase() });
  151 |     await expect(page.locator('.modal__window')).toContainText('Авто з таким номером уже є у вашому довіднику');
  152 |   });
  153 | 
  154 |   test('S-09.5 редагування зберігає нові марку і вантажопідйомність', async ({ page }) => {
  155 |     const plate = await api.freePlate();
  156 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand: 'UITEST старий' });
  157 |     createdVehicles.push(vehicle.id);
  158 | 
  159 |     await loginSupplier(page);
  160 |     await goto(page, '/vehicles');
  161 |     await row(page, plate).locator('button:has-text("Редагувати")').click();
  162 |     await expect(page.locator('.modal__title')).toHaveText('Редагування авто');
  163 |     expect(await page.locator('#veh-plate').inputValue(), 'форма має відкритись із поточними даними').toBe(plate);
  164 | 
  165 |     await saveVehicleForm(page, { brand: 'UITEST новий', weight: '18.5' });
  166 |     await Promise.all([
  167 |       page.waitForResponse((r) => r.url().includes('/vehicles/') && r.request().method() === 'PATCH'),
  168 |       page.locator('.modal__window button:has-text("Зберегти")').click(),
  169 |     ]);
  170 | 
  171 |     await expect(row(page, plate)).toContainText('UITEST новий');
  172 |     const updated = (await api.vehicles()).find((v) => v.id === vehicle.id);
  173 |     expect(updated?.brand).toBe('UITEST новий');
  174 |     expect(updated?.weightTons).toBe(18.5);
  175 |   });
  176 | 
  177 |   test('S-09.6 деактивація і повернення авто в роботу', async ({ page }) => {
  178 |     const plate = await api.freePlate();
  179 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4 });
  180 |     createdVehicles.push(vehicle.id);
  181 | 
  182 |     await loginSupplier(page);
  183 |     await goto(page, '/vehicles');
  184 | 
  185 |     await row(page, plate).locator('button:has-text("Деактивувати")').click();
  186 |     await expect(page.locator('.toast__text').first()).toHaveText('Авто деактивовано');
  187 |     await expect(row(page, plate)).toContainText('Деактивоване');
  188 |     expect((await api.vehicles()).find((v) => v.id === vehicle.id)?.active).toBe(false);
  189 | 
  190 |     await row(page, plate).locator('button:has-text("Активувати")').click();
  191 |     await page.waitForTimeout(800);
  192 |     await expect(row(page, plate)).toContainText('Активне');
  193 |     expect((await api.vehicles()).find((v) => v.id === vehicle.id)?.active).toBe(true);
  194 |   });
  195 | 
  196 |   test('S-09.7 видалення авто, яке ніде не використовується', async ({ page }) => {
  197 |     const plate = await api.freePlate();
  198 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4 });
  199 |     createdVehicles.push(vehicle.id);
  200 | 
  201 |     await loginSupplier(page);
  202 |     await goto(page, '/vehicles');
  203 |     await row(page, plate).locator('button:has-text("Видалити")').click();
  204 |     await expect(page.locator('.modal__window')).toContainText(`Видалити авто ${plate}?`);
  205 | 
  206 |     const [response] = await Promise.all([
  207 |       page.waitForResponse((r) => r.url().includes('/vehicles/') && r.request().method() === 'DELETE'),
  208 |       page.locator('.modal__window button:has-text("Видалити")').click(),
  209 |     ]);
  210 | 
  211 |     expect(
  212 |       response.status(),
  213 |       'авто без жодного бронювання має видалятись; ' +
  214 |         `сервер відповів ${response.status()}: ${normalizedText(await response.text()).slice(0, 200)}`,
  215 |     ).toBe(204);
  216 |     await expect(row(page, plate)).toHaveCount(0);
  217 |     expect((await api.vehicles()).some((v) => v.id === vehicle.id)).toBe(false);
  218 |   });
  219 | 
  220 |   test('S-09.8 авто з активним бронюванням видалити не можна, деактивувати — можна', async ({ page }) => {
  221 |     const plate = await api.freePlate();
  222 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 3 });
  223 |     createdVehicles.push(vehicle.id);
  224 | 
  225 |     const store = kharkiv[0];
  226 |     const date = workingDay(1);
  227 |     const grid = await api.slots(store.storeId, date);
  228 |     const free = grid.slots.filter((s) => s.selectable).pop();
  229 |     expect(free, 'потрібен вільний слот').toBeTruthy();
  230 |     const booking = await api.createBooking({
  231 |       storeId: store.storeId,
  232 |       rampId: free!.rampId,
  233 |       slotStart: free!.slotStart,
  234 |       plateNumber: plate,
  235 |       weightTons: 3,
  236 |       palletsCount: 4,
  237 |       orderId: 'UITEST-veh-lock',
  238 |     });
  239 |     createdBookings.push(booking.id);
  240 | 
  241 |     await loginSupplier(page);
  242 |     await goto(page, '/vehicles');
  243 |     await row(page, plate).locator('button:has-text("Видалити")').click();
  244 |     await page.locator('.modal__window button:has-text("Видалити")').click();
  245 | 
> 246 |     await expect(page.locator('.toast__text').first()).toContainText(
      |                                                        ^ Error: expect(locator).toContainText(expected) failed
  247 |       'Авто привʼязане до активних бронювань — доступна лише деактивація',
  248 |     );
  249 |     await expect(row(page, plate), 'авто має лишитись у довіднику').toBeVisible();
  250 | 
  251 |     await row(page, plate).locator('button:has-text("Деактивувати")').click();
  252 |     await expect(row(page, plate)).toContainText('Деактивоване');
  253 |   });
  254 | 
  255 |   test('S-09.9 пошук у довіднику за номером і за маркою', async ({ page }) => {
  256 |     const plate = await api.freePlate();
  257 |     const mark = plate.slice(2, 6);
  258 |     const brand = `UITEST Renault ${mark}`;
  259 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand });
  260 |     createdVehicles.push(vehicle.id);
  261 | 
  262 |     await loginSupplier(page);
  263 |     await goto(page, '/vehicles');
  264 |     const search = page.locator('.vehicles__search');
  265 |     const rows = page.locator('.table tbody tr');
  266 | 
  267 |     const vehicles = await api.vehicles();
  268 |     await search.fill(mark);
  269 |     await page.waitForTimeout(300);
  270 |     const expected = vehicles.filter((v) => v.plateNumber.includes(mark) || (v.brand ?? '').includes(mark)).length;
  271 |     expect(await rows.count(), `пошук за фрагментом номера «${mark}»`).toBe(expected);
  272 | 
  273 |     // Держномер із пробілами — так його диктують і записують.
  274 |     await search.fill(`${plate.slice(0, 2)} ${plate.slice(2, 6)} ${plate.slice(6)}`);
  275 |     await page.waitForTimeout(300);
  276 |     await expect(rows, 'пошук за номером із пробілами').toHaveCount(1);
  277 | 
  278 |     await search.fill('renault');
  279 |     await page.waitForTimeout(300);
  280 |     const byBrandWord = vehicles.filter((v) => (v.brand ?? '').toLowerCase().includes('renault')).length;
  281 |     expect(await rows.count(), 'пошук за одним словом марки').toBe(byBrandWord);
  282 | 
  283 |     await search.fill('немаєтакогономера');
  284 |     await page.waitForTimeout(300);
  285 |     await expect(page.locator('.empty-state')).toBeVisible();
  286 |   });
  287 | 
  288 |   test('S-09.10 пошук за маркою з кількох слів знаходить авто', async ({ page }) => {
  289 |     const plate = await api.freePlate();
  290 |     const mark = plate.slice(2, 6);
  291 |     const brand = `Renault ${mark}`;
  292 |     const vehicle = await api.createVehicle({ plateNumber: plate, weightTons: 4, brand });
  293 |     createdVehicles.push(vehicle.id);
  294 | 
  295 |     await loginSupplier(page);
  296 |     await goto(page, '/vehicles');
  297 |     const rows = page.locator('.table tbody tr');
  298 | 
  299 |     await page.locator('.vehicles__search').fill(brand);
  300 |     await page.waitForTimeout(400);
  301 |     await expect(
  302 |       rows,
  303 |       `поле обіцяє «Пошук за держномером або маркою», марка авто — «${brand}»; ` +
  304 |         'пошук за нею має знаходити авто',
  305 |     ).toHaveCount(1);
  306 |   });
  307 | });
  308 | 
  309 | test.describe('S-10 Водії', () => {
  310 |   test('S-10.1 X-01 у таблиці всі водії з API', async ({ page }) => {
  311 |     await loginSupplier(page);
  312 |     await goto(page, '/drivers');
  313 | 
  314 |     const drivers = await api.drivers();
  315 |     const rows = page.locator('.table tbody tr');
  316 |     await expect(rows.first()).toBeVisible();
  317 |     expect(await rows.count(), `в API ${drivers.length} водіїв`).toBe(drivers.length);
  318 | 
  319 |     const text = await bodyText(page);
  320 |     for (const driver of drivers) {
  321 |       expect(text, `водій ${driver.lastName} має бути у списку`).toContain(driver.lastName);
  322 |       expect(text, `телефон ${driver.phone} має бути показаний`).toContain(driver.phone);
  323 |     }
  324 |   });
  325 | 
  326 |   test('S-10.2 телефон приймається у всіх поширених форматах', async ({ page }) => {
  327 |     test.setTimeout(180_000);
  328 |     await loginSupplier(page);
  329 | 
  330 |     const variants = [
  331 |       { label: '0XXXXXXXXX', value: (d: string) => `099000${d}` },
  332 |       { label: '+380XXXXXXXXX', value: (d: string) => `+38099000${d}` },
  333 |       { label: '380XXXXXXXXX', value: (d: string) => `38099000${d}` },
  334 |       { label: 'з пробілами і дефісами', value: (d: string) => `0 (99) 000-${d.slice(0, 2)}-${d.slice(2)}` },
  335 |     ];
  336 |     // Кожному формату — свій вільний номер: діапазон +38099000XXXX спільний
  337 |     // з попередніми прогонами, і зайнятий номер дав би 409 замість перевірки.
  338 |     const phones = [
  339 |       await api.freePhone(),
  340 |       await api.freePhone(),
  341 |       await api.freePhone(),
  342 |       await api.freePhone(),
  343 |     ];
  344 | 
  345 |     for (const [index, variant] of variants.entries()) {
  346 |       const expectedPhone = phones[index];
```