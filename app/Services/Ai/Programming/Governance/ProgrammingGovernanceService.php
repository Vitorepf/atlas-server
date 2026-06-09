<?php

namespace App\Services\Ai\Programming\Governance;

use App\Models\AtlasProgrammingReview;
use App\Models\AtlasProgrammingWorkItem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Top-level orchestrator for the Atlas Programming Governance flow.
 *
 * Lifecycle: intake -> placement -> code-intel -> spec -> task contract ->
 * execution (external) -> evidence -> verification -> review -> completion.
 *
 * Persistence is required: methods that mutate state will throw if the
 * `atlas_programming_work_items` table is missing.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system.md
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
 */
class ProgrammingGovernanceService
{
    /** @var list<string> */
    private const STAGES_BEFORE_SPEC = ['intake', 'placement', 'code_intel'];

    /** @var list<string> */
    private const STAGES_AFTER_EXECUTION = ['executing', 'verifying', 'review', 'closed', 'blocked'];

    public function __construct(
        private readonly ProgrammingWorkItemClassifier $classifier,
        private readonly ProgrammingGateRunner $gateRunner,
    ) {}

    /**
     * Receive a new programming intent, classify, persist, return snapshot.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function intake(string $intent, array $options = []): array
    {
        $this->requireStorage();
        $intent = trim($intent);
        if ($intent === '') {
            throw new RuntimeException('intent_text_required');
        }

        $classification = $this->classifier->classify($intent, $options);
        $code = $this->generateCode($classification['intent_type']);

        $workItem = AtlasProgrammingWorkItem::query()->create([
            'code' => $code,
            'intent_text' => $intent,
            'intent_type' => $classification['intent_type'],
            'scope_mode' => $classification['scope_mode'],
            'risk_level' => $classification['risk_level'],
            'owner' => $this->stringOption($options, 'owner'),
            'workspace' => $this->stringOption($options, 'workspace'),
            'status' => $this->initialStatus($classification['scope_mode']),
            'current_stage' => 'intake',
            'placement_json' => [],
            'code_intelligence_json' => [],
            'spec_json' => [],
            'plan_json' => [],
            'tasks_json' => [],
            'evidence_refs_json' => [],
            'gaps_json' => $this->initialGaps(),
            'metadata_json' => [
                'classification_signals' => $classification['signals'],
                'options' => $this->safeOptions($options),
            ],
        ]);

        return $this->snapshot($workItem);
    }

    public function find(string $codeOrId): AtlasProgrammingWorkItem
    {
        $this->requireStorage();

        $query = AtlasProgrammingWorkItem::query()->where('code', $codeOrId);
        if ($this->looksLikeUuid($codeOrId)) {
            $query->orWhere('id', $codeOrId);
        }
        $item = $query->first();

        if ($item === null) {
            throw new RuntimeException("work_item_not_found:{$codeOrId}");
        }

        return $item;
    }

    private function looksLikeUuid(string $candidate): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $candidate);
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    public function attachSpec(AtlasProgrammingWorkItem $workItem, array $spec): array
    {
        $this->requireStorage();
        $this->guardRetroactiveSpec($workItem);

        $normalized = $this->normalizeSpec($spec);
        $hash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        $workItem->forceFill([
            'spec_json' => $normalized,
            'spec_hash' => $hash,
            'status' => $workItem->scope_mode === ProgrammingScopeMode::Structural->value ? 'plan_required' : $workItem->status,
            'current_stage' => 'spec',
        ])->save();

        return $this->snapshot($workItem->refresh());
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  list<array<string,mixed>>  $tasks
     * @return array<string,mixed>
     */
    public function attachPlan(AtlasProgrammingWorkItem $workItem, array $plan, array $tasks): array
    {
        $this->requireStorage();
        $this->guardRetroactivePlan($workItem);

        $normalizedTasks = array_values(array_map(fn (array $task): array => $this->normalizeTaskContract($task), $tasks));
        $normalizedPlan = $this->normalizePlan($plan, $normalizedTasks);
        $hash = hash('sha256', json_encode($normalizedPlan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        $workItem->forceFill([
            'plan_json' => $normalizedPlan,
            'plan_hash' => $hash,
            'tasks_json' => $normalizedTasks,
            'status' => 'executing',
            'current_stage' => 'execution',
        ])->save();

        return $this->snapshot($workItem->refresh());
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function appendEvidence(AtlasProgrammingWorkItem $workItem, array $receipt): array
    {
        $this->requireStorage();

        $refs = (array) $workItem->evidence_refs_json;
        $refs[] = $receipt;
        $workItem->forceFill([
            'evidence_refs_json' => array_values($refs),
            'current_stage' => 'evidence',
            'status' => in_array($workItem->status, ['closed', 'blocked'], true) ? $workItem->status : 'verifying',
        ])->save();

        return $this->snapshot($workItem->refresh());
    }

    /**
     * @param  list<string>|null  $gates
     * @return array<string,mixed>
     */
    public function verify(AtlasProgrammingWorkItem $workItem, ?array $gates = null, bool $strict = false): array
    {
        $this->requireStorage();

        $summary = $this->gateRunner->run($workItem, $gates);
        $payload = array_merge($this->snapshot($workItem->refresh()), [
            'gate_summary' => $summary,
            'strict' => $strict,
        ]);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $reviewPayload
     * @return array<string,mixed>
     */
    public function closeout(AtlasProgrammingWorkItem $workItem, string $result, array $reviewPayload = []): array
    {
        $this->requireStorage();

        $allowed = ['approved', 'changes_requested', 'blocked', 'deferred'];
        if (! in_array($result, $allowed, true)) {
            throw new RuntimeException("invalid_review_result:{$result}");
        }

        $review = AtlasProgrammingReview::query()->create([
            'work_item_id' => $workItem->id,
            'result' => $result,
            'summary' => $reviewPayload['summary'] ?? null,
            'risk_notes' => $reviewPayload['risk_notes'] ?? null,
            'decided_by' => $reviewPayload['decided_by'] ?? null,
            'payload_json' => $reviewPayload,
        ]);

        $workItem->forceFill([
            'status' => 'review',
            'current_stage' => 'completion',
        ])->save();

        $summary = $this->gateRunner->run($workItem->refresh(), ['hierarchical-control', 'completion']);

        return array_merge($this->snapshot($workItem->refresh()), [
            'review_id' => $review->id,
            'gate_summary' => $summary,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(AtlasProgrammingWorkItem $workItem): array
    {
        return [
            'schema_version' => 'atlas.programming.work_item.v1',
            'id' => $workItem->id,
            'code' => $workItem->code,
            'intent_text' => $workItem->intent_text,
            'intent_type' => $workItem->intent_type,
            'scope_mode' => $workItem->scope_mode,
            'risk_level' => $workItem->risk_level,
            'owner' => $workItem->owner,
            'workspace' => $workItem->workspace,
            'status' => $workItem->status,
            'current_stage' => $workItem->current_stage,
            'spec_hash' => $workItem->spec_hash,
            'plan_hash' => $workItem->plan_hash,
            'placement' => $workItem->placement_json,
            'code_intelligence' => $workItem->code_intelligence_json,
            'spec' => $workItem->spec_json,
            'plan' => $workItem->plan_json,
            'tasks' => $workItem->tasks_json,
            'evidence_refs' => $workItem->evidence_refs_json,
            'gaps' => $workItem->gaps_json,
            'metadata' => $workItem->metadata_json,
            'required_gates' => ProgrammingScopeMode::from($workItem->scope_mode)->requiredGates(),
            'closed_at' => optional($workItem->closed_at)?->toJSON(),
            'created_at' => $workItem->created_at?->toJSON(),
            'updated_at' => $workItem->updated_at?->toJSON(),
        ];
    }

    /**
     * Append a gap entry without duplicates.
     */
    public function recordGap(AtlasProgrammingWorkItem $workItem, string $gapName, ?string $reason = null): void
    {
        $gaps = (array) $workItem->gaps_json;
        foreach ($gaps as $existing) {
            if (is_array($existing) && ($existing['name'] ?? null) === $gapName) {
                return;
            }
        }
        $gaps[] = ['name' => $gapName, 'reason' => $reason, 'recorded_at' => now()->toJSON()];
        $workItem->forceFill(['gaps_json' => array_values($gaps)])->save();
    }

    public function markClosed(AtlasProgrammingWorkItem $workItem): void
    {
        $workItem->forceFill([
            'status' => 'closed',
            'current_stage' => 'completion',
            'closed_at' => now(),
        ])->save();
    }

    private function generateCode(string $intentType): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^a-z]/i', '', $intentType) ?: 'pgs', 0, 3));
        $suffix = strtoupper(substr((string) Str::ulid(), -8));

        return $prefix.'-'.$suffix;
    }

    private function initialStatus(string $scopeMode): string
    {
        return $scopeMode === ProgrammingScopeMode::Structural->value
            ? 'spec_required'
            : 'open';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function initialGaps(): array
    {
        return [
            ['name' => 'plan_autogeneration', 'reason' => 'spec_to_plan_compiler_not_implemented_yet', 'recorded_at' => now()->toJSON()],
            ['name' => 'cartography_publishing', 'reason' => 'cartographic_knowledge_os_not_implemented_yet', 'recorded_at' => now()->toJSON()],
            ['name' => 'learning_loop_automation', 'reason' => 'drift_detector_not_implemented_yet', 'recorded_at' => now()->toJSON()],
        ];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function normalizeSpec(array $spec): array
    {
        $required = [
            'objective', 'context', 'expected_behavior', 'likely_files',
            'inputs_outputs', 'risks', 'tests', 'evidence_required',
            'rollback', 'completion_criteria',
        ];
        $normalized = [];
        foreach ($required as $field) {
            $normalized[$field] = $spec[$field] ?? null;
        }
        foreach ($spec as $key => $value) {
            if (! array_key_exists($key, $normalized)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $task
     * @return array<string,mixed>
     */
    private function normalizeTaskContract(array $task): array
    {
        $required = [
            'owner' => $task['owner'] ?? null,
            'allowed_files' => array_values((array) ($task['allowed_files'] ?? [])),
            'forbidden_files' => array_values((array) ($task['forbidden_files'] ?? [])),
            'expected_files' => array_values((array) ($task['expected_files'] ?? [])),
            'dependencies' => array_values((array) ($task['dependencies'] ?? [])),
            'risk_level' => $task['risk_level'] ?? null,
            'validation_commands' => array_values((array) ($task['validation_commands'] ?? [])),
            'acceptance_criteria' => array_values((array) ($task['acceptance_criteria'] ?? [])),
            'rollback' => $task['rollback'] ?? null,
            'evidence_required' => array_values((array) ($task['evidence_required'] ?? [])),
            'docs_required' => array_values((array) ($task['docs_required'] ?? [])),
            'cartography_required' => (bool) ($task['cartography_required'] ?? false),
        ];
        foreach ($task as $key => $value) {
            if (! array_key_exists($key, $required)) {
                $required[$key] = $value;
            }
        }

        return $required;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  list<array<string,mixed>>  $tasks
     * @return array<string,mixed>
     */
    private function normalizePlan(array $plan, array $tasks): array
    {
        return array_merge([
            'schema_version' => 'atlas.programming.plan.v1',
            'phases' => array_values((array) ($plan['phases'] ?? [])),
            'task_count' => count($tasks),
        ], $plan);
    }

    private function guardRetroactiveSpec(AtlasProgrammingWorkItem $workItem): void
    {
        if (in_array($workItem->status, self::STAGES_AFTER_EXECUTION, true) && $workItem->status !== 'blocked') {
            throw new RuntimeException("retroactive_spec_rejected:status={$workItem->status}");
        }
    }

    private function guardRetroactivePlan(AtlasProgrammingWorkItem $workItem): void
    {
        if (in_array($workItem->status, ['closed'], true)) {
            throw new RuntimeException("retroactive_plan_rejected:status={$workItem->status}");
        }
        if ($workItem->scope_mode === ProgrammingScopeMode::Structural->value && $workItem->spec_hash === null) {
            throw new RuntimeException('plan_requires_spec_for_structural_scope');
        }
    }

    private function requireStorage(): void
    {
        try {
            if (Schema::hasTable('atlas_programming_work_items')) {
                return;
            }
        } catch (Throwable) {
            // fall through
        }
        throw new RuntimeException('atlas_programming_work_items_table_missing');
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function safeOptions(array $options): array
    {
        $allowed = ['type', 'mode', 'risk', 'owner', 'workspace'];
        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $options)) {
                $safe[$key] = $options[$key];
            }
        }

        return $safe;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
