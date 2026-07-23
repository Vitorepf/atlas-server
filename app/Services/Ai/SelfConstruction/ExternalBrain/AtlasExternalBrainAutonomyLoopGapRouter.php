<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Routes autonomy-loop gaps into implementable task families for the five
 * stages of an autonomy loop: sensing, deciding, acting, verifying and
 * learning. Each stage maps to a distinct task family with concrete
 * allowed_files and runnable acceptance criteria.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainAutonomyLoopGapRouter
{
    public const SCHEMA = 'atlas.external_brain.autonomy_loop_gap_router.v1';

    public const STAGE_SENSING = 'sensing';
    public const STAGE_DECIDING = 'deciding';
    public const STAGE_ACTING = 'acting';
    public const STAGE_VERIFYING = 'verifying';
    public const STAGE_LEARNING = 'learning';

    public const ALL_STAGES = [
        self::STAGE_SENSING,
        self::STAGE_DECIDING,
        self::STAGE_ACTING,
        self::STAGE_VERIFYING,
        self::STAGE_LEARNING,
    ];

    public const FAMILY_SENSING = 'autonomy_sensing_gap';
    public const FAMILY_DECIDING = 'autonomy_deciding_gap';
    public const FAMILY_ACTING = 'autonomy_acting_gap';
    public const FAMILY_VERIFYING = 'autonomy_verifying_gap';
    public const FAMILY_LEARNING = 'autonomy_learning_gap';

    private const FAMILY_BY_STAGE = [
        self::STAGE_SENSING => self::FAMILY_SENSING,
        self::STAGE_DECIDING => self::FAMILY_DECIDING,
        self::STAGE_ACTING => self::FAMILY_ACTING,
        self::STAGE_VERIFYING => self::FAMILY_VERIFYING,
        self::STAGE_LEARNING => self::FAMILY_LEARNING,
    ];

    private const ALLOWED_FILES_BY_FAMILY = [
        self::FAMILY_SENSING => [
            'app/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeSensor.php',
            'tests/Unit/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeSensorTest.php',
        ],
        self::FAMILY_DECIDING => [
            'app/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeDecisionGate.php',
            'tests/Unit/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeDecisionGateTest.php',
        ],
        self::FAMILY_ACTING => [
            'app/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeActuator.php',
            'tests/Unit/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeActuatorTest.php',
        ],
        self::FAMILY_VERIFYING => [
            'app/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeVerifier.php',
            'tests/Unit/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeVerifierTest.php',
        ],
        self::FAMILY_LEARNING => [
            'app/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeLearningLoop.php',
            'tests/Unit/Services/Ai/SelfConstruction/AutonomousRuntime/AtlasAutonomousRuntimeLearningLoopTest.php',
        ],
    ];

    private const ACCEPTANCE_BY_FAMILY = [
        self::FAMILY_SENSING => 'Implement and test the sensing component so it emits normalized observations for the autonomy loop.',
        self::FAMILY_DECIDING => 'Implement and test the decision gate so it selects the next autonomy action with explicit blocked reasons.',
        self::FAMILY_ACTING => 'Implement and test the actuator so it executes the selected action and returns an outcome receipt.',
        self::FAMILY_VERIFYING => 'Implement and test the verifier so it checks the outcome against acceptance criteria and records evidence.',
        self::FAMILY_LEARNING => 'Implement and test the learning loop so it updates the autonomy policy from verified outcomes.',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function route(array $input): array
    {
        $gaps = is_array($input['gaps'] ?? null) ? $input['gaps'] : [];
        $originatorId = (string) ($input['originator_id'] ?? '');
        $roundId = (string) ($input['round_id'] ?? '');

        $routes = [];
        foreach ($gaps as $gap) {
            if (! is_array($gap)) {
                continue;
            }

            $stage = (string) ($gap['stage'] ?? '');
            if (! in_array($stage, self::ALL_STAGES, true)) {
                continue;
            }

            $family = self::FAMILY_BY_STAGE[$stage];
            $routes[] = [
                'stage' => $stage,
                'task_family' => $family,
                'allowed_files' => self::ALLOWED_FILES_BY_FAMILY[$family],
                'acceptance_criteria' => [self::ACCEPTANCE_BY_FAMILY[$family]],
                'reason' => (string) ($gap['reason'] ?? 'gap detected in '.$stage),
                'severity' => (string) ($gap['severity'] ?? 'medium'),
                'priority' => $this->priorityFor($gap),
            ];
        }

        usort($routes, static function (array $a, array $b): int {
            return $a['priority'] <=> $b['priority']
                ?: strcmp($a['stage'], $b['stage']);
        });

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'routes' => $routes,
            'routable_gap_count' => count($routes),
        ];
    }

    /**
     * @param  array<string, mixed>  $gap
     */
    private function priorityFor(array $gap): int
    {
        $severity = strtolower((string) ($gap['severity'] ?? 'medium'));

        return match ($severity) {
            'critical' => 1,
            'high' => 2,
            'medium' => 3,
            'low' => 4,
            default => 3,
        };
    }
}
