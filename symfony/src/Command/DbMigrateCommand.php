<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runner de migraciones idempotente para los ficheros db/*.sql.
 *
 * docker-entrypoint-initdb.d solo aplica db/*.sql en el PRIMER arranque del
 * volumen de Postgres: en un despliegue ya inicializado (VPS en producción)
 * hay que aplicar a mano cualquier fichero nuevo. Este comando sustituye la
 * receta manual (`psql -f db/0N_x.sql`) por un runner que:
 *  - registra en la tabla `schema_migration` cada fichero ya aplicado, para
 *    no reaplicarlo en la siguiente ejecución;
 *  - aplica cada fichero pendiente dentro de su PROPIA transacción, así que
 *    si uno falla los anteriores quedan aplicados y registrados;
 *  - no depende de que la base esté vacía: como TODOS los ficheros de db/
 *    son idempotentes (CREATE ... IF NOT EXISTS / DROP ... IF EXISTS +
 *    CREATE), la primera vez que se ejecuta sobre una base ya poblada por
 *    docker-entrypoint-initdb.d simplemente reaplica cada fichero sin efecto
 *    destructivo y los deja registrados para las siguientes ejecuciones.
 *
 * No es un framework de migraciones (no hay "down", ni generación
 * automática de ficheros): es deliberadamente mínimo, en línea con el resto
 * del proyecto (SQL-first, sin dependencias pesadas).
 */
#[AsCommand(
    name: 'app:db:migrate',
    description: 'Aplica los ficheros .sql pendientes de un directorio, registrando los ya aplicados en schema_migration.',
)]
final class DbMigrateCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'directory',
                InputArgument::OPTIONAL,
                'Directorio con los ficheros *.sql a aplicar, en orden alfabético. Por defecto '.
                '"%kernel.project_dir%/../db", que solo tiene sentido en "Desarrollo sin Docker" '.
                '(el checkout completo del repo). Dentro del contenedor web no existe ese ../db: '.
                'pasa la ruta explícita, p.ej. /var/www/html/db (así lo hace bin/migrate.sh).',
            )
            ->addOption(
                'dsn',
                null,
                InputOption::VALUE_REQUIRED,
                'DSN DBAL alternativo, p.ej. postgresql://user:pass@host:5432/dbname. Por defecto usa '.
                'la conexión ya configurada de la app (DATABASE_URL). Útil para tests contra una base '.
                'de scratch o para apuntar explícitamente a una base distinta en producción.',
            )
            ->setHelp(
                'Aplica en orden alfabético los ficheros *.sql de un directorio, saltando los que ya '.
                'estén registrados en la tabla schema_migration (se crea sola si no existe). Cada '.
                'fichero se aplica dentro de su propia transacción: si uno falla, se detiene ahí, '.
                'informa qué fichero fue y deja intactos los registros de los ficheros anteriores.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $directory = rtrim((string) ($input->getArgument('directory') ?? $this->projectDir.'/../db'), '/');
        if (!is_dir($directory)) {
            $io->error(sprintf('El directorio "%s" no existe.', $directory));

            return Command::FAILURE;
        }

        $dsn = $input->getOption('dsn');
        if (null !== $dsn) {
            // DBAL 4 ya no parsea la clave 'url' dentro de DriverManager::getConnection():
            // hay que pasar por DsnParser explícitamente con el mismo mapeo de
            // esquemas que usa doctrine-bundle para DATABASE_URL.
            $parser = new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']);
            $connection = DriverManager::getConnection($parser->parse($dsn));
        } else {
            $connection = $this->db;
        }

        $connection->executeStatement(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS schema_migration (
                    filename    VARCHAR(255) PRIMARY KEY,
                    applied_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
                )
                SQL
        );

        $files = glob($directory.'/*.sql') ?: [];
        sort($files, SORT_STRING);

        if ([] === $files) {
            $io->warning(sprintf('No se encontraron ficheros .sql en "%s".', $directory));

            return Command::SUCCESS;
        }

        $appliedCount = 0;
        foreach ($files as $path) {
            $filename = basename($path);

            $alreadyApplied = $connection->fetchOne(
                'SELECT 1 FROM schema_migration WHERE filename = :filename',
                ['filename' => $filename],
            );
            if (false !== $alreadyApplied) {
                $io->writeln(sprintf('%s: omitido', $filename));
                continue;
            }

            $sql = $this->stripOwnTransactionWrapper((string) file_get_contents($path));

            $connection->beginTransaction();
            try {
                $connection->executeStatement($sql);
                $connection->insert('schema_migration', ['filename' => $filename]);
                $connection->commit();
            } catch (\Throwable $e) {
                $connection->rollBack();
                $io->error(sprintf('Fallo aplicando "%s": %s', $filename, $e->getMessage()));

                return Command::FAILURE;
            }

            $io->writeln(sprintf('%s: aplicado', $filename));
            ++$appliedCount;
        }

        $io->success(sprintf(
            '%d ficheros procesados, %d aplicados, %d omitidos.',
            \count($files),
            $appliedCount,
            \count($files) - $appliedCount,
        ));

        return Command::SUCCESS;
    }

    /**
     * Los ficheros db/*.sql traen su propio BEGIN;/COMMIT; para poder
     * aplicarse también sueltos con `psql -f`. Aquí cada fichero se envuelve
     * en NUESTRA propia transacción (para poder capturar el error de uno y
     * decidir si seguimos con los demás), así que el BEGIN/COMMIT del propio
     * fichero se retira antes de ejecutarlo: Postgres no admite abrir una
     * transacción dentro de otra ya abierta.
     */
    private function stripOwnTransactionWrapper(string $sql): string
    {
        $sql = trim($sql);
        $sql = preg_replace('/^BEGIN\s*;/i', '', $sql, 1) ?? $sql;
        $sql = preg_replace('/COMMIT\s*;\s*$/i', '', trim($sql), 1) ?? $sql;

        return trim($sql);
    }
}
