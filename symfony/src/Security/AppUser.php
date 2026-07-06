<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Usuario de la app (fila de app_user). No es una entidad Doctrine: se
 * hidrata a mano en AppUserProvider a partir de una consulta DBAL, en línea
 * con el resto del proyecto (sin ORM).
 */
final class AppUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        private readonly int $id,
        private readonly string $username,
        private readonly string $passwordHash,
        private readonly string $displayName,
        private readonly ?string $coachName,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getCoachName(): ?string
    {
        return $this->coachName;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function eraseCredentials(): void
    {
        // No hay credenciales en texto plano que borrar: el password_hash
        // es lo único que se guarda y ya es el hash.
    }
}
