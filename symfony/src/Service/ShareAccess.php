<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resuelve y valida enlaces de invitado (tabla share_link). Un enlace es
 * válido si existe, no está revocado y no ha caducado; la sesión concedida
 * (contraseña ya verificada) se comprueba aparte, en cada petición, para que
 * revocar un enlace corte el acceso al instante aunque el invitado ya
 * hubiera iniciado sesión.
 */
final class ShareAccess
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Enlace por token si existe y sigue vigente (no caducado, no revocado); null en cualquier otro caso. */
    public function findValid(string $token): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, token, label, password_hash, date_from, date_to, expires_at, revoked_at,
                    failed_attempts, last_failed_at
             FROM share_link WHERE token = :token',
            ['token' => $token],
        );

        if (false === $row || null !== $row['revoked_at']) {
            return null;
        }

        if (new \DateTimeImmutable($row['expires_at']) < new \DateTimeImmutable('now')) {
            return null;
        }

        return $row;
    }

    /**
     * Exige que el invitado tenga la sesión concedida para $token (contraseña
     * verificada en ShareController::check) Y que el enlace siga vigente.
     * Se comprueba en cada petición a las vistas de invitado: revocar o dejar
     * caducar un enlace corta el acceso aunque la sesión siguiera marcada
     * como concedida. Un enlace inválido/caducado/revocado lanza 404 para no
     * revelar si el token existió alguna vez; un enlace válido pero sin
     * sesión concedida (p.ej. sesión expirada) lanza ShareGrantRequiredException
     * para que el controlador reenvíe al formulario de contraseña en vez de
     * mostrar un 404 al invitado legítimo.
     */
    public function requireGranted(Request $request, string $token): array
    {
        $share = $this->findValid($token);
        if (null === $share) {
            throw new NotFoundHttpException('Enlace no válido o caducado.');
        }

        if (true !== $request->getSession()->get('share_'.$token)) {
            throw new ShareGrantRequiredException($token);
        }

        return $share;
    }
}
