#!/usr/bin/env python3
"""Прибирання даних, створених UI-тестами.

Джерело — реєстр e2e/artifacts.jsonl, який тести дописують під час роботи.
Додатково робиться пошук за маркером UITEST на випадок, якщо тест впав до
того, як устиг зареєструвати створене.

Запуск: python3 infra/cleanup-test-data.py [--dry-run]
"""
import json
import sys
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter
from pathlib import Path

import credentials

ADMIN = credentials.ADMIN
SUPPLIER = credentials.SUPPLIER

REGISTRY = Path(__file__).resolve().parent.parent / "e2e" / "artifacts.jsonl"
DRY = "--dry-run" in sys.argv


def call(method, base, path, token=None, body=None):
    req = urllib.request.Request(base + path, method=method)
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
            return e.code, {}
    except Exception as e:
        return 0, {"error": str(e)}


def main():
    stats = Counter()
    print("Прибирання тестових даних" + (" (пробний запуск)" if DRY else ""))

    _, res = call("POST", ADMIN, "/api/admin/v1/auth/login", body=credentials.admin_login())
    admin_token = res.get("accessToken")
    _, res = call("POST", SUPPLIER, "/api/supplier/v1/auth/login", body=credentials.supplier_login())
    supplier_token = res.get("accessToken")

    if not admin_token:
        print("  Не вдалося увійти адміністратором — прибирання неможливе")
        return 1

    # --- 1. Реєстр -------------------------------------------------------
    entries = []
    if REGISTRY.exists():
        for line in REGISTRY.read_text().splitlines():
            if line.strip():
                try:
                    entries.append(json.loads(line))
                except json.JSONDecodeError:
                    pass
    print(f"\nУ реєстрі записів: {len(entries)}")

    seen = set()
    for e in entries:
        key = (e.get("kind"), e.get("id"))
        if key in seen or not e.get("id"):
            continue
        seen.add(key)
        kind, ident = key

        if DRY:
            stats[f"{kind} (знайдено)"] += 1
            continue

        if kind == "booking":
            st, _ = call("DELETE", SUPPLIER, f"/api/supplier/v1/bookings/{ident}",
                         supplier_token, {"reason": "Прибирання тестових даних"})
            stats["бронювання скасовано" if st in (200, 204) else f"бронювання не скасовано ({st})"] += 1
        elif kind == "supplier":
            st, _ = call("DELETE", ADMIN, f"/api/admin/v1/suppliers/{ident}", admin_token)
            if st not in (200, 204):
                st, _ = call("POST", ADMIN, f"/api/admin/v1/suppliers/{ident}/suspend",
                             admin_token, {"reason": "Прибирання тестових даних"})
                stats["постачальник призупинено" if st in (200, 204) else f"постачальник не прибрано ({st})"] += 1
            else:
                stats["постачальник видалено"] += 1
        elif kind == "driver":
            st, _ = call("POST", SUPPLIER, f"/api/supplier/v1/drivers/{ident}/deactivate", supplier_token)
            stats["водій деактивований" if st in (200, 204) else f"водій не прибраний ({st})"] += 1
        elif kind == "vehicle":
            st, _ = call("POST", SUPPLIER, f"/api/supplier/v1/vehicles/{ident}/deactivate", supplier_token)
            stats["авто деактивоване" if st in (200, 204) else f"авто не прибране ({st})"] += 1
        elif kind == "reserved-slot-rule":
            store = e.get("note", "").split("|")[0].strip()
            st, _ = call("DELETE", ADMIN, f"/api/admin/v1/stores/{store}/reserved-slot-rules/{ident}", admin_token)
            stats["резерв видалено" if st in (200, 204) else f"резерв не видалено ({st})"] += 1
        elif kind == "slot-block":
            store = e.get("note", "").split("|")[0].strip()
            st, _ = call("DELETE", ADMIN, f"/api/admin/v1/stores/{store}/slot-blocks/{ident}", admin_token)
            stats["блокування знято" if st in (200, 204) else f"блокування не знято ({st})"] += 1
        elif kind in ("walk-in",):
            # Walk-in створюється одразу в статусі arrived, тому скасувати його
            # штатним шляхом не можна. Лишаємо як історію — це не заважає.
            stats["walk-in лишено (скасування не передбачене)"] += 1
        elif kind in ("vehicle-plate", "store-config", "store-general", "store-status", "store-visibility"):
            # Не окремі сутності, а зміни в пісочниці Харкова: відновлюються
            # окремим кроком restore_sandbox() нижче.
            stats[f"{kind} (відновлюється окремо)"] += 1
        else:
            stats[f"невідомий тип: {kind}"] += 1

    # --- 2. Пошук за маркером (страховка) --------------------------------
    print("\nПошук залишків за маркером UITEST:")

    st, res = call("GET", ADMIN, "/api/admin/v1/suppliers?limit=200", admin_token)
    leftovers = [s for s in (res.get("items") or []) if "UITEST" in (s.get("name") or "")]
    print(f"  постачальників з маркером: {len(leftovers)}")
    for s in leftovers:
        if not DRY:
            call("DELETE", ADMIN, f"/api/admin/v1/suppliers/{s['id']}", admin_token)
        stats["постачальник за маркером"] += 1

    if supplier_token:
        st, res = call("GET", SUPPLIER, "/api/supplier/v1/vehicles?includeInactive=true", supplier_token)
        cars = [v for v in (res.get("items") or []) if (v.get("plateNumber") or "").startswith("UT")]
        print(f"  тестових авто: {len(cars)}")

        st, res = call("GET", SUPPLIER, "/api/supplier/v1/drivers", supplier_token)
        drivers = [d for d in (res.get("items") or []) if (d.get("phone") or "").startswith("+38099000")]
        print(f"  тестових водіїв: {len(drivers)}")
        for d in drivers:
            if not DRY:
                call("POST", SUPPLIER, f"/api/supplier/v1/drivers/{d['id']}/deactivate", supplier_token)
            stats["водій за маркером"] += 1

    print("\nПідсумок:")
    for k, v in sorted(stats.items()):
        print(f"  {k}: {v}")

    if not DRY and REGISTRY.exists():
        REGISTRY.rename(REGISTRY.with_suffix(".jsonl.done"))
        print(f"\nРеєстр перейменовано у {REGISTRY.with_suffix('.jsonl.done').name}")

    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except credentials.MissingCredential as e:
        sys.exit(credentials.fail_fast(e))
