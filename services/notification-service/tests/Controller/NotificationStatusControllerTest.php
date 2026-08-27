<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\NotificationStatusController;
use App\Domain\Security\SecretMasker;
use App\Infrastructure\Http\ProblemJsonFactory;
use App\Tests\Support\NotificationTestEnvironment;
use App\Domain\Dispatch\NotificationRequest;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Читання статусу доставки в адмінці (NOT-03) і формат помилки RFC 7807.
 */
#[CoversClass(NotificationStatusController::class)]
final class NotificationStatusControllerTest extends TestCase
{
    private NotificationTestEnvironment $env;
    private NotificationStatusController $controller;

    protected function setUp(): void
    {
        $this->env = new NotificationTestEnvironment();
        $this->controller = new NotificationStatusController(
            $this->env->repository,
            new SecretMasker(),
            new ProblemJsonFactory(),
        );
    }

    public function testUnknownNotificationReturnsProblemJson(): void
    {
        $response = $this->controller->show('невідомий', new Request());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('NOTIFICATION_NOT_FOUND', $body['code']);
        self::assertArrayHasKey('requestId', $body);
    }

    public function testStatusIsReturnedWithMaskedPayload(): void
    {
        $notification = $this->env->dispatcher->send(new NotificationRequest(
            template: NotificationTemplate::DriverPassword,
            channel: NotificationChannel::Sms,
            recipient: '+380671234567',
            payload: ['phone' => '+380671234567', 'password' => 'Xk7m2Qp9', 'url' => 'https://yms.silpo.ua/d'],
            correlationId: 'drv-1',
        ));
        self::assertNotNull($notification);

        $response = $this->controller->show($notification->id(), new Request());

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('sent', $body['status']);
        self::assertSame('NOT-T1', $body['template']);
        self::assertSame('sms', $body['channel']);
        self::assertSame('drv-1', $body['correlationId']);
        self::assertStringNotContainsString('Xk7m2Qp9', (string) $response->getContent());
    }
}
