<?php

namespace App\Tests\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests del runner de migraciones (`app:db:migrate`) contra una base de
 * datos de scratch dedicada (health_migrate_test), creada y destruida en
 * cada test. No se reutiliza health_test (la de los tests funcionales)
 * porque aquí el punto es partir de una base vacía y ejercer el runner
 * de verdad contra los ficheros reales de db/ (bin/test.sh los copia a
 * /var/www/html/db dentro del contenedor).
 */
final class DbMigrateCommandTest extends KernelTestCase
{
    private const SCRATCH_DB = 'health_migrate_test';

    private Connection $admin;
    private array $baseParams;
    private string $dbDir;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $appConnection */
        $appConnection = self::getContainer()->get(Connection::class);
        $this->baseParams = $appConnection->getParams();
        $this->dbDir = self::getContainer()->getParameter('kernel.project_dir').'/db';

        // CREATE/DROP DATABASE no se pueden ejecutar dentro de una
        // transacción: hace falta una conexión propia, a una base distinta
        // de la que se va a crear/destruir (aquí, "postgres").
        $this->admin = DriverManager::getConnection($this->connectionParams('postgres'));
        $this->recreateScratchDatabase();
    }

    protected function tearDown(): void
    {
        $this->dropScratchDatabase();
        $this->admin->close();
        parent::tearDown();
    }

    public function testFreshDatabaseAppliesAllFilesAndCreatesKeyObjects(): void
    {
        $files = $this->sqlFiles();
        self::assertNotEmpty($files, 'no se encontraron ficheros .sql en '.$this->dbDir);

        [$exitCode, $display] = $this->runMigrate();

        self::assertSame(Command::SUCCESS, $exitCode);
        foreach ($files as $file) {
            self::assertStringContainsString(basename($file).': aplicado', $display);
        }

        $scratch = DriverManager::getConnection($this->connectionParams(self::SCRATCH_DB));
        try {
            self::assertTrue($this->tableExists($scratch, 'hc_movement_bucket'), 'falta la tabla hc_movement_bucket');
            self::assertTrue($this->viewExists($scratch, 'v_steps_fused_15m'), 'falta la vista v_steps_fused_15m');
            self::assertTrue($this->viewExists($scratch, 'v_weekly_summary'), 'falta la vista v_weekly_summary');
            self::assertTrue($this->triggerExists($scratch, 'trg_auth_updated', 'garmin_auth_token'), 'falta el trigger trg_auth_updated');
            self::assertTrue($this->triggerExists($scratch, 'trg_app_user_updated', 'app_user'), 'falta el trigger trg_app_user_updated');
            self::assertTrue($this->triggerExists($scratch, 'trg_share_link_updated', 'share_link'), 'falta el trigger trg_share_link_updated');
        } finally {
            $scratch->close();
        }
    }

    public function testSecondRunSkipsEveryFile(): void
    {
        $this->runMigrate();
        [$exitCode, $display] = $this->runMigrate();

        self::assertSame(Command::SUCCESS, $exitCode);
        foreach ($this->sqlFiles() as $file) {
            self::assertStringContainsString(basename($file).': omitido', $display);
        }
        self::assertStringContainsString('0 aplicados', $display);
    }

    public function testEveryFileIsSafeToReapplyRawTwiceWithoutSchemaMigration(): void
    {
        // Red de seguridad para las bases de producción ya existentes: si
        // schema_migration todavía no existe (VPS con volumen viejo), la
        // primera pasada del runner reaplica TODOS los ficheros tal cual.
        // Aquí se simula ese escenario dos veces seguidas, ejecutando cada
        // fichero .sql en bruto (sin pasar por el runner ni por
        // schema_migration) sobre una base que ya tiene el esquema completo.
        $this->runMigrate();

        $scratch = DriverManager::getConnection($this->connectionParams(self::SCRATCH_DB));
        try {
            $files = $this->sqlFiles();
            for ($pass = 0; $pass < 2; ++$pass) {
                foreach ($files as $file) {
                    $scratch->executeStatement((string) file_get_contents($file));
                }
            }

            self::assertTrue($this->tableExists($scratch, 'hc_movement_bucket'));
            self::assertTrue($this->viewExists($scratch, 'v_weekly_summary'));
        } finally {
            $scratch->close();
        }
    }

    /**
     * @return array{0: int, 1: string} [exit code, salida de consola]
     */
    private function runMigrate(): array
    {
        $application = new Application(self::$kernel);
        $command = $application->find('app:db:migrate');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'directory' => $this->dbDir,
            '--dsn' => $this->dsn(self::SCRATCH_DB),
        ]);

        return [$exitCode, $tester->getDisplay()];
    }

    /** @return list<string> */
    private function sqlFiles(): array
    {
        $files = glob($this->dbDir.'/*.sql') ?: [];
        sort($files, \SORT_STRING);

        return $files;
    }

    private function connectionParams(string $dbname): array
    {
        $params = $this->baseParams;
        $params['dbname'] = $dbname;
        unset($params['url']);

        return $params;
    }

    private function dsn(string $dbname): string
    {
        $params = $this->baseParams;

        return sprintf(
            'postgresql://%s:%s@%s:%s/%s',
            rawurlencode((string) ($params['user'] ?? '')),
            rawurlencode((string) ($params['password'] ?? '')),
            $params['host'] ?? '127.0.0.1',
            $params['port'] ?? 5432,
            $dbname,
        );
    }

    private function recreateScratchDatabase(): void
    {
        $this->terminateScratchConnections();
        $this->admin->executeStatement('DROP DATABASE IF EXISTS '.self::SCRATCH_DB);
        $this->admin->executeStatement('CREATE DATABASE '.self::SCRATCH_DB);
    }

    private function dropScratchDatabase(): void
    {
        $this->terminateScratchConnections();
        $this->admin->executeStatement('DROP DATABASE IF EXISTS '.self::SCRATCH_DB);
    }

    private function terminateScratchConnections(): void
    {
        $this->admin->executeStatement(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :db AND pid <> pg_backend_pid()',
            ['db' => self::SCRATCH_DB],
        );
    }

    private function tableExists(Connection $db, string $name): bool
    {
        return false !== $db->fetchOne(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :n",
            ['n' => $name],
        );
    }

    private function viewExists(Connection $db, string $name): bool
    {
        return false !== $db->fetchOne(
            "SELECT 1 FROM information_schema.views WHERE table_schema = 'public' AND table_name = :n",
            ['n' => $name],
        );
    }

    private function triggerExists(Connection $db, string $trigger, string $table): bool
    {
        return false !== $db->fetchOne(
            'SELECT 1 FROM information_schema.triggers WHERE trigger_name = :t AND event_object_table = :tbl',
            ['t' => $trigger, 'tbl' => $table],
        );
    }
}
