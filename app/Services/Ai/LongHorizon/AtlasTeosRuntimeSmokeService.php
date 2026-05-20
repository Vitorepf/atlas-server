<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Models\AiAutonomousEngineeringGoal;
use App\Models\AiCodebaseWorldModel;
use App\Models\AiForgeIntake;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AutonomousEngineering\AtlasAutonomousEngineeringService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * TEOS-I5 runtime smoke.
 *
 * This is the controlled write path that materializes enough local runtime
 * data to prove TEOS-I2 through TEOS-I5 can certify end-to-end. It never calls
 * providers, rivals or benchmark harnesses.
 */
class AtlasTeosRuntimeSmokeService
{
    public const SCHEMA_VERSION = 'atlas.teos.runtime_smoke.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AtlasAutonomousEngineeringService $autonomousEngineering,
        private readonly ForgeIntakeService $forgeIntake,
        private readonly AtlasTeosFinalCertificationService $finalCertification,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input = []): array
    {
        $now = ($input['now'] ?? null) instanceof CarbonImmutable ? $input['now'] : CarbonImmutable::now();
        $goalText = $this->stringOrNull($input['goal'] ?? null)
            ?? 'TEOS runtime smoke: materializar World Model e Forge intake locais com evidência';

        $missing = $this->missingTables();
        if ($missing !== []) {
            return $this->blocked($now, $goalText, 'missing_required_tables', ['missing_tables' => $missing]);
        }

        try {
            $created = DB::transaction(function () use ($now, $goalText): array {
                $goal = $this->autonomousEngineering->createGoal(
                    $goalText,
                    flowId: 'programming.dev',
                    promotionTarget: 'atlas_forge',
                );
                $worldModel = $this->autonomousEngineering->buildWorldModel($goal);
                $intake = $this->createForgeIntake($goalText, $worldModel, $now);
                $memory = $this->createSmokeMemory($goal, $worldModel, $intake, $now);

                return compact('goal', 'worldModel', 'intake', 'memory');
            });

            /** @var AiAutonomousEngineeringGoal $goal */
            $goal = $created['goal'];
            /** @var AiCodebaseWorldModel $worldModel */
            $worldModel = $created['worldModel'];
            /** @var AiForgeIntake $intake */
            $intake = $created['intake'];
            /** @var AtlasMemoryEntry|null $memory */
            $memory = $created['memory'];

            $final = $this->finalCertification->certify([
                'now' => $now,
                'intake' => $intake->uuid,
                'world_model_id' => $worldModel->model_id,
            ]);

            $payload = [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => ($final['status'] ?? null) === AtlasTeosFinalCertificationService::STATUS_READY
                    ? self::STATUS_READY
                    : self::STATUS_BLOCKED,
                'generated_at' => $now->toJSON(),
                'writes' => true,
                'created' => [
                    'goal_id' => $goal->goal_id,
                    'world_model_id' => $worldModel->model_id,
                    'world_model_row_id' => (string) $worldModel->id,
                    'forge_intake_uuid' => $intake->uuid,
                    'forge_intake_row_id' => (string) $intake->id,
                    'memory_entry_id' => $memory?->id ? (string) $memory->id : null,
                ],
                'counts' => [
                    'world_model_nodes' => $this->worldModelNodes($worldModel),
                    'world_model_edges' => $this->worldModelEdges($worldModel),
                    'forge_milestones' => $intake->milestones()->count(),
                    'forge_work_packets' => $intake->workPackets()->count(),
                ],
                'final_certification' => $final,
                'blockers' => ($final['status'] ?? null) === AtlasTeosFinalCertificationService::STATUS_READY
                    ? []
                    : (array) ($final['blockers'] ?? $final['warnings'] ?? []),
                'claim_policy' => [
                    'benchmark_not_run' => true,
                    'rivals_compared' => false,
                    'provider_calls_made' => false,
                    'allows_external_superiority_claim' => false,
                    'declares_teos_complete' => false,
                    'local_smoke_only' => true,
                ],
            ];
            $payload['smoke_hash'] = $this->hashSmoke($payload);

            return $payload;
        } catch (Throwable $exception) {
            return $this->blocked($now, $goalText, 'runtime_smoke_exception', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function createForgeIntake(string $goalText, AiCodebaseWorldModel $worldModel, CarbonImmutable $now): AiForgeIntake
    {
        return $this->forgeIntake->intakeFromPrompt(
            'Implementar e certificar uma Obra TEOS local de smoke para provar continuidade, World Model e revisão Forge sem provider externo.',
            [
                'workspace_slug' => 'atlas',
                'obra_title' => 'TEOS Runtime Smoke',
                'normalized_intent' => $goalText,
                'scope_assessment' => 'local_runtime_smoke',
                'risk_assessment' => 'controlled_local_write_no_provider_no_benchmark',
                'ambiguity_assessment' => 'low',
                'risk_band' => 'medium',
                'definition_of_done' => [
                    'world_model_built',
                    'forge_intake_ready',
                    'final_certification_ready',
                ],
                'required_evidence' => [
                    'world_model:'.$worldModel->model_id,
                    'command:atlas:teos:runtime-smoke',
                    'certification:atlas.teos.final_certification.v1',
                ],
                'evidence_refs' => [
                    'world_model:'.$worldModel->model_id,
                    'runtime_smoke:'.$now->toJSON(),
                ],
                'context_refs' => [
                    'docs:docs/engineering-knowledge-base/atlas-teos-i2-i5-runtime-certification.md',
                    'world_model:'.$worldModel->model_id,
                ],
                'constraints' => [
                    'no_provider_calls',
                    'no_rivals',
                    'no_benchmark',
                    'local_smoke_only',
                ],
                'actor_type' => 'system',
            ],
        );
    }

    private function createSmokeMemory(
        AiAutonomousEngineeringGoal $goal,
        AiCodebaseWorldModel $worldModel,
        AiForgeIntake $intake,
        CarbonImmutable $now,
    ): ?AtlasMemoryEntry {
        if (! Schema::hasTable('atlas_memory_entries')) {
            return null;
        }

        $row = [
            'memory_type' => 'technical_context',
            'scope_type' => 'long_horizon',
            'scope_id' => $goal->goal_id,
            'title' => 'TEOS runtime smoke evidence',
            'body' => 'Local TEOS smoke created a Codebase World Model and Forge intake without provider, rivals or benchmark execution.',
            'summary' => 'TEOS smoke evidence for final local certification.',
            'importance' => 3,
            'priority' => 70,
            'confidence' => 0.95,
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'teos_runtime_smoke',
            'source_id' => $goal->goal_id,
            'source_label' => 'atlas:teos:runtime-smoke',
            'status' => 'active',
            'tags' => ['teos', 'runtime_smoke', 'long_horizon'],
            'metadata' => [
                'goal_id' => $goal->goal_id,
                'world_model_id' => $worldModel->model_id,
                'forge_intake_uuid' => $intake->uuid,
                'evidence_refs' => [
                    'goal:'.$goal->goal_id,
                    'world_model:'.$worldModel->model_id,
                    'forge_intake:'.$intake->uuid,
                ],
                'must_keep' => true,
            ],
            'recorded_at' => $now,
            'last_used_at' => $now,
            'observed_at' => $now,
            'verified_at' => $now,
            'source_hash' => $worldModel->model_hash,
            'authority_level' => 'verified',
        ];

        if (Schema::hasColumn('atlas_memory_entries', 'content_hash')) {
            $row['content_hash'] = MissionCanonicalHash::sha256([
                'goal' => $goal->goal_id,
                'world_model' => $worldModel->model_id,
                'intake' => $intake->uuid,
            ]);
        }

        return AtlasMemoryEntry::query()->create($row);
    }

    /**
     * @return array<int,string>
     */
    private function missingTables(): array
    {
        $required = [
            'ai_autonomous_engineering_goals',
            'ai_codebase_world_models',
            'ai_codebase_world_model_nodes',
            'ai_codebase_world_model_edges',
            'ai_forge_intakes',
            'ai_forge_milestones',
            'ai_forge_work_packets',
            'atlas_memory_entries',
            'atlas_long_horizon_continuation_packs',
            'atlas_long_horizon_compaction_receipts',
            'atlas_long_horizon_replay_manifests',
        ];

        return array_values(array_filter($required, fn (string $table): bool => ! Schema::hasTable($table)));
    }

    private function worldModelNodes(AiCodebaseWorldModel $worldModel): int
    {
        return $worldModel->newQuery()
            ->getModel()
            ->getConnection()
            ->table('ai_codebase_world_model_nodes')
            ->where('world_model_id', $worldModel->id)
            ->count();
    }

    private function worldModelEdges(AiCodebaseWorldModel $worldModel): int
    {
        return $worldModel->newQuery()
            ->getModel()
            ->getConnection()
            ->table('ai_codebase_world_model_edges')
            ->where('world_model_id', $worldModel->id)
            ->count();
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function blocked(CarbonImmutable $now, string $goalText, string $reason, array $evidence = []): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => self::STATUS_BLOCKED,
            'generated_at' => $now->toJSON(),
            'goal' => $goalText,
            'writes' => false,
            'blockers' => [
                [
                    'reason' => $reason,
                    'evidence' => $evidence,
                ],
            ],
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
                'allows_external_superiority_claim' => false,
                'declares_teos_complete' => false,
                'local_smoke_only' => true,
            ],
        ];
        $payload['smoke_hash'] = $this->hashSmoke($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashSmoke(array $payload): string
    {
        unset($payload['generated_at']);

        return MissionCanonicalHash::sha256($payload);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
