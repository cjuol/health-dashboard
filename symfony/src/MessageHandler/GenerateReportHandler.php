<?php

namespace App\MessageHandler;

use App\Message\GenerateReport;
use App\Service\ReportDataBuilder;
use App\Service\SidecarClient;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Genera el PDF de un informe: lee la fila, arma el payload fusionado,
 * lo POSTea al sidecar (/render) y guarda el PDF en var/reports.
 * Idempotente respecto a reintentos: regenerar sobrescribe el mismo archivo.
 */
#[AsMessageHandler]
final class GenerateReportHandler
{
    public function __construct(
        private readonly Connection $db,
        private readonly ReportDataBuilder $builder,
        private readonly SidecarClient $sidecar,
        private readonly string $reportsDir,
    ) {
    }

    public function __invoke(GenerateReport $message): void
    {
        $report = $this->db->fetchAssociative(
            'SELECT id, date_from, date_to, notes FROM report WHERE id = :id',
            ['id' => $message->reportId],
        );
        if (false === $report) {
            return; // fila borrada entre el dispatch y el consumo
        }

        try {
            $payload = $this->builder->build(
                new \DateTimeImmutable($report['date_from']),
                new \DateTimeImmutable($report['date_to']),
                $report['notes'],
            );

            $pdf = $this->sidecar->render($payload);

            if (!is_dir($this->reportsDir)) {
                mkdir($this->reportsDir, 0775, true);
            }
            $path = sprintf('%s/informe-%d.pdf', $this->reportsDir, $report['id']);
            file_put_contents($path, $pdf);

            $this->db->executeStatement(
                "UPDATE report SET status = 'complete', pdf_path = :p, error = NULL WHERE id = :id",
                ['p' => $path, 'id' => $report['id']],
            );
        } catch (\Throwable $e) {
            $this->db->executeStatement(
                "UPDATE report SET status = 'failed', error = :e WHERE id = :id",
                ['e' => mb_substr($e->getMessage(), 0, 2000), 'id' => $report['id']],
            );
            throw $e;
        }
    }
}
