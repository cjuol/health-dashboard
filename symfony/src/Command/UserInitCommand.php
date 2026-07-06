<?php

namespace App\Command;

use App\Security\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Alta o actualización del usuario único del dashboard (app_user). No hay
 * entidad Doctrine: se hashea la contraseña con el mismo hasher configurado
 * en security.yaml para App\Security\AppUser y se hace UPSERT por username
 * directamente vía DBAL.
 */
#[AsCommand(
    name: 'app:user:init',
    description: 'Crea o actualiza el usuario del dashboard (login + perfil).',
)]
final class UserInitCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::OPTIONAL, 'Nombre de usuario')
            ->addArgument('display-name', InputArgument::OPTIONAL, 'Nombre a mostrar en el dashboard e informes')
            ->addArgument('coach-name', InputArgument::OPTIONAL, 'Nombre del entrenador (opcional, para el informe PDF)')
            ->setHelp(
                'Crea el usuario del dashboard si no existe, o actualiza su contraseña y '.
                'datos si el username ya existe (UPSERT por username). Pensado para un '.
                'único usuario; se puede volver a ejecutar tantas veces como haga falta. '.
                'La contraseña NUNCA se acepta como argumento: siempre se pide de forma '.
                'interactiva y oculta, para que no quede en el historial de la shell ni '.
                'sea visible en `ps aux`. Requiere una terminal interactiva (TTY): al '.
                'ejecutar vía `docker compose exec`, no uses el flag `-T`.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Alta / actualización del usuario del dashboard');

        $username = $input->getArgument('username') ?? $io->ask('Usuario', null, function ($v) {
            if (!\is_string($v) || '' === trim($v)) {
                throw new \RuntimeException('El usuario no puede estar vacío.');
            }

            return trim($v);
        });

        $question = new Question('Contraseña');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $password = $io->askQuestion($question);
        if (!\is_string($password) || \strlen($password) < 10) {
            $io->error('La contraseña debe tener al menos 10 caracteres.');

            return Command::FAILURE;
        }

        $displayName = $input->getArgument('display-name') ?? $io->ask('Nombre a mostrar', $username);
        $coachName = $input->getArgument('coach-name') ?? $io->ask('Nombre del entrenador (opcional)', null);

        // AppUser transitorio solo para pasar por el hasher configurado en
        // security.yaml (password_hashers: App\Security\AppUser: auto).
        $transientUser = new AppUser(0, $username, '', $displayName, $coachName ?: null);
        $hash = $this->hasher->hashPassword($transientUser, $password);

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO app_user (username, password_hash, display_name, coach_name)
                VALUES (:username, :password_hash, :display_name, :coach_name)
                ON CONFLICT (username) DO UPDATE SET
                    password_hash = EXCLUDED.password_hash,
                    display_name  = EXCLUDED.display_name,
                    coach_name    = EXCLUDED.coach_name,
                    updated_at    = now()
                SQL,
            [
                'username' => $username,
                'password_hash' => $hash,
                'display_name' => $displayName,
                'coach_name' => $coachName ?: null,
            ],
        );

        $io->success(sprintf('Usuario "%s" listo. Ya puedes iniciar sesión en /login.', $username));

        return Command::SUCCESS;
    }
}
