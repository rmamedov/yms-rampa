<?php

declare(strict_types=1);

namespace App\Domain\Notification;

/**
 * Реєстр шаблонів повідомлень (розділ 11.2.2 SRS, коди NOT-T1…NOT-T9, NOT-T14).
 *
 * Тексти зафіксовані замовником і є обовʼязковими — змінювати формулювання
 * не можна. Значення enum — це код вимоги, щоб журнал і адмінка однозначно
 * посилалися на специфікацію.
 *
 * Плейсхолдери в тексті мають англійські імена (конвенція проєкту:
 * назви полів — англійською), самі тексти — українською.
 */
enum NotificationTemplate: string
{
    /** NOT-T1. Створення водія (DriverCreated) → SMS водію. */
    case DriverPassword = 'NOT-T1';

    /** NOT-T2. Підтвердження бронювання (BookingCreated без rescheduleOf). */
    case BookingConfirmed = 'NOT-T2';

    /** NOT-T3. Нагадування за 24 год (планувальник, NOT-06). */
    case Reminder24h = 'NOT-T3';

    /** NOT-T4. Нагадування за 2 год (планувальник, NOT-06). */
    case Reminder2h = 'NOT-T4';

    /** NOT-T5. Скасування бронювання (BookingCancelled без звʼязку rescheduleOf). */
    case BookingCancelled = 'NOT-T5';

    /** NOT-T6. Затримка (BookingDelaySet). */
    case BookingDelayed = 'NOT-T6';

    /** NOT-T7. Перенесення слота (пара BookingCreated + BookingCancelled, NOT-16). */
    case BookingRescheduled = 'NOT-T7';

    /** NOT-T8. Відмова в прийомі (BookingRejected, NOT-17). */
    case BookingRejected = 'NOT-T8';

    /** NOT-T9. Зміна рампи / перепризначення (BookingReassigned, NOT-18). */
    case BookingReassigned = 'NOT-T9';

    /** NOT-T14. Конфлікт архівації філії (INT-10 → NOT-14), e-mail network_manager. */
    case BranchArchivedConflict = 'NOT-T14';

    /** Код вимоги SRS, за яким шаблон зафіксовано. */
    public function code(): string
    {
        return $this->value;
    }

    /**
     * Обовʼязковий текст шаблону (розділ 11.2.2).
     * Для e-mail це тіло листа, для SMS — повний текст повідомлення.
     */
    public function body(): string
    {
        return match ($this) {
            self::DriverPassword => 'Сільпо YMS Рампа. Ваш логін: {phone}, пароль: {password}. Вхід: {url}. Нікому не повідомляйте пароль.',
            self::BookingConfirmed => 'Бронювання підтверджено: {date} {time}, філія №{externalId}, {city}, {address}, рампа {rampNumber}. Авто {vehicleNumber}. Замовлення {orderId}.',
            self::Reminder24h => 'Нагадування: завтра {date} о {time} — доставка у філію №{externalId}, {address}, рампа {rampNumber}.',
            self::Reminder2h => 'Через 2 години о {time} — доставка у філію №{externalId}, {address}, рампа {rampNumber}. Не забудьте натиснути На місці після прибуття.',
            self::BookingCancelled => 'Бронювання {date} {time}, філія №{externalId} скасовано. Причина: {reason}. Оберіть інший слот у кабінеті: {url}.',
            self::BookingDelayed => 'Увага: по бронюванню {date} {time}, філія №{externalId} зафіксована затримка. Причина: {reason}.',
            self::BookingRescheduled => 'Ваше бронювання перенесено: нова дата {date}, час {time}, філія №{externalId}, рампа {rampNumber}. Деталі: {url}.',
            self::BookingRejected => 'Відмовлено в прийомі: бронювання {date} {time}, філія №{externalId}, авто {vehicleNumber}. Причина: {reason}{comment}. Деталі та контакти магазину: {url}.',
            self::BookingReassigned => 'Зміни у бронюванні {date} {time}, філія №{externalId}: {changes}. Перевірте деталі: {url}.',
            self::BranchArchivedConflict => 'Філію №{externalId} архівовано за даними MCP, але існує {count} майбутніх бронювань. Потрібне рішення: {url}.',
        };
    }

    /** Тема листа (використовується лише каналом e-mail). */
    public function emailSubject(): string
    {
        return match ($this) {
            self::DriverPassword => 'Доступ до YMS «Рампа»',
            self::BookingConfirmed => 'Бронювання підтверджено — філія №{externalId}, {date} {time}',
            self::Reminder24h => 'Нагадування про доставку завтра — філія №{externalId}',
            self::Reminder2h => 'Доставка через 2 години — філія №{externalId}',
            self::BookingCancelled => 'Бронювання скасовано — філія №{externalId}, {date} {time}',
            self::BookingDelayed => 'Затримка по бронюванню — філія №{externalId}, {date} {time}',
            self::BookingRescheduled => 'Бронювання перенесено — філія №{externalId}, {date} {time}',
            self::BookingRejected => 'Відмова в прийомі — філія №{externalId}, {date} {time}',
            self::BookingReassigned => 'Зміни у бронюванні — філія №{externalId}, {date} {time}',
            self::BranchArchivedConflict => 'Конфлікт синхронізації: філію №{externalId} архівовано',
        };
    }

    /**
     * Канали, якими шаблон розсилається за замовчуванням (розділ 11.2.2).
     *
     * @return list<NotificationChannel>
     */
    public function channels(): array
    {
        return match ($this) {
            self::DriverPassword,
            self::Reminder2h,
            self::BookingDelayed => [NotificationChannel::Sms],
            self::BookingRejected,
            self::BranchArchivedConflict => [NotificationChannel::Email],
            self::BookingConfirmed,
            self::Reminder24h,
            self::BookingCancelled,
            self::BookingRescheduled,
            self::BookingReassigned => [NotificationChannel::Sms, NotificationChannel::Email],
        };
    }

    /**
     * Критичні сповіщення (NOT-05) — opt-out до них не застосовується:
     * пароль водія, підтвердження бронювання, скасування, зміна слота,
     * відмова в прийомі, зміна рампи/перепризначення.
     */
    public function isCritical(): bool
    {
        return match ($this) {
            self::DriverPassword,
            self::BookingConfirmed,
            self::BookingCancelled,
            self::BookingRescheduled,
            self::BookingRejected,
            self::BookingReassigned,
            self::BranchArchivedConflict => true,
            self::Reminder24h,
            self::Reminder2h,
            self::BookingDelayed => false,
        };
    }

    /**
     * Опис підстановок шаблону.
     *
     * @return array<string, TemplateVariableSpec>
     */
    public function variables(): array
    {
        $specs = match ($this) {
            self::DriverPassword => [
                TemplateVariableSpec::required('phone'),
                TemplateVariableSpec::required('password', sensitive: true),
                TemplateVariableSpec::required('url'),
            ],
            self::BookingConfirmed => [
                TemplateVariableSpec::required('date'),
                TemplateVariableSpec::required('time'),
                TemplateVariableSpec::required('externalId'),
                TemplateVariableSpec::required('city'),
                TemplateVariableSpec::required('address'),
                TemplateVariableSpec::required('rampNumber'),
                TemplateVariableSpec::required('vehicleNumber'),
                // NOT-08: порожній необовʼязковий orderId рендериться як «без номера».
                TemplateVariableSpec::optional('orderId', fallback: 'без номера'),
            ],
            self::Reminder24h => [
                TemplateVariableSpec::required('date'),
                TemplateVariableSpec::required('time'),
                TemplateVariableSpec::required('externalId'),
                TemplateVariableSpec::required('address'),
                TemplateVariableSpec::required('rampNumber'),
            ],
            self::Reminder2h => [
                TemplateVariableSpec::required('time'),
                TemplateVariableSpec::required('externalId'),
                TemplateVariableSpec::required('address'),
                TemplateVariableSpec::required('rampNumber'),
            ],
            self::BookingCancelled => [
                TemplateVariableSpec::required('date'),
                TemplateVariableSpec::required('time'),
                TemplateVariableSpec::required('externalId'),
                TemplateVariableSpec::required('reason'),
                TemplateVariableSpec::required('url'),
            ],
            self::BookingDelayed => [
                TemplateVariableSpec::required('date'),
                TemplateVariableSpec::required('time'),
                TemplateVariableSpec::required('externalId'),
                TemplateVariableSpec::required('reason'),
            ],
            self::BookingRescheduled => [
                TemplateVariableSpec::required('date'),
                TemplateVariableSpec::required('time'),
                TemplateVariableSpec::required('externalId'),
                TemplateVariableSpec::required('rampNumber'),
                TemplateVariableSpec::required('url'),
            ],
            self::BookingRejected => [
                TemplateVariableSpec::required('date'),
                TemplateVariableSpec::required('time'),
                TemplateVariableSpec::required('externalId'),
                TemplateVariableSpec::required('vehicleNumber'),
                TemplateVariableSpec::required('reason'),
                // «{, коментар}» — необовʼязковий коментар з комою-префіксом.
                TemplateVariableSpec::optional('comment', prefix: ', '),
                TemplateVariableSpec::required('url'),
            ],
            self::BookingReassigned => [
                TemplateVariableSpec::required('date'),
                TemplateVariableSpec::required('time'),
                TemplateVariableSpec::required('externalId'),
                TemplateVariableSpec::required('changes'),
                TemplateVariableSpec::required('url'),
            ],
            self::BranchArchivedConflict => [
                TemplateVariableSpec::required('externalId'),
                TemplateVariableSpec::required('count'),
                TemplateVariableSpec::required('url'),
            ],
        };

        $indexed = [];
        foreach ($specs as $spec) {
            $indexed[$spec->name] = $spec;
        }

        return $indexed;
    }

    /**
     * Імена підстановок, значення яких є секретами і не мають потрапляти
     * ані в журнали, ані в сховище після відправки (NOT-15).
     *
     * @return list<string>
     */
    public function sensitiveVariables(): array
    {
        $names = [];
        foreach ($this->variables() as $spec) {
            if ($spec->sensitive) {
                $names[] = $spec->name;
            }
        }

        return $names;
    }

    /** Чи входить канал у типове маршрутизування шаблону (розділ 11.2.2). */
    public function supports(NotificationChannel $channel): bool
    {
        return \in_array($channel, $this->channels(), true);
    }

    /**
     * Чи можна взагалі відрендерити шаблон для каналу.
     *
     * Ширше за supports(): резервний канал (NOT-04) може не збігатися з
     * типовим маршрутом — наприклад, критичний NOT-T1 при відмові SMS
     * дублюється e-mail. Заборонений лише Viber: тексти для нього — фаза 2.
     */
    public function canRenderFor(NotificationChannel $channel): bool
    {
        return $channel->isAvailableInMvp();
    }
}
