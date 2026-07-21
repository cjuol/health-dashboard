<?php

namespace App\Tests\Smoke;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test mínimo de infraestructura: confirma que el kernel arranca en
 * APP_ENV=test y que la conexión a health_test funciona. Sirve de canario
 * para bin/test.sh antes de sumar tests de comportamiento más pesados.
 */
final class DatabaseConnectionTest extends KernelTestCase
{
    public function testDatabaseConnectionIsUsable(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
    }
}
