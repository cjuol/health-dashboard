<?php

namespace App\Controller;

use App\Repository\HealthRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Editor de series de fuerza: overlay manual sobre lo que trae Garmin (ver
 * db/11_strength_overrides.sql). NUNCA escribe en garmin_strength_set — solo
 * en strength_set_override y strength_session_note, para no perder las
 * ediciones en el siguiente re-sync del sidecar. Ninguna ruta cuelga de
 * /compartir/, así que el catch-all de security.yaml ya las deja solo para
 * el propietario autenticado.
 */
final class StrengthController extends AbstractController
{
    private const CSRF_INTENT = 'fuerza_editar';

    public function __construct(
        private readonly Connection $db,
        private readonly HealthRepository $repo,
    ) {
    }

    #[Route('/fuerza', name: 'strength_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('strength/index.html.twig', [
            'sessions' => $this->repo->strengthSessions(50),
        ]);
    }

    #[Route('/fuerza/{activityId}', name: 'strength_show', requirements: ['activityId' => '\d+'], methods: ['GET'])]
    public function show(int $activityId): Response
    {
        $activity = $this->requireStrengthActivity($activityId);

        return $this->render('strength/show.html.twig', [
            'a' => $activity,
            'sets' => $this->repo->strengthSetsAllEffective($activityId),
            'session_notes' => $this->repo->strengthSessionNote($activityId),
        ]);
    }

    #[Route('/fuerza/{activityId}/guardar', name: 'strength_save', requirements: ['activityId' => '\d+'], methods: ['POST'])]
    public function save(int $activityId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $this->requireStrengthActivity($activityId);

        $sets = $this->repo->strengthSetsAllEffective($activityId);
        $input = $request->request->all('sets');

        $upserts = [];
        $deletes = [];

        foreach ($sets as $set) {
            $order = (int) $set['set_order'];
            $row = $input[$order] ?? [];

            $exerciseOverride = null;
            $repsOverride = null;
            $weightOverride = null;
            $rir = null;
            $rpe = null;

            if ('ACTIVE' === $set['set_type']) {
                $rawName = trim((string) ($row['exercise_name'] ?? ''));
                if (mb_strlen($rawName) > 128) {
                    $this->addFlash('error', sprintf('Serie #%d: el nombre del ejercicio no puede superar 128 caracteres.', $order));

                    return $this->redirectToRoute('strength_show', ['activityId' => $activityId]);
                }
                $submittedName = '' !== $rawName ? $rawName : null;
                $exerciseOverride = $submittedName === $set['garmin_exercise_name'] ? null : $submittedName;

                $rawReps = trim((string) ($row['reps'] ?? ''));
                if ('' !== $rawReps) {
                    if (!ctype_digit($rawReps) || (int) $rawReps > 999) {
                        $this->addFlash('error', sprintf('Serie #%d: las repeticiones deben ser un número entero entre 0 y 999.', $order));

                        return $this->redirectToRoute('strength_show', ['activityId' => $activityId]);
                    }
                    $repsVal = (int) $rawReps;
                    $originalReps = null !== $set['garmin_reps'] ? (int) $set['garmin_reps'] : null;
                    $repsOverride = $repsVal === $originalReps ? null : $repsVal;
                }

                $rawWeight = trim(str_replace(',', '.', (string) ($row['weight_kg'] ?? '')));
                if ('' !== $rawWeight) {
                    if (!is_numeric($rawWeight) || (float) $rawWeight < 0 || (float) $rawWeight > 500) {
                        $this->addFlash('error', sprintf('Serie #%d: el peso debe ser un número entre 0 y 500 kg.', $order));

                        return $this->redirectToRoute('strength_show', ['activityId' => $activityId]);
                    }
                    $weightVal = round((float) $rawWeight, 2);
                    $originalWeight = null !== $set['garmin_weight_kg'] ? round((float) $set['garmin_weight_kg'], 2) : null;
                    $weightOverride = null !== $originalWeight && abs($weightVal - $originalWeight) < 0.005 ? null : $weightVal;
                }

                [$rir, $rirInvalid] = $this->parseOptionalScale($row['rir'] ?? '');
                if ($rirInvalid) {
                    $this->addFlash('error', sprintf('Serie #%d: el RIR debe ser un número entre 0 y 10.', $order));

                    return $this->redirectToRoute('strength_show', ['activityId' => $activityId]);
                }
                [$rpe, $rpeInvalid] = $this->parseOptionalScale($row['rpe'] ?? '');
                if ($rpeInvalid) {
                    $this->addFlash('error', sprintf('Serie #%d: el RPE debe ser un número entre 0 y 10.', $order));

                    return $this->redirectToRoute('strength_show', ['activityId' => $activityId]);
                }
            }

            // notes es enriquecimiento manual (sin original de Garmin con el
            // que compararse) y está disponible tanto en series ACTIVE como
            // REST.
            $rawNotes = trim((string) ($row['notes'] ?? ''));
            $notes = '' !== $rawNotes ? $rawNotes : null;

            if (null === $exerciseOverride && null === $repsOverride && null === $weightOverride
                && null === $rir && null === $rpe && null === $notes) {
                $deletes[] = $order;
            } else {
                $upserts[$order] = [
                    'exercise_name' => $exerciseOverride,
                    'reps' => $repsOverride,
                    'weight_kg' => $weightOverride,
                    'rir' => $rir,
                    'rpe' => $rpe,
                    'notes' => $notes,
                ];
            }
        }

        $rawSessionNotes = trim((string) $request->request->get('session_notes', ''));
        $sessionNotes = '' !== $rawSessionNotes ? $rawSessionNotes : null;

        $this->db->transactional(function () use ($activityId, $upserts, $deletes, $sessionNotes): void {
            foreach ($upserts as $order => $values) {
                $this->db->executeStatement(
                    <<<'SQL'
                        INSERT INTO strength_set_override
                            (activity_id, set_order, exercise_name, reps, weight_kg, rir, rpe, notes)
                        VALUES
                            (:activity_id, :set_order, :exercise_name, :reps, :weight_kg, :rir, :rpe, :notes)
                        ON CONFLICT (activity_id, set_order) DO UPDATE SET
                            exercise_name = EXCLUDED.exercise_name,
                            reps          = EXCLUDED.reps,
                            weight_kg     = EXCLUDED.weight_kg,
                            rir           = EXCLUDED.rir,
                            rpe           = EXCLUDED.rpe,
                            notes         = EXCLUDED.notes,
                            updated_at    = now()
                        SQL,
                    [
                        'activity_id' => $activityId,
                        'set_order' => $order,
                        'exercise_name' => $values['exercise_name'],
                        'reps' => $values['reps'],
                        'weight_kg' => $values['weight_kg'],
                        'rir' => $values['rir'],
                        'rpe' => $values['rpe'],
                        'notes' => $values['notes'],
                    ],
                );
            }

            if ([] !== $deletes) {
                $this->db->executeStatement(
                    'DELETE FROM strength_set_override WHERE activity_id = :activity_id AND set_order IN (:orders)',
                    ['activity_id' => $activityId, 'orders' => $deletes],
                    ['orders' => ArrayParameterType::INTEGER],
                );
            }

            if (null !== $sessionNotes) {
                $this->db->executeStatement(
                    'INSERT INTO strength_session_note (activity_id, notes) VALUES (:id, :notes)
                     ON CONFLICT (activity_id) DO UPDATE SET notes = EXCLUDED.notes, updated_at = now()',
                    ['id' => $activityId, 'notes' => $sessionNotes],
                );
            } else {
                $this->db->executeStatement('DELETE FROM strength_session_note WHERE activity_id = :id', ['id' => $activityId]);
            }
        });

        $this->addFlash('success', 'Sesión de fuerza actualizada.');

        return $this->redirectToRoute('strength_show', ['activityId' => $activityId]);
    }

    #[Route('/fuerza/{activityId}/series/{setOrder}/revertir', name: 'strength_set_revert', requirements: ['activityId' => '\d+', 'setOrder' => '\d+'], methods: ['POST'])]
    public function revertSet(int $activityId, int $setOrder, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_INTENT, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $this->requireStrengthActivity($activityId);

        $this->db->executeStatement(
            'DELETE FROM strength_set_override WHERE activity_id = :id AND set_order = :order',
            ['id' => $activityId, 'order' => $setOrder],
        );

        $this->addFlash('success', sprintf('Serie #%d restaurada a los valores de Garmin.', $setOrder));

        return $this->redirectToRoute('strength_show', ['activityId' => $activityId]);
    }

    private function requireStrengthActivity(int $activityId): array
    {
        $activity = $this->repo->activityById($activityId);
        if (null === $activity || !$activity['is_strength']) {
            throw $this->createNotFoundException('Sesión de fuerza no encontrada.');
        }

        return $activity;
    }

    /**
     * Parsea un campo numérico opcional (RIR/RPE) en [0, 10], admitiendo
     * coma decimal. Devuelve [valor|null, huboError].
     *
     * @return array{0: ?float, 1: bool}
     */
    private function parseOptionalScale(mixed $raw): array
    {
        $value = trim(str_replace(',', '.', (string) $raw));
        if ('' === $value) {
            return [null, false];
        }
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 10) {
            return [null, true];
        }

        return [round((float) $value, 1), false];
    }
}
