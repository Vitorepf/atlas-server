<?php

declare(strict_types=1);

namespace App\Services\Ai\IntelligenceFactory;

use App\Models\AtlasIntelligenceFactoryCapability;
use App\Models\AtlasIntelligenceFactoryCertification;
use App\Models\AtlasIntelligenceFactoryDecision;
use App\Models\AtlasIntelligenceFactoryEvolutionEvent;
use App\Models\AtlasIntelligenceFactoryGap;
use App\Models\AtlasIntelligenceFactorySimulation;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AtlasIntelligenceFactoryRuntimeService
{
    public const GAP_SCHEMA = 'atlas.intelligence_factory.capability_gap.v1';

    public const DECISION_SCHEMA = 'atlas.intelligence_factory.build_buy_borrow_decision.v1';

    public const CAPABILITY_SCHEMA = 'atlas.intelligence_factory.capability_spec.v1';

    public const SIMULATION_SCHEMA = 'atlas.intelligence_factory.simulation_result.v1';

    public const CERTIFICATION_SCHEMA = 'atlas.intelligence_factory.sandbox_certification.v1';

    public const EVOLUTION_SCHEMA = 'atlas.intelligence_factory.capability_evolution.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function advise(array $input): array
    {
        $gap = $this->detectGap($input);
        $decision = $this->decide($input + ['gap_id' => $gap['gap_id'] ?? null, 'gap_type' => $gap['gap_type'] ?? null]);
        $simulation = $this->simulate($input + [
            'decision_id' => $decision['decision_id'] ?? null,
            'capability_id' => $decision['selected_capability_id'] ?? null,
            'decision' => $decision['decision'] ?? null,
        ]);

        $payload = [
            'schema_version' => 'atlas.intelligence_factory.advice.v1',
            'status' => ($decision['status'] ?? null) === 'blocked' || ($simulation['status'] ?? null) === 'blocked' ? 'blocked' : 'ready',
            'gap' => $gap,
            'decision' => $decision,
            'simulation' => $simulation,
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['advice_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function detectGap(array $input): array
    {
        $objective = $this->objective($input);
        $domain = $this->stringValue($input['domain'] ?? data_get($input, 'payload.mode') ?? null);
        $flowId = $this->stringValue($input['flow_id'] ?? data_get($input, 'payload.task') ?? null);
        $gapType = $this->classifyGap($objective, $domain, $flowId);
        $candidates = $this->matchingCapabilities($gapType, $domain, $flowId);
        $status = $candidates->isEmpty() ? 'open' : 'resolved';
        $severity = $this->severity($objective, $gapType);
        $payload = [
            'schema_version' => self::GAP_SCHEMA,
            'status' => $status,
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
            'objective' => $objective,
            'domain' => $domain,
            'flow_id' => $flowId,
            'scope_type' => $this->stringValue($input['scope_type'] ?? null) ?? 'workspace',
            'scope_id' => $this->stringValue($input['scope_id'] ?? null) ?? $this->workspaceScopeId($this->stringValue($input['workspace'] ?? null) ?? base_path()),
            'gap_type' => $gapType,
            'severity' => $severity,
            'missing_capabilities' => $this->missingCapabilities($gapType),
            'existing_candidates' => $candidates->map(fn (AtlasIntelligenceFactoryCapability $capability): array => [
                'capability_id' => $capability->id,
                'capability_key' => $capability->capability_key,
                'status' => $capability->status,
                'capability_type' => $capability->capability_type,
            ])->values()->all(),
            'evidence_refs' => $this->stringList($input['evidence_refs'] ?? []),
            'metadata' => [
                'source' => $this->stringValue($input['source'] ?? null) ?? 'aseif_runtime',
                'claim_policy' => $this->claimPolicy(),
            ],
        ];
        $payload['gap_hash'] = MissionCanonicalHash::sha256($payload);

        $record = null;
        if (DatabaseTableAvailability::has('atlas_intelligence_factory_gaps')) {
            $record = AtlasIntelligenceFactoryGap::query()->create($payload);
        }

        return [
            'schema_version' => self::GAP_SCHEMA,
            'status' => $status,
            'gap_id' => $record?->id,
            'gap_type' => $gapType,
            'severity' => $severity,
            'objective_hash' => $payload['objective_hash'],
            'missing_capabilities' => $payload['missing_capabilities'],
            'existing_candidates' => $payload['existing_candidates'],
            'gap_hash' => $payload['gap_hash'],
            'writes' => $record !== null,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function decide(array $input): array
    {
        $objective = $this->objective($input);
        $domain = $this->stringValue($input['domain'] ?? null);
        $flowId = $this->stringValue($input['flow_id'] ?? null);
        $gapType = $this->stringValue($input['gap_type'] ?? null) ?? $this->classifyGap($objective, $domain, $flowId);
        $selected = $this->matchingCapabilities($gapType, $domain, $flowId)->first();
        $risk = $this->riskProfile($objective, $gapType);
        $decision = $this->decisionKind($objective, $gapType, $selected, $risk);
        $status = $decision === 'block' ? 'blocked' : ($decision === 'use' ? 'ready' : 'ready_for_simulation');
        $payload = [
            'gap_id' => $this->uuidOrNull($input['gap_id'] ?? null),
            'schema_version' => self::DECISION_SCHEMA,
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
            'decision' => $decision,
            'status' => $status,
            'rationale' => [
                'gap_type' => $gapType,
                'risk' => $risk,
                'reason' => $this->decisionReason($decision, $gapType, $selected),
            ],
            'selected_capability_id' => $selected?->id,
            'required_controls' => $this->requiredControls($decision, $risk, $gapType),
            'evidence_refs' => $this->stringList($input['evidence_refs'] ?? []),
            'metadata' => [
                'claim_policy' => $this->claimPolicy(),
                'source' => $this->stringValue($input['source'] ?? null) ?? 'aseif_runtime',
            ],
        ];
        $payload['decision_hash'] = MissionCanonicalHash::sha256($payload);

        $record = null;
        if (DatabaseTableAvailability::has('atlas_intelligence_factory_decisions')) {
            $record = AtlasIntelligenceFactoryDecision::query()->create($payload);
        }
        $usage = $this->recordCapabilityUsage($selected, $record, $payload, $input);

        return [
            'schema_version' => self::DECISION_SCHEMA,
            'status' => $status,
            'decision' => $decision,
            'decision_id' => $record?->id,
            'selected_capability_id' => $selected?->id,
            'usage_event_id' => $usage['event_id'],
            'usage_event_hash' => $usage['event_hash'],
            'required_controls' => $payload['required_controls'],
            'decision_hash' => $payload['decision_hash'],
            'writes' => $record !== null,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function simulate(array $input): array
    {
        $objective = $this->objective($input);
        $decision = $this->stringValue($input['decision'] ?? null) ?? 'build';
        $controls = is_array($input['required_controls'] ?? null) ? $input['required_controls'] : $this->requiredControls($decision, $this->riskProfile($objective, $this->classifyGap($objective)), $this->classifyGap($objective));
        $hasApproval = in_array('operator_approval_recorded', $this->stringList($input['evidence_refs'] ?? []), true);
        $blocked = in_array('human_approval_required', $controls, true) && ! $hasApproval;
        $payload = [
            'decision_id' => $this->uuidOrNull($input['decision_id'] ?? null),
            'capability_id' => $this->uuidOrNull($input['capability_id'] ?? null),
            'schema_version' => self::SIMULATION_SCHEMA,
            'status' => $blocked ? 'blocked' : 'passed',
            'mode' => $this->stringValue($input['mode'] ?? null) ?? 'dry_run',
            'scenario' => $objective,
            'predicted_actions' => [
                'detect_gap',
                'choose_build_buy_borrow',
                $decision === 'use' ? 'invoke_certified_capability' : 'create_sandboxed_capability_candidate',
                'require_evidence_before_trust',
            ],
            'risks' => $this->simulationRisks($objective, $decision),
            'required_evidence' => ['simulation_result', 'safety_policy', 'operator_review_if_high_risk'],
            'result' => [
                'external_execution_performed' => false,
                'provider_invoked' => false,
                'blocked_reason' => $blocked ? 'human_approval_required' : null,
            ],
            'evidence_refs' => $this->stringList($input['evidence_refs'] ?? []),
        ];
        $payload['simulation_hash'] = MissionCanonicalHash::sha256($payload);

        $record = null;
        if (DatabaseTableAvailability::has('atlas_intelligence_factory_simulations')) {
            $record = AtlasIntelligenceFactorySimulation::query()->create($payload);
        }

        return [
            'schema_version' => self::SIMULATION_SCHEMA,
            'status' => $payload['status'],
            'simulation_id' => $record?->id,
            'simulation_hash' => $payload['simulation_hash'],
            'risks' => $payload['risks'],
            'result' => $payload['result'],
            'writes' => $record !== null,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function registerCapability(array $input): array
    {
        $key = Str::slug($this->stringValue($input['capability_key'] ?? $input['name'] ?? null) ?? 'atlas-factory-capability');
        $evidenceRefs = $this->stringList($input['evidence_refs'] ?? []);
        $payload = [
            'schema_version' => self::CAPABILITY_SCHEMA,
            'status' => $this->stringValue($input['status'] ?? null) ?? ($evidenceRefs === [] ? 'sandboxed' : 'certified'),
            'capability_key' => $key,
            'name' => $this->stringValue($input['name'] ?? null) ?? Str::headline($key),
            'capability_type' => $this->stringValue($input['capability_type'] ?? null) ?? 'workflow',
            'domain' => $this->stringValue($input['domain'] ?? null),
            'flow_id' => $this->stringValue($input['flow_id'] ?? null),
            'version' => $this->stringValue($input['version'] ?? null) ?? 'v1',
            'description' => $this->stringValue($input['description'] ?? null) ?? 'ASEIF sandboxed capability.',
            'input_schema' => is_array($input['input_schema'] ?? null) ? $input['input_schema'] : ['type' => 'object'],
            'output_schema' => is_array($input['output_schema'] ?? null) ? $input['output_schema'] : ['type' => 'object'],
            'use_when' => $this->stringList($input['use_when'] ?? ['objective matches capability contract']),
            'do_not_use_when' => $this->stringList($input['do_not_use_when'] ?? ['missing evidence', 'outside safety policy', 'superseded']),
            'safety_policy' => is_array($input['safety_policy'] ?? null) ? $input['safety_policy'] : $this->defaultSafetyPolicy(),
            'evidence_refs' => $evidenceRefs,
            'certification_hash' => null,
            'aemor_outcome_refs' => $this->stringList($input['aemor_outcome_refs'] ?? []),
            'metadata' => [
                'trusted_automatically' => false,
                'claim_policy' => $this->claimPolicy(),
            ],
        ];
        $payload['capability_hash'] = MissionCanonicalHash::sha256($payload);

        $record = null;
        if (DatabaseTableAvailability::has('atlas_intelligence_factory_capabilities')) {
            $record = AtlasIntelligenceFactoryCapability::query()->updateOrCreate(
                ['capability_key' => $key],
                $payload
            );
        }

        return [
            'schema_version' => self::CAPABILITY_SCHEMA,
            'status' => $payload['status'],
            'capability_id' => $record?->id,
            'capability_key' => $key,
            'capability_hash' => $payload['capability_hash'],
            'writes' => $record !== null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function certifyCapability(string $capabilityId): array
    {
        $capability = $this->capability($capabilityId);
        if (! $capability instanceof AtlasIntelligenceFactoryCapability) {
            return $this->blocked(self::CERTIFICATION_SCHEMA, 'missing_capability', 'Capability certification requires an existing capability.');
        }

        $evidenceRefs = $this->stringList($capability->evidence_refs ?? []);
        $lastSimulation = AtlasIntelligenceFactorySimulation::query()
            ->where('capability_id', $capability->id)
            ->latest()
            ->first();
        $checks = [
            ['id' => 'has_input_schema', 'status' => is_array($capability->input_schema) ? 'pass' : 'fail'],
            ['id' => 'has_output_schema', 'status' => is_array($capability->output_schema) ? 'pass' : 'fail'],
            ['id' => 'has_safety_policy', 'status' => is_array($capability->safety_policy) ? 'pass' : 'fail'],
            ['id' => 'has_evidence_refs', 'status' => $evidenceRefs !== [] ? 'pass' : 'fail'],
            ['id' => 'simulation_passed_or_not_required', 'status' => $lastSimulation === null || $lastSimulation->status === 'passed' ? 'pass' : 'fail'],
            ['id' => 'not_trusted_automatically', 'status' => $capability->status !== 'trusted' ? 'pass' : 'fail'],
        ];
        $status = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail') ? 'blocked' : 'passed';
        $payload = [
            'capability_id' => $capability->id,
            'schema_version' => self::CERTIFICATION_SCHEMA,
            'status' => $status,
            'checks' => $checks,
            'evidence_refs' => $evidenceRefs,
            'expires_at' => CarbonImmutable::now()->addDays(30),
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256(array_diff_key($payload, ['expires_at' => true]));
        $record = AtlasIntelligenceFactoryCertification::query()->create($payload);
        if ($status === 'passed') {
            $capability->forceFill([
                'status' => 'certified',
                'certification_hash' => $record->certification_hash,
            ])->save();
        }

        return [
            'schema_version' => self::CERTIFICATION_SCHEMA,
            'status' => $status,
            'capability_id' => $capability->id,
            'certification_id' => $record->id,
            'certification_hash' => $record->certification_hash,
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function controlPlane(int $hours = 24): array
    {
        $since = CarbonImmutable::now()->subHours(max(1, $hours));
        $capabilities = DatabaseTableAvailability::has('atlas_intelligence_factory_capabilities') ? AtlasIntelligenceFactoryCapability::query()->where('created_at', '>=', $since)->latest()->limit(50)->get() : collect();
        $gaps = DatabaseTableAvailability::has('atlas_intelligence_factory_gaps') ? AtlasIntelligenceFactoryGap::query()->where('created_at', '>=', $since)->latest()->limit(50)->get() : collect();
        $decisions = DatabaseTableAvailability::has('atlas_intelligence_factory_decisions') ? AtlasIntelligenceFactoryDecision::query()->where('created_at', '>=', $since)->latest()->limit(50)->get() : collect();
        $simulations = DatabaseTableAvailability::has('atlas_intelligence_factory_simulations') ? AtlasIntelligenceFactorySimulation::query()->where('created_at', '>=', $since)->latest()->limit(50)->get() : collect();

        $payload = [
            'schema_version' => 'atlas.intelligence_factory.control_plane.v1',
            'status' => $decisions->where('status', 'blocked')->isNotEmpty() || $simulations->where('status', 'blocked')->isNotEmpty() ? 'watch' : 'ready',
            'summary' => [
                'capabilities_total' => $capabilities->count(),
                'certified_capabilities' => $capabilities->where('status', 'certified')->count(),
                'trusted_capabilities' => $capabilities->where('status', 'trusted')->count(),
                'open_gaps' => $gaps->where('status', 'open')->count(),
                'blocked_decisions' => $decisions->where('status', 'blocked')->count(),
                'simulations_total' => $simulations->count(),
                'blocked_simulations' => $simulations->where('status', 'blocked')->count(),
            ],
            'recent_gaps' => $gaps->take(10)->map(fn (AtlasIntelligenceFactoryGap $gap): array => [
                'gap_id' => $gap->id,
                'status' => $gap->status,
                'gap_type' => $gap->gap_type,
                'severity' => $gap->severity,
                'gap_hash' => $gap->gap_hash,
            ])->values()->all(),
            'recent_decisions' => $decisions->take(10)->map(fn (AtlasIntelligenceFactoryDecision $decision): array => [
                'decision_id' => $decision->id,
                'decision' => $decision->decision,
                'status' => $decision->status,
                'decision_hash' => $decision->decision_hash,
            ])->values()->all(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['control_plane_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function marketplace(int $limit = 50): array
    {
        if (! DatabaseTableAvailability::has('atlas_intelligence_factory_capabilities')) {
            return [];
        }

        return AtlasIntelligenceFactoryCapability::query()
            ->whereIn('status', ['certified', 'trusted'])
            ->latest()
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn (AtlasIntelligenceFactoryCapability $capability): array => [
                'capability_id' => $capability->id,
                'capability_key' => $capability->capability_key,
                'name' => $capability->name,
                'status' => $capability->status,
                'capability_type' => $capability->capability_type,
                'domain' => $capability->domain,
                'flow_id' => $capability->flow_id,
                'certification_hash' => $capability->certification_hash,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evolveFromAemor(array $input): array
    {
        $payload = [
            'capability_id' => $this->uuidOrNull($input['capability_id'] ?? null),
            'schema_version' => self::EVOLUTION_SCHEMA,
            'source_type' => $this->stringValue($input['source_type'] ?? null) ?? 'aemor',
            'source_id' => $this->stringValue($input['source_id'] ?? $input['outcome_id'] ?? null),
            'event_type' => $this->stringValue($input['event_type'] ?? null) ?? 'outcome_learning_candidate',
            'status' => 'candidate',
            'payload' => [
                'summary' => $this->stringValue($input['summary'] ?? null) ?? 'AEMOR outcome suggests a capability evolution.',
                'requires_operator_review' => true,
                'auto_mutates_policy' => false,
            ],
            'evidence_refs' => $this->stringList($input['evidence_refs'] ?? []),
        ];
        $payload['event_hash'] = MissionCanonicalHash::sha256($payload);
        $record = null;
        if (DatabaseTableAvailability::has('atlas_intelligence_factory_evolution_events')) {
            $record = AtlasIntelligenceFactoryEvolutionEvent::query()->create($payload);
        }

        return [
            'schema_version' => self::EVOLUTION_SCHEMA,
            'status' => 'candidate',
            'event_id' => $record?->id,
            'event_hash' => $payload['event_hash'],
            'writes' => $record !== null,
        ];
    }

    /**
     * @param  array<string,mixed>  $decisionPayload
     * @param  array<string,mixed>  $input
     * @return array{event_id:?string,event_hash:?string}
     */
    private function recordCapabilityUsage(?AtlasIntelligenceFactoryCapability $capability, ?AtlasIntelligenceFactoryDecision $decision, array $decisionPayload, array $input): array
    {
        if (! $capability instanceof AtlasIntelligenceFactoryCapability) {
            return ['event_id' => null, 'event_hash' => null];
        }
        if (($decisionPayload['decision'] ?? null) !== 'use') {
            return ['event_id' => null, 'event_hash' => null];
        }
        if (! DatabaseTableAvailability::has('atlas_intelligence_factory_evolution_events')) {
            return ['event_id' => null, 'event_hash' => null];
        }

        $evidenceRefs = array_values(array_unique(array_filter(array_merge(
            $this->stringList($input['evidence_refs'] ?? []),
            $this->stringList($capability->evidence_refs ?? [])
        ))));
        $payload = [
            'capability_id' => $capability->id,
            'schema_version' => self::EVOLUTION_SCHEMA,
            'source_type' => 'aseif_decision',
            'source_id' => $decision?->id,
            'event_type' => 'capability_used',
            'status' => 'observed',
            'payload' => [
                'capability_key' => $capability->capability_key,
                'capability_type' => $capability->capability_type,
                'domain' => $capability->domain,
                'flow_id' => $capability->flow_id,
                'decision_hash' => $decisionPayload['decision_hash'] ?? null,
                'objective_hash' => $decisionPayload['objective_hash'] ?? null,
                'auto_mutates_policy' => false,
                'provider_invoked' => false,
            ],
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['event_hash'] = MissionCanonicalHash::sha256($payload);
        $record = AtlasIntelligenceFactoryEvolutionEvent::query()->create($payload);

        return ['event_id' => $record->id, 'event_hash' => $record->event_hash];
    }

    /**
     * @return array<string,mixed>
     */
    public function claimPolicy(): array
    {
        return [
            'external_execution_performed' => false,
            'provider_invoked' => false,
            'auto_trust_disabled' => true,
            'auto_policy_mutation_disabled' => true,
            'benchmark_not_run' => true,
            'human_approval_required_for_high_risk' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function objective(array $input): string
    {
        return $this->stringValue($input['objective'] ?? $input['prompt'] ?? $input['input_text'] ?? null) ?? 'ASEIF capability objective';
    }

    private function classifyGap(string $objective, ?string $domain = null, ?string $flowId = null): string
    {
        $text = Str::lower($objective.' '.($domain ?? '').' '.($flowId ?? ''));
        if (str_contains($text, 'youtube') || str_contains($text, 'video') || str_contains($text, 'transcri')) {
            return 'multimodal_video_intelligence';
        }
        if (str_contains($text, 'instagram') || str_contains($text, 'scrap') || str_contains($text, 'browser') || str_contains($text, 'api')) {
            return 'external_automation';
        }
        if (str_contains($text, 'pdf') || str_contains($text, 'document') || str_contains($text, 'arquivo')) {
            return 'document_processing';
        }
        if (str_contains($text, 'debug') || str_contains($text, 'patch') || str_contains($text, 'teste') || str_contains($text, 'program')) {
            return 'engineering_tooling';
        }
        if (str_contains($text, 'marketing') || str_contains($text, 'campanha')) {
            return 'marketing_workflow';
        }
        if (str_contains($text, 'finan') || str_contains($text, 'carteira') || str_contains($text, 'trade')) {
            return 'finance_analysis_workflow';
        }

        return 'general_workflow';
    }

    private function decisionKind(string $objective, string $gapType, ?AtlasIntelligenceFactoryCapability $selected, array $risk): string
    {
        if ($selected instanceof AtlasIntelligenceFactoryCapability) {
            return 'use';
        }
        if (($risk['level'] ?? null) === 'critical') {
            return 'block';
        }
        if (str_contains(Str::lower($objective), 'obra') || str_contains(Str::lower($objective), 'meses')) {
            return 'promote';
        }
        if (in_array($gapType, ['external_automation', 'document_processing', 'multimodal_video_intelligence'], true)) {
            return 'borrow';
        }

        return 'build';
    }

    /**
     * @return array<string,mixed>
     */
    private function riskProfile(string $objective, string $gapType): array
    {
        $text = Str::lower($objective);
        if (str_contains($text, 'senha') || str_contains($text, 'pagamento') || str_contains($text, 'comprar') || str_contains($text, 'vender') || str_contains($text, 'day trade')) {
            return ['level' => 'critical', 'reason' => 'credential_financial_or_irreversible_action'];
        }
        if ($gapType === 'external_automation') {
            return ['level' => 'high', 'reason' => 'third_party_surface_or_terms_risk'];
        }

        return ['level' => 'normal', 'reason' => 'sandboxable_capability_candidate'];
    }

    /**
     * @return array<int,string>
     */
    private function requiredControls(string $decision, array $risk, string $gapType): array
    {
        $controls = ['sandbox_simulation', 'evidence_refs_required', 'operator_review_before_trust'];
        if (($risk['level'] ?? null) !== 'normal' || $decision === 'block') {
            $controls[] = 'human_approval_required';
        }
        if ($gapType === 'external_automation') {
            $controls[] = 'third_party_policy_review';
            $controls[] = 'human_approval_required';
        }
        if ($decision === 'promote') {
            $controls[] = 'forge_handoff_required';
        }

        return array_values(array_unique($controls));
    }

    private function decisionReason(string $decision, string $gapType, ?AtlasIntelligenceFactoryCapability $selected): string
    {
        if ($selected instanceof AtlasIntelligenceFactoryCapability) {
            return 'A certified or trusted capability already matches this gap.';
        }

        return match ($decision) {
            'block' => 'Risk exceeds local sandbox authority.',
            'borrow' => 'External or document capability should prefer adapter/library before custom tool.',
            'promote' => 'Scope is large enough to be governed by Forge.',
            default => 'No safe existing capability matched; build sandbox candidate.',
        };
    }

    /**
     * @return array<int,string>
     */
    private function missingCapabilities(string $gapType): array
    {
        return match ($gapType) {
            'multimodal_video_intelligence' => ['youtube_ingestion_status_sync', 'transcript_language_detection', 'translation_summary_pipeline'],
            'external_automation' => ['policy_checked_browser_automation', 'credential_safe_adapter', 'operator_review_gate'],
            'document_processing' => ['large_document_chunker', 'source_manifest_extractor', 'citation_preserving_reader'],
            'engineering_tooling' => ['patch_verifier', 'test_impact_planner', 'repair_loop_adapter'],
            'marketing_workflow' => ['campaign_research_harness', 'creative_variant_simulator', 'analytics_feedback_loop'],
            'finance_analysis_workflow' => ['portfolio_risk_model', 'compliance_review_gate', 'decision_journal'],
            default => ['workflow_adapter', 'sandbox_simulation', 'evidence_contract'],
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function simulationRisks(string $objective, string $decision): array
    {
        $risks = [
            ['id' => 'false_capability_fit', 'severity' => 'medium'],
            ['id' => 'missing_evidence', 'severity' => 'high'],
        ];
        if ($decision === 'borrow') {
            $risks[] = ['id' => 'third_party_policy_or_api_drift', 'severity' => 'high'];
        }
        if ($decision === 'block' || str_contains(Str::lower($objective), 'trade')) {
            $risks[] = ['id' => 'irreversible_action_without_approval', 'severity' => 'critical'];
        }

        return $risks;
    }

    /**
     * @return Collection<int,AtlasIntelligenceFactoryCapability>
     */
    private function matchingCapabilities(string $gapType, ?string $domain, ?string $flowId): Collection
    {
        if (! DatabaseTableAvailability::has('atlas_intelligence_factory_capabilities')) {
            return collect();
        }

        return AtlasIntelligenceFactoryCapability::query()
            ->whereIn('status', ['certified', 'trusted'])
            ->where(function ($query) use ($domain, $flowId): void {
                $query->whereNull('domain');
                if ($domain !== null) {
                    $query->orWhere('domain', $domain);
                }
                if ($flowId !== null) {
                    $query->orWhere('flow_id', $flowId);
                }
            })
            ->where(function ($query) use ($gapType): void {
                $query->where('capability_key', 'like', '%'.$gapType.'%')
                    ->orWhere('capability_type', $this->capabilityTypeForGap($gapType));
            })
            ->limit(10)
            ->get();
    }

    private function capabilityTypeForGap(string $gapType): string
    {
        return match ($gapType) {
            'external_automation' => 'tool',
            'engineering_tooling' => 'workflow',
            'multimodal_video_intelligence', 'document_processing' => 'workflow',
            default => 'workflow',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultSafetyPolicy(): array
    {
        return [
            'sandbox_required' => true,
            'evidence_required' => true,
            'human_approval_for_high_risk' => true,
            'no_secret_exfiltration' => true,
            'no_auto_policy_mutation' => true,
        ];
    }

    private function severity(string $objective, string $gapType): string
    {
        $risk = $this->riskProfile($objective, $gapType);

        return match ($risk['level'] ?? 'normal') {
            'critical' => 'critical',
            'high' => 'high',
            default => in_array($gapType, ['general_workflow', 'marketing_workflow'], true) ? 'medium' : 'high',
        };
    }

    private function capability(?string $id): ?AtlasIntelligenceFactoryCapability
    {
        if ($id === null || ! DatabaseTableAvailability::has('atlas_intelligence_factory_capabilities')) {
            return null;
        }

        return AtlasIntelligenceFactoryCapability::query()->find($id);
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $schema, string $reason, string $message): array
    {
        return [
            'schema_version' => $schema,
            'status' => 'blocked',
            'reason' => $reason,
            'message' => $message,
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (mixed $item): ?string => $this->stringValue($item), $value), fn (?string $item): bool => $item !== null && $item !== ''));
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }
        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    private function uuidOrNull(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        return $value !== null && Str::isUuid($value) ? $value : null;
    }

    private function workspaceScopeId(string $workspace): string
    {
        return 'workspace:'.substr(MissionCanonicalHash::sha256(['workspace' => $workspace]), 0, 16);
    }
}
