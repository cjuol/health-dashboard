<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Botón manual del nav para forzar la ingesta de Garmin sin esperar al
 * cron. A diferencia del disparo silencioso de MovementApiController, aquí
 * sí interesa informar al usuario del resultado (lanzado, ya en curso o
 * fallo), así que se llama con force=true y se traduce la respuesta del
 * sidecar en un flash. Protegido por el catch-all IS_AUTHENTICATED_FULLY
 * de security.yaml (^/), igual que el resto de páginas del propietario.
 */
final class SyncController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'SIDECAR_URL')]
        private readonly string $sidecarUrl,
        #[Autowire(env: 'SIDECAR_TOKEN')]
        private readonly string $sidecarToken,
    ) {
    }

    #[Route('/sync/garmin', name: 'sync_garmin', methods: ['POST'])]
    public function __invoke(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('sync-garmin', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido.');

            return $this->redirectBack($request);
        }

        try {
            $response = $this->http->request('POST', rtrim($this->sidecarUrl, '/').'/sync-garmin', [
                'headers' => ['X-Auth-Token' => $this->sidecarToken],
                'query' => ['force' => 'true'],
                'timeout' => 5,
            ]);
            $status = $response->toArray(false)['status'] ?? null;

            match ($status) {
                'lanzado' => $this->addFlash('success', 'Sincronización de Garmin lanzada.'),
                'en_curso' => $this->addFlash('success', 'Ya hay una sincronización en curso.'),
                default => $this->addFlash('error', 'No se pudo lanzar la sincronización de Garmin.'),
            };
        } catch (\Throwable) {
            // Sidecar caído, timeout o respuesta no válida: se informa al
            // usuario en vez de fallar en silencio, ya que aquí sí ha
            // pulsado un botón esperando un resultado.
            $this->addFlash('error', 'No se pudo lanzar la sincronización de Garmin.');
        }

        return $this->redirectBack($request);
    }

    /**
     * Vuelve a la página desde la que se pulsó el botón (el nav está en
     * todas partes). Si el Referer no apunta a este mismo host, mejor no
     * seguirlo y caer al dashboard.
     */
    private function redirectBack(Request $request): RedirectResponse
    {
        $referer = $request->headers->get('referer');
        if (\is_string($referer)) {
            $host = parse_url($referer, PHP_URL_HOST);
            if ($host === $request->getHost()) {
                return $this->redirect($referer);
            }
        }

        return $this->redirectToRoute('dashboard');
    }
}
