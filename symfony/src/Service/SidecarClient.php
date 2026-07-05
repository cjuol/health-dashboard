<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente del sidecar Python. Patrón invertido del spec: Symfony POSTea los
 * datos ya fusionados a /render y recibe el PDF binario. El sidecar no toca
 * la base de datos para renderizar.
 */
final class SidecarClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'SIDECAR_URL')] private readonly string $baseUrl,
        #[Autowire(env: 'SIDECAR_TOKEN')] private readonly string $token,
    ) {
    }

    /** @return string bytes del PDF */
    public function render(array $payload): string
    {
        $response = $this->http->request('POST', rtrim($this->baseUrl, '/').'/render', [
            'headers' => ['X-Auth-Token' => $this->token],
            'json' => $payload,
            'timeout' => 120, // rangos largos con gráficas tardan
        ]);

        return $response->getContent(); // lanza excepción si no es 2xx
    }
}
