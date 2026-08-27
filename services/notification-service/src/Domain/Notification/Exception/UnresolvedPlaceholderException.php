<?php

declare(strict_types=1);

namespace App\Domain\Notification\Exception;

use App\Domain\Notification\NotificationTemplate;

/**
 * Після підстановки в тексті лишився нерозкритий плейсхолдер.
 *
 * Це страховка від «сирих» повідомлень на кшталт «рампа {rampNumber}»,
 * які дійшли б до водія.
 */
final class UnresolvedPlaceholderException extends TemplateRenderingException
{
    public function __construct(NotificationTemplate $template, public readonly string $placeholder)
    {
        parent::__construct(
            \sprintf('Шаблон %s містить нерозкритий плейсхолдер «%s».', $template->code(), $placeholder),
            'TEMPLATE_PLACEHOLDER_UNRESOLVED',
        );
    }
}
