<?php

declare(strict_types=1);

namespace App\Domain\Notification\Exception;

use App\Domain\Notification\NotificationTemplate;

/**
 * Для обовʼязкової підстановки шаблону не передано значення.
 */
final class MissingTemplateVariableException extends TemplateRenderingException
{
    public function __construct(NotificationTemplate $template, public readonly string $variable)
    {
        parent::__construct(
            \sprintf('Не передано обовʼязкову підстановку «%s» для шаблону %s.', $variable, $template->code()),
            'TEMPLATE_VARIABLE_MISSING',
        );
    }
}
