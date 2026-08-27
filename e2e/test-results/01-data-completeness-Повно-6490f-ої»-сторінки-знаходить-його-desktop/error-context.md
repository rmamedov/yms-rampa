# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: 01-data-completeness.spec.ts >> Повнота даних >> X-02 адмінка: пошук магазину з «дальньої» сторінки знаходить його
- Location: tests/01-data-completeness.spec.ts:105:7

# Error details

```
Error: пошук за externalId 4400 має знайти філію

expect(received).toContain(expected) // indexOf

Expected substring: "4400"
Received string:    "YMS «Рампа» Магазини Постачальники Синхронізація MCP Аналітика Адміністратор мережі Супер-адміністратор Вийти Магазини Пошук Місто — ▾ Статус YMS — ▾ Налаштованість Будь-який Налаштовано Не налаштовано Застосувати КОД ФІЛІЇ НАЗВА ДЛЯ ВІДОБРАЖЕННЯ МІСТО ↑ АДРЕСА СТАТУС YMS НАЛАШТОВАНО РАМП МАКС. ТОННАЖ, Т ОСТАННЯ СИНХРОНІЗАЦІЯ 2505 Не налаштовано Ні 0 — 27.08.2026, 18:45 3097 вул. Яворівська, 30 вул. Яворівська, 30 Не налаштовано Ні 0 — 27.08.2026, 18:45 3656 Не налаштовано Ні 0 — 27.08.2026, 18:45 delete_filia_silpo_ferma_2286 Не налаштовано Ні 0 — 27.08.2026, 18:45 delete_filia_silpo_ferma_2287 Не налаштовано Ні 0 — 27.08.2026, 18:45 delete_filia_silpo_ivasuka46 Не налаштовано Ні 0 — 27.08.2026, 18:45 delete_filia_silpo_nerejanskaya22 Не налаштовано Ні 0 — 27.08.2026, 18:45 delete_filia_silpo_stalingrad46 Не налаштовано Ні 0 — 27.08.2026, 18:45 2116 вул. Мазепи, 168А Івано-Франківськ вул. Мазепи, 168А Не налаштовано Ні 0 — 27.08.2026, 18:45 2117 вул. Дністровська, 3 Івано-Франківськ вул. Дністровська, 3 Не налаштовано Ні 0 — 27.08.2026, 18:45 2118 бульв. Північний, 2А Івано-Франківськ бульв. Північний, 2А Не налаштовано Ні 0 — 27.08.2026, 18:45 3976 вул. Мазепи, 168А Івано-Франківськ вул. Мазепи, 168А Не налаштовано Ні 0 — 27.08.2026, 18:45 2966 вул. Літературна, 27 Ірпінь вул. Літературна, 27 Не налаштовано Ні 0 — 27.08.2026, 18:45 3259 вул. Сковороди, 8 Ірпінь вул. Сковороди, 8 Не налаштовано Ні 0 — 27.08.2026, 18:45 3891 вул. Соборна, 160 Ірпінь вул. Соборна, 160 Не налаштовано Ні 0 — 27.08.2026, 18:45 3905 вул. Соборна, 160 Ірпінь вул. Соборна, 160 Не налаштовано Ні 0 — 27.08.2026, 18:45 1997 вул. Київський Шлях, 76 Бориспіль вул. Київський Шлях, 76 Не налаштовано Ні 0 — 27.08.2026, 18:45 3190 вул. Київський Шлях, 67 Бориспіль вул. Київський Шлях, 67 Не налаштовано Ні 0 — 27.08.2026, 18:45 3436 вул. Київський Шлях, 6 Бориспіль вул. Київський Шлях, 6 Не налаштовано Ні 0 — 27.08.2026, 18:45 4204 вул. Київський шлях, 6 Бориспіль вул. Київський шлях, 6 Не налаштовано Ні 0 — 27.08.2026, 18:45 Усього: 455 Рядків на сторінці 20 50 100 ‹ Сторінка 1 з 23 ›"
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
      - link "Синхронізація MCP" [ref=f1e10] [cursor=pointer]:
        - /url: /mcp-sync
      - link "Аналітика" [ref=f1e11] [cursor=pointer]:
        - /url: /analytics
    - generic [ref=f1e12]:
      - generic [ref=f1e13]: Адміністратор мережі
      - generic [ref=f1e14]: Супер-адміністратор
      - button "Вийти" [ref=f1e15] [cursor=pointer]
  - main [ref=f1e16]:
    - generic [ref=f1e17]:
      - heading "Магазини" [level=1] [ref=f1e19]
      - generic [ref=f1e20]:
        - generic [ref=f1e21]:
          - generic [ref=f1e22]: Пошук
          - searchbox "Пошук" [active] [ref=f1e23]: "4400"
        - generic [ref=f1e25]:
          - generic [ref=f1e26]: Місто
          - button "— ▾" [ref=f1e27] [cursor=pointer]:
            - generic [ref=f1e28]: —
            - generic [ref=f1e29]: ▾
        - generic [ref=f1e31]:
          - generic [ref=f1e32]: Статус YMS
          - button "— ▾" [ref=f1e33] [cursor=pointer]:
            - generic [ref=f1e34]: —
            - generic [ref=f1e35]: ▾
        - generic [ref=f1e36]:
          - generic [ref=f1e37]: Налаштованість
          - combobox "Налаштованість" [ref=f1e38]:
            - option "Будь-який" [selected]
            - option "Налаштовано"
            - option "Не налаштовано"
        - button "Застосувати" [ref=f1e39] [cursor=pointer]
      - generic [ref=f1e40]:
        - table [ref=f1e41]:
          - rowgroup [ref=f1e42]:
            - row [ref=f1e43]:
              - columnheader [ref=f1e44]:
                - checkbox "select-all" [ref=f1e45]
              - columnheader "Код філії" [ref=f1e46] [cursor=pointer]
              - columnheader "Назва для відображення" [ref=f1e47]
              - columnheader "Місто ↑" [ref=f1e48] [cursor=pointer]
              - columnheader "Адреса" [ref=f1e49] [cursor=pointer]
              - columnheader "Статус YMS" [ref=f1e50] [cursor=pointer]
              - columnheader "Налаштовано" [ref=f1e51]
              - columnheader "Рамп" [ref=f1e52]
              - columnheader "Макс. тоннаж, т" [ref=f1e53]
              - columnheader "Остання синхронізація" [ref=f1e54] [cursor=pointer]
          - rowgroup [ref=f1e55]:
            - row [ref=f1e56]:
              - cell [ref=f1e57]:
                - checkbox "2505" [ref=f1e58]
              - cell [ref=f1e59]:
                - link "2505" [ref=f1e60] [cursor=pointer]:
                  - /url: /stores/1edb7353-c9ea-6382-b36b-11a6c487168c
              - cell [ref=f1e61]
              - cell [ref=f1e62]
              - cell [ref=f1e63]
              - cell "Не налаштовано" [ref=f1e64]
              - cell "Ні" [ref=f1e66]
              - cell "0" [ref=f1e68]
              - cell "—" [ref=f1e69]
              - cell "27.08.2026, 18:45" [ref=f1e70]
            - row [ref=f1e71]:
              - cell [ref=f1e72]:
                - checkbox "3097" [ref=f1e73]
              - cell [ref=f1e74]:
                - link "3097" [ref=f1e75] [cursor=pointer]:
                  - /url: /stores/1edb7335-9721-69c8-8769-11a6c487168c
              - cell "вул. Яворівська, 30" [ref=f1e76]
              - cell [ref=f1e77]
              - cell "вул. Яворівська, 30" [ref=f1e78]
              - cell "Не налаштовано" [ref=f1e79]
              - cell "Ні" [ref=f1e81]
              - cell "0" [ref=f1e83]
              - cell "—" [ref=f1e84]
              - cell "27.08.2026, 18:45" [ref=f1e85]
            - row [ref=f1e86]:
              - cell [ref=f1e87]:
                - checkbox "3656" [ref=f1e88]
              - cell [ref=f1e89]:
                - link "3656" [ref=f1e90] [cursor=pointer]:
                  - /url: /stores/1eecbd44-a3ed-65fc-9ac4-c39702503ccc
              - cell [ref=f1e91]
              - cell [ref=f1e92]
              - cell [ref=f1e93]
              - cell "Не налаштовано" [ref=f1e94]
              - cell "Ні" [ref=f1e96]
              - cell "0" [ref=f1e98]
              - cell "—" [ref=f1e99]
              - cell "27.08.2026, 18:45" [ref=f1e100]
            - row [ref=f1e101]:
              - cell [ref=f1e102]:
                - checkbox "delete_filia_silpo_ferma_2286" [ref=f1e103]
              - cell [ref=f1e104]:
                - link "delete_filia_silpo_ferma_2286" [ref=f1e105] [cursor=pointer]:
                  - /url: /stores/1edb735e-e4f1-6936-ba95-a143e3aed11b
              - cell [ref=f1e106]
              - cell [ref=f1e107]
              - cell [ref=f1e108]
              - cell "Не налаштовано" [ref=f1e109]
              - cell "Ні" [ref=f1e111]
              - cell "0" [ref=f1e113]
              - cell "—" [ref=f1e114]
              - cell "27.08.2026, 18:45" [ref=f1e115]
            - row [ref=f1e116]:
              - cell [ref=f1e117]:
                - checkbox "delete_filia_silpo_ferma_2287" [ref=f1e118]
              - cell [ref=f1e119]:
                - link "delete_filia_silpo_ferma_2287" [ref=f1e120] [cursor=pointer]:
                  - /url: /stores/1edb735e-7a82-64e2-ac3c-f77053673ad9
              - cell [ref=f1e121]
              - cell [ref=f1e122]
              - cell [ref=f1e123]
              - cell "Не налаштовано" [ref=f1e124]
              - cell "Ні" [ref=f1e126]
              - cell "0" [ref=f1e128]
              - cell "—" [ref=f1e129]
              - cell "27.08.2026, 18:45" [ref=f1e130]
            - row [ref=f1e131]:
              - cell [ref=f1e132]:
                - checkbox "delete_filia_silpo_ivasuka46" [ref=f1e133]
              - cell [ref=f1e134]:
                - link "delete_filia_silpo_ivasuka46" [ref=f1e135] [cursor=pointer]:
                  - /url: /stores/1edb2828-37e2-6690-af0a-5f4f054120bc
              - cell [ref=f1e136]
              - cell [ref=f1e137]
              - cell [ref=f1e138]
              - cell "Не налаштовано" [ref=f1e139]
              - cell "Ні" [ref=f1e141]
              - cell "0" [ref=f1e143]
              - cell "—" [ref=f1e144]
              - cell "27.08.2026, 18:45" [ref=f1e145]
            - row [ref=f1e146]:
              - cell [ref=f1e147]:
                - checkbox "delete_filia_silpo_nerejanskaya22" [ref=f1e148]
              - cell [ref=f1e149]:
                - link "delete_filia_silpo_nerejanskaya22" [ref=f1e150] [cursor=pointer]:
                  - /url: /stores/1edb6b29-08c1-667e-bcb8-d9341fb2cc7b
              - cell [ref=f1e151]
              - cell [ref=f1e152]
              - cell [ref=f1e153]
              - cell "Не налаштовано" [ref=f1e154]
              - cell "Ні" [ref=f1e156]
              - cell "0" [ref=f1e158]
              - cell "—" [ref=f1e159]
              - cell "27.08.2026, 18:45" [ref=f1e160]
            - row [ref=f1e161]:
              - cell [ref=f1e162]:
                - checkbox "delete_filia_silpo_stalingrad46" [ref=f1e163]
              - cell [ref=f1e164]:
                - link "delete_filia_silpo_stalingrad46" [ref=f1e165] [cursor=pointer]:
                  - /url: /stores/1edb6b1a-c102-6eae-9f91-0f4ab5c79679
              - cell [ref=f1e166]
              - cell [ref=f1e167]
              - cell [ref=f1e168]
              - cell "Не налаштовано" [ref=f1e169]
              - cell "Ні" [ref=f1e171]
              - cell "0" [ref=f1e173]
              - cell "—" [ref=f1e174]
              - cell "27.08.2026, 18:45" [ref=f1e175]
            - row [ref=f1e176]:
              - cell [ref=f1e177]:
                - checkbox "2116" [ref=f1e178]
              - cell [ref=f1e179]:
                - link "2116" [ref=f1e180] [cursor=pointer]:
                  - /url: /stores/1edb6b5a-55fb-6864-9a0f-d54e0a9fe643
              - cell "вул. Мазепи, 168А" [ref=f1e181]
              - cell "Івано-Франківськ" [ref=f1e182]
              - cell "вул. Мазепи, 168А" [ref=f1e183]
              - cell "Не налаштовано" [ref=f1e184]
              - cell "Ні" [ref=f1e186]
              - cell "0" [ref=f1e188]
              - cell "—" [ref=f1e189]
              - cell "27.08.2026, 18:45" [ref=f1e190]
            - row [ref=f1e191]:
              - cell [ref=f1e192]:
                - checkbox "2117" [ref=f1e193]
              - cell [ref=f1e194]:
                - link "2117" [ref=f1e195] [cursor=pointer]:
                  - /url: /stores/1edb6b5a-b1b0-611e-a929-d11f2666a570
              - cell "вул. Дністровська, 3" [ref=f1e196]
              - cell "Івано-Франківськ" [ref=f1e197]
              - cell "вул. Дністровська, 3" [ref=f1e198]
              - cell "Не налаштовано" [ref=f1e199]
              - cell "Ні" [ref=f1e201]
              - cell "0" [ref=f1e203]
              - cell "—" [ref=f1e204]
              - cell "27.08.2026, 18:45" [ref=f1e205]
            - row [ref=f1e206]:
              - cell [ref=f1e207]:
                - checkbox "2118" [ref=f1e208]
              - cell [ref=f1e209]:
                - link "2118" [ref=f1e210] [cursor=pointer]:
                  - /url: /stores/1edb6b5b-1b9a-6ce6-bb85-639d81d4aac4
              - cell "бульв. Північний, 2А" [ref=f1e211]
              - cell "Івано-Франківськ" [ref=f1e212]
              - cell "бульв. Північний, 2А" [ref=f1e213]
              - cell "Не налаштовано" [ref=f1e214]
              - cell "Ні" [ref=f1e216]
              - cell "0" [ref=f1e218]
              - cell "—" [ref=f1e219]
              - cell "27.08.2026, 18:45" [ref=f1e220]
            - row [ref=f1e221]:
              - cell [ref=f1e222]:
                - checkbox "3976" [ref=f1e223]
              - cell [ref=f1e224]:
                - link "3976" [ref=f1e225] [cursor=pointer]:
                  - /url: /stores/1ef9b801-5831-6216-99a5-fd246a208e47
              - cell "вул. Мазепи, 168А" [ref=f1e226]
              - cell "Івано-Франківськ" [ref=f1e227]
              - cell "вул. Мазепи, 168А" [ref=f1e228]
              - cell "Не налаштовано" [ref=f1e229]
              - cell "Ні" [ref=f1e231]
              - cell "0" [ref=f1e233]
              - cell "—" [ref=f1e234]
              - cell "27.08.2026, 18:45" [ref=f1e235]
            - row [ref=f1e236]:
              - cell [ref=f1e237]:
                - checkbox "2966" [ref=f1e238]
              - cell [ref=f1e239]:
                - link "2966" [ref=f1e240] [cursor=pointer]:
                  - /url: /stores/1edb733d-b42b-64ee-b5d5-73524574f50b
              - cell "вул. Літературна, 27" [ref=f1e241]
              - cell "Ірпінь" [ref=f1e242]
              - cell "вул. Літературна, 27" [ref=f1e243]
              - cell "Не налаштовано" [ref=f1e244]
              - cell "Ні" [ref=f1e246]
              - cell "0" [ref=f1e248]
              - cell "—" [ref=f1e249]
              - cell "27.08.2026, 18:45" [ref=f1e250]
            - row [ref=f1e251]:
              - cell [ref=f1e252]:
                - checkbox "3259" [ref=f1e253]
              - cell [ref=f1e254]:
                - link "3259" [ref=f1e255] [cursor=pointer]:
                  - /url: /stores/1f0dcc8f-0f9f-6a6e-a05e-c38d9b34c11f
              - cell "вул. Сковороди, 8" [ref=f1e256]
              - cell "Ірпінь" [ref=f1e257]
              - cell "вул. Сковороди, 8" [ref=f1e258]
              - cell "Не налаштовано" [ref=f1e259]
              - cell "Ні" [ref=f1e261]
              - cell "0" [ref=f1e263]
              - cell "—" [ref=f1e264]
              - cell "27.08.2026, 18:45" [ref=f1e265]
            - row [ref=f1e266]:
              - cell [ref=f1e267]:
                - checkbox "3891" [ref=f1e268]
              - cell [ref=f1e269]:
                - link "3891" [ref=f1e270] [cursor=pointer]:
                  - /url: /stores/1efb6e1e-aa8f-604c-988a-27f1dec9eef8
              - cell "вул. Соборна, 160" [ref=f1e271]
              - cell "Ірпінь" [ref=f1e272]
              - cell "вул. Соборна, 160" [ref=f1e273]
              - cell "Не налаштовано" [ref=f1e274]
              - cell "Ні" [ref=f1e276]
              - cell "0" [ref=f1e278]
              - cell "—" [ref=f1e279]
              - cell "27.08.2026, 18:45" [ref=f1e280]
            - row [ref=f1e281]:
              - cell [ref=f1e282]:
                - checkbox "3905" [ref=f1e283]
              - cell [ref=f1e284]:
                - link "3905" [ref=f1e285] [cursor=pointer]:
                  - /url: /stores/1efb6e15-b171-6ba2-ba6f-e90a6eb5ec15
              - cell "вул. Соборна, 160" [ref=f1e286]
              - cell "Ірпінь" [ref=f1e287]
              - cell "вул. Соборна, 160" [ref=f1e288]
              - cell "Не налаштовано" [ref=f1e289]
              - cell "Ні" [ref=f1e291]
              - cell "0" [ref=f1e293]
              - cell "—" [ref=f1e294]
              - cell "27.08.2026, 18:45" [ref=f1e295]
            - row [ref=f1e296]:
              - cell [ref=f1e297]:
                - checkbox "1997" [ref=f1e298]
              - cell [ref=f1e299]:
                - link "1997" [ref=f1e300] [cursor=pointer]:
                  - /url: /stores/1edb6b1a-626d-64ca-9046-0b7012e7f9f8
              - cell "вул. Київський Шлях, 76" [ref=f1e301]
              - cell "Бориспіль" [ref=f1e302]
              - cell "вул. Київський Шлях, 76" [ref=f1e303]
              - cell "Не налаштовано" [ref=f1e304]
              - cell "Ні" [ref=f1e306]
              - cell "0" [ref=f1e308]
              - cell "—" [ref=f1e309]
              - cell "27.08.2026, 18:45" [ref=f1e310]
            - row [ref=f1e311]:
              - cell [ref=f1e312]:
                - checkbox "3190" [ref=f1e313]
              - cell [ref=f1e314]:
                - link "3190" [ref=f1e315] [cursor=pointer]:
                  - /url: /stores/1edb7319-118e-6778-8404-6fea04bfe766
              - cell "вул. Київський Шлях, 67" [ref=f1e316]
              - cell "Бориспіль" [ref=f1e317]
              - cell "вул. Київський Шлях, 67" [ref=f1e318]
              - cell "Не налаштовано" [ref=f1e319]
              - cell "Ні" [ref=f1e321]
              - cell "0" [ref=f1e323]
              - cell "—" [ref=f1e324]
              - cell "27.08.2026, 18:45" [ref=f1e325]
            - row [ref=f1e326]:
              - cell [ref=f1e327]:
                - checkbox "3436" [ref=f1e328]
              - cell [ref=f1e329]:
                - link "3436" [ref=f1e330] [cursor=pointer]:
                  - /url: /stores/1edf3108-fb26-610a-8e0a-d980ecba6063
              - cell "вул. Київський Шлях, 6" [ref=f1e331]
              - cell "Бориспіль" [ref=f1e332]
              - cell "вул. Київський Шлях, 6" [ref=f1e333]
              - cell "Не налаштовано" [ref=f1e334]
              - cell "Ні" [ref=f1e336]
              - cell "0" [ref=f1e338]
              - cell "—" [ref=f1e339]
              - cell "27.08.2026, 18:45" [ref=f1e340]
            - row [ref=f1e341]:
              - cell [ref=f1e342]:
                - checkbox "4204" [ref=f1e343]
              - cell [ref=f1e344]:
                - link "4204" [ref=f1e345] [cursor=pointer]:
                  - /url: /stores/1f071d01-27ef-61e4-bd93-f981fceb620c
              - cell "вул. Київський шлях, 6" [ref=f1e346]
              - cell "Бориспіль" [ref=f1e347]
              - cell "вул. Київський шлях, 6" [ref=f1e348]
              - cell "Не налаштовано" [ref=f1e349]
              - cell "Ні" [ref=f1e351]
              - cell "0" [ref=f1e353]
              - cell "—" [ref=f1e354]
              - cell "27.08.2026, 18:45" [ref=f1e355]
        - generic [ref=f1e357]:
          - generic [ref=f1e358]: "Усього: 455"
          - generic [ref=f1e359]:
            - text: Рядків на сторінці
            - combobox "page-size" [ref=f1e360]:
              - option "20" [selected]
              - option "50"
              - option "100"
          - button "‹" [disabled] [ref=f1e361]
          - generic [ref=f1e362]: Сторінка 1 з 23
          - button "›" [ref=f1e363] [cursor=pointer]
```

# Test source

```ts
  26  |   });
  27  | 
  28  |   test('X-01/X-02 адмінка: вибір філій постачальника бачить УСІ київські філії', async ({ page }) => {
  29  |     const ctx = await api();
  30  |     const token = await adminToken(ctx);
  31  | 
  32  |     // Скільки київських філій насправді.
  33  |     const res = await ctx.get(`${HOSTS.admin}/api/admin/v1/stores?city=${encodeURIComponent('Київ')}&perPage=100`, {
  34  |       headers: { Authorization: `Bearer ${token}` },
  35  |     });
  36  |     const body = await res.json();
  37  |     const kyivTotal = body.total as number;
  38  |     expect(kyivTotal, 'у Києві має бути більше 20 філій, інакше тест безсилий').toBeGreaterThan(20);
  39  | 
  40  |     await loginUi(page, HOSTS.admin, { 'input[type=email]': CREDS.admin.email, 'input[type=password]': CREDS.admin.password });
  41  |     await page.goto(HOSTS.admin + '/suppliers/new');
  42  |     await page.waitForLoadState('networkidle');
  43  | 
  44  |     // Скільки варіантів філій узагалі завантажив застосунок.
  45  |     const loaded = await page.evaluate(() => {
  46  |       const w = window as unknown as { __storeOptionsCount?: number };
  47  |       return w.__storeOptionsCount ?? null;
  48  |     });
  49  | 
  50  |     // Головна перевірка — через пошук: набираємо «Київ» і рахуємо знайдене.
  51  |     const searchBox = page.locator('input[type=search], input[placeholder*="ошук"], input[placeholder*="ілі"]').first();
  52  |     const hasSearch = await searchBox.count();
  53  |     expect(hasSearch, 'у виборі філій має бути пошук').toBeGreaterThan(0);
  54  | 
  55  |     await searchBox.fill('Київ');
  56  |     await page.waitForTimeout(1200);
  57  | 
  58  |     const optionsShown = await page.evaluate(() => {
  59  |       const nodes = document.querySelectorAll('[role=option], .option, li, label');
  60  |       return [...nodes].map((n) => (n as HTMLElement).innerText || '').filter((t) => t.includes('Київ')).length;
  61  |     });
  62  | 
  63  |     expect(
  64  |       optionsShown,
  65  |       `пошук «Київ» показав ${optionsShown} філій, а в базі їх ${kyivTotal}` +
  66  |         (loaded ? ` (застосунок завантажив лише ${loaded})` : ''),
  67  |     ).toBeGreaterThanOrEqual(kyivTotal);
  68  |   });
  69  | 
  70  |   test('X-01 кабінет постачальника: список міст повний', async ({ page }) => {
  71  |     const ctx = await api();
  72  |     const token = await supplierToken(ctx);
  73  |     const res = await ctx.get(`${HOSTS.supplier}/api/supplier/v1/cities`, {
  74  |       headers: { Authorization: `Bearer ${token}` },
  75  |     });
  76  |     const cities = (await res.json()).items as { city: string; storeCount: number }[];
  77  | 
  78  |     await loginUi(page, HOSTS.supplier, { 'input[type=email]': CREDS.supplier.login, 'input[type=password]': CREDS.supplier.password });
  79  |     await page.goto(HOSTS.supplier + '/booking/cities');
  80  |     await page.waitForLoadState('networkidle');
  81  | 
  82  |     const text = await pageText(page);
  83  |     for (const c of cities) {
  84  |       expect(text, `місто ${c.city} має бути в списку`).toContain(c.city);
  85  |     }
  86  |   });
  87  | 
  88  |   test('X-01 кабінет постачальника: у місті видно всі активні філії', async ({ page }) => {
  89  |     const ctx = await api();
  90  |     const token = await supplierToken(ctx);
  91  |     const res = await ctx.get(`${HOSTS.supplier}/api/supplier/v1/stores?city=${encodeURIComponent('Київ')}`, {
  92  |       headers: { Authorization: `Bearer ${token}` },
  93  |     });
  94  |     const stores = (await res.json()).items as { externalId: string }[];
  95  | 
  96  |     await loginUi(page, HOSTS.supplier, { 'input[type=email]': CREDS.supplier.login, 'input[type=password]': CREDS.supplier.password });
  97  |     await page.goto(HOSTS.supplier + '/booking/cities/' + encodeURIComponent('Київ'));
  98  |     await page.waitForLoadState('networkidle');
  99  | 
  100 |     const text = await pageText(page);
  101 |     const missing = stores.filter((s) => !text.includes(s.externalId)).map((s) => s.externalId);
  102 |     expect(missing, `філії, яких немає на екрані: ${missing.join(', ')}`).toHaveLength(0);
  103 |   });
  104 | 
  105 |   test('X-02 адмінка: пошук магазину з «дальньої» сторінки знаходить його', async ({ page }) => {
  106 |     const ctx = await api();
  107 |     const token = await adminToken(ctx);
  108 | 
  109 |     // Беремо філію з кінця повного списку — вона свідомо не на першій сторінці.
  110 |     const res = await ctx.get(`${HOSTS.admin}/api/admin/v1/stores?perPage=100&page=4`, {
  111 |       headers: { Authorization: `Bearer ${token}` },
  112 |     });
  113 |     const items = (await res.json()).items as { externalId: string; address: string }[];
  114 |     const target = items.find((i) => i.address && i.externalId);
  115 |     test.skip(!target, 'немає даних для перевірки');
  116 | 
  117 |     await loginUi(page, HOSTS.admin, { 'input[type=email]': CREDS.admin.email, 'input[type=password]': CREDS.admin.password });
  118 |     await page.goto(HOSTS.admin + '/stores');
  119 |     await page.waitForLoadState('networkidle');
  120 | 
  121 |     const search = page.locator('input[type=search], input[placeholder*="ошук"]').first();
  122 |     await search.fill(target!.externalId);
  123 |     await page.waitForTimeout(1500);
  124 | 
  125 |     const text = await pageText(page);
> 126 |     expect(text, `пошук за externalId ${target!.externalId} має знайти філію`).toContain(target!.externalId);
      |                                                                                ^ Error: пошук за externalId 4400 має знайти філію
  127 |   });
  128 | });
  129 | 
```