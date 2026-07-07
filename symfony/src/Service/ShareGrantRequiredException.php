<?php

namespace App\Service;

/**
 * El token del enlace es válido, pero la sesión del invitado no tiene la
 * contraseña verificada (sesión nueva o expirada). A diferencia de un enlace
 * inválido/caducado/revocado (NotFoundHttpException), este caso corresponde
 * reenviar al formulario de contraseña de share_entry, no a un 404.
 */
final class ShareGrantRequiredException extends \RuntimeException
{
    public function __construct(private readonly string $token)
    {
        parent::__construct('Falta la sesión concedida para este enlace.');
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
