<?php

namespace App\Controller;

use App\Repository\HealthRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vistas de detalle: una métrica, rango libre, granularidad fina.
 *   /detalle/pasos   — apilado por fuente + heatmap día×hora (turnos visibles) + pisos
 *   /detalle/sueno   — fases, score, respiración, SpO2
 *   /detalle/cuerpo  — peso, % grasa, masa muscular, % agua, masa ósea
 *   /detalle/carga   — FC reposo, estrés, HRV, minutos de intensidad, VO2max, BMR
 *   /actividades     — listado enriquecido (TE, carga, ritmo, SWOLF) con filtros
 *   /actividad/{id}  — sesión: zonas FC, parciales/laps, series de fuerza
 */
final class DetailController extends AbstractController
{
    private const METRICS = ['pasos', 'sueno', 'cuerpo', 'carga'];

    public function __construct(private readonly HealthRepository $repo)
    {
    }

    #[Route('/detalle/{metric}', name: 'detail', requirements: ['metric' => 'pasos|sueno|cuerpo|carga'], methods: ['GET'])]
    public function detail(string $metric, Request $request): Response
    {
        [$from, $to] = $this->range($request, defaultDays: 30);

        $data = match ($metric) {
            'pasos' => [
                'by_source' => $this->repo->dailyStepsBySource($from, $to),
                'daily' => $this->repo->dailySteps($from, $to),
                'floors' => $this->repo->dailyFloors($from, $to),
                'heatmap' => $this->heatmap($from, $to),
            ],
            'sueno' => ['sleep' => $this->repo->sleep($from, $to)],
            'cuerpo' => ['body' => $this->repo->bodyComposition($from, $to)],
            'carga' => [
                'daily' => $this->repo->garminDaily($from, $to),
                'hrv' => $this->repo->hrv($from, $to),
                'intensity' => $this->repo->weeklyIntensity($from, $to),
                'vo2max' => $this->repo->vo2max($from, $to),
            ],
        };
        if ('pasos' === $metric) {
            $data['step_days'] = $this->stepDayRows($data['daily'], $data['by_source'], $data['floors']);
        }

        return $this->render('detail/index.html.twig', [
            'metric' => $metric,
            'metrics' => self::METRICS,
            'from' => $from,
            'to' => $to,
            'data' => $data,
        ]);
    }

    #[Route('/actividades', name: 'activities', methods: ['GET'])]
    public function activities(Request $request): Response
    {
        [$from, $to] = $this->range($request, defaultDays: 30);
        $type = $request->query->get('tipo');

        return $this->render('activity/index.html.twig', [
            'from' => $from,
            'to' => $to,
            'type' => $type,
            'types' => $this->repo->activityTypes(),
            'activities' => $this->repo->activitiesDetailed($from, $to, $type),
        ]);
    }

    #[Route('/actividad/{id}', name: 'activity_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $activity = $this->repo->activityById($id);
        if (null === $activity) {
            throw $this->createNotFoundException('Actividad no encontrada.');
        }

        $zones = $this->repo->activityHrZones($id);
        $totalZoneSecs = array_sum(array_column($zones, 'secs_in_zone')) ?: 1;
        foreach ($zones as &$z) {
            $z['pct'] = round($z['secs_in_zone'] / $totalZoneSecs * 100, 1);
        }

        return $this->render('activity/show.html.twig', [
            'a' => $activity,
            'zones' => $zones,
            'splits' => $this->repo->activitySplits($id),
            'sets' => $this->repo->strengthSets([$id]),
            'is_swim' => str_contains((string) $activity['activity_type'], 'swim'),
        ]);
    }

    /** Reorganiza la matriz día×hora en filas por día para el heatmap. */
    private function heatmap(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = [];
        $max = 1;
        foreach ($this->repo->stepsHeatmap($from, $to) as $r) {
            $rows[$r['day']][$r['hour']] = $r;
            $max = max($max, (int) $r['steps']);
        }
        krsort($rows); // días recientes arriba

        return ['rows' => $rows, 'max' => $max];
    }

    /**
     * Combina dailySteps() (meta/% cumplido) con dailyStepsBySource() (reparto
     * Garmin/móvil) y dailyFloors() (pisos subidos) en una fila por día, para
     * la lista de días que acompaña al heatmap. Día más reciente primero.
     */
    private function stepDayRows(array $daily, array $bySource, array $floors): array
    {
        $sourceByDay = [];
        foreach ($bySource as $r) {
            $sourceByDay[$r['day']] = $r;
        }
        $floorsByDay = [];
        foreach ($floors as $r) {
            $floorsByDay[$r['day']] = $r['floors'];
        }

        $rows = [];
        foreach ($daily as $r) {
            $s = $sourceByDay[$r['day']] ?? null;
            $rows[] = [
                'day' => $r['day'],
                'phone_steps' => (int) ($s['phone_steps'] ?? 0),
                'garmin_steps' => (int) ($s['garmin_steps'] ?? 0),
                'phone_distance_m' => null !== ($s['phone_distance_m'] ?? null) ? (float) $s['phone_distance_m'] : null,
                'daily_goal' => (int) $r['daily_goal'],
                'pct_goal' => (float) $r['pct_goal'],
                'goal_met' => (bool) $r['goal_met'],
                'floors' => null !== ($floorsByDay[$r['day']] ?? null) ? (int) $floorsByDay[$r['day']] : null,
            ];
        }
        usort($rows, static fn (array $a, array $b) => $b['day'] <=> $a['day']);

        return $rows;
    }

    private function range(Request $request, int $defaultDays): array
    {
        $to = $this->parseDate((string) $request->query->get('hasta'))
            ?? new \DateTimeImmutable('today');
        $from = $this->parseDate((string) $request->query->get('desde'))
            ?? $to->modify(sprintf('-%d days', $defaultDays - 1));

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /**
     * Parseo estricto de 'Y-m-d': createFromFormat corrige fechas imposibles
     * por desbordamiento (p.ej. 2026-13-45 se cuela como una fecha válida).
     * Se descarta cualquier resultado cuyo round-trip no coincida exacto.
     */
    private function parseDate(string $input): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $input);

        return ($d && $d->format('Y-m-d') === $input) ? $d : null;
    }
}
