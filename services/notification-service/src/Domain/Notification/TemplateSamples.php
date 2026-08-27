<?php

declare(strict_types=1);

namespace App\Domain\Notification;

/**
 * Типові дані підстановок для кожного шаблону.
 *
 * Єдине джерело «типового тексту» з NOT-07: ці ж значення використовує
 * і консольний перегляд шаблонів (`app:notifications:preview`), і тест
 * довжини SMS. Якщо шаблон переверстають так, що типовий текст перестане
 * вкладатися у 2 сегменти, тест це покаже.
 */
final class TemplateSamples
{
    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     */
    public static function for(NotificationTemplate $template): array
    {
        $common = [
            'date' => '05.09.2026',
            'time' => '14:30',
            'externalId' => '1998',
            'city' => 'Київ',
            'address' => 'вул. Хрещатик, 1',
            'rampNumber' => '3',
            'vehicleNumber' => 'AA1234BB',
            'url' => 'https://yms.silpo.ua/b',
        ];

        $specific = match ($template) {
            NotificationTemplate::DriverPassword => [
                'phone' => '+380671234567',
                'password' => 'Xk7m2Qp9',
                'url' => 'https://yms.silpo.ua/d',
            ],
            NotificationTemplate::BookingConfirmed => [
                'orderId' => '12345',
            ],
            NotificationTemplate::BookingCancelled => [
                'reason' => 'Ремонт рампи',
            ],
            NotificationTemplate::BookingDelayed => [
                'reason' => 'Затримка транспорту',
            ],
            NotificationTemplate::BookingRejected => [
                'reason' => 'Невідповідність документів',
                'comment' => 'Немає ТТН',
            ],
            NotificationTemplate::BookingReassigned => [
                'changes' => 'рампа 5',
            ],
            NotificationTemplate::BranchArchivedConflict => [
                'count' => '3',
                'url' => 'https://yms.silpo.ua/a/sync',
            ],
            NotificationTemplate::Reminder24h,
            NotificationTemplate::Reminder2h,
            NotificationTemplate::BookingRescheduled => [],
        };

        $payload = [...$common, ...$specific];

        // Лишаємо тільки ті ключі, які шаблон дійсно очікує.
        return array_intersect_key($payload, $template->variables());
    }
}
