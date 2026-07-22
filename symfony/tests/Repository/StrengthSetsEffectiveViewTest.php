<?php

namespace App\Tests\Repository;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests de la vista v_strength_sets_effective (db/11_strength_overrides.sql)
 * contra health_test. Usa un activity_id de marcador (fuera del rango de
 * cualquier id real de Garmin) y limpia sus propias filas en tearDown vía
 * ON DELETE CASCADE desde garmin_activity.
 */
final class StrengthSetsEffectiveViewTest extends KernelTestCase
{
    private const ACTIVITY_ID = 900000001;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $db */
        $db = self::getContainer()->get(Connection::class);
        $this->db = $db;

        $this->db->insert('garmin_activity', [
            'activity_id' => self::ACTIVITY_ID,
            'activity_type' => 'strength_training',
            'activity_name' => 'repo-test fuerza',
            'start_time' => '2031-07-01 08:00:00+02',
            'is_strength' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // ON DELETE CASCADE se lleva garmin_strength_set, strength_set_override
        // y strength_session_note de este activity_id.
        $this->db->executeStatement('DELETE FROM garmin_activity WHERE activity_id = :id', ['id' => self::ACTIVITY_ID]);
        parent::tearDown();
    }

    public function testViewReturnsGarminValuesWhenNoOverride(): void
    {
        $this->db->insert('garmin_strength_set', [
            'activity_id' => self::ACTIVITY_ID,
            'set_order' => 1,
            'exercise_name' => 'Press banca',
            'reps' => 8,
            'weight_kg' => 60.0,
            'set_type' => 'ACTIVE',
        ]);

        $row = $this->db->fetchAssociative(
            'SELECT * FROM v_strength_sets_effective WHERE activity_id = :id AND set_order = 1',
            ['id' => self::ACTIVITY_ID],
        );

        self::assertNotFalse($row);
        self::assertSame('Press banca', $row['exercise_name']);
        self::assertSame(8, (int) $row['reps']);
        self::assertEqualsWithDelta(60.0, (float) $row['weight_kg'], 0.001);
        self::assertSame('Press banca', $row['garmin_exercise_name']);
        self::assertFalse((bool) $row['is_edited']);
        self::assertFalse((bool) $row['exercise_name_edited']);
        self::assertFalse((bool) $row['reps_edited']);
        self::assertFalse((bool) $row['weight_kg_edited']);
        self::assertNull($row['rir']);
        self::assertNull($row['rpe']);
        self::assertNull($row['notes']);
    }

    public function testViewReturnsOverrideValuesAndFlagsWhenPresent(): void
    {
        $this->db->insert('garmin_strength_set', [
            'activity_id' => self::ACTIVITY_ID,
            'set_order' => 2,
            'exercise_name' => 'Sentadilla',
            'reps' => 5,
            'weight_kg' => 100.0,
            'set_type' => 'ACTIVE',
        ]);
        $this->db->insert('strength_set_override', [
            'activity_id' => self::ACTIVITY_ID,
            'set_order' => 2,
            // Solo reps corregido: exercise_name/weight_kg quedan NULL (sin override).
            'reps' => 6,
            'rir' => 2.5,
            'rpe' => 8.0,
            'notes' => 'sentí molestia en la rodilla',
        ]);

        $row = $this->db->fetchAssociative(
            'SELECT * FROM v_strength_sets_effective WHERE activity_id = :id AND set_order = 2',
            ['id' => self::ACTIVITY_ID],
        );

        self::assertNotFalse($row);
        // Override aplicado en reps.
        self::assertSame(6, (int) $row['reps']);
        self::assertTrue((bool) $row['reps_edited']);
        // Sin override en nombre/peso: se conserva el valor de Garmin.
        self::assertSame('Sentadilla', $row['exercise_name']);
        self::assertFalse((bool) $row['exercise_name_edited']);
        self::assertEqualsWithDelta(100.0, (float) $row['weight_kg'], 0.001);
        self::assertFalse((bool) $row['weight_kg_edited']);
        // Originales de Garmin siempre visibles, editados o no.
        self::assertSame(5, (int) $row['garmin_reps']);
        self::assertEqualsWithDelta(2.5, (float) $row['rir'], 0.001);
        self::assertEqualsWithDelta(8.0, (float) $row['rpe'], 0.001);
        self::assertSame('sentí molestia en la rodilla', $row['notes']);
        self::assertTrue((bool) $row['is_edited']);
    }

    public function testResyncUpsertDoesNotOverwriteOverride(): void
    {
        $this->db->insert('garmin_strength_set', [
            'activity_id' => self::ACTIVITY_ID,
            'set_order' => 3,
            'exercise_name' => 'Peso muerto',
            'category' => 'deadlift',
            'reps' => 5,
            'weight_kg' => 120.0,
            'duration_s' => 45.0,
            'set_type' => 'ACTIVE',
        ]);
        $this->db->insert('strength_set_override', [
            'activity_id' => self::ACTIVITY_ID,
            'set_order' => 3,
            'exercise_name' => 'Peso muerto rumano',
            'weight_kg' => 125.0,
        ]);

        // Mismo INSERT ... ON CONFLICT DO UPDATE que sidecar/garmin_sync.py
        // ejecuta en cada re-sync (ver _sync_strength_sets), simulando que
        // Garmin devuelve los mismos datos de nuevo (o corregidos) sobre
        // garmin_strength_set. NUNCA debe tocar strength_set_override.
        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO garmin_strength_set (activity_id, set_order, exercise_name, category,
                                                 reps, weight_kg, duration_s, set_type, raw)
                VALUES (:activity_id, :set_order, :exercise_name, :category,
                        :reps, :weight_kg, :duration_s, :set_type, :raw)
                ON CONFLICT (activity_id, set_order) DO UPDATE SET
                    exercise_name = EXCLUDED.exercise_name, category = EXCLUDED.category,
                    reps = EXCLUDED.reps, weight_kg = EXCLUDED.weight_kg,
                    duration_s = EXCLUDED.duration_s, set_type = EXCLUDED.set_type,
                    raw = EXCLUDED.raw
                SQL,
            [
                'activity_id' => self::ACTIVITY_ID,
                'set_order' => 3,
                'exercise_name' => 'Peso muerto',
                'category' => 'deadlift',
                'reps' => 6, // Garmin "corrige" las reps tras el re-sync
                'weight_kg' => 120.0,
                'duration_s' => 46.0,
                'set_type' => 'ACTIVE',
                'raw' => '{}',
            ],
        );

        $row = $this->db->fetchAssociative(
            'SELECT * FROM v_strength_sets_effective WHERE activity_id = :id AND set_order = 3',
            ['id' => self::ACTIVITY_ID],
        );

        self::assertNotFalse($row);
        // El override de nombre y peso sobrevive al re-sync.
        self::assertSame('Peso muerto rumano', $row['exercise_name']);
        self::assertEqualsWithDelta(125.0, (float) $row['weight_kg'], 0.001);
        // reps no tenía override: sigue el nuevo valor de Garmin tras el re-sync.
        self::assertSame(6, (int) $row['reps']);
        // Los originales de Garmin también reflejan el re-sync.
        self::assertSame('Peso muerto', $row['garmin_exercise_name']);
        self::assertSame(6, (int) $row['garmin_reps']);
    }
}
