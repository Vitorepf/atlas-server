<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * AI Harness governance admission decider for one Obra AI action.
 *
 * Pure, deterministic implementation of the admission contract the doc states:
 * before any AI action runs inside an Obra, the harness builds an Operation
 * Envelope and decides the single controlled outcome — `admit`, `clarify`,
 * `route_workspace` or `checkpoint` (human) — so that an Obra AI action is never
 * treated as loose chat and never bypasses a required human checkpoint, a
 * provider-policy constraint or the shared-workspace rule.
 *
 * Contract (from the doc body):
 *   - Obra Context Pack / Operation Envelope: the AI "must build an Operation
 *     Envelope with obra_id, domain, section, task type, sources, decisions,
 *     deadline, quality gate and risk"; it "must not treat ... as loose chat".
 *     => a missing required envelope field is loose chat and forces `clarify`.
 *   - Governance > Human checkpoints are REQUIRED for: scope changes, strategic
 *     decisions, dubious sources, external publication, financial action,
 *     sensitive action, irreversible change. => any trigger forces `checkpoint`.
 *   - Provider Policy: before selecting a model Atlas asks "does this Obra
 *     contain sensitive data? may data leave local environment? is local model
 *     required?". => sensitive data that may NOT leave local requires a local
 *     model; a non-local model under that constraint is rejected.
 *   - Shared Workspace Rule: "for long work, programming work or multi-provider
 *     work, AI sessions ... must coordinate through Obras Shared Workspace ...
 *     must not pass authority through informal chat". => multi-provider /
 *     programming / long work without a workspace forces `route_workspace`.
 *
 * The service NEVER calls a model, mutates an Obra or touches the database. It
 * emits the admission decision plus an auditable AI-session-trace skeleton (the
 * doc "AI Session Trace" — "this is what separates Obras from a chat").
 *
 * @see docs/engineering-knowledge-base/obras/ai-harness-governance-and-quality.md
 */
final class AtlasAiHarnessGovernanceAndQualityService
{
    /** Stable receipt schema id for the admission decision this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.obras.ai_harness_admission.v1';

    /** Canonical outcomes (closed set). */
    public const OUTCOME_ADMIT = 'admit';
    public const OUTCOME_CLARIFY = 'clarify';
    public const OUTCOME_ROUTE_WORKSPACE = 'route_workspace';
    public const OUTCOME_CHECKPOINT = 'checkpoint';

    /**
     * Operation Envelope required fields — the doc lists exactly these as what
     * the AI "must build" so the action is an Obra operation and not loose chat.
     *
     * @var list<string>
     */
    public const ENVELOPE_REQUIRED_FIELDS = [
        'obra_id',
        'domain',
        'section',
        'task_type',
        'sources',
        'decisions',
        'deadline',
        'quality_gate',
        'risk',
    ];

    /**
     * Human-checkpoint triggers — the doc: "Human checkpoints are required for:
     * scope changes; strategic decisions; dubious sources; external publication;
     * financial action; sensitive action; irreversible change."
     *
     * @var list<string>
     */
    public const HUMAN_CHECKPOINT_TRIGGERS = [
        'scope_change',
        'strategic_decision',
        'dubious_sources',
        'external_publication',
        'financial_action',
        'sensitive_action',
        'irreversible_change',
    ];

    /**
     * Task types that the Shared Workspace Rule names explicitly: "for long
     * work, programming work or multi-provider work" coordination MUST go
     * through the workspace.
     *
     * @var list<string>
     */
    private const WORKSPACE_REQUIRED_TASK_TYPES = [
        'code',
        'programming',
        'long_work',
    ];

    /**
     * Decide the single controlled admission outcome for one Obra AI action.
     *
     * @param array<string,mixed> $action
     *        envelope          : array  Operation Envelope (see ENVELOPE_REQUIRED_FIELDS)
     *        checkpoint_flags   : list   active human-checkpoint triggers (default [])
     *        provider          : array  { model:string, is_local:bool }
     *        providers          : list   provider names taking part (multi => >1)
     *        workspace_bound    : bool   action already coordinated via the workspace
     *
     * @return array<string,mixed> the decision + audit trace skeleton
     */
    public function decide(array $action): array
    {
        $envelope = is_array($action['envelope'] ?? null) ? $action['envelope'] : [];
        $missingFields = $this->missingEnvelopeFields($envelope);
        $envelopeComplete = $missingFields === [];

        $checkpointFlags = $this->normalizeCheckpointFlags($action['checkpoint_flags'] ?? []);

        $provider = is_array($action['provider'] ?? null) ? $action['provider'] : [];
        $providerModel = is_string($provider['model'] ?? null) && trim((string) $provider['model']) !== ''
            ? trim((string) $provider['model'])
            : 'unspecified';
        $providerIsLocal = (bool) ($provider['is_local'] ?? false);

        $providers = $this->normalizeProviders($action['providers'] ?? []);
        $isMultiProvider = count($providers) > 1;

        $taskType = $this->envelopeString($envelope, 'task_type');
        $needsWorkspace = $isMultiProvider
            || in_array($taskType, self::WORKSPACE_REQUIRED_TASK_TYPES, true);
        $workspaceBound = (bool) ($action['workspace_bound'] ?? false);

        // Provider-policy probe (doc "Provider Policy"): an Obra carrying
        // sensitive data that may NOT leave local requires a local model.
        $sensitiveData = $this->envelopeRisk($envelope) === 'sensitive'
            || (bool) ($envelope['sensitive_data'] ?? false);
        $dataMayLeaveLocal = (bool) ($envelope['data_may_leave_local'] ?? false);
        $localModelRequired = $sensitiveData && ! $dataMayLeaveLocal;
        $providerPolicyViolation = $localModelRequired && ! $providerIsLocal;

        $reasons = [];
        $outcome = null;

        // Rule 1 — loose-chat guard. A missing required envelope field means the
        // harness cannot build a sufficient Operation Envelope; it must clarify
        // instead of treating the request as loose chat. (Highest precedence:
        // without a complete envelope no other governance check is trustworthy.)
        if (! $envelopeComplete) {
            $outcome = self::OUTCOME_CLARIFY;
            $reasons[] = 'envelope_incomplete:' . implode(',', $missingFields);
        }

        // Rule 2 — provider policy. Sensitive, local-only data on a non-local
        // model is a hard checkpoint: a human must approve before the model can
        // see data that must not leave the local environment.
        if ($outcome === null && $providerPolicyViolation) {
            $outcome = self::OUTCOME_CHECKPOINT;
            $reasons[] = 'provider_policy_requires_local_model';
        }

        // Rule 3 — human checkpoints. Any documented trigger forces a human
        // checkpoint before the action proceeds.
        if ($outcome === null && $checkpointFlags !== []) {
            $outcome = self::OUTCOME_CHECKPOINT;
            $reasons[] = 'human_checkpoint_triggered:' . implode(',', $checkpointFlags);
        }

        // Rule 4 — shared-workspace rule. Multi-provider / programming / long
        // work that is not yet workspace-bound must route through the Obras
        // Shared Workspace instead of passing authority through informal chat.
        if ($outcome === null && $needsWorkspace && ! $workspaceBound) {
            $outcome = self::OUTCOME_ROUTE_WORKSPACE;
            $reasons[] = $isMultiProvider
                ? 'multi_provider_requires_shared_workspace'
                : "task_type_requires_shared_workspace:{$taskType}";
        }

        // Rule 5 — otherwise the action is admitted to run under the harness.
        if ($outcome === null) {
            $outcome = self::OUTCOME_ADMIT;
            $reasons[] = 'admitted_under_harness';
        }

        $humanRequired = $outcome === self::OUTCOME_CHECKPOINT;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'outcome' => $outcome,
            'envelope_complete' => $envelopeComplete,
            'missing_envelope_fields' => $missingFields,
            'is_loose_chat' => ! $envelopeComplete,
            'checkpoint_flags' => $checkpointFlags,
            'human_required' => $humanRequired,
            'provider_model' => $providerModel,
            'provider_is_local' => $providerIsLocal,
            'sensitive_data' => $sensitiveData,
            'local_model_required' => $localModelRequired,
            'provider_policy_violation' => $providerPolicyViolation,
            'provider_count' => count($providers),
            'is_multi_provider' => $isMultiProvider,
            'needs_workspace' => $needsWorkspace,
            'workspace_bound' => $workspaceBound,
            'auditable' => true,
            'trace' => $this->traceSkeleton($envelope, $providers, $providerModel, $reasons),
            'reasons' => $reasons,
        ];
    }

    /**
     * Convenience predicate for the harness driver: may this action run now
     * without any human / clarify / workspace gate? (false => caller must
     * resolve the returned outcome first).
     */
    public function mayProceed(array $action): bool
    {
        return $this->decide($action)['outcome'] === self::OUTCOME_ADMIT;
    }

    /**
     * Operation Envelope completeness check (doc "Obra Context Pack").
     *
     * @param array<string,mixed> $envelope
     * @return list<string> the required fields that are absent/empty (in order)
     */
    public function missingEnvelopeFields(array $envelope): array
    {
        $missing = [];
        foreach (self::ENVELOPE_REQUIRED_FIELDS as $field) {
            if (! $this->fieldPresent($envelope, $field)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * A field counts as present when it is set and not an empty string / empty
     * array. `sources` and `decisions` may be empty lists only if explicitly
     * provided as arrays — an absent key is always missing.
     */
    private function fieldPresent(array $envelope, string $field): bool
    {
        if (! array_key_exists($field, $envelope)) {
            return false;
        }

        $value = $envelope[$field];

        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            // sources / decisions are list fields: presence = the key exists
            // with an array value (an empty list is a declared, intentional []).
            return true;
        }

        return true;
    }

    /**
     * @param mixed $flags
     * @return list<string> only documented, recognised checkpoint triggers
     */
    private function normalizeCheckpointFlags(mixed $flags): array
    {
        if (! is_array($flags)) {
            return [];
        }

        $clean = [];
        foreach ($flags as $flag) {
            if (! is_string($flag)) {
                continue;
            }
            $key = strtolower(trim($flag));
            if ($key !== '' && in_array($key, self::HUMAN_CHECKPOINT_TRIGGERS, true) && ! in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }

        return array_values($clean);
    }

    /**
     * @param mixed $providers
     * @return list<string>
     */
    private function normalizeProviders(mixed $providers): array
    {
        if (! is_array($providers)) {
            return [];
        }

        $clean = [];
        foreach ($providers as $provider) {
            if (is_string($provider) && trim($provider) !== '') {
                $name = strtolower(trim($provider));
                if (! in_array($name, $clean, true)) {
                    $clean[] = $name;
                }
            }
        }

        return array_values($clean);
    }

    private function envelopeString(array $envelope, string $field): string
    {
        $value = $envelope[$field] ?? null;

        return is_string($value) ? strtolower(trim($value)) : '';
    }

    private function envelopeRisk(array $envelope): string
    {
        return $this->envelopeString($envelope, 'risk');
    }

    /**
     * AI Session Trace skeleton (doc "AI Session Trace"). Records the auditable
     * fields the doc enumerates so the decision "separates Obras from a chat".
     *
     * @param array<string,mixed> $envelope
     * @param list<string>        $providers
     * @param list<string>        $reasons
     * @return array<string,mixed>
     */
    private function traceSkeleton(array $envelope, array $providers, string $providerModel, array $reasons): array
    {
        return [
            'obra_id' => is_string($envelope['obra_id'] ?? null) ? $envelope['obra_id'] : null,
            'domain' => is_string($envelope['domain'] ?? null) ? $envelope['domain'] : null,
            'section' => is_string($envelope['section'] ?? null) ? $envelope['section'] : null,
            'task_type' => is_string($envelope['task_type'] ?? null) ? $envelope['task_type'] : null,
            'model' => $providerModel,
            'providers' => $providers,
            'quality_gate' => $envelope['quality_gate'] ?? null,
            'governance_reasons' => $reasons,
        ];
    }
}
