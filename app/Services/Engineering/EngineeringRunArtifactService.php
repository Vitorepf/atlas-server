<?php

namespace App\Services\Engineering;

use App\Models\AiTrace;
use App\Models\AtlasEngineeringEvidence;
use App\Models\AtlasTask;
use App\Models\AtlasTaskEvent;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EngineeringRunArtifactService
{
    public function __construct(private readonly ?EngineeringContextIntelligenceInput $input = null) {}

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     * @param  array<string,mixed>  $devPlan
     * @param  array<string,mixed>|null  $completion
     * @param  array<int,array<string,mixed>>  $runs
     * @return array<string,mixed>
     */
    public function completionArtifact(
        AtlasTask $task,
        array $contract,
        array $blueprint,
        array $devPlan,
        ?array $completion,
        array $runs,
    ): array {
        $completion = is_array($completion) ? $completion : [];
        $tests = (array) data_get($completion, 'completion_packet.tests', []);
        $changedFiles = array_values((array) ($completion['changed_files'] ?? data_get($completion, 'completion_packet.files_changed', [])));
        $testEvidence = $this->testEvidence($tests);
        $databaseReviewRequired = $this->gateRequired($blueprint, 'database_review');
        $manualQaRequired = $this->gateRequired($blueprint, 'manual_qa');
        $engineeringEvidence = $this->engineeringEvidence($task);
        $acceptanceChecklist = $this->acceptanceChecklist($blueprint, $testEvidence, $databaseReviewRequired, $manualQaRequired, $engineeringEvidence);
        $reviewGates = $this->applyAcceptanceGateStatus(
            $this->reviewGates($blueprint, $completion, $testEvidence, $databaseReviewRequired, $manualQaRequired, $engineeringEvidence),
            $acceptanceChecklist,
        );

        return [
            'artifact_version' => 1,
            'generated_at' => now()->toJSON(),
            'source' => 'atlas:cli:dev',
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'project_id' => $task->project_id,
                'project_step_id' => $task->project_step_id,
            ],
            'plan_id' => (string) ($devPlan['plan_id'] ?? ''),
            'blueprint_id' => (string) ($blueprint['blueprint_id'] ?? ''),
            'status' => (string) ($completion['status'] ?? 'unknown'),
            'decision' => $this->decision($acceptanceChecklist, $reviewGates, $completion),
            'acceptance_checklist' => $acceptanceChecklist,
            'scenario_inventory' => $blueprint['scenario_inventory'] ?? [],
            'review_gates' => $reviewGates,
            'evidence' => [
                'changed_files' => $changedFiles,
                'dirty_count' => count($changedFiles),
                'diff_hash' => $completion['diff_hash'] ?? null,
                'tests' => $tests,
                'test_evidence_recorded' => $testEvidence,
                'engineering_evidence' => $engineeringEvidence,
                'provider_run_count' => count($runs),
                'trace_ids' => $this->traceIds($runs),
            ],
            'residual_risks' => array_values((array) data_get($completion, 'completion_packet.risks', [])),
            'contract_snapshot' => $contract,
        ];
    }

    /**
     * @param  array<string,mixed>  $artifact
     */
    public function persistTaskRun(AtlasTask $task, array $artifact): void
    {
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $history = is_array($metadata['engineering_run_history'] ?? null)
            ? $metadata['engineering_run_history']
            : [];

        $summary = $this->summary($artifact);
        $metadata['latest_engineering_run'] = $summary;
        $metadata['engineering_run_history'] = array_slice([$summary, ...$history], 0, 20);

        if (! isset($metadata['engineering_contract'])) {
            $metadata['engineering_contract'] = $artifact['contract_snapshot'] ?? [];
        }

        $task->forceFill(['metadata' => $metadata])->save();

        if (Schema::hasTable('atlas_task_events')) {
            AtlasTaskEvent::query()->create([
                'task_id' => $task->id,
                'event_type' => 'engineering_dev_run_completed',
                'source' => 'atlas:cli:dev',
                'payload' => $artifact,
                'occurred_at' => now(),
            ]);
        }

        $this->recordAutomaticValidationEvidence($task->refresh(), $artifact);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function recordEvidence(AtlasTask $task, array $data, string $source = 'tasks.engineering.evidence'): array
    {
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $history = is_array($metadata['engineering_evidence'] ?? null)
            ? $metadata['engineering_evidence']
            : [];

        $entry = [
            'id' => (string) Str::orderedUuid(),
            'evidence_type' => (string) $data['evidence_type'],
            'target_id' => $data['target_id'] ?? null,
            'status' => (string) $data['status'],
            'confidence' => isset($data['confidence']) ? round((float) $data['confidence'], 3) : null,
            'summary' => (string) $data['summary'],
            'trace_id' => $data['trace_id'] ?? null,
            'command' => $data['command'] ?? null,
            'artifact_url' => $data['artifact_url'] ?? null,
            'output_excerpt' => $data['output_excerpt'] ?? null,
            'files' => array_values((array) ($data['files'] ?? [])),
            'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            'source' => $source,
            'recorded_at' => now()->toJSON(),
        ];

        $metadata['latest_engineering_evidence'] = $entry;
        $metadata['engineering_evidence'] = array_slice([$entry, ...$history], 0, 100);
        $task->forceFill(['metadata' => $metadata])->save();
        $this->persistEvidenceRecord($task, $entry, $data);

        if (Schema::hasTable('atlas_task_events')) {
            AtlasTaskEvent::query()->create([
                'task_id' => $task->id,
                'event_type' => 'engineering_evidence_recorded',
                'source' => $source,
                'payload' => $entry,
                'occurred_at' => now(),
            ]);
        }

        return $entry;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function evidenceHistory(AtlasTask $task, int $limit = 100): array
    {
        return array_slice($this->engineeringEvidence($task), 0, $this->contextInput()->evidenceHistoryLimit($limit));
    }

    /**
     * @param  array<string,mixed>  $artifact
     */
    private function recordAutomaticValidationEvidence(AtlasTask $task, array $artifact): void
    {
        $tests = collect((array) data_get($artifact, 'evidence.tests', []))
            ->filter(fn (mixed $test): bool => is_array($test) && array_key_exists('ok', $test))
            ->values();

        if ($tests->isEmpty()) {
            return;
        }

        $failed = $tests->contains(fn (array $test): bool => ($test['ok'] ?? null) === false);
        $commands = $tests
            ->map(fn (array $test): mixed => $test['command'] ?? null)
            ->filter(fn (mixed $command): bool => is_string($command) && trim($command) !== '')
            ->values()
            ->all();
        $traceIds = collect((array) data_get($artifact, 'evidence.trace_ids', []))
            ->filter(fn (mixed $traceId): bool => is_string($traceId) && $traceId !== '')
            ->values();

        $this->recordEvidence($task, [
            'evidence_type' => 'validation_evidence',
            'target_id' => 'validation_evidence',
            'status' => $failed ? 'failed' : 'passed',
            'confidence' => $failed ? 0.72 : 0.9,
            'summary' => $this->validationEvidenceSummary($tests->all(), $failed),
            'trace_id' => $traceIds->last(),
            'command' => implode(' && ', array_slice($commands, 0, 3)) ?: null,
            'metadata' => [
                'source_artifact' => 'engineering_run',
                'plan_id' => $artifact['plan_id'] ?? null,
                'blueprint_id' => $artifact['blueprint_id'] ?? null,
                'test_count' => $tests->count(),
            ],
        ], 'atlas:cli:dev.validation');
    }

    /**
     * @param  array<int,array<string,mixed>>  $tests
     */
    private function validationEvidenceSummary(array $tests, bool $failed): string
    {
        $count = count($tests);
        $commands = collect($tests)
            ->map(fn (array $test): mixed => $test['command'] ?? null)
            ->filter(fn (mixed $command): bool => is_string($command) && trim($command) !== '')
            ->take(2)
            ->values()
            ->all();

        $prefix = $failed ? 'Validacao automatica falhou' : 'Validacao automatica passou';
        $suffix = $commands === [] ? '' : ': '.implode(' ; ', $commands);

        return "{$prefix} com {$count} resultado(s) de teste{$suffix}.";
    }

    /**
     * @param  array<string,mixed>  $artifact
     */
    public function persistTraceArtifact(?string $traceId, array $artifact): void
    {
        if (! $traceId || ! Schema::hasTable('ai_traces')) {
            return;
        }

        $trace = AiTrace::query()->find($traceId);
        if (! $trace) {
            return;
        }

        $metadata = is_array($trace->metadata) ? $trace->metadata : [];
        $metadata['engineering_artifact'] = $artifact;
        $metadata['engineering_artifact_summary'] = $this->summary($artifact);
        $trace->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * @param  array<string,mixed>  $artifact
     * @return array<string,mixed>
     */
    public function summary(array $artifact): array
    {
        return [
            'generated_at' => $artifact['generated_at'] ?? null,
            'plan_id' => $artifact['plan_id'] ?? null,
            'blueprint_id' => $artifact['blueprint_id'] ?? null,
            'status' => $artifact['status'] ?? null,
            'decision' => data_get($artifact, 'decision.status'),
            'changed_files_count' => count((array) data_get($artifact, 'evidence.changed_files', [])),
            'acceptance_total' => count((array) ($artifact['acceptance_checklist'] ?? [])),
            'acceptance_needs_review' => collect((array) ($artifact['acceptance_checklist'] ?? []))
                ->whereIn('status', ['needs_review', 'manual_qa_required', 'database_review_required'])
                ->count(),
            'review_needs_attention' => collect((array) ($artifact['review_gates'] ?? []))
                ->whereIn('status', ['needs_review', 'failed'])
                ->count(),
        ];
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $blueprint
     * @return array<string,mixed>
     */
    public function statusSnapshot(AtlasTask $task, array $contract, array $blueprint): array
    {
        $testEvidence = false;
        $databaseReviewRequired = $this->gateRequired($blueprint, 'database_review');
        $manualQaRequired = $this->gateRequired($blueprint, 'manual_qa');
        $engineeringEvidence = $this->engineeringEvidence($task);
        $acceptanceChecklist = $this->acceptanceChecklist($blueprint, $testEvidence, $databaseReviewRequired, $manualQaRequired, $engineeringEvidence);
        $reviewGates = $this->applyAcceptanceGateStatus(
            $this->reviewGates($blueprint, ['status' => 'snapshot'], $testEvidence, $databaseReviewRequired, $manualQaRequired, $engineeringEvidence),
            $acceptanceChecklist,
        );
        $decision = $this->decision($acceptanceChecklist, $reviewGates, ['status' => 'snapshot']);

        return [
            'snapshot_version' => 1,
            'generated_at' => now()->toJSON(),
            'task_id' => $task->id,
            'contract_goal' => (string) ($contract['goal'] ?? $task->title ?? ''),
            'blueprint_id' => (string) ($blueprint['blueprint_id'] ?? ''),
            'decision' => $decision,
            'acceptance_checklist' => $acceptanceChecklist,
            'review_gates' => $reviewGates,
            'evidence_summary' => [
                'total' => count($engineeringEvidence),
                'latest' => $engineeringEvidence[0] ?? null,
                'passed' => collect($engineeringEvidence)->where('status', 'passed')->count(),
                'failed' => collect($engineeringEvidence)->where('status', 'failed')->count(),
                'needs_review' => collect($engineeringEvidence)->where('status', 'needs_review')->count(),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $blueprint
     * @return array<int,array<string,mixed>>
     */
    private function acceptanceChecklist(
        array $blueprint,
        bool $testEvidence,
        bool $databaseReviewRequired,
        bool $manualQaRequired,
        array $engineeringEvidence,
    ): array {
        return collect((array) ($blueprint['acceptance_matrix'] ?? []))
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->map(function (array $entry) use ($testEvidence, $databaseReviewRequired, $manualQaRequired, $engineeringEvidence): array {
                $method = (string) ($entry['verification_method'] ?? 'inspection_or_targeted_test');
                $evidence = $this->matchingEvidence($engineeringEvidence, (string) ($entry['id'] ?? ''), 'acceptance');

                return [
                    ...$entry,
                    'status' => $this->acceptanceStatus($method, $testEvidence, $databaseReviewRequired, $manualQaRequired, $evidence),
                    'evidence' => $this->acceptanceEvidence($method, $testEvidence, $evidence),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>|null  $evidence
     */
    private function acceptanceStatus(string $method, bool $testEvidence, bool $databaseReviewRequired, bool $manualQaRequired, ?array $evidence): string
    {
        if ($evidence !== null) {
            return $this->normalizedEvidenceStatus($evidence);
        }

        if ($method === 'database_review' && $databaseReviewRequired) {
            return 'database_review_required';
        }

        if ($method === 'manual_qa_or_playwright' && $manualQaRequired) {
            return 'manual_qa_required';
        }

        if (in_array($method, ['automated_or_smoke_test', 'inspection_or_targeted_test'], true) && $testEvidence) {
            return 'evidence_recorded';
        }

        return 'needs_review';
    }

    /**
     * @param  array<string,mixed>|null  $evidence
     */
    private function acceptanceEvidence(string $method, bool $testEvidence, ?array $evidence): string
    {
        if ($evidence !== null) {
            return (string) ($evidence['summary'] ?? 'engineering_evidence_recorded');
        }

        if ($testEvidence) {
            return 'test_result_present';
        }

        return match ($method) {
            'database_review' => 'requires_database_review_notes',
            'manual_qa_or_playwright' => 'requires_manual_qa_or_playwright_evidence',
            default => 'requires_operator_or_provider_review',
        };
    }

    /**
     * @param  array<string,mixed>  $blueprint
     * @param  array<string,mixed>  $completion
     * @param  array<int,array<string,mixed>>  $engineeringEvidence
     * @return array<int,array<string,mixed>>
     */
    private function reviewGates(
        array $blueprint,
        array $completion,
        bool $testEvidence,
        bool $databaseReviewRequired,
        bool $manualQaRequired,
        array $engineeringEvidence,
    ): array {
        return collect((array) ($blueprint['review_gates'] ?? []))
            ->filter(fn (mixed $gate): bool => is_array($gate))
            ->map(function (array $gate) use ($testEvidence, $databaseReviewRequired, $manualQaRequired, $engineeringEvidence): array {
                $id = (string) ($gate['id'] ?? '');
                $evidence = $this->matchingEvidence($engineeringEvidence, $id, $id);
                $status = $evidence !== null ? $this->gateStatusFromEvidence($gate, $evidence) : match ($id) {
                    'validation_evidence' => $testEvidence ? 'evidence_recorded' : 'needs_review',
                    'database_review' => $databaseReviewRequired ? 'needs_review' : 'not_applicable',
                    'manual_qa' => $manualQaRequired ? 'needs_review' : 'not_applicable',
                    'acceptance_criteria' => (bool) ($gate['required'] ?? false) ? 'needs_review' : 'not_applicable',
                    'deep_code_review' => 'needs_review',
                    default => (string) ($gate['status'] ?? 'needs_review'),
                };

                return [
                    ...$gate,
                    'status' => $status,
                    'evidence' => $evidence !== null
                        ? (string) ($evidence['summary'] ?? 'engineering_evidence_recorded')
                        : $this->gateEvidence($id, $testEvidence),
                    'evidence_id' => $evidence['id'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function gateEvidence(string $gateId, bool $testEvidence): string
    {
        return match ($gateId) {
            'validation_evidence' => $testEvidence ? 'test_result_present' : 'no_test_result',
            'database_review' => 'requires_schema_or_query_review_notes',
            'manual_qa' => 'requires_manual_qa_or_playwright_evidence',
            'deep_code_review' => 'requires_human_or_provider_review_confidence',
            'acceptance_criteria' => 'requires_mapping_criteria_to_evidence',
            default => 'requires_review',
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $reviewGates
     * @param  array<int,array<string,mixed>>  $acceptanceChecklist
     * @return array<int,array<string,mixed>>
     */
    private function applyAcceptanceGateStatus(array $reviewGates, array $acceptanceChecklist): array
    {
        $total = count($acceptanceChecklist);
        $needsReview = collect($acceptanceChecklist)
            ->pluck('status')
            ->contains(fn (mixed $status): bool => in_array($status, ['needs_review', 'manual_qa_required', 'database_review_required', 'failed'], true));
        $status = match (true) {
            $total === 0 => 'not_applicable',
            $needsReview => 'needs_review',
            default => 'passed',
        };
        $evidence = match (true) {
            $total === 0 => 'no_acceptance_criteria',
            $needsReview => 'acceptance_criteria_need_evidence',
            default => 'all_acceptance_criteria_have_evidence',
        };

        return collect($reviewGates)
            ->map(function (array $gate) use ($status, $evidence): array {
                if (($gate['id'] ?? null) !== 'acceptance_criteria') {
                    return $gate;
                }

                return [
                    ...$gate,
                    'status' => $status,
                    'evidence' => $evidence,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $acceptanceChecklist
     * @param  array<int,array<string,mixed>>  $reviewGates
     * @param  array<string,mixed>  $completion
     * @return array<string,mixed>
     */
    private function decision(array $acceptanceChecklist, array $reviewGates, array $completion): array
    {
        $completionStatus = (string) ($completion['status'] ?? 'unknown');
        $needsReview = collect($acceptanceChecklist)
            ->pluck('status')
            ->merge(collect($reviewGates)->pluck('status'))
            ->contains(fn (mixed $status): bool => in_array($status, ['needs_review', 'manual_qa_required', 'database_review_required', 'failed'], true));

        return [
            'status' => $completionStatus === 'failed' ? 'failed' : ($needsReview ? 'needs_human_review' : 'ready'),
            'auto_complete_allowed' => $completionStatus !== 'failed' && ! $needsReview,
            'reason' => $needsReview
                ? 'Ha criterios ou gates sem evidencia conclusiva.'
                : 'Todos os gates deterministicos possuem evidencia registrada.',
        ];
    }

    /**
     * @param  array<string,mixed>  $blueprint
     */
    private function gateRequired(array $blueprint, string $gateId): bool
    {
        return collect((array) ($blueprint['review_gates'] ?? []))
            ->filter(fn (mixed $gate): bool => is_array($gate))
            ->contains(fn (array $gate): bool => ($gate['id'] ?? null) === $gateId && (bool) ($gate['required'] ?? false));
    }

    /**
     * @param  array<int,mixed>  $tests
     */
    private function testEvidence(array $tests): bool
    {
        return collect($tests)
            ->filter(fn (mixed $test): bool => is_array($test))
            ->contains(fn (array $test): bool => array_key_exists('ok', $test));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function engineeringEvidence(AtlasTask $task): array
    {
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $metadataEntries = collect((array) ($metadata['engineering_evidence'] ?? []))
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->values();

        $tableEntries = collect();
        if ($task->exists && Schema::hasTable('atlas_engineering_evidence')) {
            $tableEntries = AtlasEngineeringEvidence::query()
                ->where('task_id', $task->id)
                ->latest('recorded_at')
                ->latest('created_at')
                ->limit($this->contextInput()->evidenceHistoryLimit(200))
                ->get()
                ->map(fn (AtlasEngineeringEvidence $evidence): array => $this->evidenceModelEntry($evidence));
        }

        return $tableEntries
            ->concat($metadataEntries)
            ->unique(fn (array $entry): string => (string) ($entry['id'] ?? md5(json_encode($entry) ?: '')))
            ->sortByDesc(fn (array $entry): string => (string) ($entry['recorded_at'] ?? ''))
            ->take($this->contextInput()->evidenceHistoryLimit())
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $data
     */
    private function persistEvidenceRecord(AtlasTask $task, array $entry, array $data): void
    {
        if (! Schema::hasTable('atlas_engineering_evidence')) {
            return;
        }

        $record = AtlasEngineeringEvidence::query()->firstOrNew(['id' => $entry['id']]);
        $record->forceFill([
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'project_step_id' => $task->project_step_id,
            'trace_id' => $entry['trace_id'],
            'evidence_type' => $entry['evidence_type'],
            'target_id' => $entry['target_id'],
            'status' => $entry['status'],
            'confidence' => $entry['confidence'],
            'summary' => $entry['summary'],
            'command' => $entry['command'],
            'artifact_url' => $entry['artifact_url'],
            'output_excerpt' => $entry['output_excerpt'],
            'files' => $entry['files'],
            'metadata' => $entry['metadata'],
            'source' => $entry['source'],
            'recorded_at' => $entry['recorded_at'],
        ])->save();
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceModelEntry(AtlasEngineeringEvidence $evidence): array
    {
        return [
            'id' => $evidence->id,
            'evidence_type' => $evidence->evidence_type,
            'target_id' => $evidence->target_id,
            'status' => $evidence->status,
            'confidence' => $evidence->confidence === null ? null : round((float) $evidence->confidence, 3),
            'summary' => $evidence->summary,
            'trace_id' => $evidence->trace_id,
            'command' => $evidence->command,
            'artifact_url' => $evidence->artifact_url,
            'output_excerpt' => $evidence->output_excerpt,
            'files' => array_values((array) $evidence->files),
            'metadata' => is_array($evidence->metadata) ? $evidence->metadata : [],
            'source' => $evidence->source,
            'recorded_at' => $evidence->recorded_at?->toJSON(),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $engineeringEvidence
     * @return array<string,mixed>|null
     */
    private function matchingEvidence(array $engineeringEvidence, string $targetId, string $fallbackType): ?array
    {
        $targetId = trim($targetId);
        $fallbackType = trim($fallbackType);

        return collect($engineeringEvidence)
            ->first(function (array $entry) use ($targetId, $fallbackType): bool {
                $entryTarget = trim((string) ($entry['target_id'] ?? ''));
                $entryType = trim((string) ($entry['evidence_type'] ?? ''));

                return ($targetId !== '' && $entryTarget === $targetId)
                    || ($entryTarget === '' && $fallbackType !== '' && $entryType === $fallbackType);
            });
    }

    /**
     * @param  array<string,mixed>  $gate
     * @param  array<string,mixed>  $evidence
     */
    private function gateStatusFromEvidence(array $gate, array $evidence): string
    {
        $status = $this->normalizedEvidenceStatus($evidence);
        if ($status !== 'passed') {
            return $status;
        }

        $minimum = (float) ($gate['minimum_confidence'] ?? 0);
        $confidence = isset($evidence['confidence']) ? (float) $evidence['confidence'] : 1.0;

        return $confidence >= $minimum ? 'passed' : 'needs_review';
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    private function normalizedEvidenceStatus(array $evidence): string
    {
        $status = (string) ($evidence['status'] ?? 'needs_review');

        return in_array($status, ['passed', 'failed', 'needs_review', 'not_applicable'], true)
            ? $status
            : 'needs_review';
    }

    /**
     * @param  array<int,array<string,mixed>>  $runs
     * @return array<int,string>
     */
    private function traceIds(array $runs): array
    {
        return collect($runs)
            ->map(fn (array $run): mixed => $run['trace_id'] ?? null)
            ->filter(fn (mixed $traceId): bool => is_string($traceId) && $traceId !== '')
            ->values()
            ->all();
    }

    private function contextInput(): EngineeringContextIntelligenceInput
    {
        return $this->input ?? app(EngineeringContextIntelligenceInput::class);
    }
}
