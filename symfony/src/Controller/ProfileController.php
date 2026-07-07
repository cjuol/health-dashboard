<?php

namespace App\Controller;

use App\Repository\HealthRepository;
use App\Security\AppUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Perfil del usuario: datos (nombre, entrenador), cambio de contraseña y
 * medidas corporales manuales (báscula/cinta métrica). Todo protegido por el
 * firewall "main" (autenticación obligatoria) y con CSRF en cada POST.
 */
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly HealthRepository $repo,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly Security $security,
    ) {
    }

    #[Route('/perfil', name: 'perfil_index', methods: ['GET'])]
    public function index(#[CurrentUser] AppUser $user): Response
    {
        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'measurements' => $this->repo->bodyMeasurements(30),
            'today' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'step_goal' => $this->repo->currentStepGoal(),
        ]);
    }

    #[Route('/perfil/datos', name: 'perfil_datos', methods: ['POST'])]
    public function updateDatos(Request $request, #[CurrentUser] AppUser $user): Response
    {
        if (!$this->isCsrfTokenValid('perfil_datos', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $displayName = trim((string) $request->request->get('display_name'));
        $coachName = trim((string) $request->request->get('coach_name'));

        if ('' === $displayName) {
            $this->addFlash('error', 'El nombre a mostrar no puede estar vacío.');

            return $this->redirectToRoute('perfil_index');
        }

        $this->db->executeStatement(
            'UPDATE app_user SET display_name = :display_name, coach_name = :coach_name WHERE id = :id',
            [
                'display_name' => $displayName,
                'coach_name' => '' !== $coachName ? $coachName : null,
                'id' => $user->getId(),
            ],
        );

        $this->addFlash('success', 'Datos actualizados.');

        return $this->redirectToRoute('perfil_index');
    }

    #[Route('/perfil/objetivo', name: 'perfil_objetivo', methods: ['POST'])]
    public function updateStepGoal(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('perfil_objetivo', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $raw = trim((string) $request->request->get('daily_goal'));
        if (!ctype_digit($raw) || (int) $raw < 1000 || (int) $raw > 50000) {
            $this->addFlash('error', 'El objetivo debe ser un número entero entre 1.000 y 50.000 pasos.');

            return $this->redirectToRoute('perfil_index');
        }
        $goal = (int) $raw;

        // Vigencia desde hoy: el histórico (días anteriores) mantiene el
        // objetivo que estuviera vigente en su momento, ver step_goal en
        // db/01_schema.sql y el patrón de db/04_mesociclo.sql.
        $this->db->executeStatement(
            'INSERT INTO step_goal (valid_from, daily_goal, note)
             VALUES (CURRENT_DATE, :goal, :note)
             ON CONFLICT (valid_from) DO UPDATE SET
                 daily_goal = EXCLUDED.daily_goal,
                 note = EXCLUDED.note',
            ['goal' => $goal, 'note' => 'ajustado desde el perfil'],
        );

        $this->addFlash('success', sprintf('Objetivo diario actualizado a %s pasos, a partir de hoy.', number_format($goal, 0, ',', '.')));

        return $this->redirectToRoute('perfil_index');
    }

    #[Route('/perfil/password', name: 'perfil_password', methods: ['POST'])]
    public function updatePassword(Request $request, #[CurrentUser] AppUser $user): Response
    {
        if (!$this->isCsrfTokenValid('perfil_password', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $current = (string) $request->request->get('current_password');
        $new = (string) $request->request->get('new_password');
        $newRepeat = (string) $request->request->get('new_password_repeat');

        if (!$this->hasher->isPasswordValid($user, $current)) {
            $this->addFlash('error', 'La contraseña actual no es correcta.');

            return $this->redirectToRoute('perfil_index');
        }
        if ($new !== $newRepeat) {
            $this->addFlash('error', 'Las contraseñas nuevas no coinciden.');

            return $this->redirectToRoute('perfil_index');
        }
        if (\strlen($new) < 10) {
            $this->addFlash('error', 'La contraseña nueva debe tener al menos 10 caracteres.');

            return $this->redirectToRoute('perfil_index');
        }

        $newHash = $this->hasher->hashPassword($user, $new);
        $this->db->executeStatement(
            'UPDATE app_user SET password_hash = :hash WHERE id = :id',
            ['hash' => $newHash, 'id' => $user->getId()],
        );

        // El token de sesión guarda el AppUser cargado al inicio de la
        // petición, con el hash ANTIGUO. En la siguiente petición,
        // refreshUser() trae el hash nuevo y AbstractToken::hasUserChanged()
        // lo detecta como "usuario cambiado", invalidando el token y
        // expulsando a /login pese a que la contraseña es correcta.
        // Reautenticamos aquí mismo con el hash ya actualizado para que la
        // sesión quede coherente con la base de datos.
        $freshUser = new AppUser(
            $user->getId(),
            $user->getUserIdentifier(),
            $newHash,
            $user->getDisplayName(),
            $user->getCoachName(),
        );
        $this->security->login($freshUser);

        $this->addFlash('success', 'Contraseña actualizada.');

        return $this->redirectToRoute('perfil_index');
    }

    #[Route('/perfil/medidas', name: 'perfil_medida_guardar', methods: ['POST'])]
    public function saveMeasurement(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('perfil_medidas', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $dayInput = (string) $request->request->get('day');
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $dayInput);
        if (!$day || $day->format('Y-m-d') !== $dayInput) {
            $this->addFlash('error', 'Fecha inválida.');

            return $this->redirectToRoute('perfil_index');
        }

        // Etiqueta (para el flash de error) y rango admisible por columna:
        // weight_kg NUMERIC(5,2), body_fat_pct NUMERIC(4,1), *_cm NUMERIC(5,1).
        // Sin este chequeo, un valor fuera de rango revienta en un overflow
        // de Postgres (500) y una entrada no numérica ('abc') se colaba como
        // 0.0 vía (float), sobrescribiendo datos reales en el upsert.
        $fields = [
            'weight_kg' => ['Peso', 20.0, 400.0],
            'body_fat_pct' => ['% Grasa', 1.0, 75.0],
            'neck_cm' => ['Cuello', 10.0, 300.0],
            'chest_cm' => ['Pecho', 10.0, 300.0],
            'waist_cm' => ['Cintura', 10.0, 300.0],
            'hip_cm' => ['Cadera', 10.0, 300.0],
            'arm_cm' => ['Brazo', 10.0, 300.0],
            'thigh_cm' => ['Muslo', 10.0, 300.0],
        ];

        $values = [];
        foreach ($fields as $field => [$label, $min, $max]) {
            $raw = trim((string) $request->request->get($field));
            if ('' === $raw) {
                $values[$field] = null;
                continue;
            }

            $normalized = str_replace(',', '.', $raw);
            if (!is_numeric($normalized)) {
                $this->addFlash('error', sprintf('%s: el valor "%s" no es un número válido.', $label, $raw));

                return $this->redirectToRoute('perfil_index');
            }

            $value = (float) $normalized;
            if ($value < $min || $value > $max) {
                $this->addFlash('error', sprintf('%s: debe estar entre %s y %s.', $label, $min, $max));

                return $this->redirectToRoute('perfil_index');
            }

            $values[$field] = $value;
        }

        $note = trim((string) $request->request->get('note'));

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO body_measurement
                    (day, weight_kg, body_fat_pct, neck_cm, chest_cm, waist_cm, hip_cm, arm_cm, thigh_cm, note)
                VALUES
                    (:day, :weight_kg, :body_fat_pct, :neck_cm, :chest_cm, :waist_cm, :hip_cm, :arm_cm, :thigh_cm, :note)
                ON CONFLICT (day) DO UPDATE SET
                    weight_kg    = EXCLUDED.weight_kg,
                    body_fat_pct = EXCLUDED.body_fat_pct,
                    neck_cm      = EXCLUDED.neck_cm,
                    chest_cm     = EXCLUDED.chest_cm,
                    waist_cm     = EXCLUDED.waist_cm,
                    hip_cm       = EXCLUDED.hip_cm,
                    arm_cm       = EXCLUDED.arm_cm,
                    thigh_cm     = EXCLUDED.thigh_cm,
                    note         = EXCLUDED.note,
                    updated_at   = now()
                SQL,
            [
                'day' => $day->format('Y-m-d'),
                'weight_kg' => $values['weight_kg'],
                'body_fat_pct' => $values['body_fat_pct'],
                'neck_cm' => $values['neck_cm'],
                'chest_cm' => $values['chest_cm'],
                'waist_cm' => $values['waist_cm'],
                'hip_cm' => $values['hip_cm'],
                'arm_cm' => $values['arm_cm'],
                'thigh_cm' => $values['thigh_cm'],
                'note' => '' !== $note ? $note : null,
            ],
        );

        $this->addFlash('success', sprintf('Medida del %s guardada.', $day->format('d/m/Y')));

        return $this->redirectToRoute('perfil_index');
    }

    #[Route('/perfil/medidas/{day}/eliminar', name: 'perfil_medida_eliminar', requirements: ['day' => '\d{4}-\d{2}-\d{2}'], methods: ['POST'])]
    public function deleteMeasurement(Request $request, string $day): Response
    {
        if (!$this->isCsrfTokenValid('perfil_medidas', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // El requirement de la ruta (\d{4}-\d{2}-\d{2}) solo valida el
        // formato, no que la fecha exista: sin este chequeo, algo como
        // "2026-13-45" llega intacto y \DateTimeImmutable la corrige (o
        // Postgres la rechaza) provocando un 500 en vez de un error de
        // usuario.
        $parsedDay = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (!$parsedDay || $parsedDay->format('Y-m-d') !== $day) {
            $this->addFlash('error', 'Fecha inválida.');

            return $this->redirectToRoute('perfil_index');
        }

        $this->db->executeStatement('DELETE FROM body_measurement WHERE day = :day', ['day' => $day]);
        $this->addFlash('success', sprintf('Medida del %s eliminada.', $parsedDay->format('d/m/Y')));

        return $this->redirectToRoute('perfil_index');
    }
}
