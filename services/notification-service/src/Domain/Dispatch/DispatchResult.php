<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

/**
 * Підсумок однієї спроби відправки.
 */
enum DispatchResult: string
{
    /** Провайдер прийняв повідомлення. */
    case Sent = 'sent';

    /** Технічний збій; заплановано наступну спробу (NOT-04). */
    case Retrying = 'retrying';

    /** Спроби вичерпані або помилка невиправна. */
    case Failed = 'failed';

    /** Відправку не робили: opt-out (NOT-05) або термінальний статус. */
    case Skipped = 'skipped';
}
