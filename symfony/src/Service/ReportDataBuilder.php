<?php

namespace App\Service;

use App\Repository\HealthRepository;

/**
 * Fusiona en un único payload todo lo que necesita el informe del entrenador:
 * pasos fusionados (vista v_steps_daily), sueño, HRV, FC reposo, peso,
 * actividades y detalle de fuerza. El sidecar solo renderiza; toda la
 * lógica de datos vive aquí (lado Symfony).
 */
final class ReportDataBuilder
{
    public function __construct(private readonly HealthRepository $repo)
    {
    }

    public function build(\DateTimeInterface $from, \DateTimeInterface $to, ?string $notes): array
    {
        $steps = $this->repo->dailySteps($from, $to);
        $sleep = $this->indexByDay($this->repo->sleep($from, $to));
        $hrv = $this->indexByDay($this->repo->hrv($from, $to));
        $daily = $this->indexByDay($this->repo->garminDaily($from, $to));
        $body = $this->indexByDay($this->repo->bodyComposition($from, $to));
        $activities = array_reverse($this->repo->activities($from, $to)); // cronológico en el PDF
        $sets = $this->repo->strengthSets(array_column($activities, 'activity_id'));
        $mesocycle = $this->repo->currentMesocycle($to);
        $weekly = $this->repo->weeklySummary($from, $to);

        // Días con sesión (fuerza o natación) → para el semáforo de recuperación
        $trainingDays = [];
        foreach ($activities as $a) {
            $d = (new \DateTimeImmutable($a['start_time']))->format('Y-m-d');
            $trainingDays[$d] = true;
        }

        // Tabla diaria unificada
        $dias = [];
        foreach ($steps as $s) {
            $d = $s['day'];
            $sleepH = isset($sleep[$d]['duration_s']) ? round($sleep[$d]['duration_s'] / 3600, 1) : null;
            $hrvStatus = $hrv[$d]['status'] ?? null;

            // Semáforo: día de entreno con HRV degradada o sueño corto la noche
            // previa → señal para que Alex module la carga.
            $lowHrv = null !== $hrvStatus
                && \in_array(strtoupper($hrvStatus), ['LOW', 'UNBALANCED', 'POOR'], true);
            $alerta = isset($trainingDays[$d]) && ($lowHrv || (null !== $sleepH && $sleepH < 6.0));

            $dias[] = [
                'day' => $d,
                // Sin step_goal vigente para el día (fresh install / días previos
                // al primer registro) el LEFT JOIN LATERAL de v_steps_daily deja
                // daily_goal/pct_goal/goal_met en NULL: coalesced a 0/false para
                // que nunca llegue un null al payload del sidecar.
                'steps' => (int) ($s['steps'] ?? 0),
                'goal' => (int) ($s['daily_goal'] ?? 0),
                'pct_goal' => (float) ($s['pct_goal'] ?? 0),
                'goal_met' => (bool) ($s['goal_met'] ?? false),
                'phone_buckets' => (int) ($s['phone_buckets'] ?? 0), // horas cubiertas por el móvil (cocina)
                'sleep_h' => $sleepH,
                'sleep_score' => $sleep[$d]['score'] ?? null,
                'hrv_ms' => $hrv[$d]['last_night_avg_ms'] ?? null,
                'hrv_status' => $hrvStatus,
                'resting_hr' => $daily[$d]['resting_hr'] ?? null,
                'weight_kg' => $body[$d]['weight_kg'] ?? null,
                'alerta' => $alerta,
            ];
        }

        // Actividades + fuerza anidada + ritmo de natación
        $setsByActivity = [];
        foreach ($sets as $set) {
            $setsByActivity[$set['activity_id']][] = $set;
        }
        $acts = array_map(function ($a) use ($setsByActivity) {
            $isSwim = str_contains((string) $a['activity_type'], 'swim');
            $ritmo = null;
            if ($isSwim && $a['distance_m'] > 0 && $a['duration_s'] > 0) {
                $secPer100 = (int) round($a['duration_s'] / ($a['distance_m'] / 100));
                $ritmo = sprintf('%d:%02d /100m', intdiv($secPer100, 60), $secPer100 % 60);
            }

            return [
                'fecha' => (new \DateTimeImmutable($a['start_time']))->format('d/m H:i'),
                'tipo' => $a['activity_type'],
                'nombre' => $a['activity_name'],
                'duracion_min' => $a['duration_s'] ? (int) round($a['duration_s'] / 60) : null,
                'distancia_km' => $a['distance_m'] ? round($a['distance_m'] / 1000, 2) : null,
                'ritmo' => $ritmo,
                'fc_media' => $a['avg_hr'],
                'fc_max' => $a['max_hr'],
                'kcal' => $a['calories'],
                'fuerza' => array_map(fn ($s) => [
                    'ejercicio' => $s['exercise_name'],
                    'reps' => $s['reps'],
                    'peso_kg' => $s['weight_kg'],
                ], $setsByActivity[$a['activity_id']] ?? []),
            ];
        }, $activities);

        // Contexto de mesociclo + semana del bloque
        $meso = null;
        if (null !== $mesocycle) {
            $start = new \DateTimeImmutable($mesocycle['date_from']);
            $end = new \DateTimeImmutable($mesocycle['date_to']);
            $meso = [
                'numero' => (int) $mesocycle['id'],
                'objetivo' => $mesocycle['objective'],
                'desde' => $start->format('d/m/Y'),
                'hasta' => $end->format('d/m/Y'),
                'semana_actual' => intdiv((int) $start->diff(min($to, $end))->format('%a'), 7) + 1,
                'semanas_total' => (int) ceil(((int) $start->diff($end)->format('%a') + 1) / 7),
                'fuerza_semana' => (int) $mesocycle['strength_sessions_week'],
                'natacion_semana' => (int) $mesocycle['swim_sessions_week'],
                'pasos_objetivo' => null !== $mesocycle['steps_goal'] ? (int) $mesocycle['steps_goal'] : null,
                'notas' => $mesocycle['notes'],
            ];
        }

        $semanas = array_map(fn ($w) => [
            'inicio' => (new \DateTimeImmutable($w['week_start']))->format('d/m'),
            'pasos_media' => null !== $w['avg_steps'] ? (int) $w['avg_steps'] : null,
            'dias_objetivo' => null !== $w['days_goal_met'] ? (int) $w['days_goal_met'] : null,
            'dias_datos' => null !== $w['days_with_data'] ? (int) $w['days_with_data'] : null,
            'fuerza' => (int) $w['strength_sessions'],
            'natacion' => (int) $w['swim_sessions'],
            'natacion_km' => $w['swim_m'] > 0 ? round($w['swim_m'] / 1000, 1) : null,
            'peso_medio' => $w['avg_weight'],
            'delta_peso' => $w['weight_delta'],
            'sleep_score' => $w['avg_sleep_score'],
            'hrv' => $w['avg_hrv_ms'],
        ], $weekly);

        return [
            'atleta' => 'Cristóbal',
            'entrenador' => 'Alex Hornero',
            'rango' => ['desde' => $from->format('d/m/Y'), 'hasta' => $to->format('d/m/Y')],
            'generado' => (new \DateTimeImmutable())->format('d/m/Y H:i'),
            'mesociclo' => $meso,
            'semanas' => $semanas,
            'resumen' => $this->summary($dias, $acts),
            'dias' => $dias,
            'actividades' => $acts,
            'notas' => $notes,
        ];
    }

    private function summary(array $dias, array $acts): array
    {
        $avg = static function (array $values): ?float {
            $values = array_filter($values, fn ($v) => null !== $v);

            return $values ? round(array_sum($values) / \count($values), 1) : null;
        };

        $weights = array_values(array_filter(array_column($dias, 'weight_kg')));
        $porTipo = [];
        foreach ($acts as $a) {
            $porTipo[$a['tipo']] = ($porTipo[$a['tipo']] ?? 0) + 1;
        }

        return [
            'media_pasos' => $avg(array_column($dias, 'steps')),
            'dias_objetivo' => \count(array_filter($dias, fn ($d) => $d['goal_met'])),
            'total_dias' => \count($dias),
            'media_sueno_h' => $avg(array_column($dias, 'sleep_h')),
            'media_sleep_score' => $avg(array_column($dias, 'sleep_score')),
            'media_hrv' => $avg(array_column($dias, 'hrv_ms')),
            'media_fc_reposo' => $avg(array_column($dias, 'resting_hr')),
            'peso_inicio' => $weights[0] ?? null,
            'peso_fin' => $weights ? end($weights) : null,
            'n_actividades' => \count($acts),
            'actividades_por_tipo' => $porTipo,
            'tiempo_total_min' => (int) array_sum(array_filter(array_column($acts, 'duracion_min'))),
        ];
    }

    private function indexByDay(array $rows): array
    {
        return array_column($rows, null, 'day');
    }
}
