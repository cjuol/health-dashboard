<?php

namespace App\Controller;

use App\Repository\HealthRepository;
use App\Service\DashboardMetrics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dashboard principal v2. Tres niveles de lectura:
 *   1. Semáforo de recuperación (¿entreno fuerte hoy o modero?)
 *   2. Tarjetas con contexto (pasos vs objetivo, semana vs pauta, tendencias)
 *   3. Gráficas del bloque a 30 días fijos (el detalle fino vive en /detalle/*)
 *
 * El cálculo de semáforo/tarjetas/pauta semanal vive en DashboardMetrics,
 * compartido con el panel de invitado de un enlace compartido (ver
 * ShareController), que ancla el mismo cálculo al último día de su rango
 * congelado en vez de a la fecha real.
 */
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly HealthRepository $repo,
        private readonly DashboardMetrics $metrics,
    ) {
    }

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $to = new \DateTimeImmutable('today');
        $from = $to->modify('-29 days');

        $steps = $this->repo->dailySteps($from, $to);
        $stepsBySource = $this->repo->dailyStepsBySource($from, $to);
        $sleep = $this->repo->sleep($from, $to);
        $hrv = $this->repo->hrv($from, $to);
        $daily = $this->repo->garminDaily($from, $to);
        $body = $this->repo->bodyComposition($from, $to);
        $intensity = $this->repo->weeklyIntensity($from, $to);
        $activities = $this->repo->activitiesDetailed($from, $to, null);

        $mesocycle = $this->repo->currentMesocycle($to);
        $weekFrom = (clone $from)->modify('monday this week');
        $weekly = $this->repo->weeklySummary($weekFrom, $to);
        $weekTargets = $this->metrics->weekTargets($weekly, $this->repo->mesocyclesInRange($weekFrom, $to));
        $mesoWeek = null;
        if (null !== $mesocycle) {
            $start = new \DateTimeImmutable($mesocycle['date_from']);
            $end = new \DateTimeImmutable($mesocycle['date_to']);
            $mesoWeek = [
                'current' => intdiv((int) $start->diff(min($to, $end))->format('%a'), 7) + 1,
                'total' => (int) ceil(((int) $start->diff($end)->format('%a') + 1) / 7),
            ];
        }

        return $this->render('dashboard/index.html.twig', [
            'from' => $from,
            'to' => $to,
            'traffic' => $this->metrics->trafficLight($to),
            'cards' => $this->metrics->cards($steps, $weekly, $mesocycle, $body, $to),
            'mesocycle' => $mesocycle,
            'meso_week' => $mesoWeek,
            'weekly' => $weekly,
            'week_targets' => $weekTargets,
            'sync' => $this->repo->syncStatus(),
            'activities' => \array_slice($activities, 0, 10),
            'series' => [
                'steps' => $steps,
                'steps_source' => $stepsBySource,
                'sleep' => $sleep,
                'hrv' => $hrv,
                'daily' => $daily,
                'body' => $body,
                'intensity' => $intensity,
            ],
        ]);
    }
}
