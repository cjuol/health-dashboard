<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/** Login del dashboard (único usuario en la práctica). */
final class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // El propio firewall (form_login) intercepta el POST antes de que
        // este controlador llegue a ejecutarse: aquí solo se pinta el
        // formulario, con el último usuario introducido y el error si lo hay.
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        // Interceptada por el listener de logout del firewall; nunca se ejecuta.
        throw new \LogicException('Esta ruta la gestiona el logout del firewall de seguridad.');
    }
}
