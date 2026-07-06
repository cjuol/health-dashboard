<?php

namespace App\Controller;

use App\Message\GenerateReport;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Informes para el entrenador: crear (rango + notas), listar y descargar. */
final class ReportController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/informes', name: 'report_index', methods: ['GET'])]
    public function index(): Response
    {
        $reports = $this->db->fetchAllAssociative(
            'SELECT id, date_from, date_to, status, notes, error, created_at
             FROM report ORDER BY id DESC LIMIT 50'
        );

        return $this->render('report/index.html.twig', ['reports' => $reports]);
    }

    #[Route('/informes', name: 'report_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        // Antes de la sesión con cookie (login por formulario) este POST no
        // tenía protección CSRF porque no hacía falta: sin autenticación de
        // sesión no había nada que un sitio externo pudiera "montar" a
        // nombre del usuario. Con cookies de sesión, un formulario
        // auto-submit en otro dominio podría generar informes sin permiso;
        // se exige el mismo token que el resto de POSTs de /perfil.
        if (!$this->isCsrfTokenValid('report_create', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido.');

            return $this->redirectToRoute('report_index');
        }

        // createFromFormat('Y-m-d', ...) sin '!' hace overflow-correction (p.ej.
        // "2026-02-30" se cuela como 2 de marzo): forzamos formato estricto y
        // validamos con un ida-y-vuelta contra el string original.
        $desdeInput = (string) $request->request->get('desde');
        $hastaInput = (string) $request->request->get('hasta');
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $desdeInput);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $hastaInput);
        $fromValid = $from && $from->format('Y-m-d') === $desdeInput;
        $toValid = $to && $to->format('Y-m-d') === $hastaInput;
        if (!$fromValid || !$toValid || $from > $to) {
            $this->addFlash('error', 'Rango de fechas inválido.');

            return $this->redirectToRoute('report_index');
        }

        $this->db->executeStatement(
            'INSERT INTO report (date_from, date_to, notes) VALUES (:f, :t, :n)',
            [
                'f' => $from->format('Y-m-d'),
                't' => $to->format('Y-m-d'),
                'n' => trim((string) $request->request->get('notas')) ?: null,
            ],
        );
        $id = (int) $this->db->lastInsertId();

        // Con el transporte 'sync' se genera aquí mismo; con doctrine:// lo
        // recoge el worker (informes largos sin bloquear la petición). Sobre
        // 'sync', cualquier fallo del handler llega aquí envuelto en
        // HandlerFailedException: el handler ya marcó la fila como 'failed'
        // (con el motivo en la columna error), así que solo evitamos que la
        // excepción reviente la petición con un 500 y avisamos al usuario.
        try {
            $this->bus->dispatch(new GenerateReport($id));
        } catch (HandlerFailedException $e) {
            $reason = $e->getPrevious()?->getMessage() ?? $e->getMessage();
            $this->addFlash('error', sprintf(
                'No se pudo generar el informe #%d: %s',
                $id,
                mb_substr($reason, 0, 300),
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf(
                'No se pudo generar el informe #%d: %s',
                $id,
                mb_substr($e->getMessage(), 0, 300),
            ));
        }

        return $this->redirectToRoute('report_index');
    }

    #[Route('/informes/{id}/descargar', name: 'report_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(int $id): Response
    {
        $report = $this->db->fetchAssociative(
            "SELECT pdf_path, date_from, date_to FROM report WHERE id = :id AND status = 'complete'",
            ['id' => $id],
        );
        if (false === $report || !is_file($report['pdf_path'])) {
            throw $this->createNotFoundException('Informe no disponible.');
        }

        $response = new BinaryFileResponse($report['pdf_path']);
        // Sin symfony/mime instalado, BinaryFileResponse::prepare() intenta
        // adivinar el Content-Type y lanza LogicException ("Mime component
        // no instalado"). Fijarlo explícitamente evita esa ruta por completo.
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('informe-%s-a-%s.pdf', $report['date_from'], $report['date_to']),
        );

        return $response;
    }
}
