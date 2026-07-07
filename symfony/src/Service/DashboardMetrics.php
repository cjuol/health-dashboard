<?php

namespace App\Service;

use App\Repository\HealthRepository;

/**
 * Cálculos del dashboard (semáforo de recuperación, pauta semanal, tarjetas)
 * compartidos entre el dashboard del propietario (DashboardController) y el
 * panel de invitado de un enlace compartido (ShareController). Ambos anclan
 * el concepto de "hoy" a una fecha distinta: el propietario a la fecha
 * real, el invitado al último día del rango congelado del enlace.
 */
final class DashboardMetrics
{
    public function __construct(private readonly HealthRepository $repo)
    {
    }

    /**
     * Semáforo de recuperación: cruza el estado de HRV con el sueño de la
     * noche previa a $asOf. Verde = adelante; ámbar = modula; rojo = día de
     * moverse suave.
     */
    public function trafficLight(\DateTimeInterface $asOf): array
    {
        $r = $this->repo->recoveryAsOf($asOf);
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
     * Pauta (fuerza/natación) aplicable a cada semana, indexada por
     * week_start. Una semana se juzga contra el mesociclo vigente en su
     * propia fecha, no contra el mesociclo actual: si el bloque cambió a
     * mitad de semana, las semanas del bloque anterior no se evalúan con la
     * pauta nueva.
     */
    public function weekTargets(array $weekly, array $mesocycles): array
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

    /**
     * Tarjetas con contexto: número + referencia + progreso, ancladas a
     * $anchor ("hoy" para el propietario, último día del rango congelado
     * para el invitado de un enlace compartido).
     */
    public function cards(array $steps, array $weekly, ?array $mesocycle, array $body, \DateTimeInterface $anchor): array
    {
        $today = end($steps) ?: null;
        $isToday = $today && $today['day'] === $anchor->format('Y-m-d');
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
