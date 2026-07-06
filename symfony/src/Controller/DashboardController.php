<?php

namespace App\Controller;

use App\Repository\HealthRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dashboard principal v2. Tres niveles de lectura:
 *   1. Semáforo de recuperación (¿entreno fuerte hoy o modero?)
 *   2. Tarjetas con contexto (pasos vs objetivo, semana vs pauta, tendencias)
 *   3. Gráficas del bloque a 30 días fijos (el detalle fino vive en /detalle/*)
 */
final class DashboardController extends AbstractController
{
    public function __construct(private readonly HealthRepository $repo)
    {
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
        $weekTargets = $this->weekTargets($weekly, $this->repo->mesocyclesInRange($weekFrom, $to));
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
            'traffic' => $this->trafficLight(),
            'cards' => $this->cards($steps, $weekly, $mesocycle, $body),
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

    /**
     * Semáforo de recuperación: cruza el estado de HRV con el sueño de anoche.
     * Verde = adelante; ámbar = modula; rojo = día de moverse suave.
     */
    private function trafficLight(): array
    {
        $r = $this->repo->recoveryToday();
        $hrv = $r['hrv'];
        $sleep = $r['sleep'];

        $hrvBad = null !== $hrv
            && \in_array(strtoupper((string) $hrv['status']), ['LOW', 'UNBALANCED', 'POOR'], true);
        $sleepH = $sleep ? round($sleep['duration_s'] / 3600, 1) : null;
        $sleepBad = null !== $sleepH && $sleepH < 6.0;
        $sleepMeh = null !== $sleep && null !== $sleep['score'] && $sleep['score'] < 60;

        if (null === $hrv && null === $sleep) {
            $level = 'off';
            $msg = 'Sin datos de recuperación todavía.';
        } elseif ($hrvBad && ($sleepBad || $sleepMeh)) {
            $level = 'red';
            $msg = 'HRV degradada y mal sueño: hoy toca suave (técnica, movilidad, pasos).';
        } elseif ($hrvBad || $sleepBad) {
            $level = 'amber';
            $msg = $hrvBad
                ? 'HRV por debajo de tu línea base: modula la carga de la sesión.'
                : 'Sueño corto anoche: baja una marcha si la sesión es exigente.';
        } else {
            $level = 'green';
            $msg = 'Recuperación en orden: día bueno para la sesión pautada.';
        }

        return [
            'level' => $level,
            'message' => $msg,
            'hrv_ms' => $hrv['last_night_avg_ms'] ?? null,
            'hrv_status' => $hrv['status'] ?? null,
            'hrv_base' => $hrv ? ($hrv['baseline_low_ms'].'–'.$hrv['baseline_high_ms']) : null,
            'sleep_h' => $sleepH,
            'sleep_score' => $sleep['score'] ?? null,
        ];
    }

    /**
     * Pauta (fuerza/natación) aplicable a cada semana, indexada por week_start.
     * Una semana se juzga contra el mesociclo vigente en su propia fecha, no
     * contra el mesociclo actual: si el bloque cambió a mitad de semana, las
     * semanas del bloque anterior no se evalúan con la pauta nueva.
     */
    private function weekTargets(array $weekly, array $mesocycles): array
    {
        $targets = [];
        foreach ($weekly as $w) {
            foreach ($mesocycles as $m) {
                if ($w['week_start'] >= $m['date_from'] && $w['week_start'] <= $m['date_to']) {
                    $targets[$w['week_start']] = [
                        'strength' => (int) $m['strength_sessions_week'],
                        'swim' => (int) $m['swim_sessions_week'],
                    ];
                    break;
                }
            }
        }

        return $targets;
    }

    /** Tarjetas con contexto: número + referencia + progreso. */
    private function cards(array $steps, array $weekly, ?array $mesocycle, array $body): array
    {
        $today = end($steps) ?: null;
        $isToday = $today && $today['day'] === (new \DateTimeImmutable('today'))->format('Y-m-d');
        $stepsToday = $isToday ? (int) $today['steps'] : 0;
        $goal = $isToday ? (int) $today['daily_goal'] : (int) ($mesocycle['steps_goal'] ?? 10000);

        $week = end($weekly) ?: null;

        // Tendencia de peso: media de los últimos 7 registros y delta semanal
        $weights = array_values(array_filter(array_column($body, 'weight_kg')));
        $last7 = \array_slice($weights, -7);
        $trend = $last7 ? round(array_sum($last7) / \count($last7), 1) : null;

        return [
            'steps_today' => $stepsToday,
            'steps_goal' => $goal,
            'steps_pct' => $goal > 0 ? min(100, (int) round($stepsToday / $goal * 100)) : 0,
            'week_strength' => (int) ($week['strength_sessions'] ?? 0),
            'week_swim' => (int) ($week['swim_sessions'] ?? 0),
            'target_strength' => (int) ($mesocycle['strength_sessions_week'] ?? 0),
            'target_swim' => (int) ($mesocycle['swim_sessions_week'] ?? 0),
            'weight_trend' => $trend,
            'weight_delta' => $week['weight_delta'] ?? null,
        ];
    }
}
