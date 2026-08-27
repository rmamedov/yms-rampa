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
from datetime import date, timedelta

IP = "104.248.132.130"
SUPPLIER = f"https://yms.{IP}.sslip.io"
STORE = f"https://store.{IP}.sslip.io"
ADMIN = f"https://admin.{IP}.sslip.io"

SUPPLIER_LOGIN = {"login": "supplier@rampa.ua", "password": "${YMS_SUPPLIER_PASSWORD}"}
STAFF_LOGIN = {"email": "admin@rampa.ua", "password": "${YMS_ADMIN_PASSWORD}"}

passed, failed = [], []


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

    booking = dict(key, holdToken=hold["holdToken"], palletsCount=14, orderId="ORD-2026-0001",
                   vehicle={"plateNumber": "AA1234BC", "weightTons": 12.5, "brand": "Mercedes Actros"})
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

    print("\n7. Дії магазину")
    for action, label in [("arrived", "машина на місці"),
                          ("unloading", "розвантаження почалось"),
                          ("completed", "розвантажено")]:
        body = {"unloadedPalletsCount": 14} if action == "completed" else {}
        st, res = call("POST", STORE, f"/api/store/v1/bookings/{booking_id}/{action}", ktoken, body)
        check(label, st in (200, 204), f"{st} {res.get('code')} {res.get('detail','')}")

    return report()


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
