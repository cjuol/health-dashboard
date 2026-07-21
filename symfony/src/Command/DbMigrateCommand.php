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
 *  - aplica TODOS los ficheros pendientes de una misma ejecución dentro de
 *    UNA ÚNICA transacción (incluidas las inserciones en
 *    `schema_migration`): todo-o-nada. Si un fichero falla, la ejecución
 *    entera se revierte y no queda ningún fichero de esa corrida aplicado
 *    ni registrado — no hay una "ventana" a medias en la que un DROP de un
 *    fichero ya quedó comprometido mientras el fichero que lo repara
 *    todavía no se ha aplicado. Como el DDL de Postgres es transaccional
 *    (nada en db/ usa CONCURRENTLY ni CREATE DATABASE), esto es seguro;
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
                'estén registrados en la tabla schema_migration (se crea sola si no existe). Todos los '.
                'ficheros pendientes de la ejecución se aplican dentro de UNA ÚNICA transacción '.
                '(todo-o-nada): si uno falla, se revierte la ejecución entera y no queda ningún '.
                'fichero de esa corrida aplicado ni registrado.',
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

        $pending = [];
        $skippedCount = 0;
        foreach ($files as $path) {
            $filename = basename($path);
            $alreadyApplied = $connection->fetchOne(
                'SELECT 1 FROM schema_migration WHERE filename = :filename',
                ['filename' => $filename],
            );
            if (false !== $alreadyApplied) {
                $io->writeln(sprintf('%s: omitido', $filename));
                ++$skippedCount;
                continue;
            }
            $pending[$filename] = $path;
        }

        if ([] === $pending) {
            $io->success(sprintf('%d ficheros procesados, 0 aplicados, %d omitidos.', \count($files), $skippedCount));

            return Command::SUCCESS;
        }

        // Todos los ficheros pendientes de esta ejecución van en UNA ÚNICA
        // transacción: si uno falla, se revierte la corrida entera (ver
        // docblock de la clase). Esto evita la ventana en la que un DROP
        // de un fichero ya queda comprometido mientras el fichero que
        // recrea el objeto todavía no se ha aplicado (p.ej. una vista de
        // la que depende el dashboard, ausente durante la corrida).
        $connection->beginTransaction();
        try {
            foreach ($pending as $filename => $path) {
                $sql = $this->stripOwnTransactionWrapper((string) file_get_contents($path));
                $connection->executeStatement($sql);
                $connection->insert('schema_migration', ['filename' => $filename]);
                $io->writeln(sprintf('%s: aplicado', $filename));
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            $io->error(sprintf(
                'Fallo aplicando "%s": %s. Se ha revertido la ejecución completa: ningún fichero de '.
                'esta corrida queda aplicado ni registrado.',
                $filename ?? '?',
                $e->getMessage(),
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%d ficheros procesados, %d aplicados, %d omitidos.',
            \count($files),
            \count($pending),
            $skippedCount,
        ));

        return Command::SUCCESS;
    }

    /**
     * Los ficheros db/*.sql traen su propio BEGIN;/COMMIT; para poder
     * aplicarse también sueltos con `psql -f`. Aquí el fichero se ejecuta
     * dentro de NUESTRA propia transacción (ver execute()), así que su
     * BEGIN/COMMIT se retira antes: Postgres no admite abrir una
     * transacción dentro de otra ya abierta.
     *
     * Todos los db/*.sql empiezan con un bloque de comentarios "-- ===",
     * así que el BEGIN; nunca está en la primera línea literal del
     * fichero: hay que saltar líneas en blanco y comentarios "--" antes de
     * buscarlo. Es un escaneo línea a línea deliberadamente simple (NO un
     * parser SQL completo): ignora BEGIN/COMMIT que aparezcan dentro de un
     * cuerpo de función entre $$...$$ (db/01 y db/09 tienen funciones
     * plpgsql/sql) para no confundirlos con la envoltura transaccional del
     * propio fichero.
     *
     * Si tras retirar el BEGIN;/COMMIT; de la envoltura queda otro
     * COMMIT; suelto a nivel superior (fuera de un cuerpo $$...$$), se
     * aborta con un error claro en vez de ejecutarlo: indicaría contenido
     * tras el COMMIT; final del fichero, lo que rompería la atomicidad por
     * fichero que este runner garantiza.
     */
    private function stripOwnTransactionWrapper(string $sql): string
    {
        $lines = explode("\n", $sql);
        $insideDollarBody = $this->dollarBodyMaskPerLine($lines);

        // 1) Retirar el primer BEGIN; que aparece en la primera línea
        //    "significativa" (no en blanco, no comentario "--"), fuera de
        //    un cuerpo $$...$$.
        foreach ($lines as $i => $line) {
            if ($insideDollarBody[$i]) {
                continue;
            }
            $trimmed = trim($line);
            if ('' === $trimmed || str_starts_with($trimmed, '--')) {
                continue;
            }
            if (preg_match('/^BEGIN\s*;$/i', $trimmed)) {
                unset($lines[$i]);
            }
            break;
        }

        // 2) Retirar el último COMMIT; que aparece en la última línea
        //    "significativa" (recorriendo desde el final, saltando líneas
        //    en blanco/comentario que puedan venir después), fuera de un
        //    cuerpo $$...$$.
        $indices = array_keys($lines);
        for ($j = \count($indices) - 1; $j >= 0; --$j) {
            $i = $indices[$j];
            if ($insideDollarBody[$i]) {
                continue;
            }
            $trimmed = trim($lines[$i]);
            if ('' === $trimmed || str_starts_with($trimmed, '--')) {
                continue;
            }
            if (preg_match('/^COMMIT\s*;$/i', $trimmed)) {
                unset($lines[$i]);
            }
            break;
        }

        $result = trim(implode("\n", $lines));

        // 3) Si queda un COMMIT; adicional a nivel superior, es una señal
        //    de que hay contenido tras el COMMIT; final del fichero (o un
        //    COMMIT; duplicado): abortar en vez de ejecutar SQL que
        //    rompería la atomicidad por fichero.
        $remainingLines = explode("\n", $result);
        $insideDollarBody = $this->dollarBodyMaskPerLine($remainingLines);
        foreach ($remainingLines as $i => $line) {
            if ($insideDollarBody[$i]) {
                continue;
            }
            $trimmed = trim($line);
            if (preg_match('/^COMMIT\s*;(\s*--.*)?$/i', $trimmed)) {
                throw new \RuntimeException(
                    'El fichero contiene un COMMIT; adicional fuera de la envoltura transaccional '.
                    'estándar (tras retirar BEGIN;/COMMIT;). Revísalo antes de aplicarlo: ejecutarlo '.
                    'tal cual rompería la atomicidad por fichero que garantiza este runner.',
                );
            }
        }

        return $result;
    }

    /**
     * Para cada línea, indica si el escaneo entra en ella ya "dentro" de un
     * cuerpo $$...$$ (antes de aplicar los toggles de esa propia línea).
     * Solo contempla el delimitador simple "$$" (sin tag), que es el único
     * que usa este proyecto — no un parser genérico de dollar-quoting.
     *
     * @param list<string> $lines
     *
     * @return array<int, bool>
     */
    private function dollarBodyMaskPerLine(array $lines): array
    {
        $mask = [];
        $inside = false;
        foreach ($lines as $i => $line) {
            $mask[$i] = $inside;
            if (1 === preg_match_all('/\$\$/', $line) % 2) {
                $inside = !$inside;
            }
        }

        return $mask;
    }
}
