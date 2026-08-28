"""Доступи до стенду — зі змінних оточення, а не з коду.

Навіщо окремий модуль: паролі були захардкоджені у трьох скриптах одразу,
тож будь-яка зміна доступів вимагала правити код, а сам код не можна було
відкрити. Тут вони збираються в одному місці й читаються з оточення.

Значень за замовчуванням у паролів НЕМАЄ свідомо: тихий fallback повернув би
секрет у репозиторій найпершим же комітом «щоб працювало з коробки».

Приклад запуску:

    export YMS_ADMIN_PASSWORD='...'
    export YMS_SUPPLIER_PASSWORD='...'
    python3 infra/seed-demo.py

Повний перелік змінних — у .env.example у корені репозиторію.
"""
import os
import sys

# Адреса стенду секретом не є, тож має розумне значення за замовчуванням.
IP = os.environ.get("YMS_IP", "104.248.132.130")

ADMIN = f"https://admin.{IP}.sslip.io"
SUPPLIER = f"https://yms.{IP}.sslip.io"
DRIVER = f"https://driver.{IP}.sslip.io"
STORE = f"https://store.{IP}.sslip.io"

ADMIN_EMAIL = os.environ.get("YMS_ADMIN_EMAIL", "admin@rampa.ua")
SUPPLIER_LOGIN_NAME = os.environ.get("YMS_SUPPLIER_LOGIN", "supplier@rampa.ua")


class MissingCredential(RuntimeError):
    """Змінної немає — далі йти не можна, інакше отримаємо незрозумілий 401."""


def _require(name: str) -> str:
    value = os.environ.get(name, "")
    if not value:
        raise MissingCredential(
            f"Не задано {name}. Доступи до стенду беруться зі змінних оточення — "
            f"див. .env.example у корені репозиторію.\n"
            f"  export {name}='...'"
        )
    return value


def admin_password() -> str:
    return _require("YMS_ADMIN_PASSWORD")


def supplier_password() -> str:
    return _require("YMS_SUPPLIER_PASSWORD")


def admin_login() -> dict:
    """Тіло запиту POST /api/admin/v1/auth/login (він же вхід у модуль магазину)."""
    return {"email": ADMIN_EMAIL, "password": admin_password()}


def supplier_login() -> dict:
    """Тіло запиту POST /api/supplier/v1/auth/login."""
    return {"login": SUPPLIER_LOGIN_NAME, "password": supplier_password()}


def fail_fast(exc: MissingCredential) -> int:
    """Друкує причину без стектрейсу: це помилка налаштування, а не збій."""
    print(f"\n{exc}\n", file=sys.stderr)
    return 2
