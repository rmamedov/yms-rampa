<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Каталог атомарних прав (RBAC-08).
 *
 * Формат права — `ресурс.дія[.модифікатор]`. Перелік дослівно відповідає
 * таблиці розділу 4.3 SRS; жодних інших прав не існує (RBAC-02 deny by default).
 */
enum Permission: string
{
    // store-service
    case StoreRead = 'store.read';
    case StoreConfigure = 'store.configure';
    case StoreSyncManage = 'store.sync.manage';

    // booking-service: слоти
    case SlotRead = 'slot.read';
    case SlotBlock = 'slot.block';
    case SlotReserve = 'slot.reserve';

    // booking-service: бронювання
    case BookingCreate = 'booking.create';
    case BookingCreateWalkIn = 'booking.create_walk_in';
    case BookingReadAll = 'booking.read.all';
    case BookingReadOwn = 'booking.read.own';
    case BookingUpdateOwn = 'booking.update.own';
    case BookingCancelOwn = 'booking.cancel.own';
    case BookingCancelAny = 'booking.cancel.any';
    case BookingMarkArrived = 'booking.mark_arrived';
    case BookingMarkUnloading = 'booking.mark_unloading';
    case BookingMarkUnloaded = 'booking.mark_unloaded';
    case BookingMarkNoShow = 'booking.mark_no_show';
    case BookingMarkDelayed = 'booking.mark_delayed';
    case BookingReject = 'booking.reject';
    case BookingReassignRamp = 'booking.reassign_ramp';

    // booking-service: маршрутні листи
    case RouteSheetReadOwn = 'routesheet.read.own';
    case RouteSheetManage = 'routesheet.manage';

    // partner-service
    case SupplierRead = 'supplier.read';
    case SupplierManage = 'supplier.manage';
    case DriverManage = 'driver.manage';
    case VehicleManage = 'vehicle.manage';

    // analytics-service
    case AnalyticsView = 'analytics.view';

    // identity-сервіси
    case UsersManageStaff = 'users.manage.staff';
    case UsersManageSupplier = 'users.manage.supplier';
    case RolesAssign = 'roles.assign';
    case AuditRead = 'audit.read';

    /**
     * Опис права українською (для адмінки та дампу матриці).
     */
    public function description(): string
    {
        return match ($this) {
            self::StoreRead => 'Перегляд довідника магазинів і їх налаштувань',
            self::StoreConfigure => 'Редагування налаштувань магазину',
            self::StoreSyncManage => 'Запуск і перегляд журналу синхронізації з MCP Сільпо',
            self::SlotRead => 'Перегляд сітки слотів та їх станів',
            self::SlotBlock => 'Блокування та розблокування слотів',
            self::SlotReserve => 'Налаштування розкладів резервів за постачальником',
            self::BookingCreate => 'Створення бронювання',
            self::BookingCreateWalkIn => 'Реєстрація позапланового прибуття (walk-in)',
            self::BookingReadAll => 'Перегляд усіх бронювань у межах скоупа',
            self::BookingReadOwn => 'Перегляд бронювань свого постачальника',
            self::BookingUpdateOwn => 'Зміна слоту власного бронювання',
            self::BookingCancelOwn => 'Скасування власного бронювання',
            self::BookingCancelAny => 'Скасування бронювання магазином або адміном',
            self::BookingMarkArrived => 'Переведення у статус arrived',
            self::BookingMarkUnloading => 'Переведення у статус unloading',
            self::BookingMarkUnloaded => 'Переведення у статус completed',
            self::BookingMarkNoShow => 'Позначення no_show',
            self::BookingMarkDelayed => 'Встановлення прапорця delayed з ETA і причиною',
            self::BookingReject => 'Відмова в прийомі: arrived → rejected',
            self::BookingReassignRamp => 'Переведення бронювання на іншу вільну рампу того самого слота',
            self::RouteSheetReadOwn => 'Перегляд власних маршрутних листів',
            self::RouteSheetManage => 'Редагування маршрутних листів і призначення водія',
            self::SupplierRead => 'Перегляд постачальників',
            self::SupplierManage => 'Створення, редагування та активація постачальників',
            self::DriverManage => 'Створення та редагування водіїв постачальника',
            self::VehicleManage => 'Керування автопарком постачальника',
            self::AnalyticsView => 'Перегляд аналітики та дашбордів',
            self::UsersManageStaff => 'Керування staff-користувачами',
            self::UsersManageSupplier => 'Керування користувачами постачальника',
            self::RolesAssign => 'Призначення ролей у межах дозволеного дерева',
            self::AuditRead => 'Перегляд журналу аудиту змін ролей',
        };
    }
}
