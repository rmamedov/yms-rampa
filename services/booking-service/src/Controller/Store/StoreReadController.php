<?php

declare(strict_types=1);

namespace App\Controller\Store;

use App\Application\Store\StoreReadService;
use App\Domain\Shared\Clock;
use App\Domain\Store\StoreBrief;
use App\Domain\Supplier\SupplierInfo;
use App\Infrastructure\Http\ActorResolver;
use App\Infrastructure\Http\StaffSlotPresenter;
use App\Infrastructure\Http\StoreBoardPresenter;
use App\Infrastructure\Http\StoreConfigPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Читання контуру магазину (/api/store/v1/...): усе, з чого складається
 * головний екран store-web.
 *
 *   GET /api/store/v1/stores                              — доступні філії
 *   GET /api/store/v1/stores/{storeId}/config             — конфігурація філії
 *   GET /api/store/v1/stores/{storeId}/suppliers          — довідник для walk-in
 *   GET /api/store/v1/stores/{storeId}/slots?date=…       — сітка слотів доби
 *   GET /api/store/v1/stores/{storeId}/slots?from=…&days= — сітка на тиждень
 *   GET /api/store/v1/bookings?storeId=…&date=…           — дошка прибуттів
 *
 * ФОРМА ВІДПОВІДІ. Колекції віддаються ПЛОСКИМ масивом, без обгортки
 * `{items: …}`: споживач цих маршрутів один — модуль магазину, і його моделі
 * (StoreScope, SupplierRef, Slot, WeekDaySlots) описують саме масив. Обгортка
 * знадобилася б для пагінації, а тут її свідомо немає: перелік філій, рамп і
 * постачальників однієї мережі — це десятки записів, які гортати нікому.
 * Виняток — дошка: їй потрібен серверний `now`, тому вона обʼєкт.
 *
 * ПРАВА перевіряються так само, як у діях ST-01..ST-07 — див. StoreReadService.
 */
final readonly class StoreReadController
{
    public function __construct(
        private StoreReadService $store,
        private ActorResolver $actors,
        private Clock $clock,
    ) {
    }

    /**
     * Філії, доступні користувачеві: джерело правди для перемикача магазину.
     *
     * Профіль користувача для цього не годиться: у мережевих ролей
     * `scope.storeIds` порожній, бо їхній скоуп задає РОЛЬ (RBAC-16), і
     * перемикач, побудований із профілю, у них порожній назавжди.
     */
    #[Route(path: '/api/store/v1/stores', name: 'store_stores_list', methods: ['GET'])]
    public function stores(Request $request): JsonResponse
    {
        return new JsonResponse(array_map(
            static fn (StoreBrief $store): array => $store->toArray(),
            $this->store->stores($this->actors->fromRequest($request)),
        ));
    }

    /** Конфігурація філії: рампи, вікна прийому, розмір слота, ліміт тоннажу. */
    #[Route(path: '/api/store/v1/stores/{storeId}/config', name: 'store_store_config', methods: ['GET'])]
    public function config(string $storeId, Request $request): JsonResponse
    {
        return new JsonResponse(StoreConfigPresenter::toArray(
            $this->store->config($this->actors->fromRequest($request), $storeId),
        ));
    }

    /** Довідник постачальників філії для форми позапланового прибуття (WALK-01). */
    #[Route(path: '/api/store/v1/stores/{storeId}/suppliers', name: 'store_store_suppliers', methods: ['GET'])]
    public function suppliers(string $storeId, Request $request): JsonResponse
    {
        return new JsonResponse(array_map(
            static fn (SupplierInfo $supplier): array => [
                'supplierId' => $supplier->supplierId,
                'name' => $supplier->name,
            ],
            $this->store->suppliers($this->actors->fromRequest($request), $storeId),
        ));
    }

    /**
     * Сітка слотів очима персоналу: `date` — одна доба, `from` + `days` —
     * діапазон для екрана «Розклад тижня». Обидва вигляди живуть на одному
     * маршруті, бо це та сама сітка, лише різної довжини.
     */
    #[Route(path: '/api/store/v1/stores/{storeId}/slots', name: 'store_store_slots', methods: ['GET'])]
    public function slots(string $storeId, Request $request): JsonResponse
    {
        $actor = $this->actors->fromRequest($request);
        $now = $this->clock->now();
        $from = trim((string) $request->query->get('from', ''));

        if ('' !== $from) {
            return new JsonResponse(StaffSlotPresenter::week(
                $this->store->week($actor, $storeId, $from, $request->query->getInt('days', 7), $now),
            ));
        }

        return new JsonResponse(StaffSlotPresenter::slots(
            $this->store->slots($actor, $storeId, trim((string) $request->query->get('date', '')), $now),
        ));
    }

    /** Дошка прибуттів магазину на локальну добу. */
    #[Route(path: '/api/store/v1/bookings', name: 'store_bookings_list', methods: ['GET'])]
    public function board(Request $request): JsonResponse
    {
        return new JsonResponse(StoreBoardPresenter::toArray($this->store->board(
            $this->actors->fromRequest($request),
            trim((string) $request->query->get('storeId', '')),
            trim((string) $request->query->get('date', '')),
            $this->clock->now(),
        )));
    }
}
