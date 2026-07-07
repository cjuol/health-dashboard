<?php

namespace App\Controller;

use App\Repository\HealthRepository;
use App\Service\DashboardMetrics;
use App\Service\ShareAccess;
use App\Service\ShareGrantRequiredException;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Zona pública de solo lectura para el invitado de un enlace compartido.
 * El firewall "main" deja pasar /compartir/ sin autenticación (ver
 * access_control en security.yaml): el invitado navega con sesión anónima,
 * nunca como AppUser, y solo ve datos recortados al rango congelado del
 * enlace (date_from/date_to fijados por el propietario al crearlo).
 */
final class ShareController extends AbstractController
{
    private const METRICS = ['pasos', 'sueno', 'cuerpo', 'carga'];

    public function __construct(
        private readonly HealthRepository $repo,
        private readonly DashboardMetrics $metrics,
        private readonly ShareAccess $access,
        private readonly Connection $db,
    ) {
    }

    #[Route('/compartir/{token}', name: 'share_entry', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function entry(string $token, Request $request): Response
    {
        $share = $this->access->findValid($token);
        if (null === $share) {
            return $this->render('share/invalid.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        // Ya tiene sesión concedida (p.ej. recarga del enlace): directo al panel.
        if (true === $request->getSession()->get('share_'.$token)) {
            return $this->redirectToRoute('share_dashboard', ['token' => $token]);
        }

        return $this->render('share/login.html.twig', ['share' => $share, 'token' => $token]);
    }

    #[Route('/compartir/{token}', name: 'share_check', requirements: ['token' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function check(string $token, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('share_login', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $share = $this->access->findValid($token);
        if (null === $share) {
            return $this->render('share/invalid.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $session = $request->getSession();

        // Freno contra fuerza bruta contado en la propia fila share_link
        // (no en la sesión PHP): un atacante que no reenvíe la cookie de
        // sesión reiniciaría un contador en sesión, pero no puede tocar
        // failed_attempts/last_failed_at. 5 fallos consecutivos exigen
        // esperar 60 s. Tradeoff aceptado: el contador es por enlace, así
        // que un spammer puede bloquear el enlace al invitado legítimo en
        // ventanas de 60 s — asumible para un dashboard personal.
        if ($share['failed_attempts'] >= 5
            && null !== $share['last_failed_at']
            && new \DateTimeImmutable($share['last_failed_at']) > new \DateTimeImmutable('-60 seconds')
        ) {
            $this->addFlash('error', 'Demasiados intentos, espera un minuto.');

            return $this->redirectToRoute('share_entry', ['token' => $token]);
        }

        $password = (string) $request->request->get('password');
        if (!password_verify($password, $share['password_hash'])) {
            $this->db->executeStatement(
                'UPDATE share_link
                 SET failed_attempts = CASE
                         WHEN last_failed_at IS NULL OR last_failed_at < now() - interval \'60 seconds\'
                         THEN 1
                         ELSE failed_attempts + 1
                     END,
                     last_failed_at = now()
                 WHERE id = :id',
                ['id' => $share['id']],
            );
            $this->addFlash('error', 'Contraseña incorrecta.');

            return $this->redirectToRoute('share_entry', ['token' => $token]);
        }

        $this->db->executeStatement(
            'UPDATE share_link SET failed_attempts = 0, last_failed_at = NULL WHERE id = :id',
            ['id' => $share['id']],
        );

        // Regenerar el id de sesión al cambiar de privilegio (igual que
        // form_login), para evitar fijación de sesión.
        $session->migrate(true);
        $session->set('share_'.$token, true);

        return $this->redirectToRoute('share_dashboard', ['token' => $token]);
    }

    #[Route('/compartir/{token}/panel', name: 'share_dashboard', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function dashboard(string $token, Request $request): Response
    {
        $share = $this->shareOrRedirect($request, $token);
        if ($share instanceof Response) {
            return $share;
        }
        [$from, $to] = $this->frozenRange($share);

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

        return $this->render('share/dashboard.html.twig', [
            'share' => $share,
            'token' => $token,
            'from' => $from,
            'to' => $to,
            'traffic' => $this->metrics->trafficLight($to),
            'cards' => $this->metrics->cards($steps, $weekly, $mesocycle, $body, $to),
            'mesocycle' => $mesocycle,
            'meso_week' => $mesoWeek,
            'weekly' => $weekly,
            'week_targets' => $weekTargets,
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

    #[Route('/compartir/{token}/actividades', name: 'share_activities', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function activities(string $token, Request $request): Response
    {
        $share = $this->shareOrRedirect($request, $token);
        if ($share instanceof Response) {
            return $share;
        }
        [$from, $to] = $this->clampedRange($request, $share);
        $type = $request->query->get('tipo');

        return $this->render('share/activities.html.twig', [
            'share' => $share,
            'token' => $token,
            'from' => $from,
            'to' => $to,
            'type' => $type,
            'types' => $this->repo->activityTypes($this->frozenFrom($share), $this->frozenTo($share)),
            'activities' => $this->repo->activitiesDetailed($from, $to, $type),
        ]);
    }

    #[Route('/compartir/{token}/actividad/{id}', name: 'share_activity_show', requirements: ['token' => '[a-f0-9]{64}', 'id' => '\d+'], methods: ['GET'])]
    public function activityShow(string $token, int $id, Request $request): Response
    {
        $share = $this->shareOrRedirect($request, $token);
        if ($share instanceof Response) {
            return $share;
        }
        $activity = $this->repo->activityById($id);
        if (null === $activity || !$this->withinFrozenRange($activity['start_time'], $share)) {
            throw $this->createNotFoundException('Actividad no encontrada.');
        }

        $zones = $this->repo->activityHrZones($id);
        $totalZoneSecs = array_sum(array_column($zones, 'secs_in_zone')) ?: 1;
        foreach ($zones as &$z) {
            $z['pct'] = round($z['secs_in_zone'] / $totalZoneSecs * 100, 1);
        }

        return $this->render('share/activity_show.html.twig', [
            'share' => $share,
            'token' => $token,
            'a' => $activity,
            'zones' => $zones,
            'splits' => $this->repo->activitySplits($id),
            'sets' => $this->repo->strengthSets([$id]),
            'is_swim' => str_contains((string) $activity['activity_type'], 'swim'),
        ]);
    }

    #[Route('/compartir/{token}/detalle/{metric}', name: 'share_detail', requirements: ['token' => '[a-f0-9]{64}', 'metric' => 'pasos|sueno|cuerpo|carga'], methods: ['GET'])]
    public function detail(string $token, string $metric, Request $request): Response
    {
        $share = $this->shareOrRedirect($request, $token);
        if ($share instanceof Response) {
            return $share;
        }
        [$from, $to] = $this->clampedRange($request, $share);

        $data = match ($metric) {
            'pasos' => [
                'by_source' => $this->repo->dailyStepsBySource($from, $to),
                'daily' => $this->repo->dailySteps($from, $to),
                'heatmap' => $this->heatmap($from, $to),
            ],
            'sueno' => ['sleep' => $this->repo->sleep($from, $to)],
            'cuerpo' => ['body' => $this->repo->bodyComposition($from, $to)],
            'carga' => [
                'daily' => $this->repo->garminDaily($from, $to),
                'hrv' => $this->repo->hrv($from, $to),
                'intensity' => $this->repo->weeklyIntensity($from, $to),
            ],
        };
        if ('pasos' === $metric) {
            $data['step_days'] = $this->stepDayRows($data['daily'], $data['by_source']);
        }

        return $this->render('share/detail.html.twig', [
            'share' => $share,
            'token' => $token,
            'metric' => $metric,
            'metrics' => self::METRICS,
            'from' => $from,
            'to' => $to,
            'data' => $data,
        ]);
    }

    /**
     * Envoltorio único para las 4 rutas de invitado (panel/actividades/
     * actividad/detalle): centraliza aquí, en un solo sitio, la conversión
     * de "enlace válido pero sin sesión concedida" en un redirect al
     * formulario de contraseña, en vez de duplicar el try/catch en cada
     * ruta. Un enlace inválido/caducado/revocado sigue devolviendo 404
     * (lanzado dentro de ShareAccess::requireGranted).
     */
    private function shareOrRedirect(Request $request, string $token): array|Response
    {
        try {
            return $this->access->requireGranted($request, $token);
        } catch (ShareGrantRequiredException) {
            return $this->redirectToRoute('share_entry', ['token' => $token]);
        }
    }

    private function frozenRange(array $share): array
    {
        return [$this->frozenFrom($share), $this->frozenTo($share)];
    }

    private function frozenFrom(array $share): \DateTimeImmutable
    {
        return new \DateTimeImmutable($share['date_from']);
    }

    private function frozenTo(array $share): \DateTimeImmutable
    {
        return new \DateTimeImmutable($share['date_to']);
    }

    /**
     * El invitado puede acotar el rango vía desde/hasta (igual que las
     * vistas del propietario), pero nunca ampliarlo más allá del rango
     * congelado del enlace: se calcula la intersección y, si queda vacía
     * (p.ej. un desde/hasta totalmente fuera de rango), se cae de vuelta al
     * rango completo del enlace en lugar de devolver una vista sin datos.
     */
    private function clampedRange(Request $request, array $share): array
    {
        $frozenFrom = $this->frozenFrom($share);
        $frozenTo = $this->frozenTo($share);

        $reqTo = $this->parseDate((string) $request->query->get('hasta'));
        $reqFrom = $this->parseDate((string) $request->query->get('desde'));

        $to = $reqTo ?? $frozenTo;
        $from = $reqFrom ?? $frozenFrom;
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $from = max($from, $frozenFrom);
        $to = min($to, $frozenTo);

        if ($from > $to) {
            return [$frozenFrom, $frozenTo];
        }

        return [$from, $to];
    }

    /** Igual que el filtro de garmin_activity por start_time en HealthRepository::activitiesDetailed(). */
    private function withinFrozenRange(string $startTime, array $share): bool
    {
        $start = new \DateTimeImmutable($startTime);

        return $start >= $this->frozenFrom($share) && $start < $this->frozenTo($share)->modify('+1 day');
    }

    /** Reorganiza la matriz día×hora en filas por día para el heatmap (mismo criterio que DetailController). */
    private function heatmap(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = [];
        $max = 1;
        foreach ($this->repo->stepsHeatmap($from, $to) as $r) {
            $rows[$r['day']][$r['hour']] = $r;
            $max = max($max, (int) $r['steps']);
        }
        krsort($rows);

        return ['rows' => $rows, 'max' => $max];
    }

    /** Combina dailySteps() + dailyStepsBySource() en filas por día (mismo criterio que DetailController). */
    private function stepDayRows(array $daily, array $bySource): array
    {
        $sourceByDay = [];
        foreach ($bySource as $r) {
            $sourceByDay[$r['day']] = $r;
        }

        $rows = [];
        foreach ($daily as $r) {
            $s = $sourceByDay[$r['day']] ?? null;
            $rows[] = [
                'day' => $r['day'],
                'phone_steps' => (int) ($s['phone_steps'] ?? 0),
                'garmin_steps' => (int) ($s['garmin_steps'] ?? 0),
                'daily_goal' => (int) $r['daily_goal'],
                'pct_goal' => (float) $r['pct_goal'],
                'goal_met' => (bool) $r['goal_met'],
            ];
        }
        usort($rows, static fn (array $a, array $b) => $b['day'] <=> $a['day']);

        return $rows;
    }

    /**
     * Parseo estricto de 'Y-m-d' (mismo criterio que DetailController::parseDate()).
     */
    private function parseDate(string $input): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $input);

        return ($d && $d->format('Y-m-d') === $input) ? $d : null;
    }
}
