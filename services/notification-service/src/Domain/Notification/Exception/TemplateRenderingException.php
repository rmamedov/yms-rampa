<?php

declare(strict_types=1);

namespace App\Domain\Notification\Exception;

use App\Domain\Exception\DomainException;

/**
 * Помилка рендерингу шаблону повідомлення.
 */
class TemplateRenderingException extends DomainException
{
    public function __construct(string $message, string $errorCode = 'TEMPLATE_RENDERING_FAILED')
    {
        parent::__construct($message, $errorCode, 422);
    }
}
