<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestión de enlaces de invitado ("compartir"), solo para el propietario
 * (firewall "main", autenticación obligatoria). Cada enlace fija su propia
 * contraseña y un rango de fechas congelado en el momento de crearlo: la
 * URL y la contraseña se comparten con el invitado por fuera de la app
 * (WhatsApp, etc.), la app nunca las envía.
 */
final class ShareAdminController extends AbstractController
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/perfil/compartir', name: 'share_admin', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('share_admin/index.html.twig', [
            'links' => $this->db->fetchAllAssociative(
                'SELECT id, token, label, date_from, date_to, expires_at, revoked_at, created_at
                 FROM share_link ORDER BY created_at DESC'
            ),
        ]);
    }

    #[Route('/perfil/compartir', name: 'share_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('share_create', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $label = trim((string) $request->request->get('label'));
        $password = (string) $request->request->get('password');
        $fromInput = (string) $request->request->get('date_from');
        $toInput = (string) $request->request->get('date_to');
        $expiresInput = (string) $request->request->get('expires_at');

        if ('' === $label) {
            $this->addFlash('error', 'La etiqueta no puede estar vacía.');

            return $this->redirectToRoute('share_admin');
        }
        if (\strlen($password) < 8) {
            $this->addFlash('error', 'La contraseña debe tener al menos 8 caracteres.');

            return $this->redirectToRoute('share_admin');
        }

        $dateFrom = $this->parseDate($fromInput);
        $dateTo = $this->parseDate($toInput);
        if (null === $dateFrom || null === $dateTo) {
            $this->addFlash('error', 'Rango de fechas inválido.');

            return $this->redirectToRoute('share_admin');
        }
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $expiresAt = $this->parseExpiry($expiresInput);
        if (null === $expiresAt) {
            $this->addFlash('error', 'Fecha de caducidad inválida.');

            return $this->redirectToRoute('share_admin');
        }

        $token = bin2hex(random_bytes(32));
        // password_hash() nativo en vez de UserPasswordHasherInterface: esa
        // interfaz exige un UserInterface (AppUser, el propietario) y aquí la
        // contraseña no pertenece a ningún usuario del sistema, solo a un
        // enlace efímero. No hace falta un hasher nuevo en security.yaml.
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $this->db->executeStatement(
            'INSERT INTO share_link (token, label, password_hash, date_from, date_to, expires_at)
             VALUES (:token, :label, :password_hash, :date_from, :date_to, :expires_at)',
            [
                'token' => $token,
                'label' => $label,
                'password_hash' => $passwordHash,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:sP'),
            ],
        );

        $url = $request->getSchemeAndHttpHost().$this->generateUrl('share_entry', ['token' => $token]);
        $this->addFlash('success', sprintf('Enlace creado para "%s". URL: %s', $label, $url));

        return $this->redirectToRoute('share_admin');
    }

    #[Route('/perfil/compartir/{id}/revocar', name: 'share_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('share_revoke', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $this->db->executeStatement(
            'UPDATE share_link SET revoked_at = now() WHERE id = :id AND revoked_at IS NULL',
            ['id' => $id],
        );

        $this->addFlash('success', 'Enlace revocado.');

        return $this->redirectToRoute('share_admin');
    }

    private function parseDate(string $input): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $input);

        return ($d && $d->format('Y-m-d') === $input) ? $d : null;
    }

    /**
     * Acepta 'Y-m-d' (caducidad al final de ese día) o 'Y-m-d\TH:i', el
     * formato que envía un <input type="datetime-local">.
     */
    private function parseExpiry(string $input): ?\DateTimeImmutable
    {
        $onlyDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $input);
        if ($onlyDate && $onlyDate->format('Y-m-d') === $input) {
            return $onlyDate->setTime(23, 59, 59);
        }

        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $input);
        if ($dateTime && $dateTime->format('Y-m-d\TH:i') === $input) {
            return $dateTime;
        }

        return null;
    }
}
