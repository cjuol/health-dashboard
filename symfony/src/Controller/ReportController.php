<?php

namespace App\Controller;

use App\Message\GenerateReport;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
            'SELECT id, date_from, date_to, status, notes, created_at
             FROM report ORDER BY id DESC LIMIT 50'
        );

        return $this->render('report/index.html.twig', ['reports' => $reports]);
    }

    #[Route('/informes', name: 'report_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $from = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $request->request->get('desde'));
        $to = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $request->request->get('hasta'));
        if (!$from || !$to || $from > $to) {
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
        // recoge el worker (informes largos sin bloquear la petición).
        $this->bus->dispatch(new GenerateReport($id));

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
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('informe-%s-a-%s.pdf', $report['date_from'], $report['date_to']),
        );

        return $response;
    }
}
