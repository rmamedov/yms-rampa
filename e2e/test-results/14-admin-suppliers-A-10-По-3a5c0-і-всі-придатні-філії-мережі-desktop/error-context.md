# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 14-admin-suppliers.spec.ts >> A-10 Постачальники >> A-10.16 X-01 у виборі магазинів доступні всі придатні філії мережі
- Location: tests/14-admin-suppliers.spec.ts:389:7

# Error details

```
Error: у виборі філій видно 200 варіантів, а придатних філій у мережі 447: решту неможливо ані побачити, ані обрати без пошуку

expect(received).toBeGreaterThanOrEqual(expected)

Expected: >= 447
Received:    200
```

# Page snapshot

```yaml
- generic [ref=f2e4]:
  - complementary [ref=f2e5]:
    - generic [ref=f2e6]: YMS «Рампа»
    - navigation [ref=f2e7]:
      - link "Магазини" [ref=f2e8] [cursor=pointer]:
        - /url: /stores
      - link "Постачальники" [ref=f2e9] [cursor=pointer]:
        - /url: /suppliers
      - link "Синхронізація MCP" [ref=f2e10] [cursor=pointer]:
        - /url: /mcp-sync
      - link "Аналітика" [ref=f2e11] [cursor=pointer]:
        - /url: /analytics
    - generic [ref=f2e12]:
      - generic [ref=f2e13]: Адміністратор мережі
      - generic [ref=f2e14]: Супер-адміністратор
      - button "Вийти" [ref=f2e15] [cursor=pointer]
  - main [ref=f2e16]:
    - generic [ref=f2e17]:
      - navigation [ref=f2e19]:
        - link "Постачальники" [ref=f2e20] [cursor=pointer]:
          - /url: /suppliers
        - generic [ref=f2e21]: →
        - generic [ref=f2e22]: UITEST-Постачальник-mtc378kf699-повнота
      - generic [ref=f2e23]:
        - heading "UITEST-Постачальник-mtc378kf699-повнота" [level=1] [ref=f2e24]
        - generic [ref=f2e25]: Активний
      - generic [ref=f2e26]:
        - button "Загальне" [ref=f2e27] [cursor=pointer]
        - button "Магазини" [ref=f2e28] [cursor=pointer]
      - generic [ref=f2e29]:
        - generic [ref=f2e30]: Магазини
        - generic [ref=f2e31]:
          - generic [ref=f2e32]: Доступ до магазинів
          - combobox "Доступ до магазинів" [ref=f2e33]:
            - option "Усі магазини"
            - option "Перелік магазинів (0)" [selected]
        - generic [ref=f2e35]:
          - generic [ref=f2e36]: Магазини
          - button "— ▾" [active] [ref=f2e37] [cursor=pointer]:
            - generic [ref=f2e38]: —
            - generic [ref=f2e39]: ▾
          - generic [ref=f2e40]:
            - searchbox "Пошук" [ref=f2e41]
            - generic [ref=f2e42]:
              - generic [ref=f2e43] [cursor=pointer]:
                - checkbox "2116 — Івано-Франківськ, вул. Мазепи, 168А" [ref=f2e44]
                - text: 2116 — Івано-Франківськ, вул. Мазепи, 168А
              - generic [ref=f2e45] [cursor=pointer]:
                - checkbox "2117 — Івано-Франківськ, вул. Дністровська, 3" [ref=f2e46]
                - text: 2117 — Івано-Франківськ, вул. Дністровська, 3
              - generic [ref=f2e47] [cursor=pointer]:
                - checkbox "2118 — Івано-Франківськ, бульв. Північний, 2А" [ref=f2e48]
                - text: 2118 — Івано-Франківськ, бульв. Північний, 2А
              - generic [ref=f2e49] [cursor=pointer]:
                - checkbox "3976 — Івано-Франківськ, вул. Мазепи, 168А" [ref=f2e50]
                - text: 3976 — Івано-Франківськ, вул. Мазепи, 168А
              - generic [ref=f2e51] [cursor=pointer]:
                - checkbox "2966 — Ірпінь, вул. Літературна, 27" [ref=f2e52]
                - text: 2966 — Ірпінь, вул. Літературна, 27
              - generic [ref=f2e53] [cursor=pointer]:
                - checkbox "3259 — Ірпінь, вул. Сковороди, 8" [ref=f2e54]
                - text: 3259 — Ірпінь, вул. Сковороди, 8
              - generic [ref=f2e55] [cursor=pointer]:
                - checkbox "3891 — Ірпінь, вул. Соборна, 160" [ref=f2e56]
                - text: 3891 — Ірпінь, вул. Соборна, 160
              - generic [ref=f2e57] [cursor=pointer]:
                - checkbox "3905 — Ірпінь, вул. Соборна, 160" [ref=f2e58]
                - text: 3905 — Ірпінь, вул. Соборна, 160
              - generic [ref=f2e59] [cursor=pointer]:
                - checkbox "1997 — Бориспіль, вул. Київський Шлях, 76" [ref=f2e60]
                - text: 1997 — Бориспіль, вул. Київський Шлях, 76
              - generic [ref=f2e61] [cursor=pointer]:
                - checkbox "3190 — Бориспіль, вул. Київський Шлях, 67" [ref=f2e62]
                - text: 3190 — Бориспіль, вул. Київський Шлях, 67
              - generic [ref=f2e63] [cursor=pointer]:
                - checkbox "3436 — Бориспіль, вул. Київський Шлях, 6" [ref=f2e64]
                - text: 3436 — Бориспіль, вул. Київський Шлях, 6
              - generic [ref=f2e65] [cursor=pointer]:
                - checkbox "4204 — Бориспіль, вул. Київський шлях, 6" [ref=f2e66]
                - text: 4204 — Бориспіль, вул. Київський шлях, 6
              - generic [ref=f2e67] [cursor=pointer]:
                - checkbox "3024 — Боярка, вул. Магістральна, 40" [ref=f2e68]
                - text: 3024 — Боярка, вул. Магістральна, 40
              - generic [ref=f2e69] [cursor=pointer]:
                - checkbox "3063 — Боярка, кв. 103 Боярське Лісництво, 1" [ref=f2e70]
                - text: 3063 — Боярка, кв. 103 Боярське Лісництво, 1
              - generic [ref=f2e71] [cursor=pointer]:
                - checkbox "2069 — Бровари, вул. Київська, 241" [ref=f2e72]
                - text: 2069 — Бровари, вул. Київська, 241
              - generic [ref=f2e73] [cursor=pointer]:
                - checkbox "2097 — Бровари, вул. Київська, 241" [ref=f2e74]
                - text: 2097 — Бровари, вул. Київська, 241
              - generic [ref=f2e75] [cursor=pointer]:
                - checkbox "3195 — Бровари, вул. Київська, 156" [ref=f2e76]
                - text: 3195 — Бровари, вул. Київська, 156
              - generic [ref=f2e77] [cursor=pointer]:
                - checkbox "3374 — Бровари, вул. Київська, 241" [ref=f2e78]
                - text: 3374 — Бровари, вул. Київська, 241
              - generic [ref=f2e79] [cursor=pointer]:
                - checkbox "2636 — Буча, бульв. Бірюкова Леоніда, 2" [ref=f2e80]
                - text: 2636 — Буча, бульв. Бірюкова Леоніда, 2
              - generic [ref=f2e81] [cursor=pointer]:
                - checkbox "2014 — Біла Церква, шосе Сквирське, 230" [ref=f2e82]
                - text: 2014 — Біла Церква, шосе Сквирське, 230
              - generic [ref=f2e83] [cursor=pointer]:
                - checkbox "2024 — Біла Церква, вул. Героїв Небесної Сотні, 2А" [ref=f2e84]
                - text: 2024 — Біла Церква, вул. Героїв Небесної Сотні, 2А
              - generic [ref=f2e85] [cursor=pointer]:
                - checkbox "2632 — Біла Церква, вул. Героїв Небесної Сотні, 2а" [ref=f2e86]
                - text: 2632 — Біла Церква, вул. Героїв Небесної Сотні, 2а
              - generic [ref=f2e87] [cursor=pointer]:
                - checkbox "3360 — Біла Церква, вул. Ярослава Мудрого, 40" [ref=f2e88]
                - text: 3360 — Біла Церква, вул. Ярослава Мудрого, 40
              - generic [ref=f2e89] [cursor=pointer]:
                - checkbox "3742 — Білогородка, вул. Будівельна, 19" [ref=f2e90]
                - text: 3742 — Білогородка, вул. Будівельна, 19
              - generic [ref=f2e91] [cursor=pointer]:
                - checkbox "2040 — Васильків, вул. Соборна, 64/1" [ref=f2e92]
                - text: 2040 — Васильків, вул. Соборна, 64/1
              - generic [ref=f2e93] [cursor=pointer]:
                - checkbox "3162 — Васильків, вул. Грушевського, 19" [ref=f2e94]
                - text: 3162 — Васильків, вул. Грушевського, 19
              - generic [ref=f2e95] [cursor=pointer]:
                - checkbox "2580 — Вишгород, вул. Набережна, 11" [ref=f2e96]
                - text: 2580 — Вишгород, вул. Набережна, 11
              - generic [ref=f2e97] [cursor=pointer]:
                - checkbox "3364 — Вишневе, вул. Європейська, 30" [ref=f2e98]
                - text: 3364 — Вишневе, вул. Європейська, 30
              - generic [ref=f2e99] [cursor=pointer]:
                - checkbox "4493 — Вишневе, вул. Промислова, 5" [ref=f2e100]
                - text: 4493 — Вишневе, вул. Промислова, 5
              - generic [ref=f2e101] [cursor=pointer]:
                - checkbox "3256 — Вовчинець, вул. Вовчинецька, 225А" [ref=f2e102]
                - text: 3256 — Вовчинець, вул. Вовчинецька, 225А
              - generic [ref=f2e103] [cursor=pointer]:
                - checkbox "3807 — Вовчинець, вул. Вовчинецька, 225" [ref=f2e104]
                - text: 3807 — Вовчинець, вул. Вовчинецька, 225
              - generic [ref=f2e105] [cursor=pointer]:
                - checkbox "2086 — Вінниця, вул. Зодчих, 2" [ref=f2e106]
                - text: 2086 — Вінниця, вул. Зодчих, 2
              - generic [ref=f2e107] [cursor=pointer]:
                - checkbox "2087 — Вінниця, пл. Калічанська, 2" [ref=f2e108]
                - text: 2087 — Вінниця, пл. Калічанська, 2
              - generic [ref=f2e109] [cursor=pointer]:
                - checkbox "2088 — Вінниця, вул. Келецька, 105" [ref=f2e110]
                - text: 2088 — Вінниця, вул. Келецька, 105
              - generic [ref=f2e111] [cursor=pointer]:
                - checkbox "2091 — Вінниця, вул. Оводова Миколи, 51" [ref=f2e112]
                - text: 2091 — Вінниця, вул. Оводова Миколи, 51
              - generic [ref=f2e113] [cursor=pointer]:
                - checkbox "2809 — Вінниця, вул. Келецька, 121" [ref=f2e114]
                - text: 2809 — Вінниця, вул. Келецька, 121
              - generic [ref=f2e115] [cursor=pointer]:
                - checkbox "3067 — Вінниця, просп. Юності, 18" [ref=f2e116]
                - text: 3067 — Вінниця, просп. Юності, 18
              - generic [ref=f2e117] [cursor=pointer]:
                - checkbox "3096 — Вінниця, просп. Космонавтів, 49" [ref=f2e118]
                - text: 3096 — Вінниця, просп. Космонавтів, 49
              - generic [ref=f2e119] [cursor=pointer]:
                - checkbox "3280 — Вінниця, вул. 600-річчя, 17Е" [ref=f2e120]
                - text: 3280 — Вінниця, вул. 600-річчя, 17Е
              - generic [ref=f2e121] [cursor=pointer]:
                - checkbox "3795 — Вінниця, вул. 600-річчя, 17Е" [ref=f2e122]
                - text: 3795 — Вінниця, вул. 600-річчя, 17Е
              - generic [ref=f2e123] [cursor=pointer]:
                - checkbox "2634 — Віта-Поштова, вул. Звенигородська, 200Д" [ref=f2e124]
                - text: 2634 — Віта-Поштова, вул. Звенигородська, 200Д
              - generic [ref=f2e125] [cursor=pointer]:
                - checkbox "2207 — Дніпро, бульв. Кельнський, 1" [ref=f2e126]
                - text: 2207 — Дніпро, бульв. Кельнський, 1
              - generic [ref=f2e127] [cursor=pointer]:
                - checkbox "2262 — Дніпро, бульв. Слави, 5" [ref=f2e128]
                - text: 2262 — Дніпро, бульв. Слави, 5
              - generic [ref=f2e129] [cursor=pointer]:
                - checkbox "2264 — Дніпро, пл. Вокзальна, 13" [ref=f2e130]
                - text: 2264 — Дніпро, пл. Вокзальна, 13
              - generic [ref=f2e131] [cursor=pointer]:
                - checkbox "2265 — Дніпро, просп. Слобожанський, 76/78" [ref=f2e132]
                - text: 2265 — Дніпро, просп. Слобожанський, 76/78
              - generic [ref=f2e133] [cursor=pointer]:
                - checkbox "2267 — Дніпро, вул. Пастера, 6А" [ref=f2e134]
                - text: 2267 — Дніпро, вул. Пастера, 6А
              - generic [ref=f2e135] [cursor=pointer]:
                - checkbox "2269 — Дніпро, просп. Слобожанський, 31Д" [ref=f2e136]
                - text: 2269 — Дніпро, просп. Слобожанський, 31Д
              - generic [ref=f2e137] [cursor=pointer]:
                - checkbox "2273 — Дніпро, просп. Науки, 3" [ref=f2e138]
                - text: 2273 — Дніпро, просп. Науки, 3
              - generic [ref=f2e139] [cursor=pointer]:
                - checkbox "2276 — Дніпро, пров. Крушельницької, 6А" [ref=f2e140]
                - text: 2276 — Дніпро, пров. Крушельницької, 6А
              - generic [ref=f2e141] [cursor=pointer]:
                - checkbox "2277 — Дніпро, вул. Новокримська, 3А" [ref=f2e142]
                - text: 2277 — Дніпро, вул. Новокримська, 3А
              - generic [ref=f2e143] [cursor=pointer]:
                - checkbox "2279 — Дніпро, бульв. Кельнський, 1" [ref=f2e144]
                - text: 2279 — Дніпро, бульв. Кельнський, 1
              - generic [ref=f2e145] [cursor=pointer]:
                - checkbox "2280 — Дніпро, вул. Європейська, 18А" [ref=f2e146]
                - text: 2280 — Дніпро, вул. Європейська, 18А
              - generic [ref=f2e147] [cursor=pointer]:
                - checkbox "2281 — Дніпро, вул. Кондратюка Юрія, 4" [ref=f2e148]
                - text: 2281 — Дніпро, вул. Кондратюка Юрія, 4
              - generic [ref=f2e149] [cursor=pointer]:
                - checkbox "2298 — Дніпро, вул. Тополина, 1" [ref=f2e150]
                - text: 2298 — Дніпро, вул. Тополина, 1
              - generic [ref=f2e151] [cursor=pointer]:
                - checkbox "2679 — Дніпро, вул. Лазаря Глоби, 7" [ref=f2e152]
                - text: 2679 — Дніпро, вул. Лазаря Глоби, 7
              - generic [ref=f2e153] [cursor=pointer]:
                - checkbox "2951 — Дніпро, вул. Незалежності, 36" [ref=f2e154]
                - text: 2951 — Дніпро, вул. Незалежності, 36
              - generic [ref=f2e155] [cursor=pointer]:
                - checkbox "4200 — Дніпро, просп. Слобожанський, 31Д" [ref=f2e156]
                - text: 4200 — Дніпро, просп. Слобожанський, 31Д
              - generic [ref=f2e157] [cursor=pointer]:
                - checkbox "4201 — Дніпро, вул. Незалежності, 36" [ref=f2e158]
                - text: 4201 — Дніпро, вул. Незалежності, 36
              - generic [ref=f2e159] [cursor=pointer]:
                - checkbox "2123 — Дрогобич, вул. Володимира Великого, 7" [ref=f2e160]
                - text: 2123 — Дрогобич, вул. Володимира Великого, 7
              - generic [ref=f2e161] [cursor=pointer]:
                - checkbox "3746 — Дрогобич, вул. Володимира Великого, 1Р" [ref=f2e162]
                - text: 3746 — Дрогобич, вул. Володимира Великого, 1Р
              - generic [ref=f2e163] [cursor=pointer]:
                - checkbox "2070 — Житомир, пл. Житній Ринок, 1" [ref=f2e164]
                - text: 2070 — Житомир, пл. Житній Ринок, 1
              - generic [ref=f2e165] [cursor=pointer]:
                - checkbox "2938 — Житомир, вул. Грушевського Михайла, 5" [ref=f2e166]
                - text: 2938 — Житомир, вул. Грушевського Михайла, 5
              - generic [ref=f2e167] [cursor=pointer]:
                - checkbox "2192 — Запоріжжя, вул. Вінтера, 30/3" [ref=f2e168]
                - text: 2192 — Запоріжжя, вул. Вінтера, 30/3
              - generic [ref=f2e169] [cursor=pointer]:
                - checkbox "2195 — Запоріжжя, вул. Ситова, 4" [ref=f2e170]
                - text: 2195 — Запоріжжя, вул. Ситова, 4
              - generic [ref=f2e171] [cursor=pointer]:
                - checkbox "2212 — Запоріжжя, вул. Сергієнка Василя, 9" [ref=f2e172]
                - text: 2212 — Запоріжжя, вул. Сергієнка Василя, 9
              - generic [ref=f2e173] [cursor=pointer]:
                - checkbox "2213 — Запоріжжя, вул. Фортечна, 6" [ref=f2e174]
                - text: 2213 — Запоріжжя, вул. Фортечна, 6
              - generic [ref=f2e175] [cursor=pointer]:
                - checkbox "2214 — Запоріжжя, просп. Соборний, 147" [ref=f2e176]
                - text: 2214 — Запоріжжя, просп. Соборний, 147
              - generic [ref=f2e177] [cursor=pointer]:
                - checkbox "2222 — Запоріжжя, шосе Хортицьке, 30А" [ref=f2e178]
                - text: 2222 — Запоріжжя, шосе Хортицьке, 30А
              - generic [ref=f2e179] [cursor=pointer]:
                - checkbox "2223 — Запоріжжя, просп. Металургів, 8Б" [ref=f2e180]
                - text: 2223 — Запоріжжя, просп. Металургів, 8Б
              - generic [ref=f2e181] [cursor=pointer]:
                - checkbox "2224 — Запоріжжя, вул. Чарівна, 155Б" [ref=f2e182]
                - text: 2224 — Запоріжжя, вул. Чарівна, 155Б
              - generic [ref=f2e183] [cursor=pointer]:
                - checkbox "2225 — Запоріжжя, вул. Руставі, 1Г" [ref=f2e184]
                - text: 2225 — Запоріжжя, вул. Руставі, 1Г
              - generic [ref=f2e185] [cursor=pointer]:
                - checkbox "2237 — Запоріжжя, вул. Іванова, 1А" [ref=f2e186]
                - text: 2237 — Запоріжжя, вул. Іванова, 1А
              - generic [ref=f2e187] [cursor=pointer]:
                - checkbox "2238 — Запоріжжя, вул. Володимира Українця, 41" [ref=f2e188]
                - text: 2238 — Запоріжжя, вул. Володимира Українця, 41
              - generic [ref=f2e189] [cursor=pointer]:
                - checkbox "2239 — Запоріжжя, вул. Петра Сагайдачного, 20Б" [ref=f2e190]
                - text: 2239 — Запоріжжя, вул. Петра Сагайдачного, 20Б
              - generic [ref=f2e191] [cursor=pointer]:
                - checkbox "2240 — Запоріжжя, вул. Перемоги, 64" [ref=f2e192]
                - text: 2240 — Запоріжжя, вул. Перемоги, 64
              - generic [ref=f2e193] [cursor=pointer]:
                - checkbox "2241 — Запоріжжя, просп. Преображенського Інженера, 13" [ref=f2e194]
                - text: 2241 — Запоріжжя, просп. Преображенського Інженера, 13
              - generic [ref=f2e195] [cursor=pointer]:
                - checkbox "2242 — Запоріжжя, вул. Чарівна, 74" [ref=f2e196]
                - text: 2242 — Запоріжжя, вул. Чарівна, 74
              - generic [ref=f2e197] [cursor=pointer]:
                - checkbox "3439 — Запоріжжя, вул. Запорізька, 1Б" [ref=f2e198]
                - text: 3439 — Запоріжжя, вул. Запорізька, 1Б
              - generic [ref=f2e199] [cursor=pointer]:
                - checkbox "3932 — Запоріжжя, вул. Запорізька, 1Б" [ref=f2e200]
                - text: 3932 — Запоріжжя, вул. Запорізька, 1Б
              - generic [ref=f2e201] [cursor=pointer]:
                - checkbox "3144 — Калуш, майдан Шептицького, 7" [ref=f2e202]
                - text: 3144 — Калуш, майдан Шептицького, 7
              - generic [ref=f2e203] [cursor=pointer]:
                - checkbox "2189 — Кам’янець-Подільський, шосе Нігинське, 41/1" [ref=f2e204]
                - text: 2189 — Кам’янець-Подільський, шосе Нігинське, 41/1
              - generic [ref=f2e205] [cursor=pointer]:
                - checkbox "3147 — Кам’янець-Подільський, вул. Українки Лесі, 30" [ref=f2e206]
                - text: 3147 — Кам’янець-Подільський, вул. Українки Лесі, 30
              - generic [ref=f2e207] [cursor=pointer]:
                - checkbox "3148 — Кам’янець-Подільський, вул. Степана Бандери, 42/1" [ref=f2e208]
                - text: 3148 — Кам’янець-Подільський, вул. Степана Бандери, 42/1
              - generic [ref=f2e209] [cursor=pointer]:
                - checkbox "3151 — Кам’янське, просп. Шевченка, 9" [ref=f2e210]
                - text: 3151 — Кам’янське, просп. Шевченка, 9
              - generic [ref=f2e211] [cursor=pointer]:
                - checkbox "3194 — Канів, вул. Енергетиків, 12А" [ref=f2e212]
                - text: 3194 — Канів, вул. Енергетиків, 12А
              - generic [ref=f2e213] [cursor=pointer]:
                - checkbox "1932 — Київ, вул. Берковецька, 6Д" [ref=f2e214]
                - text: 1932 — Київ, вул. Берковецька, 6Д
              - generic [ref=f2e215] [cursor=pointer]:
                - checkbox "1934 — Київ, вул. Берковецька, 6" [ref=f2e216]
                - text: 1934 — Київ, вул. Берковецька, 6
              - generic [ref=f2e217] [cursor=pointer]:
                - checkbox "1990 — Київ, просп. Берестейський, 87" [ref=f2e218]
                - text: 1990 — Київ, просп. Берестейський, 87
              - generic [ref=f2e219] [cursor=pointer]:
                - checkbox "1991 — Київ, вул. Гната Юри, 20" [ref=f2e220]
                - text: 1991 — Київ, вул. Гната Юри, 20
              - generic [ref=f2e221] [cursor=pointer]:
                - checkbox "1992 — Київ, шосе Харківське, 21" [ref=f2e222]
                - text: 1992 — Київ, шосе Харківське, 21
              - generic [ref=f2e223] [cursor=pointer]:
                - checkbox "1993 — Київ, вул. Героїв полку «Азов», 34" [ref=f2e224]
                - text: 1993 — Київ, вул. Героїв полку «Азов», 34
              - generic [ref=f2e225] [cursor=pointer]:
                - checkbox "1994 — Київ, просп. Червоної Калини, 75/2" [ref=f2e226]
                - text: 1994 — Київ, просп. Червоної Калини, 75/2
              - generic [ref=f2e227] [cursor=pointer]:
                - checkbox "1995 — Київ, вул. Білоруська, 2" [ref=f2e228]
                - text: 1995 — Київ, вул. Білоруська, 2
              - generic [ref=f2e229] [cursor=pointer]:
                - checkbox "1996 — Київ, просп. Оболонський, 36б" [ref=f2e230]
                - text: 1996 — Київ, просп. Оболонський, 36б
              - generic [ref=f2e231] [cursor=pointer]:
                - checkbox "1998 — Київ, просп. Володимира Івасюка, 46" [ref=f2e232]
                - text: 1998 — Київ, просп. Володимира Івасюка, 46
              - generic [ref=f2e233] [cursor=pointer]:
                - checkbox "1999 — Київ, наб. Русанівська, 10" [ref=f2e234]
                - text: 1999 — Київ, наб. Русанівська, 10
              - generic [ref=f2e235] [cursor=pointer]:
                - checkbox "2000 — Київ, вул. Закревського Миколи, 61/2" [ref=f2e236]
                - text: 2000 — Київ, вул. Закревського Миколи, 61/2
              - generic [ref=f2e237] [cursor=pointer]:
                - checkbox "2001 — Київ, вул. Малевича, 107" [ref=f2e238]
                - text: 2001 — Київ, вул. Малевича, 107
              - generic [ref=f2e239] [cursor=pointer]:
                - checkbox "2002 — Київ, пров. Фінський, 3" [ref=f2e240]
                - text: 2002 — Київ, пров. Фінський, 3
              - generic [ref=f2e241] [cursor=pointer]:
                - checkbox "2004 — Київ, вул. Північна, 6" [ref=f2e242]
                - text: 2004 — Київ, вул. Північна, 6
              - generic [ref=f2e243] [cursor=pointer]:
                - checkbox "2005 — Київ, просп. Європейського Союзу, 66" [ref=f2e244]
                - text: 2005 — Київ, просп. Європейського Союзу, 66
              - generic [ref=f2e245] [cursor=pointer]:
                - checkbox "2006 — Київ, вул. Рональда Рейгана, 8" [ref=f2e246]
                - text: 2006 — Київ, вул. Рональда Рейгана, 8
              - generic [ref=f2e247] [cursor=pointer]:
                - checkbox "2007 — Київ, вул. Щербаківського Данила, 56/7" [ref=f2e248]
                - text: 2007 — Київ, вул. Щербаківського Данила, 56/7
              - generic [ref=f2e249] [cursor=pointer]:
                - checkbox "2008 — Київ, вул. Чорнобильська, 3" [ref=f2e250]
                - text: 2008 — Київ, вул. Чорнобильська, 3
              - generic [ref=f2e251] [cursor=pointer]:
                - checkbox "2009 — Київ, вул. Булгакова, 11" [ref=f2e252]
                - text: 2009 — Київ, вул. Булгакова, 11
              - generic [ref=f2e253] [cursor=pointer]:
                - checkbox "2010 — Київ, вул. Западинська, 15А" [ref=f2e254]
                - text: 2010 — Київ, вул. Западинська, 15А
              - generic [ref=f2e255] [cursor=pointer]:
                - checkbox "2011 — Київ, просп. Берестейський, 47" [ref=f2e256]
                - text: 2011 — Київ, просп. Берестейський, 47
              - generic [ref=f2e257] [cursor=pointer]:
                - checkbox "2012 — Київ, вул. Райдужна, 8" [ref=f2e258]
                - text: 2012 — Київ, вул. Райдужна, 8
              - generic [ref=f2e259] [cursor=pointer]:
                - checkbox "2013 — Київ, вул. Підлісна, 1" [ref=f2e260]
                - text: 2013 — Київ, вул. Підлісна, 1
              - generic [ref=f2e261] [cursor=pointer]:
                - checkbox "2015 — Київ, вул. Григоренка Петра, 23" [ref=f2e262]
                - text: 2015 — Київ, вул. Григоренка Петра, 23
              - generic [ref=f2e263] [cursor=pointer]:
                - checkbox "2016 — Київ, вул. Тичини Павла, 1В" [ref=f2e264]
                - text: 2016 — Київ, вул. Тичини Павла, 1В
              - generic [ref=f2e265] [cursor=pointer]:
                - checkbox "2017 — Київ, вул. Милославська, 10А" [ref=f2e266]
                - text: 2017 — Київ, вул. Милославська, 10А
              - generic [ref=f2e267] [cursor=pointer]:
                - checkbox "2018 — Київ, просп. Литовський, 4А" [ref=f2e268]
                - text: 2018 — Київ, просп. Литовський, 4А
              - generic [ref=f2e269] [cursor=pointer]:
                - checkbox "2019 — Київ, бульв. Чоколівський, 6" [ref=f2e270]
                - text: 2019 — Київ, бульв. Чоколівський, 6
              - generic [ref=f2e271] [cursor=pointer]:
                - checkbox "2020 — Київ, вул. Срібнокільська, 3Г" [ref=f2e272]
                - text: 2020 — Київ, вул. Срібнокільська, 3Г
              - generic [ref=f2e273] [cursor=pointer]:
                - checkbox "2021 — Київ, вул. Родини Бунґе, 8" [ref=f2e274]
                - text: 2021 — Київ, вул. Родини Бунґе, 8
              - generic [ref=f2e275] [cursor=pointer]:
                - checkbox "2022 — Київ, вул. Лаврухіна, 4" [ref=f2e276]
                - text: 2022 — Київ, вул. Лаврухіна, 4
              - generic [ref=f2e277] [cursor=pointer]:
                - checkbox "2023 — Київ, вул. Самійла Кішки, 7" [ref=f2e278]
                - text: 2023 — Київ, вул. Самійла Кішки, 7
              - generic [ref=f2e279] [cursor=pointer]:
                - checkbox "2025 — Київ, вул. Бережанська, 22" [ref=f2e280]
                - text: 2025 — Київ, вул. Бережанська, 22
              - generic [ref=f2e281] [cursor=pointer]:
                - checkbox "2026 — Київ, шосе Харківське, 144Б" [ref=f2e282]
                - text: 2026 — Київ, шосе Харківське, 144Б
              - generic [ref=f2e283] [cursor=pointer]:
                - checkbox "2027 — Київ, бульв. Чоколівський, 28/1" [ref=f2e284]
                - text: 2027 — Київ, бульв. Чоколівський, 28/1
              - generic [ref=f2e285] [cursor=pointer]:
                - checkbox "2028 — Київ, бульв. Миколи Руденка, 14М" [ref=f2e286]
                - text: 2028 — Київ, бульв. Миколи Руденка, 14М
              - generic [ref=f2e287] [cursor=pointer]:
                - checkbox "2029 — Київ, вул. Дорогожицька, 2" [ref=f2e288]
                - text: 2029 — Київ, вул. Дорогожицька, 2
              - generic [ref=f2e289] [cursor=pointer]:
                - checkbox "2030 — Київ, вул. Архипенка Олександра, 6" [ref=f2e290]
                - text: 2030 — Київ, вул. Архипенка Олександра, 6
              - generic [ref=f2e291] [cursor=pointer]:
                - checkbox "2031 — Київ, вул. Гончара Олеся, 96" [ref=f2e292]
                - text: 2031 — Київ, вул. Гончара Олеся, 96
              - generic [ref=f2e293] [cursor=pointer]:
                - checkbox "2032 — Київ, вул. Мишуги Олександра, 4" [ref=f2e294]
                - text: 2032 — Київ, вул. Мишуги Олександра, 4
              - generic [ref=f2e295] [cursor=pointer]:
                - checkbox "2034 — Київ, вул. Сагайдачного, 41" [ref=f2e296]
                - text: 2034 — Київ, вул. Сагайдачного, 41
              - generic [ref=f2e297] [cursor=pointer]:
                - checkbox "2035 — Київ, вул. Героїв полку «Азов», 5" [ref=f2e298]
                - text: 2035 — Київ, вул. Героїв полку «Азов», 5
              - generic [ref=f2e299] [cursor=pointer]:
                - checkbox "2036 — Київ, просп. Лісовий, 39" [ref=f2e300]
                - text: 2036 — Київ, просп. Лісовий, 39
              - generic [ref=f2e301] [cursor=pointer]:
                - checkbox "2037 — Київ, вул. Стальського Сулеймана, 22/10" [ref=f2e302]
                - text: 2037 — Київ, вул. Стальського Сулеймана, 22/10
              - generic [ref=f2e303] [cursor=pointer]:
                - checkbox "2038 — Київ, вул. Порика Василя, 5" [ref=f2e304]
                - text: 2038 — Київ, вул. Порика Василя, 5
              - generic [ref=f2e305] [cursor=pointer]:
                - checkbox "2039 — Київ, бульв. Дарницький, 8А" [ref=f2e306]
                - text: 2039 — Київ, бульв. Дарницький, 8А
              - generic [ref=f2e307] [cursor=pointer]:
                - checkbox "2041 — Київ, шосе Харківське, 168" [ref=f2e308]
                - text: 2041 — Київ, шосе Харківське, 168
              - generic [ref=f2e309] [cursor=pointer]:
                - checkbox "2042 — Київ, вул. Борщагівська, 154А" [ref=f2e310]
                - text: 2042 — Київ, вул. Борщагівська, 154А
              - generic [ref=f2e311] [cursor=pointer]:
                - checkbox "2043 — Київ, наб. Дніпровська, 33" [ref=f2e312]
                - text: 2043 — Київ, наб. Дніпровська, 33
              - generic [ref=f2e313] [cursor=pointer]:
                - checkbox "2044 — Київ, вул. Здолбунівська, 4" [ref=f2e314]
                - text: 2044 — Київ, вул. Здолбунівська, 4
              - generic [ref=f2e315] [cursor=pointer]:
                - checkbox "2045 — Київ, вул. Басейна, 6" [ref=f2e316]
                - text: 2045 — Київ, вул. Басейна, 6
              - generic [ref=f2e317] [cursor=pointer]:
                - checkbox "2046 — Київ, просп. Червоної Калини, 43/2" [ref=f2e318]
                - text: 2046 — Київ, просп. Червоної Калини, 43/2
              - generic [ref=f2e319] [cursor=pointer]:
                - checkbox "2047 — Київ, вул. Оленівська, 3" [ref=f2e320]
                - text: 2047 — Київ, вул. Оленівська, 3
              - generic [ref=f2e321] [cursor=pointer]:
                - checkbox "2048 — Київ, вул. Січових Стрільців, 37/41" [ref=f2e322]
                - text: 2048 — Київ, вул. Січових Стрільців, 37/41
              - generic [ref=f2e323] [cursor=pointer]:
                - checkbox "2050 — Київ, вул. Шептицького, 4" [ref=f2e324]
                - text: 2050 — Київ, вул. Шептицького, 4
              - generic [ref=f2e325] [cursor=pointer]:
                - checkbox "2051 — Київ, вул. Вербицького Архітектора, 1" [ref=f2e326]
                - text: 2051 — Київ, вул. Вербицького Архітектора, 1
              - generic [ref=f2e327] [cursor=pointer]:
                - checkbox "2052 — Київ, просп. Володимира Івасюка, 8А" [ref=f2e328]
                - text: 2052 — Київ, просп. Володимира Івасюка, 8А
              - generic [ref=f2e329] [cursor=pointer]:
                - checkbox "2053 — Київ, вул. Драгомирова Михайла, 16" [ref=f2e330]
                - text: 2053 — Київ, вул. Драгомирова Михайла, 16
              - generic [ref=f2e331] [cursor=pointer]:
                - checkbox "2054 — Київ, вул. Філатова Академіка, 7" [ref=f2e332]
                - text: 2054 — Київ, вул. Філатова Академіка, 7
              - generic [ref=f2e333] [cursor=pointer]:
                - checkbox "2060 — Київ, пл. Спортивна, 1А" [ref=f2e334]
                - text: 2060 — Київ, пл. Спортивна, 1А
              - generic [ref=f2e335] [cursor=pointer]:
                - checkbox "2061 — Київ, вул. Калнишевського Петра, 2" [ref=f2e336]
                - text: 2061 — Київ, вул. Калнишевського Петра, 2
              - generic [ref=f2e337] [cursor=pointer]:
                - checkbox "2062 — Київ, вул. Скляренка Семена, 17" [ref=f2e338]
                - text: 2062 — Київ, вул. Скляренка Семена, 17
              - generic [ref=f2e339] [cursor=pointer]:
                - checkbox "2063 — Київ, вул. Коновальця Євгена, 26А" [ref=f2e340]
                - text: 2063 — Київ, вул. Коновальця Євгена, 26А
              - generic [ref=f2e341] [cursor=pointer]:
                - checkbox "2064 — Київ, просп. Європейського Союзу, 58" [ref=f2e342]
                - text: 2064 — Київ, просп. Європейського Союзу, 58
              - generic [ref=f2e343] [cursor=pointer]:
                - checkbox "2065 — Київ, просп. Тичини Павла, 1В" [ref=f2e344]
                - text: 2065 — Київ, просп. Тичини Павла, 1В
              - generic [ref=f2e345] [cursor=pointer]:
                - checkbox "2066 — Київ, вул. Лаврухіна, 4" [ref=f2e346]
                - text: 2066 — Київ, вул. Лаврухіна, 4
              - generic [ref=f2e347] [cursor=pointer]:
                - checkbox "2067 — Київ, вул. Басейна, 6" [ref=f2e348]
                - text: 2067 — Київ, вул. Басейна, 6
              - generic [ref=f2e349] [cursor=pointer]:
                - checkbox "2096 — Київ, просп. Тичини Павла, 1В" [ref=f2e350]
                - text: 2096 — Київ, просп. Тичини Павла, 1В
              - generic [ref=f2e351] [cursor=pointer]:
                - checkbox "2291 — Київ, вул. Липківського Василя митрополита, 45" [ref=f2e352]
                - text: 2291 — Київ, вул. Липківського Василя митрополита, 45
              - generic [ref=f2e353] [cursor=pointer]:
                - checkbox "2301 — Київ, просп. Бандери Степана, 36" [ref=f2e354]
                - text: 2301 — Київ, просп. Бандери Степана, 36
              - generic [ref=f2e355] [cursor=pointer]:
                - checkbox "2326 — Київ, пл. Спортивна, пл, 1А" [ref=f2e356]
                - text: 2326 — Київ, пл. Спортивна, пл, 1А
              - generic [ref=f2e357] [cursor=pointer]:
                - checkbox "2335 — Київ, просп. Володимира Івасюка, 27Б" [ref=f2e358]
                - text: 2335 — Київ, просп. Володимира Івасюка, 27Б
              - generic [ref=f2e359] [cursor=pointer]:
                - checkbox "2380 — Київ, вул. Івашкевича Ярослава, 6-8А" [ref=f2e360]
                - text: 2380 — Київ, вул. Івашкевича Ярослава, 6-8А
              - generic [ref=f2e361] [cursor=pointer]:
                - checkbox "2382 — Київ, просп. Берестейський, 24" [ref=f2e362]
                - text: 2382 — Київ, просп. Берестейський, 24
              - generic [ref=f2e363] [cursor=pointer]:
                - checkbox "2406 — Київ, просп. Оболонський, 19" [ref=f2e364]
                - text: 2406 — Київ, просп. Оболонський, 19
              - generic [ref=f2e365] [cursor=pointer]:
                - checkbox "2482 — Київ, наб. Дніпровська, 12" [ref=f2e366]
                - text: 2482 — Київ, наб. Дніпровська, 12
              - generic [ref=f2e367] [cursor=pointer]:
                - checkbox "2578 — Київ, просп. Володимира Івасюка, 12П" [ref=f2e368]
                - text: 2578 — Київ, просп. Володимира Івасюка, 12П
              - generic [ref=f2e369] [cursor=pointer]:
                - checkbox "2668 — Київ, вул. Дніпровська, 12" [ref=f2e370]
                - text: 2668 — Київ, вул. Дніпровська, 12
              - generic [ref=f2e371] [cursor=pointer]:
                - checkbox "2732 — Київ, вул. Якова Гніздовського, 1А" [ref=f2e372]
                - text: 2732 — Київ, вул. Якова Гніздовського, 1А
              - generic [ref=f2e373] [cursor=pointer]:
                - checkbox "2733 — Київ, просп. Оболонський, 21Б" [ref=f2e374]
                - text: 2733 — Київ, просп. Оболонський, 21Б
              - generic [ref=f2e375] [cursor=pointer]:
                - checkbox "2734 — Київ, просп. Оболонський, 1Б" [ref=f2e376]
                - text: 2734 — Київ, просп. Оболонський, 1Б
              - generic [ref=f2e377] [cursor=pointer]:
                - checkbox "2735 — Київ, вул. Липківського Василя митрополита, 1А" [ref=f2e378]
                - text: 2735 — Київ, вул. Липківського Василя митрополита, 1А
              - generic [ref=f2e379] [cursor=pointer]:
                - checkbox "2737 — Київ, вул. Глибочицька, 32Б" [ref=f2e380]
                - text: 2737 — Київ, вул. Глибочицька, 32Б
              - generic [ref=f2e381] [cursor=pointer]:
                - checkbox "2783 — Київ, вул. Драгоманова, 10" [ref=f2e382]
                - text: 2783 — Київ, вул. Драгоманова, 10
              - generic [ref=f2e383] [cursor=pointer]:
                - checkbox "2795 — Київ, вул. Ярославська, 56А" [ref=f2e384]
                - text: 2795 — Київ, вул. Ярославська, 56А
              - generic [ref=f2e385] [cursor=pointer]:
                - checkbox "2908 — Київ, вул. Загорівська, 17-20" [ref=f2e386]
                - text: 2908 — Київ, вул. Загорівська, 17-20
              - generic [ref=f2e387] [cursor=pointer]:
                - checkbox "2941 — Київ, вул. Глибочицька, 32Б" [ref=f2e388]
                - text: 2941 — Київ, вул. Глибочицька, 32Б
              - generic [ref=f2e389] [cursor=pointer]:
                - checkbox "2947 — Київ, вул. Ярославська, 56А" [ref=f2e390]
                - text: 2947 — Київ, вул. Ярославська, 56А
              - generic [ref=f2e391] [cursor=pointer]:
                - checkbox "2948 — Київ, шосе Столичне, 103" [ref=f2e392]
                - text: 2948 — Київ, шосе Столичне, 103
              - generic [ref=f2e393] [cursor=pointer]:
                - checkbox "2963 — Київ, просп. Володимира Івасюка, 46" [ref=f2e394]
                - text: 2963 — Київ, просп. Володимира Івасюка, 46
              - generic [ref=f2e395] [cursor=pointer]:
                - checkbox "2999 — Київ, вул. Вирій, 1" [ref=f2e396]
                - text: 2999 — Київ, вул. Вирій, 1
              - generic [ref=f2e397] [cursor=pointer]:
                - checkbox "3060 — Київ, вул. Лютеранська, 7/10" [ref=f2e398]
                - text: 3060 — Київ, вул. Лютеранська, 7/10
              - generic [ref=f2e399] [cursor=pointer]:
                - checkbox "3061 — Київ, вул. Ярославів Вал, 21/20" [ref=f2e400]
                - text: 3061 — Київ, вул. Ярославів Вал, 21/20
              - generic [ref=f2e401] [cursor=pointer]:
                - checkbox "3062 — Київ, вул. Ярославів Вал, 21/20" [ref=f2e402]
                - text: 3062 — Київ, вул. Ярославів Вал, 21/20
              - generic [ref=f2e403] [cursor=pointer]:
                - checkbox "3068 — Київ, шосе Столичне, 103" [ref=f2e404]
                - text: 3068 — Київ, шосе Столичне, 103
              - generic [ref=f2e405] [cursor=pointer]:
                - checkbox "3087 — Київ, вул. Січових Стрільців, 37/41" [ref=f2e406]
                - text: 3087 — Київ, вул. Січових Стрільців, 37/41
              - generic [ref=f2e407] [cursor=pointer]:
                - checkbox "3110 — Київ, просп. Тичини Павла, 1В" [ref=f2e408]
                - text: 3110 — Київ, просп. Тичини Павла, 1В
              - generic [ref=f2e409] [cursor=pointer]:
                - checkbox "3141 — Київ, вул. Кільцева, 1" [ref=f2e410]
                - text: 3141 — Київ, вул. Кільцева, 1
              - generic [ref=f2e411] [cursor=pointer]:
                - checkbox "3164 — Київ, вул. Вербицького Архітектора, 30" [ref=f2e412]
                - text: 3164 — Київ, вул. Вербицького Архітектора, 30
              - generic [ref=f2e413] [cursor=pointer]:
                - checkbox "3165 — Київ, вул. Вершигори Петра, 1" [ref=f2e414]
                - text: 3165 — Київ, вул. Вершигори Петра, 1
              - generic [ref=f2e415] [cursor=pointer]:
                - checkbox "3167 — Київ, вул. Інженерна, 1" [ref=f2e416]
                - text: 3167 — Київ, вул. Інженерна, 1
              - generic [ref=f2e417] [cursor=pointer]:
                - checkbox "3168 — Київ, вул. Антоновича, 165" [ref=f2e418]
                - text: 3168 — Київ, вул. Антоновича, 165
              - generic [ref=f2e419] [cursor=pointer]:
                - checkbox "3169 — Київ, вул. Кибальчича Миколи, 11А" [ref=f2e420]
                - text: 3169 — Київ, вул. Кибальчича Миколи, 11А
              - generic [ref=f2e421] [cursor=pointer]:
                - checkbox "3170 — Київ, вул. Шептицького, 22" [ref=f2e422]
                - text: 3170 — Київ, вул. Шептицького, 22
              - generic [ref=f2e423] [cursor=pointer]:
                - checkbox "3171 — Київ, просп. Берестейський, 94/1" [ref=f2e424]
                - text: 3171 — Київ, просп. Берестейський, 94/1
              - generic [ref=f2e425] [cursor=pointer]:
                - checkbox "3172 — Київ, вул. Райдужна, 15" [ref=f2e426]
                - text: 3172 — Київ, вул. Райдужна, 15
              - generic [ref=f2e427] [cursor=pointer]:
                - checkbox "3174 — Київ, вул. Братиславська, 14Б" [ref=f2e428]
                - text: 3174 — Київ, вул. Братиславська, 14Б
              - generic [ref=f2e429] [cursor=pointer]:
                - checkbox "3175 — Київ, вул. Вишгородська, 21" [ref=f2e430]
                - text: 3175 — Київ, вул. Вишгородська, 21
              - generic [ref=f2e431] [cursor=pointer]:
                - checkbox "3176 — Київ, вул. Ревуцького, 12/1" [ref=f2e432]
                - text: 3176 — Київ, вул. Ревуцького, 12/1
              - generic [ref=f2e433] [cursor=pointer]:
                - checkbox "3209 — Київ, просп. Володимира Івасюка, 46" [ref=f2e434]
                - text: 3209 — Київ, просп. Володимира Івасюка, 46
              - generic [ref=f2e435] [cursor=pointer]:
                - checkbox "3241 — Київ, вул. Тичини, 1б" [ref=f2e436]
                - text: 3241 — Київ, вул. Тичини, 1б
              - generic [ref=f2e437] [cursor=pointer]:
                - checkbox "3247 — Київ, просп. Воскресенський, 36" [ref=f2e438]
                - text: 3247 — Київ, просп. Воскресенський, 36
              - generic [ref=f2e439] [cursor=pointer]:
                - checkbox "3248 — Київ, вул. Бальзака Оноре, 91/29А" [ref=f2e440]
                - text: 3248 — Київ, вул. Бальзака Оноре, 91/29А
              - generic [ref=f2e441] [cursor=pointer]:
                - checkbox "3249 — Київ, вул. Причальна, 14-А" [ref=f2e442]
                - text: 3249 — Київ, вул. Причальна, 14-А
            - generic [ref=f2e443]:
              - button "Скинути фільтри" [ref=f2e444] [cursor=pointer]
              - button "Закрити" [ref=f2e445] [cursor=pointer]
        - button "Зберегти" [ref=f2e446] [cursor=pointer]
```

# Test source

```ts
  321 |   });
  322 | 
  323 |   test('A-10.14 призупинений постачальник зникає з фільтра «Активний»', async ({ page }) => {
  324 |     const name = testSupplierName('фільтр');
  325 |     const id = await createSupplier(page, {
  326 |       name,
  327 |       edrpou: nextTestEdrpou(),
  328 |       contact: 'UITEST',
  329 |       phone: '+380501115511',
  330 |       email: 'uitest.filter@rampa.test',
  331 |     });
  332 |     const suspend = await apiRaw('post', `/suppliers/${id}/suspend`, { reason: 'UITEST' });
  333 |     expect(suspend.status).toBeLessThan(400);
  334 | 
  335 |     await goto(page, '/suppliers');
  336 |     await page.locator('#sup-search').fill(name);
  337 |     await page.locator('#sup-status').selectOption('active');
  338 |     await page.locator('.toolbar button', { hasText: 'Застосувати' }).click();
  339 |     await page.waitForLoadState('networkidle');
  340 |     await expect.poll(() => dataRowCount(page), { message: 'серед активних його немає' }).toBe(0);
  341 | 
  342 |     await page.locator('#sup-status').selectOption('suspended');
  343 |     await page.waitForLoadState('networkidle');
  344 |     await expect(page.locator('table.data tbody')).toContainText(name);
  345 |   });
  346 | 
  347 |   test('A-10.15 режим доступу «усі магазини» ↔ «перелік магазинів»', async ({ page }) => {
  348 |     const id = await createSupplier(page, {
  349 |       name: testSupplierName('доступ'),
  350 |       edrpou: nextTestEdrpou(),
  351 |       contact: 'UITEST',
  352 |       phone: '+380501116611',
  353 |       email: 'uitest.access@rampa.test',
  354 |     });
  355 | 
  356 |     await goto(page, `/suppliers/${id}`);
  357 |     await openTab(page, 'Магазини');
  358 |     await expect(page.locator('#access-mode'), 'новий постачальник — «усі магазини»').toHaveValue(
  359 |       'all',
  360 |     );
  361 | 
  362 |     await page.locator('#access-mode').selectOption('whitelist');
  363 |     const options = await multiSelectOptions(page, 'Магазини');
  364 |     expect(options.length, 'у переліку мають бути магазини').toBeGreaterThan(0);
  365 | 
  366 |     // обираємо перший варіант і зберігаємо
  367 |     const root = page.locator('.multi-select').filter({
  368 |       has: page.locator('.field-label:text-is("Магазини")'),
  369 |     });
  370 |     await root.locator('.multi-select-list label').first().locator('input').check();
  371 |     await root.locator('.multi-select-footer button', { hasText: 'Закрити' }).click();
  372 |     await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
  373 |     await waitForToast(page);
  374 | 
  375 |     const saved = await apiGet<any>(`/suppliers/${id}`);
  376 |     expect(saved.storeAccess.allStores, 'режим змінено на whitelist').toBe(false);
  377 |     expect(saved.storeAccess.storeIds.length, 'обраний магазин збережено').toBe(1);
  378 | 
  379 |     // назад на «усі магазини»
  380 |     await goto(page, `/suppliers/${id}`);
  381 |     await openTab(page, 'Магазини');
  382 |     await page.locator('#access-mode').selectOption('all');
  383 |     await page.locator('button.btn-primary', { hasText: 'Зберегти' }).click();
  384 |     await waitForToast(page);
  385 |     const back = await apiGet<any>(`/suppliers/${id}`);
  386 |     expect(back.storeAccess.allStores, 'повернулись до «усі магазини»').toBe(true);
  387 |   });
  388 | 
  389 |   test('A-10.16 X-01 у виборі магазинів доступні всі придатні філії мережі', async ({ page }) => {
  390 |     // Придатні = ті, що взагалі можна показати: з містом і адресою.
  391 |     // Записи MCP без міста застосунок свідомо ховає, тож еталон рахуємо так само.
  392 |     const all = await apiStores('perPage=100&page=1');
  393 |     const usable: number = await (async () => {
  394 |       let count = 0;
  395 |       const pages = Math.ceil(all.total / 100);
  396 |       for (let p = 1; p <= pages; p += 1) {
  397 |         const chunk = await apiStores(`perPage=100&page=${p}`);
  398 |         count += chunk.items.filter((i) => i.city?.trim() && i.address?.trim()).length;
  399 |       }
  400 |       return count;
  401 |     })();
  402 |     const total = usable;
  403 | 
  404 |     const id = await createSupplier(page, {
  405 |       name: testSupplierName('повнота'),
  406 |       edrpou: nextTestEdrpou(),
  407 |       contact: 'UITEST',
  408 |       phone: '+380501117711',
  409 |       email: 'uitest.full@rampa.test',
  410 |     });
  411 | 
  412 |     await goto(page, `/suppliers/${id}`);
  413 |     await openTab(page, 'Магазини');
  414 |     await page.locator('#access-mode').selectOption('whitelist');
  415 | 
  416 |     const options = await multiSelectOptions(page, 'Магазини');
  417 |     expect(
  418 |       options.length,
  419 |       `у виборі філій видно ${options.length} варіантів, а придатних філій у мережі ${total}: ` +
  420 |         'решту неможливо ані побачити, ані обрати без пошуку',
> 421 |     ).toBeGreaterThanOrEqual(total);
      |       ^ Error: у виборі філій видно 200 варіантів, а придатних філій у мережі 447: решту неможливо ані побачити, ані обрати без пошуку
  422 |   });
  423 | 
  424 |   test('A-10.17 X-02 пошук «Київ» у виборі магазинів знаходить усі київські філії', async ({
  425 |     page,
  426 |   }) => {
  427 |     const kyiv = await apiStores(`city=${encodeURIComponent('Київ')}&perPage=20`);
  428 |     const id = await createSupplier(page, {
  429 |       name: testSupplierName('пошук-київ'),
  430 |       edrpou: nextTestEdrpou(),
  431 |       contact: 'UITEST',
  432 |       phone: '+380501118811',
  433 |       email: 'uitest.kyiv@rampa.test',
  434 |     });
  435 | 
  436 |     await goto(page, `/suppliers/${id}`);
  437 |     await openTab(page, 'Магазини');
  438 |     await page.locator('#access-mode').selectOption('whitelist');
  439 | 
  440 |     const found = await multiSelectSearch(page, 'Магазини', 'Київ');
  441 |     expect(
  442 |       found.length,
  443 |       `пошук «Київ» показав ${found.length} філій, а в базі їх ${kyiv.total}`,
  444 |     ).toBeGreaterThanOrEqual(kyiv.total);
  445 |   });
  446 | 
  447 |   test('A-10.18 у виборі магазинів немає непридатних записів без міста й адреси', async ({
  448 |     page,
  449 |   }) => {
  450 |     const id = await createSupplier(page, {
  451 |       name: testSupplierName('сміття'),
  452 |       edrpou: nextTestEdrpou(),
  453 |       contact: 'UITEST',
  454 |       phone: '+380501119911',
  455 |       email: 'uitest.junk@rampa.test',
  456 |     });
  457 | 
  458 |     await goto(page, `/suppliers/${id}`);
  459 |     await openTab(page, 'Магазини');
  460 |     await page.locator('#access-mode').selectOption('whitelist');
  461 | 
  462 |     const options = await multiSelectOptions(page, 'Магазини');
  463 |     const junk = options.filter((o) => /—\s*,\s*$/.test(o.trim()) || /—\s*,$/.test(o.trim()));
  464 |     expect(
  465 |       junk,
  466 |       `у виборі є ${junk.length} записів без міста й адреси: ${junk.slice(0, 5).join(' | ')}`,
  467 |     ).toEqual([]);
  468 |   });
  469 | 
  470 |   test('A-10.19 форма створення дозволяє одразу задати доступ до магазинів', async ({ page }) => {
  471 |     await goto(page, '/suppliers/new');
  472 |     const text = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
  473 |     expect(
  474 |       text.includes('Доступ до магазинів') || text.includes('Усі магазини'),
  475 |       'режим доступу до магазинів — частина заведення контрагента, ' +
  476 |         'інакше новий постачальник до першого редагування має доступ за замовчуванням',
  477 |     ).toBe(true);
  478 |   });
  479 | 
  480 |   test('A-10.20 видалення щойно створеного постачальника без бронювань', async ({ page }) => {
  481 |     const name = testSupplierName('видалення');
  482 |     const id = await createSupplier(page, {
  483 |       name,
  484 |       edrpou: nextTestEdrpou(),
  485 |       contact: 'UITEST',
  486 |       phone: '+380501110011',
  487 |       email: 'uitest.del@rampa.test',
  488 |     });
  489 | 
  490 |     await goto(page, `/suppliers/${id}`);
  491 |     await page.locator('button.btn-danger', { hasText: 'Видалити' }).click();
  492 |     const toast = await waitForToast(page).catch(() => '');
  493 | 
  494 |     const res = await apiRaw('get', `/suppliers/${id}`);
  495 |     expect(
  496 |       res.status,
  497 |       'постачальника створено щойно, бронювань у нього бути не може, ' +
  498 |         `тож видалення має спрацювати; повідомлення на екрані: «${toast}»`,
  499 |     ).toBe(404);
  500 |   });
  501 | 
  502 |   test('A-10.21 видалення постачальника з бронюваннями відхиляється', async () => {
  503 |     // Бронювання створюються лише в кабінеті постачальника (/api/supplier/v1),
  504 |     // тож із адмін-панелі підготувати умову «є активні бронювання» неможливо.
  505 |     // Видаляти демо-постачальника наосліп не можна: якщо бронювань немає,
  506 |     // перевірка знищить дані стенду. Сценарій лишається непокритим свідомо.
  507 |     test.skip(
  508 |       true,
  509 |       'потрібне бронювання, яке створюється поза адмін-панеллю (кабінет постачальника, S-06)',
  510 |     );
  511 |   });
  512 | });
  513 | 
```