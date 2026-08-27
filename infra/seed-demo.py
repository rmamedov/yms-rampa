#!/usr/bin/env python3
"""Демонстраційне наповнення YMS «Рампа» на розгорнутому стенді.

Налаштовує і активує вибрані філії Сільпо через адмінське API — саме тим
шляхом, яким це робив би менеджер мережі, а не записом напряму в базу.
Так заразом перевіряється, що ланцюжок «токен → права → валідація →
збереження» справді працює.
"""
import datetime
import json
import sys
import urllib.error
import urllib.request

BASE = "https://admin.104.248.132.130.sslip.io"
ADMIN_EMAIL = "admin@rampa.ua"
ADMIN_PASSWORD = "${YMS_ADMIN_PASSWORD}"

# Скільки філій налаштувати у кожному з міст.
CITIES = {"Київ": 12, "Львів": 5, "Одеса": 5, "Дніпро": 4, "Харків": 4}


def call(method, path, token=None, body=None):
    req = urllib.request.Request(BASE + path, method=method)
    req.add_header("Content-Type", "application/json")
    if token:
        req.add_header("Authorization", "Bearer " + token)
    data = json.dumps(body).encode() if body is not None else None
    try:
        with urllib.request.urlopen(req, data, timeout=30) as resp:
            raw = resp.read().decode()
            return resp.status, (json.loads(raw) if raw else {})
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw)
        except json.JSONDecodeError:
            return e.code, {"raw": raw[:300]}


def configuration_payload(index):
    """Різні магазини — різні налаштування, щоб стенд не виглядав однаково."""
    profiles = [
        {"slotSizeMinutes": 30, "ramps": 2, "tons": 20.0, "from": "08:00", "to": "14:00"},
        {"slotSizeMinutes": 60, "ramps": 1, "tons": 10.0, "from": "07:00", "to": "13:00"},
        {"slotSizeMinutes": 20, "ramps": 3, "tons": 40.0, "from": "06:00", "to": "16:00"},
        {"slotSizeMinutes": 15, "ramps": 2, "tons": 7.5, "from": "09:00", "to": "12:00"},
    ]
    p = profiles[index % len(profiles)]
    return {
        # effectiveFrom навмисно не задаємо: для першої конфігурації магазину
        # сервіс сам виставляє «сьогодні», для наступних — «завтра» (STC-60).
        "receivingWindows": [
            {"dayOfWeek": d, "intervals": [{"from": p["from"], "to": p["to"]}]}
            for d in range(1, 7)  # пн–сб, неділя без прийому
        ],
        "slotSizeMinutes": p["slotSizeMinutes"],
        "ramps": [
            {"rampId": f"ramp-{i}", "number": i, "name": f"Рампа {i}", "active": True}
            for i in range(1, p["ramps"] + 1)
        ],
        "maxVehicleWeightTons": p["tons"],
        "leadTimeMinutes": 60,
        "bookingHorizonDays": 14,
        "noShowGraceMinutes": 30,
        "holdMaxMinutes": 15,
        "calendarExceptions": [],
    }


def main():
    status, res = call("POST", "/api/admin/v1/auth/login",
                       body={"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD})
    if status != 200:
        print("Не вдалося увійти:", status, res)
        return 1
    token = res["accessToken"]
    print("Вхід виконано, роль:", res.get("user", {}).get("role", "?"))

    configured = failed = 0
    for city, limit in CITIES.items():
        status, res = call("GET", f"/api/admin/v1/stores?city={urllib.parse.quote(city)}&perPage=100", token)
        if status != 200:
            print(f"  {city}: не вдалося отримати список ({status})")
            continue

        picked = [s for s in res.get("items", []) if s.get("city") == city][:limit]
        print(f"\n{city}: беру {len(picked)} філій")

        for i, store in enumerate(picked):
            sid = store["branchId"]
            label = f'{store.get("address", "")[:40]} (№{store.get("externalId")})'

            st, out = call("POST", f"/api/admin/v1/stores/{sid}/configurations",
                           token, configuration_payload(i))
            if st not in (200, 201):
                print(f"  ✗ {label}: конфігурація {st} {str(out.get('detail'))[:80]}")
                failed += 1
                continue

            st, out = call("PATCH", f"/api/admin/v1/stores/{sid}", token,
                           {"ymsStatus": "active", "visibleToSuppliers": True,
                            "displayName": f'Сільпо, {store.get("address", "")}'})
            if st not in (200, 204):
                print(f"  ✗ {label}: активація {st} {str(out.get('detail'))[:80]}")
                failed += 1
                continue

            configured += 1
            print(f"  ✓ {label}")

    print(f"\nНалаштовано і активовано: {configured}, помилок: {failed}")

    status, res = call("GET", "/api/supplier/v1/cities")
    print("Постачальник бачить міст:", len(res.get("items", [])) if status == 200 else f"помилка {status}")
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    import urllib.parse
    sys.exit(main())
