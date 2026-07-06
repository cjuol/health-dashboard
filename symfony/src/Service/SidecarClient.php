<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
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
            'timeout' => 120, // rangos largos con gráficas tardan (WeasyPrint puede ser lento)
        ]);

        try {
            return $response->getContent(); // lanza excepción si no es 2xx
        } catch (HttpExceptionInterface $e) {
            // El mensaje por defecto de Symfony ("HTTP 500 returned for ...")
            // no incluye el cuerpo de la respuesta: sin él, un token inválido
            // (401) o un fallo de WeasyPrint/plantilla (500) son indistinguibles
            // en el error persistido en report.error. Lo añadimos truncado.
            $body = mb_substr($e->getResponse()->getContent(false), 0, 500);

            throw new \RuntimeException(sprintf(
                'Sidecar respondió %d en /render: %s',
                $e->getResponse()->getStatusCode(),
                $body !== '' ? $body : '(cuerpo vacío)',
            ), 0, $e);
        }
    }
}
