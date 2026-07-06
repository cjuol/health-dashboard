<?php

namespace App\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Proveedor de usuarios contra la tabla app_user vía DBAL (sin ORM, igual
 * que el resto de repositorios del proyecto).
 */
final class AppUserProvider implements UserProviderInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        try {
            $row = $this->db->fetchAssociative(
                'SELECT id, username, password_hash, display_name, coach_name
                 FROM app_user WHERE username = :u',
                ['u' => $identifier],
            );
        } catch (TableNotFoundException) {
            // Despliegue en el que db/07_users.sql todavía no se ha aplicado
            // a mano (volumen ya existente): sin tabla no hay usuario, así
            // que se trata igual que credenciales inexistentes en vez de
            // dejar que el 500 de Doctrine llegue al usuario.
            throw new UserNotFoundException(sprintf('Usuario "%s" no encontrado.', $identifier));
        }

        if (false === $row) {
            throw new UserNotFoundException(sprintf('Usuario "%s" no encontrado.', $identifier));
        }

        return $this->hydrate($row);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof AppUser) {
            throw new UnsupportedUserException(sprintf('Clase de usuario no soportada: %s.', $user::class));
        }

        $row = $this->db->fetchAssociative(
            'SELECT id, username, password_hash, display_name, coach_name
             FROM app_user WHERE id = :id',
            ['id' => $user->getId()],
        );

        if (false === $row) {
            throw new UserNotFoundException(sprintf('El usuario #%d ya no existe.', $user->getId()));
        }

        return $this->hydrate($row);
    }

    public function supportsClass(string $class): bool
    {
        return AppUser::class === $class || is_subclass_of($class, AppUser::class);
    }

    private function hydrate(array $row): AppUser
    {
        return new AppUser(
            (int) $row['id'],
            $row['username'],
            $row['password_hash'],
            $row['display_name'],
            $row['coach_name'],
        );
    }
}
