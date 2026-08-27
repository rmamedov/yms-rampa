<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Domain\Identity\PartnerAccountGateway;
use App\Infrastructure\Identity\HttpPartnerAccountGateway;
use App\Infrastructure\InMemory\InMemoryPartnerAccountGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Захист від пастки порядку завантаження конфігів (див. коментарі у
 * config/services_prod.yaml).
 *
 * Ядро імпортує config/packages/prod/*.yaml ПЕРЕД config/services.yaml, тож
 * будь-який кореневий аліас або глоб `App\` мовчки затирає прод-профіль. Один
 * раз це вже коштувало проду заглушки замість HTTP-шлюзу: водій зберігався в
 * partner_users, а увійти не міг (401). Тест відтворює саме той порядок
 * завантаження і перевіряє, ЩО в підсумку стоїть за портом.
 *
 * Контейнер тут не компілюється: перевіряється звʼязування, а не збірка —
 * її стереже `APP_ENV=prod bin/console lint:container`.
 */
final class ProdWiringTest extends TestCase
{
    /** ПРОД: порт має дивитися на HTTP-реалізацію, а не на заглушку. */
    public function testProdBindsIdentityGatewayToHttpImplementation(): void
    {
        $container = self::loadContainer('prod');

        self::assertTrue($container->hasAlias(PartnerAccountGateway::class));
        self::assertSame(
            HttpPartnerAccountGateway::class,
            (string) $container->getAlias(PartnerAccountGateway::class),
        );
    }

    /**
     * Глоб `App\` із config/services.yaml перереєстровує класи src/ на чисте
     * автовайрингове визначення і губить явні аргументи. Повторний імпорт
     * прод-профілю останнім має повернути базовий URL сусіда.
     */
    public function testProdKeepsExplicitBaseUrlOfNeighbour(): void
    {
        $definition = self::loadContainer('prod')->getDefinition(HttpPartnerAccountGateway::class);

        self::assertSame('%env(IDENTITY_PARTNER_BASE_URL)%', $definition->getArgument('$baseUrl'));
        self::assertSame('http_client', (string) $definition->getArgument('$http'));
    }

    /** DEV і тести лишаються без мережі — на InMemory-емуляції контуру. */
    public function testDevKeepsInMemoryGateway(): void
    {
        $container = self::loadContainer('dev');

        self::assertSame(
            InMemoryPartnerAccountGateway::class,
            (string) $container->getAlias(PartnerAccountGateway::class),
        );
    }

    /**
     * Відтворення порядку MicroKernelTrait::configureContainer:
     * config/packages/{env}/*.yaml → config/services.yaml → config/services_{env}.yaml.
     *
     * config/packages/*.yaml не завантажуються: там конфігурація бандлів
     * (framework), а не сервіси, і без зареєстрованих розширень такий файл
     * узагалі неможливо прочитати.
     */
    private static function loadContainer(string $env): ContainerBuilder
    {
        $configDir = \dirname(__DIR__, 2).'/config';
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator($configDir), $env);

        $envPackages = glob($configDir.'/packages/'.$env.'/*.yaml') ?: [];
        sort($envPackages);

        foreach ($envPackages as $file) {
            $loader->load($file);
        }

        $loader->load($configDir.'/services.yaml');

        if (is_file($envServices = $configDir.'/services_'.$env.'.yaml')) {
            $loader->load($envServices);
        }

        return $container;
    }
}
