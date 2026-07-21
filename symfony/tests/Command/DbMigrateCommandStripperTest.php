<?php

namespace App\Tests\Command;

use App\Command\DbMigrateCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios de stripOwnTransactionWrapper(), el escaneo línea a línea
 * que retira el BEGIN;/COMMIT; propio de cada fichero db/*.sql antes de
 * ejecutarlo dentro de la transacción del runner. No arranca el kernel: usa
 * un mock de Connection (nunca se llama, el método bajo test es puro) y
 * Reflection para invocar el método privado.
 */
final class DbMigrateCommandStripperTest extends TestCase
{
    private DbMigrateCommand $command;

    protected function setUp(): void
    {
        // createStub() en vez de createMock(): no se verifica ninguna
        // expectativa sobre la conexión, el método bajo test es puro y
        // nunca la usa — solo hace falta satisfacer el tipo del
        // constructor.
        $this->command = new DbMigrateCommand($this->createStub(Connection::class), '/var/www/html');
    }

    private function strip(string $sql): string
    {
        $method = new \ReflectionMethod(DbMigrateCommand::class, 'stripOwnTransactionWrapper');

        return $method->invoke($this->command, $sql);
    }

    /** @return list<string> */
    private function realSqlFiles(): array
    {
        // Dentro del contenedor, bin/test.sh copia db/ como hijo directo de
        // /var/www/html (no como "../db"): mismo layout que usa
        // kernel.project_dir en DbMigrateCommandTest.
        $dir = \dirname(__DIR__, 2).'/db';
        $files = glob($dir.'/*.sql') ?: [];
        sort($files, \SORT_STRING);

        return $files;
    }

    public function testRealDbFilesAllHaveTheirBeginAndCommitStripped(): void
    {
        $files = $this->realSqlFiles();
        self::assertCount(10, $files, 'se esperaban los 10 ficheros db/*.sql del repo');

        foreach ($files as $path) {
            $stripped = $this->strip((string) file_get_contents($path));

            self::assertDoesNotMatchRegularExpression(
                '/^BEGIN\s*;\s*$/mi',
                $stripped,
                basename($path).': el BEGIN; de la envoltura debería haberse retirado',
            );
            self::assertDoesNotMatchRegularExpression(
                '/^COMMIT\s*;\s*$/mi',
                $stripped,
                basename($path).': el COMMIT; de la envoltura debería haberse retirado',
            );
        }
    }

    public function testTrailingCommentAfterCommitIsHarmlessAndCommitIsStillStripped(): void
    {
        $sql = <<<'SQL'
            -- comentario de cabecera
            BEGIN;

            SELECT 1;

            COMMIT;
            -- nota final tras el commit, no ejecutable
            SQL;

        $stripped = $this->strip($sql);

        self::assertStringNotContainsString('BEGIN;', $stripped);
        self::assertStringContainsString('SELECT 1;', $stripped);
        // El COMMIT; en sí se retira; el comentario que lo sigue puede
        // quedar (es inocuo, nunca se ejecuta como sentencia).
        self::assertDoesNotMatchRegularExpression('/^COMMIT\s*;/mi', $stripped);
    }

    public function testCommitInsideDollarQuotedFunctionBodyIsIgnoredByTheStripper(): void
    {
        // db/01_schema.sql tiene justo este patrón: una función plpgsql
        // cuyo cuerpo usa la palabra BEGIN (sin punto y coma, como
        // delimitador de bloque, no como transacción) entre $$...$$.
        $sql = <<<'SQL'
            -- cabecera
            BEGIN;

            CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger AS $$
            BEGIN
                NEW.updated_at = now();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            COMMIT;
            SQL;

        $stripped = $this->strip($sql);

        // El BEGIN; de la envoltura se retira, pero el BEGIN del cuerpo de
        // la función (dentro de $$...$$) se conserva intacto.
        self::assertStringContainsString("AS \$\$\nBEGIN\n", $stripped);
        self::assertStringNotContainsString("BEGIN;\n\nCREATE", $stripped);
        self::assertDoesNotMatchRegularExpression('/^COMMIT\s*;/mi', $stripped);
    }

    public function testStrayCommitOutsideTheWrapperAbortsWithAClearError(): void
    {
        $sql = <<<'SQL'
            BEGIN;

            SELECT 1;
            COMMIT;

            SELECT 2;
            COMMIT;
            SQL;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/COMMIT/');

        $this->strip($sql);
    }
}
