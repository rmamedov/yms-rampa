# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 11-admin-stores-list.spec.ts >> A-02 Список магазинів >> A-02.20 фільтром за містом досяжні всі магазини мережі
- Location: tests/11-admin-stores-list.spec.ts:314:7

# Error details

```
Error: сума лічильників у фільтрі міст — 447, усього магазинів — 455: 8 філій не потрапляє в жодне значення фільтра (у них порожнє місто, а окремого варіанта «без міста» у списку немає)

expect(received).toBe(expected) // Object.is equality

Expected: 455
Received: 447
```

# Page snapshot

```yaml
- generic [ref=f1e4]:
  - complementary [ref=f1e5]:
    - generic [ref=f1e6]: YMS «Рампа»
    - navigation [ref=f1e7]:
      - link "Магазини" [ref=f1e8] [cursor=pointer]:
        - /url: /stores
      - link "Постачальники" [ref=f1e9] [cursor=pointer]:
        - /url: /suppliers
      - link "Користувачі" [ref=f1e10] [cursor=pointer]:
        - /url: /users
      - link "Синхронізація MCP" [ref=f1e11] [cursor=pointer]:
        - /url: /mcp-sync
      - link "Аналітика" [ref=f1e12] [cursor=pointer]:
        - /url: /analytics
    - generic [ref=f1e13]:
      - generic [ref=f1e14]: Адміністратор мережі
      - generic [ref=f1e15]: Супер-адміністратор
      - button "Вийти" [ref=f1e16] [cursor=pointer]
  - main [ref=f1e17]:
    - generic [ref=f1e18]:
      - heading "Магазини" [level=1] [ref=f1e20]
      - generic [ref=f1e21]:
        - generic [ref=f1e22]:
          - generic [ref=f1e23]: Пошук
          - searchbox "Пошук" [ref=f1e24]
        - generic [ref=f1e26]:
          - generic [ref=f1e27]: Місто
          - button "— ▾" [active] [ref=f1e28] [cursor=pointer]:
            - generic [ref=f1e29]: —
            - generic [ref=f1e30]: ▾
          - generic [ref=f1e31]:
            - searchbox "Пошук" [ref=f1e32]
            - generic [ref=f1e33]:
              - generic [ref=f1e34] [cursor=pointer]:
                - checkbox "Івано-Франківськ (4)" [ref=f1e35]
                - text: Івано-Франківськ (4)
              - generic [ref=f1e36] [cursor=pointer]:
                - checkbox "Ірпінь (4)" [ref=f1e37]
                - text: Ірпінь (4)
              - generic [ref=f1e38] [cursor=pointer]:
                - checkbox "Бориспіль (4)" [ref=f1e39]
                - text: Бориспіль (4)
              - generic [ref=f1e40] [cursor=pointer]:
                - checkbox "Боярка (2)" [ref=f1e41]
                - text: Боярка (2)
              - generic [ref=f1e42] [cursor=pointer]:
                - checkbox "Бровари (4)" [ref=f1e43]
                - text: Бровари (4)
              - generic [ref=f1e44] [cursor=pointer]:
                - checkbox "Буча (1)" [ref=f1e45]
                - text: Буча (1)
              - generic [ref=f1e46] [cursor=pointer]:
                - checkbox "Біла Церква (4)" [ref=f1e47]
                - text: Біла Церква (4)
              - generic [ref=f1e48] [cursor=pointer]:
                - checkbox "Білогородка (1)" [ref=f1e49]
                - text: Білогородка (1)
              - generic [ref=f1e50] [cursor=pointer]:
                - checkbox "Васильків (2)" [ref=f1e51]
                - text: Васильків (2)
              - generic [ref=f1e52] [cursor=pointer]:
                - checkbox "Вишгород (1)" [ref=f1e53]
                - text: Вишгород (1)
              - generic [ref=f1e54] [cursor=pointer]:
                - checkbox "Вишневе (2)" [ref=f1e55]
                - text: Вишневе (2)
              - generic [ref=f1e56] [cursor=pointer]:
                - checkbox "Вовчинець (2)" [ref=f1e57]
                - text: Вовчинець (2)
              - generic [ref=f1e58] [cursor=pointer]:
                - checkbox "Вінниця (9)" [ref=f1e59]
                - text: Вінниця (9)
              - generic [ref=f1e60] [cursor=pointer]:
                - checkbox "Віта-Поштова (1)" [ref=f1e61]
                - text: Віта-Поштова (1)
              - generic [ref=f1e62] [cursor=pointer]:
                - checkbox "Дніпро (17)" [ref=f1e63]
                - text: Дніпро (17)
              - generic [ref=f1e64] [cursor=pointer]:
                - checkbox "Дрогобич (2)" [ref=f1e65]
                - text: Дрогобич (2)
              - generic [ref=f1e66] [cursor=pointer]:
                - checkbox "Житомир (2)" [ref=f1e67]
                - text: Житомир (2)
              - generic [ref=f1e68] [cursor=pointer]:
                - checkbox "Запоріжжя (17)" [ref=f1e69]
                - text: Запоріжжя (17)
              - generic [ref=f1e70] [cursor=pointer]:
                - checkbox "Калуш (1)" [ref=f1e71]
                - text: Калуш (1)
              - generic [ref=f1e72] [cursor=pointer]:
                - checkbox "Кам’янець-Подільський (3)" [ref=f1e73]
                - text: Кам’янець-Подільський (3)
              - generic [ref=f1e74] [cursor=pointer]:
                - checkbox "Кам’янське (1)" [ref=f1e75]
                - text: Кам’янське (1)
              - generic [ref=f1e76] [cursor=pointer]:
                - checkbox "Канів (1)" [ref=f1e77]
                - text: Канів (1)
              - generic [ref=f1e78] [cursor=pointer]:
                - checkbox "Київ (155)" [ref=f1e79]
                - text: Київ (155)
              - generic [ref=f1e80] [cursor=pointer]:
                - checkbox "Коростень (1)" [ref=f1e81]
                - text: Коростень (1)
              - generic [ref=f1e82] [cursor=pointer]:
                - checkbox "Коростишів (1)" [ref=f1e83]
                - text: Коростишів (1)
              - generic [ref=f1e84] [cursor=pointer]:
                - checkbox "Костопіль (1)" [ref=f1e85]
                - text: Костопіль (1)
              - generic [ref=f1e86] [cursor=pointer]:
                - checkbox "Кривий Ріг (6)" [ref=f1e87]
                - text: Кривий Ріг (6)
              - generic [ref=f1e88] [cursor=pointer]:
                - checkbox "Кропивницький (2)" [ref=f1e89]
                - text: Кропивницький (2)
              - generic [ref=f1e90] [cursor=pointer]:
                - checkbox "Крюківщина (3)" [ref=f1e91]
                - text: Крюківщина (3)
              - generic [ref=f1e92] [cursor=pointer]:
                - checkbox "Лиманка (1)" [ref=f1e93]
                - text: Лиманка (1)
              - generic [ref=f1e94] [cursor=pointer]:
                - checkbox "Лозова (1)" [ref=f1e95]
                - text: Лозова (1)
              - generic [ref=f1e96] [cursor=pointer]:
                - checkbox "Луцьк (7)" [ref=f1e97]
                - text: Луцьк (7)
              - generic [ref=f1e98] [cursor=pointer]:
                - checkbox "Львів (31)" [ref=f1e99]
                - text: Львів (31)
              - generic [ref=f1e100] [cursor=pointer]:
                - checkbox "Лісники (1)" [ref=f1e101]
                - text: Лісники (1)
              - generic [ref=f1e102] [cursor=pointer]:
                - checkbox "Малин (1)" [ref=f1e103]
                - text: Малин (1)
              - generic [ref=f1e104] [cursor=pointer]:
                - checkbox "Миколаїв (4)" [ref=f1e105]
                - text: Миколаїв (4)
              - generic [ref=f1e106] [cursor=pointer]:
                - checkbox "Миргород (1)" [ref=f1e107]
                - text: Миргород (1)
              - generic [ref=f1e108] [cursor=pointer]:
                - checkbox "Мироцьке (1)" [ref=f1e109]
                - text: Мироцьке (1)
              - generic [ref=f1e110] [cursor=pointer]:
                - checkbox "Мукачево (1)" [ref=f1e111]
                - text: Мукачево (1)
              - generic [ref=f1e112] [cursor=pointer]:
                - checkbox "Нетішин (1)" [ref=f1e113]
                - text: Нетішин (1)
              - generic [ref=f1e114] [cursor=pointer]:
                - checkbox "Новий Розділ (1)" [ref=f1e115]
                - text: Новий Розділ (1)
              - generic [ref=f1e116] [cursor=pointer]:
                - checkbox "Ніжин (1)" [ref=f1e117]
                - text: Ніжин (1)
              - generic [ref=f1e118] [cursor=pointer]:
                - checkbox "Обухів (2)" [ref=f1e119]
                - text: Обухів (2)
              - generic [ref=f1e120] [cursor=pointer]:
                - checkbox "Овідіополь (1)" [ref=f1e121]
                - text: Овідіополь (1)
              - generic [ref=f1e122] [cursor=pointer]:
                - checkbox "Одеса (35)" [ref=f1e123]
                - text: Одеса (35)
              - generic [ref=f1e124] [cursor=pointer]:
                - checkbox "Остер (1)" [ref=f1e125]
                - text: Остер (1)
              - generic [ref=f1e126] [cursor=pointer]:
                - checkbox "Павлоград (1)" [ref=f1e127]
                - text: Павлоград (1)
              - generic [ref=f1e128] [cursor=pointer]:
                - checkbox "Погреби (1)" [ref=f1e129]
                - text: Погреби (1)
              - generic [ref=f1e130] [cursor=pointer]:
                - checkbox "Полтава (7)" [ref=f1e131]
                - text: Полтава (7)
              - generic [ref=f1e132] [cursor=pointer]:
                - checkbox "Поляниця (2)" [ref=f1e133]
                - text: Поляниця (2)
              - generic [ref=f1e134] [cursor=pointer]:
                - checkbox "Прилуки (1)" [ref=f1e135]
                - text: Прилуки (1)
              - generic [ref=f1e136] [cursor=pointer]:
                - checkbox "Прип'ять (1)" [ref=f1e137]
                - text: Прип'ять (1)
              - generic [ref=f1e138] [cursor=pointer]:
                - checkbox "Південне (1)" [ref=f1e139]
                - text: Південне (1)
              - generic [ref=f1e140] [cursor=pointer]:
                - checkbox "Південноукраїнськ (1)" [ref=f1e141]
                - text: Південноукраїнськ (1)
              - generic [ref=f1e142] [cursor=pointer]:
                - checkbox "Радомишль (1)" [ref=f1e143]
                - text: Радомишль (1)
              - generic [ref=f1e144] [cursor=pointer]:
                - checkbox "Рівне (5)" [ref=f1e145]
                - text: Рівне (5)
              - generic [ref=f1e146] [cursor=pointer]:
                - checkbox "Самар (1)" [ref=f1e147]
                - text: Самар (1)
              - generic [ref=f1e148] [cursor=pointer]:
                - checkbox "Самбір (1)" [ref=f1e149]
                - text: Самбір (1)
              - generic [ref=f1e150] [cursor=pointer]:
                - checkbox "Славутич (1)" [ref=f1e151]
                - text: Славутич (1)
              - generic [ref=f1e152] [cursor=pointer]:
                - checkbox "Сміла (1)" [ref=f1e153]
                - text: Сміла (1)
              - generic [ref=f1e154] [cursor=pointer]:
                - checkbox "Софіївська Борщагівка (7)" [ref=f1e155]
                - text: Софіївська Борщагівка (7)
              - generic [ref=f1e156] [cursor=pointer]:
                - checkbox "Старі Петрівці (1)" [ref=f1e157]
                - text: Старі Петрівці (1)
              - generic [ref=f1e158] [cursor=pointer]:
                - checkbox "Стоянка (1)" [ref=f1e159]
                - text: Стоянка (1)
              - generic [ref=f1e160] [cursor=pointer]:
                - checkbox "Стрий (1)" [ref=f1e161]
                - text: Стрий (1)
              - generic [ref=f1e162] [cursor=pointer]:
                - checkbox "Суми (4)" [ref=f1e163]
                - text: Суми (4)
              - generic [ref=f1e164] [cursor=pointer]:
                - checkbox "Теплодар (1)" [ref=f1e165]
                - text: Теплодар (1)
              - generic [ref=f1e166] [cursor=pointer]:
                - checkbox "Тернопіль (8)" [ref=f1e167]
                - text: Тернопіль (8)
              - generic [ref=f1e168] [cursor=pointer]:
                - checkbox "Трускавець (2)" [ref=f1e169]
                - text: Трускавець (2)
              - generic [ref=f1e170] [cursor=pointer]:
                - checkbox "Ужгород (6)" [ref=f1e171]
                - text: Ужгород (6)
              - generic [ref=f1e172] [cursor=pointer]:
                - checkbox "Умань (1)" [ref=f1e173]
                - text: Умань (1)
              - generic [ref=f1e174] [cursor=pointer]:
                - checkbox "Фастів (2)" [ref=f1e175]
                - text: Фастів (2)
              - generic [ref=f1e176] [cursor=pointer]:
                - checkbox "Харків (15)" [ref=f1e177]
                - text: Харків (15)
              - generic [ref=f1e178] [cursor=pointer]:
                - checkbox "Херсон (2)" [ref=f1e179]
                - text: Херсон (2)
              - generic [ref=f1e180] [cursor=pointer]:
                - checkbox "Хмельницький (5)" [ref=f1e181]
                - text: Хмельницький (5)
              - generic [ref=f1e182] [cursor=pointer]:
                - checkbox "Хмільник (1)" [ref=f1e183]
                - text: Хмільник (1)
              - generic [ref=f1e184] [cursor=pointer]:
                - checkbox "Черкаси (3)" [ref=f1e185]
                - text: Черкаси (3)
              - generic [ref=f1e186] [cursor=pointer]:
                - checkbox "Чернівці (4)" [ref=f1e187]
                - text: Чернівці (4)
              - generic [ref=f1e188] [cursor=pointer]:
                - checkbox "Чернігів (8)" [ref=f1e189]
                - text: Чернігів (8)
              - generic [ref=f1e190] [cursor=pointer]:
                - checkbox "Чорноморськ (1)" [ref=f1e191]
                - text: Чорноморськ (1)
              - generic [ref=f1e192] [cursor=pointer]:
                - checkbox "Шептицький (1)" [ref=f1e193]
                - text: Шептицький (1)
              - generic [ref=f1e194] [cursor=pointer]:
                - checkbox "Щасливе (2)" [ref=f1e195]
                - text: Щасливе (2)
              - generic [ref=f1e196] [cursor=pointer]:
                - checkbox "Яворів (1)" [ref=f1e197]
                - text: Яворів (1)
            - generic [ref=f1e198]:
              - button "Скинути фільтри" [ref=f1e199] [cursor=pointer]
              - button "Закрити" [ref=f1e200] [cursor=pointer]
        - generic [ref=f1e202]:
          - generic [ref=f1e203]: Статус YMS
          - button "— ▾" [ref=f1e204] [cursor=pointer]:
            - generic [ref=f1e205]: —
            - generic [ref=f1e206]: ▾
        - generic [ref=f1e207]:
          - generic [ref=f1e208]: Налаштованість
          - combobox "Налаштованість" [ref=f1e209]:
            - option "Будь-який" [selected]
            - option "Налаштовано"
            - option "Не налаштовано"
        - button "Застосувати" [ref=f1e210] [cursor=pointer]
      - generic [ref=f1e211]:
        - table [ref=f1e212]:
          - rowgroup [ref=f1e213]:
            - row [ref=f1e214]:
              - columnheader [ref=f1e215]:
                - checkbox "select-all" [ref=f1e216]
              - columnheader "Код філії" [ref=f1e217] [cursor=pointer]
              - columnheader "Назва для відображення" [ref=f1e218]
              - columnheader "Місто ↑" [ref=f1e219] [cursor=pointer]
              - columnheader "Адреса" [ref=f1e220] [cursor=pointer]
              - columnheader "Статус YMS" [ref=f1e221] [cursor=pointer]
              - columnheader "Налаштовано" [ref=f1e222]
              - columnheader "Рамп" [ref=f1e223]
              - columnheader "Макс. тоннаж, т" [ref=f1e224]
              - columnheader "Остання синхронізація" [ref=f1e225] [cursor=pointer]
          - rowgroup [ref=f1e226]:
            - row [ref=f1e227]:
              - cell [ref=f1e228]:
                - checkbox "2505" [ref=f1e229]
              - cell [ref=f1e230]:
                - link "2505" [ref=f1e231] [cursor=pointer]:
                  - /url: /stores/1edb7353-c9ea-6382-b36b-11a6c487168c
              - cell [ref=f1e232]
              - cell [ref=f1e233]
              - cell [ref=f1e234]
              - cell "Не налаштовано" [ref=f1e235]
              - cell "Ні" [ref=f1e237]
              - cell "0" [ref=f1e239]
              - cell "—" [ref=f1e240]
              - cell "28.08.2026, 02:53" [ref=f1e241]
            - row [ref=f1e242]:
              - cell [ref=f1e243]:
                - checkbox "3097" [ref=f1e244]
              - cell [ref=f1e245]:
                - link "3097" [ref=f1e246] [cursor=pointer]:
                  - /url: /stores/1edb7335-9721-69c8-8769-11a6c487168c
              - cell "вул. Яворівська, 30" [ref=f1e247]
              - cell [ref=f1e248]
              - cell "вул. Яворівська, 30" [ref=f1e249]
              - cell "Не налаштовано" [ref=f1e250]
              - cell "Ні" [ref=f1e252]
              - cell "0" [ref=f1e254]
              - cell "—" [ref=f1e255]
              - cell "28.08.2026, 02:53" [ref=f1e256]
            - row [ref=f1e257]:
              - cell [ref=f1e258]:
                - checkbox "3656" [ref=f1e259]
              - cell [ref=f1e260]:
                - link "3656" [ref=f1e261] [cursor=pointer]:
                  - /url: /stores/1eecbd44-a3ed-65fc-9ac4-c39702503ccc
              - cell [ref=f1e262]
              - cell [ref=f1e263]
              - cell [ref=f1e264]
              - cell "Не налаштовано" [ref=f1e265]
              - cell "Ні" [ref=f1e267]
              - cell "0" [ref=f1e269]
              - cell "—" [ref=f1e270]
              - cell "28.08.2026, 02:53" [ref=f1e271]
            - row [ref=f1e272]:
              - cell [ref=f1e273]:
                - checkbox "delete_filia_silpo_ferma_2286" [ref=f1e274]
              - cell [ref=f1e275]:
                - link "delete_filia_silpo_ferma_2286" [ref=f1e276] [cursor=pointer]:
                  - /url: /stores/1edb735e-e4f1-6936-ba95-a143e3aed11b
              - cell [ref=f1e277]
              - cell [ref=f1e278]
              - cell [ref=f1e279]
              - cell "Не налаштовано" [ref=f1e280]
              - cell "Ні" [ref=f1e282]
              - cell "0" [ref=f1e284]
              - cell "—" [ref=f1e285]
              - cell "28.08.2026, 02:53" [ref=f1e286]
            - row [ref=f1e287]:
              - cell [ref=f1e288]:
                - checkbox "delete_filia_silpo_ferma_2287" [ref=f1e289]
              - cell [ref=f1e290]:
                - link "delete_filia_silpo_ferma_2287" [ref=f1e291] [cursor=pointer]:
                  - /url: /stores/1edb735e-7a82-64e2-ac3c-f77053673ad9
              - cell [ref=f1e292]
              - cell [ref=f1e293]
              - cell [ref=f1e294]
              - cell "Не налаштовано" [ref=f1e295]
              - cell "Ні" [ref=f1e297]
              - cell "0" [ref=f1e299]
              - cell "—" [ref=f1e300]
              - cell "28.08.2026, 02:53" [ref=f1e301]
            - row [ref=f1e302]:
              - cell [ref=f1e303]:
                - checkbox "delete_filia_silpo_ivasuka46" [ref=f1e304]
              - cell [ref=f1e305]:
                - link "delete_filia_silpo_ivasuka46" [ref=f1e306] [cursor=pointer]:
                  - /url: /stores/1edb2828-37e2-6690-af0a-5f4f054120bc
              - cell [ref=f1e307]
              - cell [ref=f1e308]
              - cell [ref=f1e309]
              - cell "Не налаштовано" [ref=f1e310]
              - cell "Ні" [ref=f1e312]
              - cell "0" [ref=f1e314]
              - cell "—" [ref=f1e315]
              - cell "28.08.2026, 02:53" [ref=f1e316]
            - row [ref=f1e317]:
              - cell [ref=f1e318]:
                - checkbox "delete_filia_silpo_nerejanskaya22" [ref=f1e319]
              - cell [ref=f1e320]:
                - link "delete_filia_silpo_nerejanskaya22" [ref=f1e321] [cursor=pointer]:
                  - /url: /stores/1edb6b29-08c1-667e-bcb8-d9341fb2cc7b
              - cell [ref=f1e322]
              - cell [ref=f1e323]
              - cell [ref=f1e324]
              - cell "Не налаштовано" [ref=f1e325]
              - cell "Ні" [ref=f1e327]
              - cell "0" [ref=f1e329]
              - cell "—" [ref=f1e330]
              - cell "28.08.2026, 02:53" [ref=f1e331]
            - row [ref=f1e332]:
              - cell [ref=f1e333]:
                - checkbox "delete_filia_silpo_stalingrad46" [ref=f1e334]
              - cell [ref=f1e335]:
                - link "delete_filia_silpo_stalingrad46" [ref=f1e336] [cursor=pointer]:
                  - /url: /stores/1edb6b1a-c102-6eae-9f91-0f4ab5c79679
              - cell [ref=f1e337]
              - cell [ref=f1e338]
              - cell [ref=f1e339]
              - cell "Не налаштовано" [ref=f1e340]
              - cell "Ні" [ref=f1e342]
              - cell "0" [ref=f1e344]
              - cell "—" [ref=f1e345]
              - cell "28.08.2026, 02:53" [ref=f1e346]
            - row [ref=f1e347]:
              - cell [ref=f1e348]:
                - checkbox "2116" [ref=f1e349]
              - cell [ref=f1e350]:
                - link "2116" [ref=f1e351] [cursor=pointer]:
                  - /url: /stores/1edb6b5a-55fb-6864-9a0f-d54e0a9fe643
              - cell "вул. Мазепи, 168А" [ref=f1e352]
              - cell "Івано-Франківськ" [ref=f1e353]
              - cell "вул. Мазепи, 168А" [ref=f1e354]
              - cell "Не налаштовано" [ref=f1e355]
              - cell "Ні" [ref=f1e357]
              - cell "0" [ref=f1e359]
              - cell "—" [ref=f1e360]
              - cell "28.08.2026, 02:53" [ref=f1e361]
            - row [ref=f1e362]:
              - cell [ref=f1e363]:
                - checkbox "2117" [ref=f1e364]
              - cell [ref=f1e365]:
                - link "2117" [ref=f1e366] [cursor=pointer]:
                  - /url: /stores/1edb6b5a-b1b0-611e-a929-d11f2666a570
              - cell "вул. Дністровська, 3" [ref=f1e367]
              - cell "Івано-Франківськ" [ref=f1e368]
              - cell "вул. Дністровська, 3" [ref=f1e369]
              - cell "Не налаштовано" [ref=f1e370]
              - cell "Ні" [ref=f1e372]
              - cell "0" [ref=f1e374]
              - cell "—" [ref=f1e375]
              - cell "28.08.2026, 02:53" [ref=f1e376]
            - row [ref=f1e377]:
              - cell [ref=f1e378]:
                - checkbox "2118" [ref=f1e379]
              - cell [ref=f1e380]:
                - link "2118" [ref=f1e381] [cursor=pointer]:
                  - /url: /stores/1edb6b5b-1b9a-6ce6-bb85-639d81d4aac4
              - cell "бульв. Північний, 2А" [ref=f1e382]
              - cell "Івано-Франківськ" [ref=f1e383]
              - cell "бульв. Північний, 2А" [ref=f1e384]
              - cell "Не налаштовано" [ref=f1e385]
              - cell "Ні" [ref=f1e387]
              - cell "0" [ref=f1e389]
              - cell "—" [ref=f1e390]
              - cell "28.08.2026, 02:53" [ref=f1e391]
            - row [ref=f1e392]:
              - cell [ref=f1e393]:
                - checkbox "3976" [ref=f1e394]
              - cell [ref=f1e395]:
                - link "3976" [ref=f1e396] [cursor=pointer]:
                  - /url: /stores/1ef9b801-5831-6216-99a5-fd246a208e47
              - cell "вул. Мазепи, 168А" [ref=f1e397]
              - cell "Івано-Франківськ" [ref=f1e398]
              - cell "вул. Мазепи, 168А" [ref=f1e399]
              - cell "Не налаштовано" [ref=f1e400]
              - cell "Ні" [ref=f1e402]
              - cell "0" [ref=f1e404]
              - cell "—" [ref=f1e405]
              - cell "28.08.2026, 02:53" [ref=f1e406]
            - row [ref=f1e407]:
              - cell [ref=f1e408]:
                - checkbox "2966" [ref=f1e409]
              - cell [ref=f1e410]:
                - link "2966" [ref=f1e411] [cursor=pointer]:
                  - /url: /stores/1edb733d-b42b-64ee-b5d5-73524574f50b
              - cell "вул. Літературна, 27" [ref=f1e412]
              - cell "Ірпінь" [ref=f1e413]
              - cell "вул. Літературна, 27" [ref=f1e414]
              - cell "Не налаштовано" [ref=f1e415]
              - cell "Ні" [ref=f1e417]
              - cell "0" [ref=f1e419]
              - cell "—" [ref=f1e420]
              - cell "28.08.2026, 02:53" [ref=f1e421]
            - row [ref=f1e422]:
              - cell [ref=f1e423]:
                - checkbox "3259" [ref=f1e424]
              - cell [ref=f1e425]:
                - link "3259" [ref=f1e426] [cursor=pointer]:
                  - /url: /stores/1f0dcc8f-0f9f-6a6e-a05e-c38d9b34c11f
              - cell "вул. Сковороди, 8" [ref=f1e427]
              - cell "Ірпінь" [ref=f1e428]
              - cell "вул. Сковороди, 8" [ref=f1e429]
              - cell "Не налаштовано" [ref=f1e430]
              - cell "Ні" [ref=f1e432]
              - cell "0" [ref=f1e434]
              - cell "—" [ref=f1e435]
              - cell "28.08.2026, 02:53" [ref=f1e436]
            - row [ref=f1e437]:
              - cell [ref=f1e438]:
                - checkbox "3891" [ref=f1e439]
              - cell [ref=f1e440]:
                - link "3891" [ref=f1e441] [cursor=pointer]:
                  - /url: /stores/1efb6e1e-aa8f-604c-988a-27f1dec9eef8
              - cell "вул. Соборна, 160" [ref=f1e442]
              - cell "Ірпінь" [ref=f1e443]
              - cell "вул. Соборна, 160" [ref=f1e444]
              - cell "Не налаштовано" [ref=f1e445]
              - cell "Ні" [ref=f1e447]
              - cell "0" [ref=f1e449]
              - cell "—" [ref=f1e450]
              - cell "28.08.2026, 02:53" [ref=f1e451]
            - row [ref=f1e452]:
              - cell [ref=f1e453]:
                - checkbox "3905" [ref=f1e454]
              - cell [ref=f1e455]:
                - link "3905" [ref=f1e456] [cursor=pointer]:
                  - /url: /stores/1efb6e15-b171-6ba2-ba6f-e90a6eb5ec15
              - cell "вул. Соборна, 160" [ref=f1e457]
              - cell "Ірпінь" [ref=f1e458]
              - cell "вул. Соборна, 160" [ref=f1e459]
              - cell "Не налаштовано" [ref=f1e460]
              - cell "Ні" [ref=f1e462]
              - cell "0" [ref=f1e464]
              - cell "—" [ref=f1e465]
              - cell "28.08.2026, 02:53" [ref=f1e466]
            - row [ref=f1e467]:
              - cell [ref=f1e468]:
                - checkbox "1997" [ref=f1e469]
              - cell [ref=f1e470]:
                - link "1997" [ref=f1e471] [cursor=pointer]:
                  - /url: /stores/1edb6b1a-626d-64ca-9046-0b7012e7f9f8
              - cell "вул. Київський Шлях, 76" [ref=f1e472]
              - cell "Бориспіль" [ref=f1e473]
              - cell "вул. Київський Шлях, 76" [ref=f1e474]
              - cell "Не налаштовано" [ref=f1e475]
              - cell "Ні" [ref=f1e477]
              - cell "0" [ref=f1e479]
              - cell "—" [ref=f1e480]
              - cell "28.08.2026, 02:53" [ref=f1e481]
            - row [ref=f1e482]:
              - cell [ref=f1e483]:
                - checkbox "3190" [ref=f1e484]
              - cell [ref=f1e485]:
                - link "3190" [ref=f1e486] [cursor=pointer]:
                  - /url: /stores/1edb7319-118e-6778-8404-6fea04bfe766
              - cell "вул. Київський Шлях, 67" [ref=f1e487]
              - cell "Бориспіль" [ref=f1e488]
              - cell "вул. Київський Шлях, 67" [ref=f1e489]
              - cell "Не налаштовано" [ref=f1e490]
              - cell "Ні" [ref=f1e492]
              - cell "0" [ref=f1e494]
              - cell "—" [ref=f1e495]
              - cell "28.08.2026, 02:53" [ref=f1e496]
            - row [ref=f1e497]:
              - cell [ref=f1e498]:
                - checkbox "3436" [ref=f1e499]
              - cell [ref=f1e500]:
                - link "3436" [ref=f1e501] [cursor=pointer]:
                  - /url: /stores/1edf3108-fb26-610a-8e0a-d980ecba6063
              - cell "вул. Київський Шлях, 6" [ref=f1e502]
              - cell "Бориспіль" [ref=f1e503]
              - cell "вул. Київський Шлях, 6" [ref=f1e504]
              - cell "Не налаштовано" [ref=f1e505]
              - cell "Ні" [ref=f1e507]
              - cell "0" [ref=f1e509]
              - cell "—" [ref=f1e510]
              - cell "28.08.2026, 02:53" [ref=f1e511]
            - row [ref=f1e512]:
              - cell [ref=f1e513]:
                - checkbox "4204" [ref=f1e514]
              - cell [ref=f1e515]:
                - link "4204" [ref=f1e516] [cursor=pointer]:
                  - /url: /stores/1f071d01-27ef-61e4-bd93-f981fceb620c
              - cell "вул. Київський шлях, 6" [ref=f1e517]
              - cell "Бориспіль" [ref=f1e518]
              - cell "вул. Київський шлях, 6" [ref=f1e519]
              - cell "Не налаштовано" [ref=f1e520]
              - cell "Ні" [ref=f1e522]
              - cell "0" [ref=f1e524]
              - cell "—" [ref=f1e525]
              - cell "28.08.2026, 02:53" [ref=f1e526]
        - generic [ref=f1e528]:
          - generic [ref=f1e529]: "Усього: 455"
          - generic [ref=f1e530]:
            - text: Рядків на сторінці
            - combobox "page-size" [ref=f1e531]:
              - option "20" [selected]
              - option "50"
              - option "100"
          - button "‹" [disabled] [ref=f1e532]
          - generic [ref=f1e533]: Сторінка 1 з 23
          - button "›" [ref=f1e534] [cursor=pointer]
```

# Test source

```ts
  228 | 
  229 |   test('A-02.13 фільтр «Налаштованість»', async ({ page }) => {
  230 |     for (const [value, query] of [
  231 |       ['true', 'configured=true'],
  232 |       ['false', 'configured=false'],
  233 |     ] as const) {
  234 |       const expected = await apiStores(`${query}&perPage=20`);
  235 |       await goto(page, '/stores');
  236 |       await page.locator('#configured').selectOption(value);
  237 |       await page.waitForLoadState('networkidle');
  238 |       await expectTotal(page, expected.total, `configured=${value}`);
  239 |     }
  240 |   });
  241 | 
  242 |   test('A-02.14 комбінація фільтрів місто + статус', async ({ page }) => {
  243 |     const expected = await apiStores(
  244 |       `city=${encodeURIComponent('Харків')}&ymsStatus=active&perPage=20`,
  245 |     );
  246 |     await goto(page, '/stores');
  247 |     await multiSelectPick(page, 'Місто', 'Харків (');
  248 |     await multiSelectPick(page, 'Статус YMS', 'Активний');
  249 |     await apply(page);
  250 | 
  251 |     await expectTotal(page, expected.total, 'Харків + активні');
  252 |     expect(await dataRowCount(page)).toBe(expected.items.length);
  253 |   });
  254 | 
  255 |   test('A-02.15 X-03 фільтри зберігаються при переході між сторінками', async ({ page }) => {
  256 |     const expected = await apiStores(`city=${encodeURIComponent('Київ')}&perPage=20`);
  257 |     await goto(page, '/stores');
  258 |     await multiSelectPick(page, 'Місто', 'Київ (');
  259 |     await apply(page);
  260 | 
  261 |     await page.locator('.pagination button').last().click();
  262 |     await page.waitForLoadState('networkidle');
  263 | 
  264 |     await expect
  265 |       .poll(async () => (await paginationPages(page)).page, { message: 'ми на другій сторінці' })
  266 |       .toBe(2);
  267 |     await expectTotal(page, expected.total, 'фільтр міста лишився чинним');
  268 |     expect(page.url()).toContain('cities=');
  269 |   });
  270 | 
  271 |   test('A-02.16 скидання фільтрів повертає повний список', async ({ page }) => {
  272 |     const total = await apiStoreTotal();
  273 |     await goto(page, '/stores');
  274 |     await multiSelectPick(page, 'Місто', 'Київ (');
  275 |     await apply(page);
  276 |     await expect.poll(() => paginationTotal(page)).toBeLessThan(total);
  277 | 
  278 |     await page.locator('.toolbar button', { hasText: 'Скинути фільтри' }).click();
  279 |     await page.waitForLoadState('networkidle');
  280 | 
  281 |     await expectTotal(page, total, 'після скидання — увесь список');
  282 |     expect(await page.locator('#store-search').inputValue(), 'поле пошуку очищено').toBe('');
  283 |   });
  284 | 
  285 |   test('A-02.17 X-04 порожній стан має свідоме повідомлення', async ({ page }) => {
  286 |     const expected = await apiStores('q=ZZZ-NO-SUCH-STORE&perPage=20');
  287 |     expect(expected.total).toBe(0);
  288 | 
  289 |     await goto(page, '/stores');
  290 |     await page.locator('#store-search').fill('ZZZ-NO-SUCH-STORE');
  291 |     await apply(page);
  292 | 
  293 |     await expect.poll(() => dataRowCount(page), { message: 'рядків немає' }).toBe(0);
  294 |     await expect(page.locator('app-empty-state')).toBeVisible();
  295 |     await expect(page.locator('app-empty-state')).toContainText(
  296 |       expected.emptyMessage ?? 'не знайдено',
  297 |     );
  298 |   });
  299 | 
  300 |   test('A-02.18 сортування за колонкою відображається у видачі', async ({ page }) => {
  301 |     await goto(page, '/stores?pageSize=20&sort=externalId&dir=asc');
  302 |     const asc = await apiStores('perPage=20&page=1&sortBy=externalId&sortDirection=asc');
  303 |     const uiAsc = await page.locator('table.data tbody tr td.mono a').allInnerTexts();
  304 |     expect(uiAsc, 'сортування за кодом філії за зростанням').toEqual(
  305 |       asc.items.map((i) => i.externalId),
  306 |     );
  307 | 
  308 |     await goto(page, '/stores?pageSize=20&sort=externalId&dir=desc');
  309 |     const desc = await apiStores('perPage=20&page=1&sortBy=externalId&sortDirection=desc');
  310 |     const uiDesc = await page.locator('table.data tbody tr td.mono a').allInnerTexts();
  311 |     expect(uiDesc, 'сортування за спаданням').toEqual(desc.items.map((i) => i.externalId));
  312 |   });
  313 | 
  314 |   test('A-02.20 фільтром за містом досяжні всі магазини мережі', async ({ page }) => {
  315 |     const cities = await apiCities();
  316 |     const total = await apiStoreTotal();
  317 |     const covered = cities.reduce((sum, c) => sum + c.storeCount, 0);
  318 | 
  319 |     await goto(page, '/stores');
  320 |     const options = await multiSelectOptions(page, 'Місто');
  321 |     const hasEmptyOption = options.some((o) => /^\s*\(/.test(o) || /^—/.test(o));
  322 | 
  323 |     expect(
  324 |       covered + (hasEmptyOption ? total - covered : 0),
  325 |       `сума лічильників у фільтрі міст — ${covered}, усього магазинів — ${total}: ` +
  326 |         `${total - covered} філій не потрапляє в жодне значення фільтра ` +
  327 |         '(у них порожнє місто, а окремого варіанта «без міста» у списку немає)',
> 328 |     ).toBe(total);
      |       ^ Error: сума лічильників у фільтрі міст — 447, усього магазинів — 455: 8 філій не потрапляє в жодне значення фільтра (у них порожнє місто, а окремого варіанта «без міста» у списку немає)
  329 |   });
  330 | 
  331 |   test('A-02.19 кожен рядок веде в картку магазину', async ({ page }) => {
  332 |     await goto(page, '/stores?q=2226');
  333 |     const link = page.locator('table.data tbody tr td.mono a', { hasText: '2226' }).first();
  334 |     await link.waitFor({ state: 'visible' });
  335 |     await link.click();
  336 |     await page.waitForURL(/\/stores\/[0-9a-f-]{8}/, { timeout: 15_000 });
  337 |     await page.waitForLoadState('networkidle');
  338 |     expect(page.url()).toMatch(/\/stores\/[0-9a-f-]+/);
  339 |     await expect(page.locator('.tabs')).toBeVisible();
  340 |   });
  341 | });
  342 | 
```