<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Product\AtlasExecutionDoctrineGateService;
use App\Services\Ai\Product\AtlasExecutionDoctrineRuntimeService;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Str;

/**
 * Atlas Code Forge Work Intake & Spec Governance v1.
 *
 * Camada de intake operacional governada para Atlas Code Forge: objetivo,
 * regra de negocio, escopo, criterios de aceite e docs canonicas obrigatorias
 * antes de liberar Fast Path enterprise.
 *
 * Schema: atlas.code.forge_work_intake.v1
 */
class AtlasCodeForgeWorkIntakeService
{
    public const SCHEMA_VERSION = 'atlas.code.forge_work_intake.v1';

    public function __construct(
        private readonly AtlasExecutionDoctrineRuntimeService $aedpds = new AtlasExecutionDoctrineRuntimeService,
        private readonly AtlasExecutionDoctrineGateService $aedpdsGate = new AtlasExecutionDoctrineGateService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function get(AtlasProject $project): array
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $latest = data_get($metadata, 'latest_atlas_code_forge_work_intake');
        if (is_array($latest)) {
            return $this->reconcileReadiness($project, $latest);
        }

        return $this->emptyIntake($project);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function save(AtlasProject $project, array $input): array
    {
        $objective = AiValueNormalizer::trimmedStringOrNull($input['objective'] ?? null);
        $businessRule = AiValueNormalizer::trimmedStringOrNull($input['business_rule'] ?? null);
        $scopeIn = AiStringListNormalizer::trimmedStringsFromArrayCast($input['scope_in'] ?? []);
        $scopeOut = AiStringListNormalizer::trimmedStringsFromArrayCast($input['scope_out'] ?? []);
        $acceptance = AiStringListNormalizer::trimmedStringsFromArrayCast($input['acceptance_criteria'] ?? []);
        $canonicalDocs = AiStringListNormalizer::trimmedStringsFromArrayCast($input['canonical_docs'] ?? []);
        $expectedOutputs = AiStringListNormalizer::trimmedStringsFromArrayCast($input['expected_outputs'] ?? []);
        $constraints = AiStringListNormalizer::trimmedStringsFromArrayCast($input['constraints'] ?? []);
        $operatorNotes = AiValueNormalizer::trimmedStringOrNull($input['operator_notes'] ?? null);
        $riskLevel = AiValueNormalizer::trimmedStringOrNull($input['risk_level'] ?? null) ?? 'medium';

        $existing = (array) data_get($project->metadata, 'latest_atlas_code_forge_work_intake', []);
        $intakeId = AiValueNormalizer::trimmedStringOrNull(data_get($existing, 'intake_id')) ?? (string) Str::ulid();
        $createdAt = AiValueNormalizer::trimmedStringOrNull(data_get($existing, 'created_at')) ?? now()->toIso8601String();

        $workItem = $this->resolveWorkItem($project);

        $intake = [
            'schema_version' => self::SCHEMA_VERSION,
            'intake_id' => $intakeId,
            'obra_id' => (string) $project->getKey(),
            'work_item_id' => $workItem?->id !== null ? (string) $workItem->id : null,
            'work_item_code' => $workItem?->code !== null ? (string) $workItem->code : null,
            'objective' => $objective,
            'business_rule' => $businessRule,
            'scope_in' => $scopeIn,
            'scope_out' => $scopeOut,
            'acceptance_criteria' => $acceptance,
            'canonical_docs' => $canonicalDocs,
            'risk_level' => in_array($riskLevel, ['low', 'medium', 'high', 'critical'], true) ? $riskLevel : 'medium',
            'expected_outputs' => $expectedOutputs,
            'constraints' => $constraints,
            'operator_notes' => $operatorNotes,
            'created_at' => $createdAt,
            'updated_at' => now()->toIso8601String(),
            'external_provider_call' => false,
        ];
        $intake['aedpds'] = $this->aedpdsEnvelope($intake);

        $intake = $this->withReadiness($intake);

        $this->remember($project, $intake);

        return $intake;
    }

    /**
     * @return array<string,mixed>
     */
    private function reconcileReadiness(AtlasProject $project, array $intake): array
    {
        if ((string) ($intake['obra_id'] ?? '') !== (string) $project->getKey()) {
            return $this->emptyIntake($project, $intake);
        }

        $intake['schema_version'] = self::SCHEMA_VERSION;
        $intake = $this->withReadiness($intake);

        return $intake;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyIntake(AtlasProject $project, array $partial = []): array
    {
        $workItem = $this->resolveWorkItem($project);

        $intake = array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'intake_id' => null,
            'obra_id' => (string) $project->getKey(),
            'work_item_id' => $workItem?->id !== null ? (string) $workItem->id : null,
            'work_item_code' => $workItem?->code !== null ? (string) $workItem->code : null,
            'objective' => null,
            'business_rule' => null,
            'scope_in' => [],
            'scope_out' => [],
            'acceptance_criteria' => [],
            'canonical_docs' => [],
            'risk_level' => 'medium',
            'expected_outputs' => [],
            'constraints' => [],
            'operator_notes' => null,
            'created_at' => null,
            'updated_at' => null,
            'external_provider_call' => false,
        ], $partial);
        $intake['aedpds'] = $this->aedpdsEnvelope($intake);

        return $this->withReadiness($intake);
    }

    /**
     * @param  array<string,mixed>  $intake
     * @return array<string,mixed>
     */
    private function aedpdsEnvelope(array $intake): array
    {
        $doctrine = $this->aedpds->select([
            'task' => (string) ($intake['objective'] ?? 'Forge work packet'),
            'surface' => 'atlas_forge',
            'workspace' => (string) ($intake['obra_id'] ?? ''),
            'risk_level' => (string) ($intake['risk_level'] ?? 'medium'),
            'code_changes_requested' => true,
            'forge_involved' => true,
            'complex_product' => $this->complexProductRequested($intake),
            'missing_context' => AiStringListNormalizer::trimmedStringsFromArrayCast($intake['canonical_docs'] ?? []) === [],
        ]);
        $gate = $this->aedpdsGate->evaluate([
            'doctrine' => $doctrine,
            'acceptance_criteria' => AiStringListNormalizer::trimmedStringsFromArrayCast($intake['acceptance_criteria'] ?? []),
            'context_refs' => AiStringListNormalizer::trimmedStringsFromArrayCast($intake['canonical_docs'] ?? []),
            'tests' => AiStringListNormalizer::trimmedStringsFromArrayCast($intake['expected_outputs'] ?? []) !== []
                ? AiStringListNormalizer::trimmedStringsFromArrayCast($intake['expected_outputs'] ?? [])
                : (AiStringListNormalizer::trimmedStringsFromArrayCast($intake['acceptance_criteria'] ?? []) === [] ? [] : ['forge_packet_acceptance_verification']),
            'docs' => AiStringListNormalizer::trimmedStringsFromArrayCast($intake['canonical_docs'] ?? []),
            'review' => in_array((string) ($intake['risk_level'] ?? 'medium'), ['high', 'critical'], true) ? [] : ['risk_review_not_required_for_current_band'],
            'evidence' => AiStringListNormalizer::trimmedStringsFromArrayCast($intake['expected_outputs'] ?? []) !== []
                ? AiStringListNormalizer::trimmedStringsFromArrayCast($intake['expected_outputs'] ?? [])
                : (AiStringListNormalizer::trimmedStringsFromArrayCast($intake['acceptance_criteria'] ?? []) === [] ? [] : ['forge_packet_evidence_required']),
        ]);

        return [
            'schema_version' => 'atlas.forge.aedpds_projection.v1',
            'selected_drivers' => $doctrine['selected_primary_drivers'],
            'required_context' => $doctrine['required_context'],
            'required_tests' => $doctrine['required_tests'],
            'required_evidence' => $doctrine['required_evidence'],
            'required_review' => $doctrine['required_review'],
            'recommended_escalation' => $doctrine['recommended_escalation'],
            'gate_status' => $gate['status'],
            'blockers' => $gate['blockers'],
            'warnings' => $gate['warnings'],
            'doctrine_hash' => $doctrine['certification_hash'],
            'gate_hash' => $gate['hash'],
        ];
    }

    /**
     * @param  array<string,mixed>  $intake
     * @return array<string,mixed>
     */
    private function withReadiness(array $intake): array
    {
        $blockers = [];

        if (empty($intake['obra_id'])) {
            $blockers[] = 'blocked_no_obra';
        }
        if (! AiValueNormalizer::trimmedStringOrNull($intake['objective'] ?? null)) {
            $blockers[] = 'blocked_missing_objective';
        }
        if (! AiValueNormalizer::trimmedStringOrNull($intake['business_rule'] ?? null)) {
            $blockers[] = 'blocked_missing_business_rule';
        }
        if (AiStringListNormalizer::trimmedStringsFromArrayCast($intake['acceptance_criteria'] ?? []) === []) {
            $blockers[] = 'blocked_missing_acceptance_criteria';
        }
        if (AiStringListNormalizer::trimmedStringsFromArrayCast($intake['canonical_docs'] ?? []) === []) {
            $blockers[] = 'blocked_missing_canonical_docs';
        }
        if (data_get($intake, 'aedpds.gate_status') === 'blocked') {
            foreach (AiStringListNormalizer::trimmedStringsFromArrayCast(data_get($intake, 'aedpds.blockers', [])) as $blocker) {
                $blockers[] = 'blocked_aedpds_'.$blocker;
            }
        }

        $readiness = $blockers === [] ? 'ready' : 'blocked';
        $intake['readiness_status'] = $readiness;
        $intake['blockers'] = $blockers;
        $intake['enterprise_ready'] = $readiness === 'ready';
        $intake['next_action'] = $this->resolveNextAction($intake, $blockers);

        return $intake;
    }

    /**
     * @param  array<string,mixed>  $intake
     * @param  array<int,string>  $blockers
     */
    private function resolveNextAction(array $intake, array $blockers): string
    {
        if (in_array('blocked_no_obra', $blockers, true)) {
            return 'provide_obra';
        }
        if (in_array('blocked_missing_objective', $blockers, true)) {
            return 'fill_objective';
        }
        if (in_array('blocked_missing_business_rule', $blockers, true)) {
            return 'fill_business_rule';
        }
        if (in_array('blocked_missing_acceptance_criteria', $blockers, true)) {
            return 'fill_acceptance_criteria';
        }
        if (in_array('blocked_missing_canonical_docs', $blockers, true)) {
            return 'link_canonical_docs';
        }
        if (empty($intake['work_item_id'])) {
            return 'create_programming_work_item';
        }

        return 'run_forge_fast_path';
    }

    /**
     * @param  array<string,mixed>  $intake
     */
    private function remember(AtlasProject $project, array $intake): void
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];

        $history = collect((array) ($metadata['atlas_code_forge_work_intake_history'] ?? []))
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->reject(fn (array $entry): bool => (string) ($entry['intake_id'] ?? '') === (string) ($intake['intake_id'] ?? ''))
            ->values()
            ->all();
        array_unshift($history, $intake);
        $metadata['atlas_code_forge_work_intake_history'] = array_slice($history, 0, 25);
        $metadata['latest_atlas_code_forge_work_intake'] = $intake;

        $project->forceFill([
            'metadata' => $metadata,
            'last_touched_at' => now(),
        ])->save();
    }

    private function resolveWorkItem(AtlasProject $project): ?AtlasProgrammingWorkItem
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $id = AiValueNormalizer::trimmedStringOrNull(data_get($metadata, 'programming_work_item_id'));
        if ($id !== null) {
            $item = AtlasProgrammingWorkItem::query()->whereKey($id)->first();
            if ($item !== null) {
                return $item;
            }
        }
        $code = AiValueNormalizer::trimmedStringOrNull(data_get($metadata, 'programming_work_item_code'));
        if ($code !== null) {
            $item = AtlasProgrammingWorkItem::query()->where('code', $code)->first();
            if ($item !== null) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $intake
     */
    private function complexProductRequested(array $intake): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            AiValueNormalizer::trimmedStringOrNull($intake['objective'] ?? null),
            AiValueNormalizer::trimmedStringOrNull($intake['business_rule'] ?? null),
            ...AiStringListNormalizer::trimmedStringsFromArrayCast($intake['scope_in'] ?? []),
            ...AiStringListNormalizer::trimmedStringsFromArrayCast($intake['scope_out'] ?? []),
            ...AiStringListNormalizer::trimmedStringsFromArrayCast($intake['expected_outputs'] ?? []),
            ...AiStringListNormalizer::trimmedStringsFromArrayCast($intake['constraints'] ?? []),
        ])));

        foreach (['saas', 'ecommerce', 'e-commerce', 'empresa', 'produto complexo'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
