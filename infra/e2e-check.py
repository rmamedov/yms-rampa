#!/usr/bin/env python3
"""Наскрізна перевірка розгорнутого стенду YMS «Рампа».

Проходить шлях реального користувача через публічний HTTPS-API:
вхід постачальника → каталог → сітка слотів → холд → бронювання →
маршрутний лист → дії магазину (прибув, розвантаження, розвантажено).
Заразом перевіряє захист: доступ без токена і з підробленими заголовками.
"""
import json
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, datetime, timedelta

IP = "104.248.132.130"
SUPPLIER = f"https://yms.{IP}.sslip.io"
DRIVER = f"https://driver.{IP}.sslip.io"
STORE = f"https://store.{IP}.sslip.io"
ADMIN = f"https://admin.{IP}.sslip.io"

SUPPLIER_LOGIN = {"login": "supplier@rampa.ua", "password": "${YMS_SUPPLIER_PASSWORD}"}
STAFF_LOGIN = {"email": "admin@rampa.ua", "password": "${YMS_ADMIN_PASSWORD}"}

passed, failed = [], []

with open("/tmp/driver.json") as f:
    DRIVER_PROFILE = json.load(f)


def call(method, base, path, token=None, body=None, headers=None):
    req = urllib.request.Request(base + path, method=method)
    req.add_header("Content-Type", "application/json")
    if token:
        req.add_header("Authorization", "Bearer " + token)
    for k, v in (headers or {}).items():
        req.add_header(k, v)
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
            return e.code, {"raw": raw[:200]}
    except Exception as e:  # мережа, TLS тощо
        return 0, {"error": str(e)}


def check(name, condition, detail=""):
    (passed if condition else failed).append(name)
    print(f"  {'✓' if condition else '✗'} {name}" + (f" — {detail}" if detail and not condition else ""))
    return condition


def main():
    print("\n1. Захист API")
    st, _ = call("GET", SUPPLIER, "/api/supplier/v1/route-sheets")
    check("запит без токена відхиляється", st == 401, f"отримано {st}")

    st, _ = call("GET", SUPPLIER, "/api/supplier/v1/route-sheets",
                 headers={"X-User-Id": "hacker", "X-User-Role": "super_admin",
                          "X-Supplier-Id": "any", "X-Store-Ids": "all"})
    check("підроблені заголовки ідентичності не проходять", st == 401, f"отримано {st}")

    print("\n2. Вхід")
    st, res = call("POST", SUPPLIER, "/api/supplier/v1/auth/login", body=SUPPLIER_LOGIN)
    if not check("постачальник входить", st == 200, f"{st} {res.get('detail','')}"):
        return report()
    stoken = res["accessToken"]

    st, res = call("POST", STORE, "/api/store/v1/auth/login", body=STAFF_LOGIN)
    if not check("співробітник входить", st == 200, f"{st} {res.get('detail','')}"):
        return report()
    ktoken = res["accessToken"]

    print("\n3. Каталог")
    st, res = call("GET", SUPPLIER, "/api/supplier/v1/cities", stoken)
    cities = res.get("items", [])
    check("список міст", st == 200 and len(cities) > 0, f"{st}, міст {len(cities)}")

    st, res = call("GET", SUPPLIER, "/api/supplier/v1/stores?city=" + urllib.parse.quote("Київ"), stoken)
    stores = res.get("items", [])
    if not check("магазини міста", st == 200 and stores, f"{st}, магазинів {len(stores)}"):
        return report()
    store_id = stores[0]["storeId"]
    print(f"     магазин: {stores[0].get('name','')[:50]}")

    print("\n4. Сітка слотів")
    day = (date.today() + timedelta(days=1)).isoformat()
    st, grid = call("GET", SUPPLIER, f"/api/supplier/v1/stores/{store_id}/slots?date={day}", stoken)
    slots = grid.get("slots", [])
    if not check("сітка слотів будується", st == 200 and slots, f"{st}, слотів {len(slots)}"):
        return report()
    free = [s for s in slots if s["state"] == "available"]
    print(f"     слотів {len(slots)}, вільних {len(free)}, "
          f"тоннаж {grid.get('maxVehicleWeightTons')} т, слот {grid.get('slotSizeMinutes')} хв")

    st, _ = call("GET", SUPPLIER, f"/api/supplier/v1/stores/{store_id}/slots?date=2030-01-01", stoken)
    check("дата поза горизонтом відхиляється", st == 422, f"отримано {st}")

    print("\n5. Бронювання")
    slot = free[0]
    key = {"storeId": store_id, "rampId": slot["rampId"], "slotStart": slot["slotStart"]}

    st, hold = call("POST", SUPPLIER, "/api/supplier/v1/slots/hold", stoken, key)
    if not check("холд слота", st == 201, f"{st} {hold.get('detail','')}"):
        return report()

    st, dup = call("POST", SUPPLIER, "/api/supplier/v1/slots/hold", stoken, key)
    check("повторний холд того самого слота відхиляється", st == 409, f"отримано {st}")

    heavy = dict(key, holdToken=hold["holdToken"], palletsCount=10,
                 vehicle={"plateNumber": "AA9999BB", "weightTons": 39.0})
    st, res = call("POST", SUPPLIER, "/api/supplier/v1/bookings", stoken, heavy)
    check("авто понад тоннаж філії не бронює", st == 422 and res.get("code") == "VEHICLE_TOO_HEAVY",
          f"{st} {res.get('code')}")

    # Номер унікальний на кожен запуск: інакше спрацює BOOK-04 (перетин за
    # часом для того самого авто) і перевірка стане одноразовою.
    plate = "AA" + datetime.now().strftime("%H%M") + "BC"
    booking = dict(key, holdToken=hold["holdToken"], palletsCount=14, orderId="ORD-2026-0001",
                   vehicle={"plateNumber": plate, "weightTons": 12.5, "brand": "Mercedes Actros"})
    st, res = call("POST", SUPPLIER, "/api/supplier/v1/bookings", stoken, booking)
    if not check("бронювання створено", st in (200, 201), f"{st} {res.get('code')} {res.get('detail','')}"):
        return report()
    booking_id = res.get("id") or res.get("bookingId")
    print(f"     бронювання {booking_id}, статус {res.get('status')}")

    st, grid2 = call("GET", SUPPLIER, f"/api/supplier/v1/stores/{store_id}/slots?date={day}", stoken)
    booked = [s for s in grid2.get("slots", [])
              if s["slotStart"] == slot["slotStart"] and s["rampId"] == slot["rampId"]]
    check("слот у сітці став зайнятим", booked and booked[0]["state"] == "booked",
          booked[0]["state"] if booked else "слот зник")

    print("\n6. Маршрутний лист")
    st, res = call("GET", SUPPLIER, f"/api/supplier/v1/route-sheets?date={day}", stoken)
    check("маршрутний лист сформовано", st == 200, f"{st} {res.get('detail','')}")

    print("\n7. Призначення водія")
    st, res = call("POST", SUPPLIER, "/api/supplier/v1/route-sheets/driver", stoken,
                   {"date": day, "bookingId": booking_id, "driverId": DRIVER_PROFILE["id"]})
    check("водія призначено на бронювання", st in (200, 204), f"{st} {res.get('code')} {res.get('detail','')}")

    driver_flow(stoken, ktoken, store_id, day, booking_id, DRIVER_PROFILE)
    return report()



def driver_flow(stoken, ktoken, store_id, day, booking_id, driver):
    """Сценарій водія: вхід за телефоном, маршрутний лист, відмітка «На місці»."""
    print("\n8. Водій")
    st, res = call("POST", DRIVER, "/api/driver/v1/auth/login",
                   body={"phone": driver["phone"], "password": driver["password"]})
    if not check("водій входить за телефоном", st == 200, f"{st} {res.get('detail','')}"):
        return
    dtoken = res["accessToken"]

    st, res = call("POST", DRIVER, "/api/driver/v1/auth/login",
                   body={"phone": "0" + driver["phone"][4:], "password": driver["password"]})
    check("телефон у форматі 0XX теж приймається", st == 200, f"отримано {st}")

    st, sheet = call("GET", DRIVER, f"/api/driver/v1/route-sheet?date={day}", dtoken)
    check("водій бачить свій маршрутний лист", st == 200, f"{st} {sheet.get('detail','')}")

    st, res = call("POST", DRIVER, f"/api/driver/v1/bookings/{booking_id}/arrived", dtoken, {})
    check("водій відмічає «На місці»", st in (200, 204), f"{st} {res.get('code')} {res.get('detail','')}")

    st, res = call("POST", DRIVER, f"/api/driver/v1/bookings/{booking_id}/arrived", dtoken, {})
    check("повторне «На місці» не ламає стан", st in (200, 204), f"отримано {st}")

    st, res = call("POST", STORE, f"/api/store/v1/bookings/{booking_id}/unloading", ktoken, {})
    check("магазин починає розвантаження", st in (200, 204), f"{st} {res.get('detail','')}")

    st, res = call("POST", DRIVER, f"/api/driver/v1/bookings/{booking_id}/unloading", dtoken, {})
    check("водій НЕ може керувати розвантаженням", st in (403, 404, 405), f"отримано {st}")

    st, res = call("POST", STORE, f"/api/store/v1/bookings/{booking_id}/completed", ktoken,
                   {"unloadedPalletsCount": 14})
    check("магазин фіксує розвантаження", st in (200, 204), f"{st} {res.get('detail','')}")



def report():
    print(f"\n{'='*50}")
    print(f"Пройдено: {len(passed)}   Не пройдено: {len(failed)}")
    if failed:
        print("Не пройдено:")
        for f in failed:
            print(f"  • {f}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
